<?php
// ============================================================
// views/admin/mentorships.php
// MODIFIED:
//   - "6-Month Info" column replaced with "6 Sessions Info".
//   - Shows sessions completed (X/6) with a visual progress bar.
//   - Sessions count fetched per pairing via subquery.
//   - All admin lock/remove actions unchanged.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::csrfVerify();

    $action     = $_POST['action']     ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);

    // ── Admin sets 6-month lock manually ──────────────────────
    if ($action === 'set_lock' && $request_id > 0) {
        $chk = $db->prepare("SELECT id FROM mentor_requests WHERE id = ? AND status = 'accepted'");
        $chk->bind_param('i', $request_id);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$row) {
            $action_error = 'Active pairing not found.';
        } else {
            $upd = $db->prepare(
                "UPDATE mentor_requests
                 SET pairing_expires_at = DATE_ADD(NOW(), INTERVAL 6 MONTH)
                 WHERE id = ?"
            );
            $upd->bind_param('i', $request_id);
            $upd->execute();
            $upd->close();
            $expires_date = date('d M Y', strtotime('+6 months'));
            Security::log('ADMIN_SET_LOCK', "Admin set 6-month lock on pairing #{$request_id} (expires {$expires_date})", $db);
            $action_msg = "6-month lock applied. Expires on {$expires_date}.";
        }
    }

    // ── Admin removes lock manually ────────────────────────────
    if ($action === 'remove_lock' && $request_id > 0) {
        $upd = $db->prepare(
            "UPDATE mentor_requests SET pairing_expires_at = NULL WHERE id = ? AND status = 'accepted'"
        );
        $upd->bind_param('i', $request_id);
        $upd->execute();
        $upd->close();
        Security::log('ADMIN_REMOVE_LOCK', "Admin removed 6-month lock on pairing #{$request_id}", $db);
        $action_msg = "6-month lock removed. Pairing is now unlocked.";
    }

    // ── Admin removes mentee pairing ──────────────────────────
    if ($action === 'remove_mentee' && $request_id > 0) {
        $chk = $db->prepare(
            "SELECT id, mentee_id, mentor_id, status
             FROM mentor_requests WHERE id = ? AND status = 'accepted'"
        );
        $chk->bind_param('i', $request_id);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$row) {
            $action_error = 'Pairing record not found or is not currently active.';
        } else {
            $db->begin_transaction();
            try {
                $upd = $db->prepare("UPDATE mentor_requests SET status = 'completed' WHERE id = ?");
                $upd->bind_param('i', $request_id);
                $upd->execute();
                $upd->close();
                $db->commit();
                Security::log('ADMIN_REMOVE_MENTEE', "Admin removed pairing #{$request_id}", $db);
                $action_msg = 'Mentee pairing removed successfully. Both mentor and mentee are now free for new pairings.';
            } catch (Exception $e) {
                $db->rollback();
                $action_error = 'Failed to remove mentee. Please try again.';
                error_log('Admin remove mentee error: ' . $e->getMessage());
            }
        }
    }
}

// ── Fetch Data ─────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'active';

if ($tab === 'completed') {
    $sql = "SELECT mr.id AS request_id,
                   mr.mentee_id, mr.mentor_id,
                   mr.status, mr.paired_at, mr.pairing_expires_at, mr.created_at,
                   um.name AS mentor_name, um.department AS mentor_dept,
                   ue.name AS mentee_name, ue.department AS mentee_dept,
                   (
                       SELECT COUNT(*)
                       FROM mentor_requests mr2
                       WHERE mr2.mentor_id = mr.mentor_id
                         AND mr2.mentee_id = mr.mentee_id
                         AND mr2.session_completed = 1
                   ) AS sessions_done
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
                   (
                       SELECT COUNT(*)
                       FROM mentor_requests mr2
                       WHERE mr2.mentor_id = mr.mentor_id
                         AND mr2.mentee_id = mr.mentee_id
                         AND mr2.session_completed = 1
                   ) AS sessions_done
            FROM mentor_requests mr
            JOIN users um ON mr.mentor_id = um.id
            JOIN users ue ON mr.mentee_id = ue.id
            WHERE mr.status = 'accepted'
            ORDER BY mr.paired_at DESC";
}

