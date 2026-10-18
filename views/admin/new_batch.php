<?php
// ============================================================
// views/admin/new_batch.php
// ADDED:
//   - Excel / CSV bulk upload for mentors & mentees
//   - "Delete All Mentors & Mentees" button (keeps admin accounts)
// ============================================================

session_start();

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/config.php";
require_once __DIR__ . "/../../includes/Security.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../../login.php");
    exit();
}

$db = (new Database())->connect();

$action_msg   = '';
$action_error = '';

/* ============================================================
   EXCEL / CSV PARSER HELPER
   Reads .xlsx (Office Open XML) or .csv — no external library.
============================================================ */
function parseUploadedFile(array $file): array {
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $path = $file['tmp_name'];
    $rows = [];

    if ($ext === 'csv') {
        if (($fh = fopen($path, 'r')) !== false) {
            $header = null;
            while (($line = fgetcsv($fh)) !== false) {
                if ($header === null) { $header = array_map('strtolower', array_map('trim', $line)); continue; }
                if (count(array_filter($line)) === 0) continue;
                $rows[] = array_combine(array_slice($header, 0, count($line)), array_slice($line, 0, count($header)));
            }
            fclose($fh);
        }
    } elseif ($ext === 'xlsx') {
        // xlsx = zip; shared strings in xl/sharedStrings.xml, sheet in xl/worksheets/sheet1.xml
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return [];

        // Load shared strings
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ss = simplexml_load_string($ssXml);
            foreach ($ss->si as $si) {
                // Concatenate all <t> nodes (handles rich text)
                $val = '';
                foreach ($si->xpath('.//t') as $t) $val .= (string)$t;
                $sharedStrings[] = $val;
            }
        }

        // Load sheet1
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!$sheetXml) return [];

        $sheet  = simplexml_load_string($sheetXml);
        $header = null;
        foreach ($sheet->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                $type = (string)$cell['t'];
                $v    = isset($cell->v) ? (string)$cell->v : '';
                if ($type === 's') $v = $sharedStrings[(int)$v] ?? '';
                $rowData[] = trim($v);
            }
            if ($header === null) {
                $header = array_map('strtolower', $rowData);
                continue;
            }
            if (count(array_filter($rowData)) === 0) continue;
            $combined = [];
            foreach ($header as $i => $h) $combined[$h] = $rowData[$i] ?? '';
            $rows[] = $combined;
        }
    }
    return $rows;
}

