<?php
// ============================================================
// views/admin/mentorships.php
// FINAL VERSION:
//   - ALL lock restrictions removed for admin.
//   - 6-month concept is INFORMATIONAL ONLY (shows expiry date
//     and days remaining as reference — does NOT block anything).
//   - Admin can Remove / Reassign ANY pairing at ANY time.
//   - No "Locked" label, no disabled buttons, no date checks.
//   - set_lock / remove_lock actions removed (not needed).
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
   POST HANDLERS — Admin has FULL unrestricted control
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::csrfVerify();

    $action     = $_POST['action']     ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);

    // ── Remove / complete a pairing (ALWAYS allowed, no date check) ──
    if ($action === 'remove_mentee' && $request_id > 0) {

        $chk = $db->prepare(
            "SELECT id, mentee_id, mentor_id
             FROM mentor_requests WHERE id = ? AND status = 'accepted'"
        );
        $chk->bind_param('i', $request_id);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$row) {
            $action_error = 'Active pairing not found.';
        } else {
            $db->begin_transaction();
            try {
                // Mark pairing as completed — frees both mentor and mentee
                $upd = $db->prepare(
                    "UPDATE mentor_requests SET status = 'completed' WHERE id = ?"
                );
                $upd->bind_param('i', $request_id);
                $upd->execute();
                $upd->close();
                $db->commit();

                Security::log(
                    'ADMIN_REMOVE_PAIRING',
                    "Admin removed pairing #{$request_id} " .
                    "(mentor #{$row['mentor_id']} / mentee #{$row['mentee_id']})",
                    $db
                );
                $action_msg = 'Pairing removed successfully. Both mentor and mentee are now free for new pairings.';
            } catch (Exception $e) {
                $db->rollback();
                $action_error = 'Failed to remove pairing. Please try again.';
                error_log('Admin remove pairing error: ' . $e->getMessage());
            }
        }
    }

    // ── Reassign mentee to a different mentor (ALWAYS allowed) ──
    if ($action === 'reassign' && $request_id > 0) {
        $new_mentor_id = (int)($_POST['new_mentor_id'] ?? 0);

        if ($new_mentor_id <= 0) {
            $action_error = 'Please select a mentor to reassign to.';
        } else {
            // Fetch the current pairing
            $chk = $db->prepare(
                "SELECT id, mentee_id, mentor_id
                 FROM mentor_requests WHERE id = ? AND status = 'accepted'"
            );
            $chk->bind_param('i', $request_id);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$row) {
                $action_error = 'Active pairing not found.';
            } elseif ($new_mentor_id == $row['mentor_id']) {
                $action_error = 'The new mentor is the same as the current mentor. Please choose a different one.';
            } else {
                $mentee_id      = (int)$row['mentee_id'];
                $old_mentor_id  = (int)$row['mentor_id'];

                // Verify new mentor exists
                $mchk = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'mentor'");
                $mchk->bind_param('i', $new_mentor_id);
                $mchk->execute();
                $new_mentor_row = $mchk->get_result()->fetch_assoc();
                $mchk->close();

                if (!$new_mentor_row) {
                    $action_error = 'Selected mentor not found.';
                } else {
                    // Check new mentor capacity (max 2 active mentees)
                    $cap = $db->prepare(
                        "SELECT COUNT(*) AS cnt FROM mentor_requests
                         WHERE mentor_id = ? AND status = 'accepted'"
                    );
                    $cap->bind_param('i', $new_mentor_id);
                    $cap->execute();
                    $cap_cnt = (int)$cap->get_result()->fetch_assoc()['cnt'];
                    $cap->close();

                    if ($cap_cnt >= 2) {
                        $action_error = "Mentor '{$new_mentor_row['name']}' already has 2 active mentees (max capacity).";
                    } else {
                        $db->begin_transaction();
                        try {
                            // Mark old pairing as completed
                            $close = $db->prepare(
                                "UPDATE mentor_requests SET status = 'completed' WHERE id = ?"
                            );
                            $close->bind_param('i', $request_id);
                            $close->execute();
                            $close->close();

                            // Create new pairing
                            $ins = $db->prepare(
                                "INSERT INTO mentor_requests
                                 (mentor_id, mentee_id, skill, message, status, paired_at)
                                 SELECT ?, mentee_id, skill, message, 'accepted', NOW()
                                 FROM mentor_requests WHERE id = ?"
                            );
                            $ins->bind_param('ii', $new_mentor_id, $request_id);
                            $ins->execute();
                            $ins->close();

                            $db->commit();

                            Security::log(
                                'ADMIN_REASSIGN',
                                "Admin reassigned mentee #{$mentee_id} from mentor #{$old_mentor_id} " .
                                "to mentor #{$new_mentor_id}",
                                $db
                            );
                            $action_msg = "Mentee successfully reassigned to {$new_mentor_row['name']}.";
                        } catch (Exception $e) {
                            $db->rollback();
                            $action_error = 'Reassignment failed. Please try again.';
                            error_log('Admin reassign error: ' . $e->getMessage());
                        }
                    }
                }
            }
        }
    }
}