$result      = $db->query($sql);
$mentorships = ($result && $result !== false) ? $result->fetch_all(MYSQLI_ASSOC) : [];

$active_count_res = $db->query("SELECT COUNT(*) AS total FROM mentor_requests WHERE status='accepted'");
$active_count     = $active_count_res ? (int)$active_count_res->fetch_assoc()['total'] : 0;

$locked_count_res = $db->query(
    "SELECT COUNT(*) AS total FROM mentor_requests
     WHERE status='accepted' AND pairing_expires_at IS NOT NULL AND pairing_expires_at > NOW()"
);
$locked_count = $locked_count_res ? (int)$locked_count_res->fetch_assoc()['total'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reassign — Mentor Connect</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .dash-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:20px; margin-bottom:28px; }
        .dash-tile { background:#fff; border-radius:16px; padding:22px 24px; box-shadow:0 4px 18px rgba(0,0,0,0.06); border:1px solid #f0f4f8; }
        .dash-tile .dt-value { font-size:34px; font-weight:800; color:#1a2b4a; line-height:1; }
        .dash-tile .dt-label { font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.6px; margin-top:6px; }
        .dash-tile.green .dt-value { color:#059669; }
        .dash-tile.amber .dt-value { color:#d97706; }
        .dash-tile.blue  .dt-value { color:#2563eb; }

        .tab-nav { display:flex; gap:8px; margin-bottom:20px; }
        .tab-btn { padding:9px 20px; border-radius:10px; font-size:13.5px; font-weight:600; text-decoration:none; color:#64748b; background:#f1f5f9; border:1.5px solid transparent; transition:all 0.18s; }
        .tab-btn:hover { background:#e2e8f0; color:#1a2b4a; }
        .tab-btn.active { background:#1a2b4a; color:#fff; border-color:#1a2b4a; }

        .card { background:#fff; border-radius:14px; box-shadow:0 4px 18px rgba(0,0,0,0.06); border:1px solid #f0f4f8; overflow:hidden; }
        .card-hdr { padding:16px 22px; border-bottom:1px solid #f0f4f8; font-weight:700; font-size:14px; color:#1a2b4a; display:flex; align-items:center; gap:8px; }
        .card-body { padding:0; }

        table { width:100%; border-collapse:collapse; font-size:13.5px; }
        thead th { background:#f8fafc; padding:11px 16px; text-align:left; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; border-bottom:1px solid #e8edf2; }
        tbody td { padding:13px 16px; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle; }
        tbody tr:last-child td { border-bottom:none; }
        tbody tr:hover { background:#f8fafc; }

        .dept-badge { display:inline-block; background:#e0e7ff; color:#3730a3; border-radius:6px; padding:2px 9px; font-size:11.5px; font-weight:600; }

        /* ── 6 Sessions progress cell ── */
        .sessions-cell { min-width: 130px; }
        .sessions-count {
            font-size: 13px;
            font-weight: 700;
            color: #1a2b4a;
            margin-bottom: 5px;
        }
        .sessions-count .done   { color: #059669; }
        .sessions-count .total  { color: #94a3b8; font-weight: 500; }
        /* Progress bar track */
        .sessions-bar {
            width: 100%;
            height: 7px;
            background: #e2e8f0;
            border-radius: 99px;
            overflow: hidden;
        }
        /* Progress bar fill — color changes by completion */
        .sessions-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width 0.4s ease;
        }
        .sessions-bar-fill.c0  { background: #e2e8f0; }   /* 0 sessions */
        .sessions-bar-fill.c1  { background: #fbbf24; }   /* 1-2 sessions */
        .sessions-bar-fill.c2  { background: #f59e0b; }   /* 3-4 sessions */
        .sessions-bar-fill.c3  { background: #10b981; }   /* 5-6 sessions */
        /* Label below bar */
        .sessions-label {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }
        .sessions-label.complete { color: #059669; font-weight: 600; }

        .status-badge { display:inline-block; border-radius:20px; padding:4px 12px; font-size:11.5px; font-weight:600; }
        .s-accepted  { background:#c6f6d5; color:#22543d; }
        .s-completed { background:#bee3f8; color:#2c5282; }
        .s-paired    { background:#fef3c7; color:#744210; }
        .s-rejected  { background:#fed7d7; color:#742a2a; }
        .s-cancelled { background:#e2e8f0; color:#4a5568; }

        .btn-action { border:none; border-radius:8px; padding:7px 12px; font-size:12px; font-weight:600; cursor:pointer; transition:background 0.18s,transform 0.12s; margin:2px; }
        .btn-set-lock  { background:#1e40af; color:#fff; }
        .btn-set-lock:hover  { background:#1d4ed8; transform:translateY(-1px); }
        .btn-remove-lock { background:#d97706; color:#fff; }
        .btn-remove-lock:hover { background:#b45309; transform:translateY(-1px); }
        .btn-remove { background:#e53e3e; color:#fff; }
        .btn-remove:hover { background:#c53030; transform:translateY(-1px); }

        .alert { border-radius:10px; padding:13px 18px; margin-bottom:20px; font-size:13.5px; font-weight:500; display:flex; align-items:flex-start; gap:10px; }
        .alert-success { background:#f0fff4; border:1px solid #9ae6b4; color:#22543d; }
        .alert-danger  { background:#fff5f5; border:1px solid #fc8181; color:#742a2a; }

        .empty-state { text-align:center; padding:52px 20px; color:#94a3b8; }
        .empty-state svg { margin-bottom:14px; opacity:0.4; }
        .empty-state p { font-size:14px; margin:0; }

        .rules-panel { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px 22px; margin-top:24px; }
        .rules-panel h6 { color:#1e40af; margin:0 0 10px; font-size:13.5px; font-weight:700; }
        .rules-panel ul { margin:0; padding-left:18px; }
        .rules-panel li { color:#475569; font-size:13px; margin-bottom:5px; line-height:1.5; }

        .actions-cell { display:flex; flex-wrap:wrap; gap:4px; align-items:center; }
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
                <h2>Reassign — Pairings Management</h2>
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
                <div class="dt-value"><?php echo $locked_count; ?></div>
                <div class="dt-label">Admin-Locked (6M)</div>
            </div>
            <div class="dash-tile amber">
                <div class="dt-value"><?php echo $active_count - $locked_count; ?></div>
                <div class="dt-label">Unlocked / Free</div>
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
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
                        <p>No <?php echo $tab === 'active' ? 'active pairings' : 'past mentorships'; ?> found.</p>
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
                            <th>6 Sessions Info</th><!-- CHANGED: was "6-Month Lock" -->
                            <th>Status</th>
                            <?php if ($tab === 'active'): ?>
                                <th>Admin Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mentorships as $m):
                        $expires_ts     = !empty($m['pairing_expires_at']) ? strtotime($m['pairing_expires_at']) : null;
                        $is_locked      = $expires_ts && $expires_ts > time();
                        $is_expired     = $expires_ts && $expires_ts <= time();
                        $days_remaining = ($is_locked && $expires_ts) ? max(0, (int) ceil(($expires_ts - time()) / 86400)) : null;

                        // Sessions progress — CHANGED: was showing date/lock info
                        $sessions_done  = (int)($m['sessions_done'] ?? 0);
                        $sessions_total = 6;
                        $pct            = min(100, round(($sessions_done / $sessions_total) * 100));
                        // Color class by progress tier
                        if ($sessions_done === 0)      $bar_class = 'c0';
                        elseif ($sessions_done <= 2)   $bar_class = 'c1';
                        elseif ($sessions_done <= 4)   $bar_class = 'c2';
                        else                           $bar_class = 'c3';
                        $all_done = ($sessions_done >= $sessions_total);
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($m['mentor_name']); ?></strong></td>
                            <td><span class="dept-badge"><?php echo htmlspecialchars($m['mentor_dept'] ?? '—'); ?></span></td>
                            <td><?php echo htmlspecialchars($m['mentee_name']); ?></td>
                            <td><span class="dept-badge"><?php echo htmlspecialchars($m['mentee_dept'] ?? '—'); ?></span></td>
                            <td><?php echo $m['paired_at'] ? date('d M Y', strtotime($m['paired_at'])) : '<span style="color:#94a3b8">—</span>'; ?></td>

                            <!-- 6 Sessions Info — CHANGED from 6-Month Lock column -->
                            <td class="sessions-cell">
                                <div class="sessions-count">
                                    <span class="done"><?php echo $sessions_done; ?></span>
                                    <span class="total"> / <?php echo $sessions_total; ?> sessions</span>
                                </div>
                                <div class="sessions-bar">
                                    <div class="sessions-bar-fill <?php echo $bar_class; ?>"
                                         style="width:<?php echo $pct; ?>%"></div>
                                </div>
                                <div class="sessions-label <?php echo $all_done ? 'complete' : ''; ?>">
                                    <?php if ($all_done): ?>
                                        ✅ All 6 sessions completed
                                    <?php elseif ($sessions_done === 0): ?>
                                        No sessions yet
                                    <?php else: ?>
                                        <?php echo $sessions_total - $sessions_done; ?> session<?php echo ($sessions_total - $sessions_done) > 1 ? 's' : ''; ?> remaining
                                    <?php endif; ?>
                                </div>
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
                                    <?php if (!$is_locked): ?>
                                    <!-- Set 6-month lock -->
                                    <form method="POST" style="margin:0;"
                                          onsubmit="return confirm('Apply 6-month lock to this pairing?\nExpiry will be set to 6 months from now.');">
                                        <?php echo Security::csrfField(); ?>
                                        <input type="hidden" name="action"     value="set_lock">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$m['request_id']; ?>">
                                        <button type="submit" class="btn-action btn-set-lock">🔒 Set 6M Lock</button>
                                    </form>
                                    <?php else: ?>
                                    <!-- Remove lock -->
                                    <form method="POST" style="margin:0;"
                                          onsubmit="return confirm('Remove the 6-month lock from this pairing?\nThe pairing will become unlocked immediately.');">
                                        <?php echo Security::csrfField(); ?>
                                        <input type="hidden" name="action"     value="remove_lock">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$m['request_id']; ?>">
                                        <button type="submit" class="btn-action btn-remove-lock">🔓 Unlock</button>
                                    </form>
                                    <?php endif; ?>

                                    <!-- Remove pairing (available anytime) -->
                                    <form method="POST" style="margin:0;"
                                          onsubmit="return confirm('Remove this mentor-mentee pairing?\n\nThis will:\n• Mark pairing as Completed\n• Free both mentor and mentee for new pairings');">
                                        <?php echo Security::csrfField(); ?>
                                        <input type="hidden" name="action"     value="remove_mentee">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$m['request_id']; ?>">
                                        <button type="submit" class="btn-action btn-remove">🗑 Remove</button>
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
        </div>

        <!-- Rules Info Panel -->
        <div class="rules-panel">
            <h6>ℹ️ Admin Control — How It Works</h6>
            <ul>
                <li><strong>6 Sessions Info:</strong> Shows how many of the 6 mentoring sessions have been marked as completed for each pairing. Progress bar turns green when all 6 are done.</li>
                <li><strong>Set 6M Lock:</strong> Click <em>🔒 Set 6M Lock</em> to apply a 6-month administrative lock. This is informational — admin can still remove the pairing at any time.</li>
                <li><strong>Unlock:</strong> Click <em>🔓 Unlock</em> to remove the lock at any time.</li>
                <li><strong>Remove Pairing:</strong> Marks the pairing as <em>Completed</em> and frees both parties for new pairings. Available at any time regardless of sessions done.</li>
                <li><strong>Cross-Department Rule:</strong> Mentors and mentees from the same department cannot be paired — enforced at request and accept stages.</li>
            </ul>
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
</script>
</body>
</html>