/* ============================================================
   POST HANDLERS
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::csrfVerify();

    $action = $_POST['action'] ?? '';

    // ── ADD NEW PARTICIPANT ────────────────────────────────────
    if ($action === 'add_participant') {
        $name       = trim(strip_tags($_POST['name']       ?? ''));
        $email      = trim(strip_tags($_POST['email']      ?? ''));
        $department = trim(strip_tags($_POST['department'] ?? ''));
        $role       = trim($_POST['role'] ?? '');
        $emp_id     = trim(strip_tags($_POST['emp_id']     ?? ''));
        $password   = $_POST['password'] ?? '';

        $errors = [];
        if (empty($name))                               $errors[] = 'Name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (empty($department))                         $errors[] = 'Department is required.';
        if (!in_array($role, ['mentor','mentee']))       $errors[] = 'Role must be mentor or mentee.';
        if (strlen($password) < 8)                      $errors[] = 'Password must be at least 8 characters.';

        if (empty($errors)) {
            $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
            $chk->bind_param('s', $email);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $action_error = "A user with email '{$email}' already exists.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare(
                    "INSERT INTO users (name, email, password, department, role, emp_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())"
                );
                $stmt->bind_param('ssssss', $name, $email, $hash, $department, $role, $emp_id);
                if ($stmt->execute()) {
                    Security::log('ADMIN_ADD_PARTICIPANT', "Admin added {$role}: {$name} ({$email})", $db);
                    $action_msg = "New {$role} '{$name}' added successfully. They can now log in.";
                } else {
                    $action_error = "Failed to add participant. Please try again.";
                }
                $stmt->close();
            }
            $chk->close();
        } else {
            $action_error = implode(' ', $errors);
        }
    }

    // ── ARCHIVE / FREE A PAIRING ───────────────────────────────
    if ($action === 'archive_pairing') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        if ($request_id > 0) {
            $chk = $db->prepare(
                "SELECT id, mentor_id, mentee_id FROM mentor_requests
                 WHERE id = ? AND status = 'accepted'"
            );
            $chk->bind_param('i', $request_id);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$row) {
                $action_error = 'Active pairing not found.';
            } else {
                $upd = $db->prepare(
                    "UPDATE mentor_requests SET status = 'completed' WHERE id = ?"
                );
                $upd->bind_param('i', $request_id);
                $upd->execute();
                $upd->close();
                Security::log(
                    'ADMIN_ARCHIVE_PAIRING',
                    "Admin archived pairing #{$request_id} " .
                    "(mentor #{$row['mentor_id']} / mentee #{$row['mentee_id']})",
                    $db
                );
                $action_msg = "Pairing archived successfully. Mentor and mentee are now free for a new batch.";
            }
        }
    }

    // ── BULK UPLOAD (Excel / CSV) ──────────────────────────────
    if ($action === 'bulk_upload') {
        if (empty($_FILES['bulk_file']['tmp_name']) || $_FILES['bulk_file']['error'] !== UPLOAD_ERR_OK) {
            $action_error = 'Please choose an Excel (.xlsx) or CSV file to upload.';
        } else {
            $ext = strtolower(pathinfo($_FILES['bulk_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'csv'])) {
                $action_error = 'Only .xlsx and .csv files are supported.';
            } else {
                $rows = parseUploadedFile($_FILES['bulk_file']);
                if (empty($rows)) {
                    $action_error = 'The file is empty or could not be read. Check the format.';
                } else {
                    $added = $skipped = $errors_list = 0;
                    $defaultPass = password_hash('Welcome@123', PASSWORD_DEFAULT);

                    foreach ($rows as $idx => $row) {
                        // Accept flexible column names; user_id replaces emp_id
                        $name  = trim($row['name'] ?? $row['full name'] ?? $row['fullname'] ?? '');
                        $email = trim($row['email'] ?? $row['email address'] ?? '');
                        $dept  = trim($row['department'] ?? $row['dept'] ?? '');
                        $role  = strtolower(trim($row['role'] ?? ''));
                        $uid   = trim($row['user_id'] ?? $row['emp_id'] ?? $row['id'] ?? '');
                        $desig = trim($row['designation'] ?? $row['title'] ?? '');

                        if (empty($name) || empty($email) || empty($role)) { $errors_list++; continue; }
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL))    { $errors_list++; continue; }
                        if (!in_array($role, ['mentor','mentee']))          { $errors_list++; continue; }

                        // Check duplicate
                        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
                        $chk->bind_param('s', $email); $chk->execute();
                        if ($chk->get_result()->num_rows > 0) { $skipped++; $chk->close(); continue; }
                        $chk->close();

                        $stmt = $db->prepare(
                            "INSERT INTO users (name, email, password, department, designation, role, emp_id, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
                        );
                        $stmt->bind_param('sssssss', $name, $email, $defaultPass, $dept, $desig, $role, $uid);
                        if ($stmt->execute()) $added++; else $errors_list++;
                        $stmt->close();
                    }

                    $msg = "Bulk upload complete: {$added} added";
                    if ($skipped)     $msg .= ", {$skipped} skipped (duplicate email)";
                    if ($errors_list) $msg .= ", {$errors_list} rows had errors (missing/invalid fields)";
                    if ($added > 0) {
                        Security::log('ADMIN_BULK_UPLOAD', $msg, $db);
                        $action_msg = $msg . ". Default password <strong>Welcome@123</strong> is set for all uploaded users.";
                    } else {
                        $action_error = $msg . ". No rows were added.";
                    }
                }
            }
        }
    }

    // ── DELETE ALL MENTORS & MENTEES ───────────────────────────
    if ($action === 'delete_all_users') {
        // Delete in safe order: messages → feedback → mentor_requests → users
        $db->query("DELETE FROM messages WHERE sender_id IN (SELECT id FROM users WHERE role IN ('mentor','mentee')) OR receiver_id IN (SELECT id FROM users WHERE role IN ('mentor','mentee'))");
        $db->query("DELETE FROM feedback WHERE user_id IN (SELECT id FROM users WHERE role IN ('mentor','mentee'))");
        $db->query("DELETE FROM mentor_requests WHERE mentor_id IN (SELECT id FROM users WHERE role IN ('mentor','mentee')) OR mentee_id IN (SELECT id FROM users WHERE role IN ('mentor','mentee'))");
        $deleted = $db->query("DELETE FROM users WHERE role IN ('mentor','mentee')");
        $count   = $db->affected_rows;
        Security::log('ADMIN_DELETE_ALL_USERS', "Admin deleted all {$count} mentor/mentee accounts", $db);
        $action_msg = "Deleted {$count} mentor/mentee accounts and all associated data. Admin accounts are untouched.";
    }
}

/* ============================================================
   FETCH DATA
============================================================ */
$active_sql = "
    SELECT mr.id AS request_id,
           mr.mentee_id, mr.mentor_id,
           mr.status, mr.paired_at, mr.created_at,
           mr.session_completed,
           um.name AS mentor_name, um.department AS mentor_dept,
           ue.name AS mentee_name, ue.department AS mentee_dept,
           (SELECT COUNT(*) FROM mentor_requests mr2
            WHERE mr2.mentor_id = mr.mentor_id
              AND mr2.mentee_id = mr.mentee_id
              AND mr2.session_completed = 1) AS sessions_done
    FROM mentor_requests mr
    JOIN users um ON mr.mentor_id = um.id
    JOIN users ue ON mr.mentee_id = ue.id
    WHERE mr.status = 'accepted'
    ORDER BY mr.paired_at DESC
