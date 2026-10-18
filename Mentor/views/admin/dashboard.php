<?php
// views/admin/dashboard.php — FIXED: $conn → Database()->connect()
session_start();

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../../login.php");
    exit();
}

$db = (new Database())->connect();

$user_count     = $db->query("SELECT COUNT(*) as total FROM users")->fetch_assoc();
$request_count  = $db->query("SELECT COUNT(*) as total FROM mentor_requests")->fetch_assoc();
$session_count  = $db->query("SELECT COUNT(*) as total FROM sessions")->fetch_assoc();
$feedback_count = $db->query("SELECT COUNT(*) as total FROM feedback")->fetch_assoc();
$active_pairs   = $db->query("SELECT COUNT(*) as total FROM mentor_requests WHERE status='accepted'")->fetch_assoc();
$completed_month = $db->query("
    SELECT COUNT(*) as total FROM mentor_requests
    WHERE session_completed = 1
      AND YEAR(session_date)  = YEAR(CURDATE())
      AND MONTH(session_date) = MONTH(CURDATE())
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — Mentor Connect</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .dash-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:20px; margin-bottom:32px; }
        .dash-tile { background:#fff; border-radius:16px; padding:24px 26px; box-shadow:0 4px 18px rgba(0,0,0,0.06); border:1px solid #f0f4f8; }
        .dash-tile .dt-value { font-size:36px; font-weight:800; color:#1a2b4a; line-height:1; }
        .dash-tile .dt-label { font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.6px; margin-top:6px; }
        .dash-tile.blue .dt-value   { color:#2980b9; }
        .dash-tile.green .dt-value  { color:#059669; }
        .dash-tile.purple .dt-value { color:#7c3aed; }
        .dash-tile.amber .dt-value  { color:#d97706; }
        .quick-links { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:32px; }
        .quick-link { display:inline-flex; align-items:center; gap:8px; padding:12px 22px; border-radius:12px; font-size:14px; font-weight:600; text-decoration:none; transition:transform 0.2s,box-shadow 0.2s; }
        .quick-link:hover { transform:translateY(-2px); }
        .ql-blue   { background:linear-gradient(135deg,#2980b9,#6dd5fa); color:#fff; box-shadow:0 4px 14px rgba(41,128,185,0.3); }
        .ql-purple { background:linear-gradient(135deg,#6f42c1,#9b6dff); color:#fff; box-shadow:0 4px 14px rgba(111,66,193,0.3); }
        .ql-green  { background:linear-gradient(135deg,#059669,#10b981); color:#fff; box-shadow:0 4px 14px rgba(5,150,105,0.3); }
        .ql-report { background:linear-gradient(135deg,#28a745,#20c35e); color:#fff; box-shadow:0 4px 14px rgba(40,167,69,0.3); }
    </style>
</head>
<body>
<div class="layout">
<?php
    $user_name = $_SESSION['name'];
    $user_role = $_SESSION['role'];
    $current_page = 'dashboard';
    require_once __DIR__ . "/../../includes/sidenav.php";
?>
    <div class="main">
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="sidenav-toggle" onclick="toggleSidenav()" title="Toggle menu">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <h2>Admin Dashboard</h2>
            </div>
            <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['name']); ?></strong></span>
        </div>

        <div class="dash-grid">
            <div class="dash-tile blue"><div class="dt-value"><?php echo (int)$user_count['total']; ?></div><div class="dt-label">Total Users</div></div>
            <div class="dash-tile green"><div class="dt-value"><?php echo (int)$active_pairs['total']; ?></div><div class="dt-label">Active Pairings</div></div>
            <div class="dash-tile"><div class="dt-value"><?php echo (int)$request_count['total']; ?></div><div class="dt-label">Total Requests</div></div>
            <div class="dash-tile"><div class="dt-value"><?php echo (int)$session_count['total']; ?></div><div class="dt-label">Sessions</div></div>
            <div class="dash-tile purple"><div class="dt-value"><?php echo (int)$feedback_count['total']; ?></div><div class="dt-label">Feedback Records</div></div>
            <div class="dash-tile amber"><div class="dt-value"><?php echo (int)$completed_month['total']; ?></div><div class="dt-label">Completed This Month</div></div>
        </div>

        <div class="section-heading">Quick Actions</div>
        <div class="quick-links">
            <a href="../../pages/session_booking.php"    class="quick-link ql-blue">📅 Session Booking</a>
            <a href="../../pages/feedback.php"           class="quick-link ql-purple">💬 Feedback</a>
            <a href="../../pages/connection_status.php"  class="quick-link ql-green">🔗 Connection Status</a>
            <a href="../../report_download.php"          class="quick-link ql-report">📥 Download Report</a>
            <a href="../../dashboard.php"                class="quick-link" style="background:#f1f5f9;color:#1a2b4a;">🏠 Main Dashboard</a>
        </div>
    </div>
</div>
<script>
function toggleSidenav(){document.getElementById('sideNav').classList.toggle('open');document.getElementById('sideNavOverlay').classList.toggle('active');}
function closeSidenav(){document.getElementById('sideNav').classList.remove('open');document.getElementById('sideNavOverlay').classList.remove('active');}
</script>
</body>
</html>