/* ============================================================
   FETCH DATA
============================================================ */
$tab = $_GET['tab'] ?? 'active';

if ($tab === 'completed') {
    $sql = "SELECT mr.id AS request_id,
                   mr.mentee_id, mr.mentor_id,
                   mr.status, mr.paired_at, mr.pairing_expires_at, mr.created_at,
                   um.name AS mentor_name, um.department AS mentor_dept,
                   ue.name AS mentee_name, ue.department AS mentee_dept,
                   (SELECT COUNT(*) FROM mentor_requests mr2
                    WHERE mr2.mentee_id = mr.mentee_id
                      AND mr2.mentor_id = mr.mentor_id
                      AND mr2.session_completed = 1) AS sessions_done
            FROM mentor_requests mr
            JOIN users um ON mr.mentor_id = um.id
            JOIN users ue ON mr.mentee_id = ue.id
            WHERE mr.status IN ('completed','rejected','already paired','cancelled')
            ORDER BY COALESCE(mr.paired_at, mr.created_at) DESC";
} else {
    $sql = "SELECT mr.id AS request_id,
                   mr.mentee_id, mr.mentor_id,
                   mr.status, mr.paired_at, mr.pairing_expires_at, mr.created_at,
                   um.name AS mentor_name, um.department AS mentor_dept,
                   ue.name AS mentee_name, ue.department AS mentee_dept,
                   (SELECT COUNT(*) FROM mentor_requests mr2
                    WHERE mr2.mentee_id = mr.mentee_id
                      AND mr2.mentor_id = mr.mentor_id
                      AND mr2.session_completed = 1) AS sessions_done
            FROM mentor_requests mr
            JOIN users um ON mr.mentor_id = um.id
            JOIN users ue ON mr.mentee_id = ue.id
            WHERE mr.status = 'accepted'
            ORDER BY mr.paired_at DESC";
}

$result      = $db->query($sql);
$mentorships = ($result && $result !== false) ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Summary counts
$active_count_res = $db->query("SELECT COUNT(*) AS total FROM mentor_requests WHERE status='accepted'");
$active_count     = $active_count_res ? (int)$active_count_res->fetch_assoc()['total'] : 0;

$completed_count_res = $db->query(
    "SELECT COUNT(*) AS total FROM mentor_requests WHERE status='completed'"
);
$completed_count = $completed_count_res ? (int)$completed_count_res->fetch_assoc()['total'] : 0;

$total_users_res = $db->query(
    "SELECT COUNT(*) AS total FROM users WHERE role IN ('mentor','mentee')"
);
$total_users = $total_users_res ? (int)$total_users_res->fetch_assoc()['total'] : 0;