";
$active_result   = $db->query($active_sql);
$active_pairings = ($active_result && $active_result !== false)
    ? $active_result->fetch_all(MYSQLI_ASSOC)
    : [];

$total_mentors_res = $db->query("SELECT COUNT(*) AS c FROM users WHERE role='mentor'");
$total_mentors     = $total_mentors_res ? (int)$total_mentors_res->fetch_assoc()['c'] : 0;

$total_mentees_res = $db->query("SELECT COUNT(*) AS c FROM users WHERE role='mentee'");
$total_mentees     = $total_mentees_res ? (int)$total_mentees_res->fetch_assoc()['c'] : 0;

$completed_pairs_res = $db->query("SELECT COUNT(*) AS c FROM mentor_requests WHERE status='completed'");
$completed_pairs     = $completed_pairs_res ? (int)$completed_pairs_res->fetch_assoc()['c'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Batch — Mentor Connect</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .dash-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:20px; margin-bottom:28px; }
        .dash-tile { background:#fff; border-radius:16px; padding:22px 24px; box-shadow:0 4px 18px rgba(0,0,0,0.06); border:1px solid #f0f4f8; }
        .dash-tile .dt-value { font-size:34px; font-weight:800; color:#1a2b4a; line-height:1; }
        .dash-tile .dt-label { font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.6px; margin-top:6px; }
        .dash-tile.green .dt-value { color:#059669; }
        .dash-tile.blue  .dt-value { color:#2563eb; }
        .dash-tile.amber .dt-value { color:#d97706; }

        .panel { background:#fff; border-radius:14px; box-shadow:0 4px 18px rgba(0,0,0,0.06); border:1px solid #f0f4f8; margin-bottom:28px; overflow:hidden; }
        .panel-hdr { padding:16px 22px; border-bottom:1px solid #f0f4f8; font-weight:700; font-size:14px; color:#1a2b4a; display:flex; align-items:center; gap:8px; }
        .panel-body { padding:24px 22px; }

        /* Form */
        .form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; }
        .form-group { display:flex; flex-direction:column; gap:6px; }
        .form-group label { font-size:12.5px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
        .form-group input,
        .form-group select { border:1.5px solid #e2e8f0; border-radius:8px; padding:9px 12px; font-size:14px; font-family:inherit; color:#1a2b4a; outline:none; transition:border 0.18s; }
        .form-group input:focus,
        .form-group select:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
        .btn-add { background:linear-gradient(135deg,#6366f1,#4f46e5); color:#fff; border:none; border-radius:10px; padding:11px 28px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; transition:transform 0.18s,box-shadow 0.18s; margin-top:8px; }
        .btn-add:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(99,102,241,0.35); }

        /* Bulk upload */
        .upload-zone { border:2px dashed #c7d2fe; border-radius:12px; padding:22px 20px; background:#f5f3ff; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        .upload-zone input[type=file] { flex:1; min-width:220px; font-size:13px; }
        .btn-upload { background:linear-gradient(135deg,#0ea5e9,#0369a1); color:#fff; border:none; border-radius:10px; padding:11px 24px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; transition:transform 0.18s; white-space:nowrap; }
        .btn-upload:hover { transform:translateY(-1px); }
        .template-hint { font-size:12px; color:#6b7280; margin-top:10px; line-height:1.6; }
        .template-hint code { background:#e0e7ff; color:#3730a3; padding:1px 6px; border-radius:4px; font-size:11.5px; }

        /* Delete all */
        .danger-zone { border:1.5px solid #fca5a5; border-radius:12px; padding:20px; background:#fff5f5; }
        .danger-zone h4 { color:#b91c1c; margin:0 0 8px; font-size:14px; }
        .danger-zone p  { color:#6b7280; font-size:13px; margin:0 0 14px; line-height:1.5; }
        .btn-delete-all { background:linear-gradient(135deg,#ef4444,#b91c1c); color:#fff; border:none; border-radius:10px; padding:10px 22px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; transition:transform 0.18s; }
        .btn-delete-all:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(185,28,28,0.35); }

        /* Table */
        table { width:100%; border-collapse:collapse; font-size:13.5px; }
        thead th { background:#f8fafc; padding:11px 16px; text-align:left; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; border-bottom:1px solid #e8edf2; }
        tbody td { padding:13px 16px; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle; }
        tbody tr:last-child td { border-bottom:none; }
        tbody tr:hover { background:#f8fafc; }

        .dept-badge { display:inline-block; background:#e0e7ff; color:#3730a3; border-radius:6px; padding:2px 9px; font-size:11.5px; font-weight:600; }
        .sessions-badge { display:inline-block; background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; border-radius:6px; padding:3px 10px; font-size:12px; font-weight:700; }
        .sessions-badge.partial { background:#fef9c3; color:#713f12; border-color:#fde68a; }

        .btn-archive { background:#0369a1; color:#fff; border:none; border-radius:8px; padding:7px 14px; font-size:12px; font-weight:600; cursor:pointer; transition:background 0.18s,transform 0.12s; font-family:inherit; }
        .btn-archive:hover { background:#0284c7; transform:translateY(-1px); }

        .alert { border-radius:10px; padding:13px 18px; margin-bottom:20px; font-size:13.5px; font-weight:500; display:flex; align-items:flex-start; gap:10px; }
        .alert-success { background:#f0fff4; border:1px solid #9ae6b4; color:#22543d; }
        .alert-danger  { background:#fff5f5; border:1px solid #fc8181; color:#742a2a; }

        .empty-state { text-align:center; padding:40px 20px; color:#94a3b8; }
        .empty-state p { font-size:14px; margin:0; }

        .info-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:14px 18px; margin-bottom:24px; font-size:13px; color:#1e40af; line-height:1.6; }
        .info-box strong { color:#1d4ed8; }
    </style>
</head>
<body>
<div class="layout">
<?php
    $user_name    = $_SESSION['name'];
    $user_role    = $_SESSION['role'];
    $current_page = 'new_batch';
    require_once __DIR__ . "/../../includes/sidenav.php";
?>
    <div class="main">
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="sidenav-toggle" onclick="toggleSidenav()" title="Toggle menu">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <line x1="3" y1="12" x2="21" y2="12"/>
                        <line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <h2>New Batch Management</h2>
            </div>
            <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['name']); ?></strong></span>
        </div>

        <!-- Summary Tiles -->
        <div class="dash-grid">
            <div class="dash-tile green">
                <div class="dt-value"><?php echo $total_mentors; ?></div>
                <div class="dt-label">Total Mentors</div>
            </div>
            <div class="dash-tile blue">
                <div class="dt-value"><?php echo $total_mentees; ?></div>
                <div class="dt-label">Total Mentees</div>
            </div>
            <div class="dash-tile amber">
                <div class="dt-value"><?php echo $completed_pairs; ?></div>
                <div class="dt-label">Archived Pairings</div>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($action_msg): ?>
            <div class="alert alert-success">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg>
                <?php echo $action_msg; ?>
            </div>
        <?php endif; ?>
        <?php if ($action_error): ?>
            <div class="alert alert-danger">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php echo htmlspecialchars($action_error); ?>
            </div>
        <?php endif; ?>

        <!-- ── ADD NEW PARTICIPANT ─────────────────────────────── -->
        <div class="panel">
            <div class="panel-hdr">➕ Add New Participant (Mentor / Mentee)</div>
            <div class="panel-body">
                <div class="info-box">
                    <strong>New Batch:</strong> Add new mentors or mentees for the next batch at any time.
                    Use <em>Archive &amp; Free</em> below to close an active pairing — both participants will be
                    freed for new matching. All session history and feedback data are always preserved.
                </div>

                <form method="POST">
                    <?php echo Security::csrfField(); ?>
                    <input type="hidden" name="action" value="add_participant">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="name" placeholder="e.g. Ravi Kumar" required maxlength="100">
                        </div>
                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" placeholder="e.g. ravi@company.com" required maxlength="150">
                        </div>
                        <div class="form-group">
                            <label>Department *</label>
                            <input type="text" name="department" placeholder="e.g. Engineering" required maxlength="100">
                        </div>
                        <div class="form-group">
                            <label>Role *</label>
                            <select name="role" required>
                                <option value="">— Select Role —</option>
                                <option value="mentor">Mentor</option>
                                <option value="mentee">Mentee</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Mentor / Mentee ID</label>
                            <input type="text" name="emp_id" placeholder="e.g. EMP001 (optional)" maxlength="50">
                        </div>
                        <div class="form-group">
                            <label>Password * (min 8 chars)</label>
                            <input type="password" name="password" placeholder="Set initial password" required minlength="8">
                        </div>
                    </div>
                    <div style="margin-top:20px;">
                        <button type="submit" class="btn-add">➕ Add Participant</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── BULK UPLOAD ─────────────────────────────────────── -->
        <div class="panel">
            <div class="panel-hdr">📂 Bulk Upload via Excel / CSV</div>
            <div class="panel-body">
                <form method="POST" enctype="multipart/form-data">
                    <?php echo Security::csrfField(); ?>
                    <input type="hidden" name="action" value="bulk_upload">
                    <div class="upload-zone">
                        <span style="font-size:32px;">📊</span>
                        <div style="flex:1;">
                            <div style="font-weight:600;color:#1a2b4a;margin-bottom:6px;">Choose Excel or CSV file</div>
                            <input type="file" name="bulk_file" accept=".xlsx,.csv" required>
                        </div>
                        <button type="submit" class="btn-upload">⬆ Upload &amp; Import</button>
                    </div>
                </form>

                <div class="template-hint" style="margin-top:14px;">
                    <strong>Required columns:</strong>
                    <code>name</code> &nbsp;<code>role</code> &nbsp;<code>email</code><br>
                    <strong>Optional columns:</strong>
                    <code>department</code> &nbsp;<code>user_id</code> &nbsp;<code>designation</code><br>
                    Default password <code>Welcome@123</code> is set for all uploaded users.<br>
                    Duplicate emails are automatically skipped.
                    <div style="margin-top:10px;">
                        <a href="#" onclick="downloadTemplate(); return false;"
                           style="color:#4f46e5;font-weight:600;text-decoration:none;">
                            ⬇ Download Sample Template (.csv)
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── DANGER ZONE — DELETE ALL ───────────────────────── -->
        <div class="panel">
            <div class="panel-hdr" style="color:#b91c1c;">🗑️ Danger Zone — Delete All Mentors &amp; Mentees</div>
            <div class="panel-body">
                <div class="danger-zone">
                    <h4>⚠️ Delete All Mentors &amp; Mentees</h4>
                    <p>
                        This permanently deletes <strong>all mentor and mentee accounts</strong> along with their
                        messages, feedback, and pairings. Admin accounts are <strong>never</strong> affected.<br>
                        Use this to fully reset the platform for a completely new batch.
                    </p>
                    <form method="POST"
                          onsubmit="return confirm('Are you sure you want to delete all mentors and mentees? This action cannot be undone.')">
                        <?php echo Security::csrfField(); ?>
                        <input type="hidden" name="action" value="delete_all_users">
                        <button type="submit" class="btn-delete-all">🗑️ Delete All</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ── ALL ACTIVE PAIRINGS ────────────────────────────── -->
        <div class="panel">
            <div class="panel-hdr">👥 Active Pairings — Archive to Start New Batch</div>
            <div class="panel-body" style="padding:0;">
                <?php if (empty($active_pairings)): ?>
                <div class="empty-state">
                    <p>No active pairings found. All pairings have been archived or there are no current pairings.</p>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Mentor</th>
                            <th>Dept</th>
                            <th>Mentee</th>
                            <th>Dept</th>
                            <th>Paired On</th>
                            <th>Sessions Done</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($active_pairings as $p):
                        $sessions_done = (int)$p['sessions_done'];
                        $badge_class   = $sessions_done >= 6 ? 'sessions-badge' : 'sessions-badge partial';
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($p['mentor_name']); ?></strong></td>
                            <td><span class="dept-badge"><?php echo htmlspecialchars($p['mentor_dept'] ?? '—'); ?></span></td>
                            <td><?php echo htmlspecialchars($p['mentee_name']); ?></td>
                            <td><span class="dept-badge"><?php echo htmlspecialchars($p['mentee_dept'] ?? '—'); ?></span></td>
                            <td><?php echo $p['paired_at'] ? date('d M Y', strtotime($p['paired_at'])) : '—'; ?></td>
                            <td>
                                <span class="<?php echo $badge_class; ?>">
                                    <?php echo $sessions_done; ?> / 6
                                    <?php if ($sessions_done >= 6): ?> ✓<?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" style="margin:0;"
                                      onsubmit="return confirm('Archive this pairing?\n\nMentor: <?php echo htmlspecialchars(addslashes($p['mentor_name'])); ?>\nMentee: <?php echo htmlspecialchars(addslashes($p['mentee_name'])); ?>\nSessions done: <?php echo $sessions_done; ?>/6\n\nBoth will be freed for new batch.\nAll history is preserved.');">
                                    <?php echo Security::csrfField(); ?>
                                    <input type="hidden" name="action"     value="archive_pairing">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$p['request_id']; ?>">
                                    <button type="submit" class="btn-archive">📦 Archive &amp; Free</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /main -->
</div><!-- /layout -->

<script>
function toggleSidenav() {
    document.getElementById('sideNav').classList.toggle('open');
    document.getElementById('sideNavOverlay').classList.toggle('active');
}
function closeSidenav() {
    document.getElementById('sideNav').classList.remove('open');
    document.getElementById('sideNavOverlay').classList.remove('active');
}

// Generate and download a sample CSV template
function downloadTemplate() {
    var csv = 'name,role,email,department,user_id,designation\n' +
              'Ravi Kumar,mentor,ravi@company.com,Engineering,EMP001,Senior Engineer\n' +
              'Priya Sharma,mentee,priya@company.com,Marketing,EMP002,Executive\n';
    var blob = new Blob([csv], { type: 'text/csv' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href   = url;
    a.download = 'bulk_upload_template.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>