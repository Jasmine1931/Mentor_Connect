<?php
// ============================================================
// pages/connection_status.php
// Admin-only page: shows all mentor-mentee connections,
// their status, session booking state, and monthly lock status.
// ============================================================

session_start();

require_once "../config/database.php";
require_once "../config/config.php";
require_once "../includes/MySessionHandler.php";
require_once "../includes/Security.php";

date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'];

// Admin only
if ($user_role !== 'admin') {
    header('Location: ../dashboard.php');
    exit();
}

$db = (new Database())->connect();
new MySessionHandler();

try {
    MySessionHandler::enforceOneSession($db);
} catch (Exception $e) {
    error_log('enforceOneSession skipped: ' . $e->getMessage());
}

/* ============================================================
   FETCH ALL CONNECTIONS
============================================================ */
$connections = $db->query("
    SELECT mr.*,
           m.name  AS mentor_name,
           m.email AS mentor_email,
           m.designation AS mentor_designation,
           t.name  AS mentee_name,
           t.email AS mentee_email,
           t.designation AS mentee_designation
    FROM mentor_requests mr
    JOIN users m ON mr.mentor_id = m.id
    JOIN users t ON mr.mentee_id = t.id
    ORDER BY mr.status ASC, mr.created_at DESC
");

$allConnections = [];
$stats = [
    'total'     => 0,
    'pending'   => 0,
    'accepted'  => 0,
    'rejected'  => 0,
    'completed_sessions' => 0,
    'pending_sessions'   => 0,
    'locked'    => 0,
];

$currentMonth = date('Y-m');

while ($row = $connections->fetch_assoc()) {
    $stats['total']++;
    $status = $row['status'];
    if (isset($stats[$status])) $stats[$status]++;

    $hasSession  = !empty($row['session_date']);
    $isCompleted = !empty($row['session_completed']) && $row['session_completed'] == 1;
    $sessionMonth = $hasSession ? substr($row['session_date'], 0, 7) : '';
    $monthlyLocked = ($isCompleted && $sessionMonth === $currentMonth);

    if ($hasSession && !$isCompleted) $stats['pending_sessions']++;
    if ($isCompleted) $stats['completed_sessions']++;
    if ($monthlyLocked) $stats['locked']++;

    $row['_has_session']     = $hasSession;
    $row['_is_completed']    = $isCompleted;
    $row['_monthly_locked']  = $monthlyLocked;
    $allConnections[] = $row;
}

$current_page = 'connection_status';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connection Status — Mentor Connect</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-hero { background:linear-gradient(135deg,#0f2027,#203a43,#2c5364); border-radius:18px; padding:28px 32px; margin-bottom:30px; color:white; display:flex; align-items:center; gap:18px; }
        .page-hero-icon { width:54px; height:54px; background:rgba(109,213,250,0.2); border-radius:14px; display:flex; align-items:center; justify-content:center; }
        .page-hero h1 { font-size:22px; font-weight:700; margin:0 0 4px; }
        .page-hero p  { font-size:13px; opacity:0.75; margin:0; }

        /* Stat strip */
        .stat-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:16px; margin-bottom:30px; }
        .stat-tile { background:#fff; border-radius:14px; padding:20px 22px; box-shadow:0 4px 18px rgba(0,0,0,0.06); border:1px solid #f0f4f8; text-align:center; }
        .stat-tile .st-value { font-size:32px; font-weight:800; color:#1a2b4a; line-height:1; }
        .stat-tile .st-label { font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.6px; margin-top:6px; }
        .stat-tile.blue .st-value   { color:#2980b9; }
        .stat-tile.green .st-value  { color:#059669; }
        .stat-tile.amber .st-value  { color:#d97706; }
        .stat-tile.red .st-value    { color:#dc2626; }
        .stat-tile.purple .st-value { color:#7c3aed; }

        /* Filter bar */
        .filter-bar { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
        .filter-btn { padding:8px 18px; border-radius:20px; border:1.5px solid #e2e8f0; background:#fff; color:#64748b; font-size:13px; font-weight:500; cursor:pointer; font-family:"Poppins",sans-serif; transition:all 0.2s; }
        .filter-btn.active { background:linear-gradient(135deg,#2980b9,#6dd5fa); color:#fff; border-color:transparent; }

        /* Connection table */
        .conn-table { width:100%; border-collapse:collapse; background:#fff; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.06); }
        .conn-table th { background:#f8fafc; color:#1a2b4a; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px; padding:14px 18px; text-align:left; border-bottom:2px solid #e8ecf4; }
        .conn-table td { padding:14px 18px; font-size:13.5px; color:#475569; border-bottom:1px solid #f0f4f8; vertical-align:middle; }
        .conn-table tr:last-child td { border-bottom:none; }
        .conn-table tr:hover td { background:#fafbff; }

        .user-cell { display:flex; flex-direction:column; gap:2px; }
        .user-cell .uc-name { font-weight:600; color:#1a2b4a; font-size:14px; }
        .user-cell .uc-email { font-size:12px; color:#94a3b8; }
        .user-cell .uc-desig { font-size:12px; color:#64748b; }

        /* Status pills */
        .pill { display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; }
        .pill-pending  { background:#fef9c3; color:#92680a; border:1px solid #fde68a; }
        .pill-accepted { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .pill-rejected { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .pill-locked   { background:#fef3c7; color:#92400e; border:1px solid #fbbf24; }
        .pill-completed { background:#dbeafe; color:#1e40af; border:1px solid #bfdbfe; }
        .pill-scheduled { background:#f0fdf4; color:#166534; border:1px solid #86efac; }
        .pill-empty    { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; }

        .no-data { text-align:center; padding:50px; color:#94a3b8; }

        /* Search */
        .search-bar { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:10px 16px; font-size:14px; font-family:"Poppins",sans-serif; width:280px; outline:none; transition:border-color 0.2s; }
        .search-bar:focus { border-color:#2980b9; }
    </style>
</head>
<body>

<div class="layout">

    <!-- SIDE NAVIGATION -->
    <?php require_once "../includes/sidenav.php"; ?>

    <!-- MAIN CONTENT -->
    <div class="main">

        <!-- TOPBAR -->
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="sidenav-toggle" onclick="toggleSidenav()" title="Toggle menu">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <h2>Connection Status</h2>
            </div>
            <span>Welcome, <strong><?php echo htmlspecialchars($user_name); ?></strong></span>
        </div>

        <!-- Page Hero -->
        <div class="page-hero">
            <div class="page-hero-icon">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#6dd5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </div>
            <div>
                <h1>Connection Status</h1>
                <p>Monitor all mentor-mentee connections, session states, and monthly lock status across the platform.</p>
            </div>
        </div>

        <!-- Stats -->
        <div class="stat-strip">
            <div class="stat-tile">
                <div class="st-value"><?php echo $stats['total']; ?></div>
                <div class="st-label">Total Requests</div>
            </div>
            <div class="stat-tile green">
                <div class="st-value"><?php echo $stats['accepted']; ?></div>
                <div class="st-label">Active Pairs</div>
            </div>
            <div class="stat-tile blue">
                <div class="st-value"><?php echo $stats['pending']; ?></div>
                <div class="st-label">Pending</div>
            </div>
            <div class="stat-tile red">
                <div class="st-value"><?php echo $stats['rejected']; ?></div>
                <div class="st-label">Rejected</div>
            </div>
            <div class="stat-tile green">
                <div class="st-value"><?php echo $stats['completed_sessions']; ?></div>
                <div class="st-label">Completed Sessions</div>
            </div>
            <div class="stat-tile amber">
                <div class="st-value"><?php echo $stats['pending_sessions']; ?></div>
                <div class="st-label">Scheduled Sessions</div>
            </div>
            <div class="stat-tile purple">
                <div class="st-value"><?php echo $stats['locked']; ?></div>
                <div class="st-label">Monthly Locked</div>
            </div>
        </div>

        <!-- Filter + Search -->
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
            <div class="filter-bar" id="filterBar">
                <button class="filter-btn active" onclick="filterRows('all', this)">All</button>
                <button class="filter-btn" onclick="filterRows('accepted', this)">Active Pairs</button>
                <button class="filter-btn" onclick="filterRows('pending', this)">Pending</button>
                <button class="filter-btn" onclick="filterRows('rejected', this)">Rejected</button>
                <button class="filter-btn" onclick="filterRows('locked', this)">🔒 Locked</button>
            </div>
            <input type="text" class="search-bar" id="searchInput"
                   placeholder="🔍 Search by name..."
                   oninput="searchRows(this.value)">
        </div>

        <!-- Connections Table -->
        <?php if (empty($allConnections)): ?>
        <div class="no-data">
            <div style="font-size:48px;margin-bottom:16px;">🔗</div>
            <p>No connections found in the system yet.</p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="conn-table" id="connTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Mentor</th>
                    <th>Mentee</th>
                    <th>Connection Status</th>
                    <th>Session Date</th>
                    <th>Session Status</th>
                    <th>Monthly Lock</th>
                    <th>Connected On</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allConnections as $i => $conn):
                $hasSession   = $conn['_has_session'];
                $isCompleted  = $conn['_is_completed'];
                $monthlyLocked = $conn['_monthly_locked'];
                $sMonth = $hasSession ? substr($conn['session_date'], 0, 7) : '';

                // Row data-filter tag
                $filterTag = $conn['status'];
                if ($monthlyLocked) $filterTag = 'locked';
            ?>
            <tr data-filter="<?php echo htmlspecialchars($filterTag); ?>"
                data-search="<?php echo strtolower(htmlspecialchars($conn['mentor_name'] . ' ' . $conn['mentee_name'])); ?>">
                <td style="color:#94a3b8;font-size:13px;"><?php echo $i + 1; ?></td>
                <td>
                    <div class="user-cell">
                        <span class="uc-name"><?php echo htmlspecialchars($conn['mentor_name']); ?></span>
                        <span class="uc-email"><?php echo htmlspecialchars($conn['mentor_email']); ?></span>
                        <?php if (!empty($conn['mentor_designation'])): ?>
                        <span class="uc-desig"><?php echo htmlspecialchars($conn['mentor_designation']); ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <div class="user-cell">
                        <span class="uc-name"><?php echo htmlspecialchars($conn['mentee_name']); ?></span>
                        <span class="uc-email"><?php echo htmlspecialchars($conn['mentee_email']); ?></span>
                        <?php if (!empty($conn['mentee_designation'])): ?>
                        <span class="uc-desig"><?php echo htmlspecialchars($conn['mentee_designation']); ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php if ($conn['status'] === 'accepted'): ?>
                        <span class="pill pill-accepted">✓ Active</span>
                    <?php elseif ($conn['status'] === 'pending'): ?>
                        <span class="pill pill-pending">⏳ Pending</span>
                    <?php elseif ($conn['status'] === 'rejected'): ?>
                        <span class="pill pill-rejected">✗ Rejected</span>
                    <?php else: ?>
                        <span class="pill pill-empty"><?php echo htmlspecialchars(ucfirst($conn['status'])); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($hasSession): ?>
                        <strong><?php echo htmlspecialchars($conn['session_date']); ?></strong>
                        <div style="font-size:12px;color:#64748b;"><?php echo htmlspecialchars($conn['start_time'] ?? ''); ?></div>
                    <?php else: ?>
                        <span style="color:#94a3b8;font-style:italic;font-size:13px;">Not booked</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isCompleted): ?>
                        <span class="pill pill-completed">✅ Completed</span>
                    <?php elseif ($hasSession): ?>
                        <span class="pill pill-scheduled">📅 Scheduled</span>
                    <?php else: ?>
                        <span class="pill pill-empty">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($monthlyLocked): ?>
                        <span class="pill pill-locked">🔒 Locked<br>
                        <small style="font-weight:400;"><?php echo date('M Y'); ?></small></span>
                    <?php elseif ($conn['status'] === 'accepted'): ?>
                        <span class="pill pill-accepted" style="background:#f0fdf4;">🔓 Open</span>
                    <?php else: ?>
                        <span style="color:#94a3b8;font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12.5px;color:#64748b;">
                    <?php echo date('M d, Y', strtotime($conn['created_at'])); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    </div><!-- .main -->
</div><!-- .layout -->

<script>
function filterRows(filter, btn) {
    document.querySelectorAll('#filterBar .filter-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('#connTable tbody tr').forEach(function(row) {
        if (filter === 'all' || row.dataset.filter === filter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
function searchRows(query) {
    var q = query.toLowerCase().trim();
    document.querySelectorAll('#connTable tbody tr').forEach(function(row) {
        row.style.display = row.dataset.search.includes(q) ? '' : 'none';
    });
    // reset filter buttons
    document.querySelectorAll('#filterBar .filter-btn').forEach(function(b) { b.classList.remove('active'); });
    document.querySelector('#filterBar .filter-btn').classList.add('active');
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