// All mentors for reassign dropdown
$mentors_res = $db->query(
    "SELECT id, name, department FROM users WHERE role='mentor' ORDER BY name ASC"
);
$all_mentors = ($mentors_res && $mentors_res !== false) ? $mentors_res->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reassign — Mentor Connect</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        /* ── Summary tiles ── */
        .dash-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }
        .dash-tile {
            background: #fff;
            border-radius: 16px;
            padding: 22px 24px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            border: 1px solid #f0f4f8;
        }
        .dash-tile .dt-value { font-size: 34px; font-weight: 800; color: #1a2b4a; line-height: 1; }
        .dash-tile .dt-label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.6px; margin-top: 6px; }
        .dash-tile.green .dt-value { color: #059669; }
        .dash-tile.blue  .dt-value { color: #2563eb; }
        .dash-tile.amber .dt-value { color: #d97706; }

        /* ── Tab nav ── */
        .tab-nav { display: flex; gap: 8px; margin-bottom: 20px; }
        .tab-btn {
            padding: 9px 20px; border-radius: 10px; font-size: 13.5px;
            font-weight: 600; text-decoration: none; color: #64748b;
            background: #f1f5f9; border: 1.5px solid transparent; transition: all 0.18s;
        }
        .tab-btn:hover { background: #e2e8f0; color: #1a2b4a; }
        .tab-btn.active { background: #1a2b4a; color: #fff; border-color: #1a2b4a; }

        /* ── Card ── */
        .card {
            background: #fff; border-radius: 14px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            border: 1px solid #f0f4f8; overflow: hidden;
        }
        .card-hdr {
            padding: 16px 22px; border-bottom: 1px solid #f0f4f8;
            font-weight: 700; font-size: 14px; color: #1a2b4a;
            display: flex; align-items: center; gap: 8px;
        }
        .card-body { padding: 0; }

        /* ── Table ── */
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th {
            background: #f8fafc; padding: 11px 16px; text-align: left;
            font-weight: 700; font-size: 11.5px; text-transform: uppercase;
            letter-spacing: 0.5px; color: #64748b; border-bottom: 1px solid #e8edf2;
        }
        tbody td {
            padding: 13px 16px; border-bottom: 1px solid #f1f5f9;
            color: #334155; vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #f8fafc; }

        /* ── Badges ── */
        .dept-badge {
            display: inline-block; background: #e0e7ff; color: #3730a3;
            border-radius: 6px; padding: 2px 9px; font-size: 11.5px; font-weight: 600;
        }
        /* Informational-only 6-month badge — NOT a lock indicator */
        .info-badge {
            display: inline-flex; align-items: center; gap: 4px;
            background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1;
            border-radius: 6px; padding: 3px 8px; font-size: 11.5px;
            font-weight: 600; white-space: nowrap;
        }
        .info-badge.done {
            background: #f0fdf4; border-color: #86efac; color: #166534;
        }
        .info-badge.none {
            background: #f8fafc; border-color: #e2e8f0; color: #94a3b8;
        }

        .status-badge { display: inline-block; border-radius: 20px; padding: 4px 12px; font-size: 11.5px; font-weight: 600; }
        .s-accepted  { background: #c6f6d5; color: #22543d; }
        .s-completed { background: #bee3f8; color: #2c5282; }
        .s-paired    { background: #fef3c7; color: #744210; }
        .s-rejected  { background: #fed7d7; color: #742a2a; }
        .s-cancelled { background: #e2e8f0; color: #4a5568; }

        /* ── Action buttons — ALL always active, never disabled ── */
        .btn-action {
            border: none; border-radius: 8px; padding: 7px 13px;
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: background 0.18s, transform 0.12s;
            margin: 2px; font-family: inherit;
        }
        .btn-reassign { background: #1e40af; color: #fff; }
        .btn-reassign:hover { background: #1d4ed8; transform: translateY(-1px); }
        .btn-remove   { background: #dc2626; color: #fff; }
        .btn-remove:hover   { background: #b91c1c; transform: translateY(-1px); }

        .actions-cell { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }

        /* ── Reassign dropdown panel ── */
        .reassign-panel {
            display: none;
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: 10px;
            padding: 14px 16px;
            margin-top: 8px;
            animation: rsPanelIn 0.18s ease;
        }
        .reassign-panel.open { display: block; }
        @keyframes rsPanelIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        .reassign-panel label { font-size: 12px; font-weight: 700; color: #1e40af; display: block; margin-bottom: 6px; }
        .reassign-panel select {
            border: 1.5px solid #bfdbfe; border-radius: 8px; padding: 7px 10px;
            font-size: 13px; font-family: inherit; color: #1a2b4a; outline: none;
            width: 100%; margin-bottom: 10px;
        }
        .reassign-panel select:focus { border-color: #3b82f6; }
        .btn-confirm-reassign {
            background: linear-gradient(135deg, #1e40af, #2563eb);
            color: #fff; border: none; border-radius: 8px;
            padding: 8px 18px; font-size: 13px; font-weight: 700;
            cursor: pointer; font-family: inherit; margin-right: 6px;
        }
        .btn-cancel-reassign {
            background: #f1f5f9; color: #64748b; border: none;
            border-radius: 8px; padding: 8px 14px; font-size: 13px;
            cursor: pointer; font-family: inherit;
        }

        /* ── Alerts ── */
        .alert {
            border-radius: 10px; padding: 13px 18px; margin-bottom: 20px;
            font-size: 13.5px; font-weight: 500;
            display: flex; align-items: flex-start; gap: 10px;
        }
        .alert-success { background: #f0fff4; border: 1px solid #9ae6b4; color: #22543d; }
        .alert-danger  { background: #fff5f5; border: 1px solid #fc8181; color: #742a2a; }

        /* ── Empty ── */
        .empty-state { text-align: center; padding: 52px 20px; color: #94a3b8; }
        .empty-state svg { margin-bottom: 14px; opacity: 0.4; }
        .empty-state p { font-size: 14px; margin: 0; }

        /* ── Info panel ── */
        .info-panel {
            background: #f0f9ff; border: 1px solid #bae6fd;
            border-radius: 12px; padding: 18px 22px; margin-top: 24px;
        }
        .info-panel h6 { color: #0369a1; margin: 0 0 10px; font-size: 13.5px; font-weight: 700; }
        .info-panel ul { margin: 0; padding-left: 18px; }
        .info-panel li { color: #0c4a6e; font-size: 13px; margin-bottom: 5px; line-height: 1.5; }
    </style>
</head>
<body>
<div class="layout">
<?php
    $user_name    = $_SESSION['name'];
    $user_role    = $_SESSION['role'];
    $current_page = 'reassign';
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
                <h2>Pairings Management</h2>
            </div>
            <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['name']); ?></strong></span>
        </div>

        <!-- Summary Tiles -->
        <div class="dash-grid">
            <div class="dash-tile green">
                <div class="dt-value"><?php echo $active_count; ?></div>
                <div class="dt-label">Active Pairings</div>
            </div>
            <div class="dash-tile blue">
                <div class="dt-value"><?php echo $completed_count; ?></div>
                <div class="dt-label">Completed Pairings</div>
            </div>
            <div class="dash-tile amber">
                <div class="dt-value"><?php echo $total_users; ?></div>
                <div class="dt-label">Total Participants</div>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($action_msg): ?>
            <div class="alert alert-success">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg>
                <?php echo htmlspecialchars($action_msg); ?>
            </div>
        <?php endif; ?>
        <?php if ($action_error): ?>
            <div class="alert alert-danger">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php echo htmlspecialchars($action_error); ?>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="tab-nav">
            <a href="?tab=active"    class="tab-btn <?php echo $tab === 'active'    ? 'active' : ''; ?>">👥 Active Pairings</a>
            <a href="?tab=completed" class="tab-btn <?php echo $tab === 'completed' ? 'active' : ''; ?>">📋 Completed / Past</a>
        </div>

        <!-- Main Table Card -->
        <div class="card">
            <div class="card-hdr">
                <?php if ($tab === 'active'): ?>
                    👥 Active Pairings — Admin Full Control
                <?php else: ?>
                    📋 Completed / Past Mentorships
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($mentorships)): ?>
                    <div class="empty-state">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                            <polyline points="13 2 13 9 20 9"/>
                        </svg>
                        <p>No <?php echo $tab === 'active' ? 'active pairings' : 'past mentorships'; ?> found.</p>
                    </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Mentor</th>
                            <th>Dept</th>
                            <th>Mentee</th>
                            <th>Dept</th>
                            <th>Paired On</th>
                            <th>6 Sessions Info</th>
                            <th>Status</th>
                            <?php if ($tab === 'active'): ?>
                                <th>Admin Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mentorships as $idx => $m):
                        $sessions_done = (int)($m['sessions_done'] ?? 0);
                        $sessions_left = max(0, 6 - $sessions_done);
                    ?>
                        <tr>
                            <td style="color:#94a3b8;font-size:12px;"><?php echo $idx + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($m['mentor_name']); ?></strong></td>
                            <td><span class="dept-badge"><?php echo htmlspecialchars($m['mentor_dept'] ?? '—'); ?></span></td>
                            <td><?php echo htmlspecialchars($m['mentee_name']); ?></td>
                            <td><span class="dept-badge"><?php echo htmlspecialchars($m['mentee_dept'] ?? '—'); ?></span></td>
                            <td><?php echo $m['paired_at'] ? date('d M Y', strtotime($m['paired_at'])) : '<span style="color:#94a3b8">—</span>'; ?></td>

                            <!-- 6 Sessions Info column -->
                            <td>
                                <?php if ($sessions_done >= 6): ?>
                                    <span class="info-badge done">
                                        ✓ All 6 Done
                                    </span>
                                    <div style="font-size:11px;color:#166534;margin-top:3px;">
                                        6 / 6 sessions completed
                                    </div>
                                <?php else: ?>
                                    <span class="info-badge">
                                        📋 <?php echo $sessions_done; ?> / 6 Sessions
                                    </span>
                                    <div style="font-size:11px;color:#64748b;margin-top:3px;">
                                        <?php echo $sessions_left; ?> session<?php echo $sessions_left !== 1 ? 's' : ''; ?> remaining
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                $sc = [
                                    'accepted'       => ['s-accepted',  'Active'],
                                    'completed'      => ['s-completed', 'Completed'],
                                    'already paired' => ['s-paired',    'Already Paired'],
                                    'rejected'       => ['s-rejected',  'Rejected'],
                                    'cancelled'      => ['s-cancelled', 'Cancelled'],
                                ];
                                [$cls, $lbl] = $sc[$m['status']] ?? ['s-cancelled', ucfirst($m['status'])];
                                echo "<span class=\"status-badge {$cls}\">{$lbl}</span>";
                                ?>
                            </td>

                            <?php if ($tab === 'active'): ?>
                            <td>
                                <div class="actions-cell">

                                    <!-- REASSIGN button — always active, no restrictions -->
                                    <button type="button"
                                            class="btn-action btn-reassign"
                                            onclick="toggleReassignPanel(<?php echo (int)$m['request_id']; ?>)">
                                        🔄 Reassign
                                    </button>

                                    <!-- REMOVE button — always active, no restrictions -->
                                    <form method="POST" style="margin:0;"
                                          onsubmit="return confirm('Remove this pairing?\n\nMentor: <?php echo htmlspecialchars(addslashes($m['mentor_name'])); ?>\nMentee: <?php echo htmlspecialchars(addslashes($m['mentee_name'])); ?>\n\nBoth will be free for new pairings.');">
                                        <?php echo Security::csrfField(); ?>
                                        <input type="hidden" name="action"     value="remove_mentee">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$m['request_id']; ?>">
                                        <button type="submit" class="btn-action btn-remove">🗑 Remove</button>
                                    </form>
                                </div>

                                <!-- Inline reassign panel (hidden by default) -->
                                <div id="rp-<?php echo (int)$m['request_id']; ?>" class="reassign-panel">
                                    <label>Select New Mentor for <?php echo htmlspecialchars($m['mentee_name']); ?>:</label>
                                    <form method="POST">
                                        <?php echo Security::csrfField(); ?>
                                        <input type="hidden" name="action"     value="reassign">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$m['request_id']; ?>">
                                        <select name="new_mentor_id" required>
                                            <option value="">— Select Mentor —</option>
                                            <?php foreach ($all_mentors as $mentor): ?>
                                            <?php if ($mentor['id'] != $m['mentor_id']): ?>
                                            <option value="<?php echo (int)$mentor['id']; ?>">
                                                <?php echo htmlspecialchars($mentor['name']); ?>
                                                (<?php echo htmlspecialchars($mentor['department'] ?? '—'); ?>)
                                            </option>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-confirm-reassign"
                                                onclick="return confirm('Confirm reassignment?')">
                                            ✓ Confirm Reassign
                                        </button>
                                        <button type="button" class="btn-cancel-reassign"
                                                onclick="toggleReassignPanel(<?php echo (int)$m['request_id']; ?>)">
                                            Cancel
                                        </button>
                                    </form>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div><!-- .card -->

        <!-- Admin Info Panel -->
        <div class="info-panel">
            <h6>ℹ️ Admin Full Control — Key Points</h6>
            <ul>
                <li><strong>No Restrictions:</strong> Admin can Reassign or Remove any pairing at any time — regardless of how many sessions have been completed or how long the pairing has been active.</li>
                <li><strong>Reassign:</strong> Click <em>🔄 Reassign</em> to move a mentee to a different mentor. The old pairing is closed and a new accepted pairing is created immediately.</li>
                <li><strong>Remove:</strong> Click <em>🗑 Remove</em> to end the pairing. Both mentor and mentee become free for new pairings. All session history is preserved.</li>
                <li><strong>6-Month column (informational only):</strong> If an expiry date is set, it is displayed purely as a reference for HR tracking. It does not restrict any admin actions.</li>
                <li><strong>Cross-Department Rule:</strong> When reassigning, the new mentor must not be from the same department as the mentee (enforced at the request/accept stage, not here).</li>
            </ul>
        </div>

    </div><!-- /main -->
</div><!-- /layout -->

<script>
function toggleReassignPanel(requestId) {
    // Close all open panels first
    document.querySelectorAll('.reassign-panel.open').forEach(function(p) {
        if (p.id !== 'rp-' + requestId) p.classList.remove('open');
    });
    var panel = document.getElementById('rp-' + requestId);
    if (panel) {
        panel.classList.toggle('open');
        if (panel.classList.contains('open')) {
            setTimeout(function() {
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 60);
        }
    }
}

function toggleSidenav() {
    document.getElementById('sideNav').classList.toggle('open');
    document.getElementById('sideNavOverlay').classList.toggle('active');
}
function closeSidenav() {
    document.getElementById('sideNav').classList.remove('open');
    document.getElementById('sideNavOverlay').classList.remove('active');
}
</script>
</body>
</html>