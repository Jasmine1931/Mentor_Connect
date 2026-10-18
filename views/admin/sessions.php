<?php
/**
 * Admin - Sessions Management
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/Session.php';

SessionHandler::requireRole('admin');

$sessionModel = new Session();

$tab = $_GET['tab'] ?? 'upcoming';

if ($tab === 'completed') {
    $sessions = $sessionModel->getSessionStats();
    // For completed, we need a different query
} else {
    $sessions_data = $sessionModel->getUpcomingSessionsForUser(null, null);
}

$page_title = 'Sessions';
?>

<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 sidebar">
            <div class="sidebar-menu">
                <a href="dashboard.php">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="users.php">
                    <i class="fas fa-users"></i> Users
                </a>
                <a href="mentorships.php">
                    <i class="fas fa-handshake"></i> Mentorships
                </a>
                <a href="sessions.php" class="active">
                    <i class="fas fa-calendar"></i> Sessions
                </a>
                <a href="feedback.php">
                    <i class="fas fa-star"></i> Feedback
                </a>
                <a href="reports.php">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </div>
        </div>

        <div class="col-md-10 main-content">
            <div class="page-header">
                <h1><i class="fas fa-calendar"></i> Sessions</h1>
            </div>

            <div class="card">
                <div class="card-header">All Sessions</div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> This page shows session management. Use the mentorships page for detailed session tracking.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
