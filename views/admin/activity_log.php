<?php
// ============================================================
// views/admin/activity_log.php
// NEW — VAPT #11: Change audit log viewer (Admin only)
// Shows all actions logged via Security::log()
// ============================================================

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/MySessionHandler.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/Security.php';

new MySessionHandler();

// FIXED #1: Admin-only page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    Security::safeRedirect('/Mentor/login.php');
}

$db = (new Database())->connect();
MySessionHandler::enforceOneSession($db); // FIXED #8

// Pagination
$per_page    = 50;
$page        = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;

// Optional filters
$filter_action = trim($_GET['action'] ?? '');
$filter_user   = trim($_GET['user']   ?? '');

// Build WHERE clause safely
$where  = [];
$params = [];
$types  = '';

if ($filter_action !== '') {
    $like = '%' . $filter_action . '%';
    $where[]  = 'action LIKE ?';
    $params[] = $like;
    $types   .= 's';
}
if ($filter_user !== '') {
    $like = '%' . $filter_user . '%';
    $where[]  = '(user_email LIKE ? OR CAST(user_id AS CHAR) LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count
$cStmt = $db->prepare("SELECT COUNT(*) AS total FROM activity_log {$whereSQL}");
if ($types) { $cStmt->bind_param($types, ...$params); }
$cStmt->execute();
$total_rows  = (int) $cStmt->get_result()->fetch_assoc()['total'];
$total_pages = (int) ceil($total_rows / $per_page);

// Fetch
$pTypes  = $types . 'ii';
$pParams = array_merge($params, [$per_page, $offset]);
$lStmt   = $db->prepare(
    "SELECT id, user_id, user_email, action, detail, ip_address, created_at
     FROM activity_log
     {$whereSQL}
     ORDER BY created_at DESC
     LIMIT ? OFFSET ?"
);
if ($pTypes) { $lStmt->bind_param($pTypes, ...$pParams); }
$lStmt->execute();
$logs = $lStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = 'Activity Log';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid">
<div class="row">

    <!-- Sidebar -->
    <div class="col-md-2 sidebar">
        <div class="sidebar-menu">
            <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a href="users.php"><i class="fas fa-users"></i> Users</a>
            <a href="mentorships.php"><i class="fas fa-handshake"></i> Mentorships</a>
            <a href="sessions.php"><i class="fas fa-calendar"></i> Sessions</a>
            <a href="feedback.php"><i class="fas fa-star"></i> Feedback</a>
            <a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
            <!-- NEW #11: Link to this page -->
            <a href="activity_log.php" class="active"><i class="fas fa-history"></i> Activity Log</a>
        </div>
    </div>

    <!-- Main -->
    <div class="col-md-10 main-content">
        <div class="page-header">
            <h1><i class="fas fa-history"></i> Activity Log</h1>
            <p class="text-muted">VAPT #11 — Full audit trail of all user actions</p>
        </div>

        <!-- Filter form -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Filter by Action</label>
                        <input type="text" name="action" class="form-control form-control-sm"
                               placeholder="e.g. LOGIN, BOOK_SESSION"
                               value="<?php echo Security::h($filter_action); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Filter by User / Email</label>
                        <input type="text" name="user" class="form-control form-control-sm"
                               placeholder="Email or user ID"
                               value="<?php echo Security::h($filter_user); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                    </div>
                    <div class="col-md-2">
                        <a href="activity_log.php" class="btn btn-secondary btn-sm w-100">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <?php echo number_format($total_rows); ?> entries
                    <?php if ($filter_action || $filter_user): ?>
                        <span class="badge bg-warning text-dark ms-2">Filtered</span>
                    <?php endif; ?>
                </span>
                <small class="text-muted">Page <?php echo $page; ?> / <?php echo max(1,$total_pages); ?></small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($logs)): ?>
                    <div class="no-data"><i class="fas fa-history"></i><p>No log entries found.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Detail</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            // Color-code by action type
                            $badge = 'secondary';
                            $action = $log['action'];
                            if (str_contains($action, 'LOGIN'))    $badge = 'success';
                            if (str_contains($action, 'FAIL'))     $badge = 'danger';
                            if (str_contains($action, 'LOGOUT'))   $badge = 'warning';
                            if (str_contains($action, 'REGISTER')) $badge = 'info';
                            if (str_contains($action, 'REQUEST'))  $badge = 'primary';
                            ?>
                            <tr>
                                <td class="text-muted small"><?php echo (int)$log['id']; ?></td>
                                <td class="text-muted small text-nowrap">
                                    <?php echo Security::h($log['created_at']); ?>
                                </td>
                                <td>
                                    <span class="small"><?php echo Security::h($log['user_email'] ?: '—'); ?></span>
                                    <?php if ($log['user_id']): ?>
                                        <br><span class="text-muted" style="font-size:11px;">ID: <?php echo (int)$log['user_id']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $badge; ?> text-wrap">
                                        <?php echo Security::h($action); ?>
                                    </span>
                                </td>
                                <td class="small"><?php echo Security::h($log['detail']); ?></td>
                                <td class="small text-muted"><?php echo Security::h($log['ip_address']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav class="px-3 py-2">
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link"
                                   href="?page=<?php echo $p;
                                       echo $filter_action ? '&action=' . urlencode($filter_action) : '';
                                       echo $filter_user   ? '&user='   . urlencode($filter_user)   : '';
                                   ?>">
                                   <?php echo $p; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>

    </div><!-- col main -->
</div><!-- row -->
</div><!-- container -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
