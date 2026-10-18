<?php
/**
 * Admin - Reports
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Mentorship.php';
require_once __DIR__ . '/../../models/Session.php';
require_once __DIR__ . '/../../models/Feedback.php';

// Check if user is logged in and has admin role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /Mentor/index.php');
    exit;
}

$userModel = new User();
$mentorshipModel = new Mentorship();
$sessionModel = new Session();
$feedbackModel = new Feedback();

// Get all statistics
$user_counts = $userModel->getUserCountByRole();
$mentorship_stats = $mentorshipModel->getMentorshipStats();
$session_stats = $sessionModel->getSessionStats();
$feedback_stats = $feedbackModel->getFeedbackStats();

$user_count_by_role = [];
foreach ($user_counts as $count) {
    $user_count_by_role[$count['role']] = $count['count'];
}

$page_title = 'Reports';

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
                <a href="sessions.php">
                    <i class="fas fa-calendar"></i> Sessions
                </a>
                <a href="feedback.php">
                    <i class="fas fa-star"></i> Feedback
                </a>
                <a href="reports.php" class="active">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </div>
        </div>

        <div class="col-md-10 main-content">
            <div class="page-header">
                <h1><i class="fas fa-chart-bar"></i> System Reports</h1>
                <p class="text-muted">Comprehensive Statistics and Metrics</p>
            </div>

            <!-- User Statistics -->
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-users"></i> User Statistics</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <p class="stat-label">Total Users</p>
                                <p class="stat-value"><?php echo array_sum($user_count_by_role); ?></p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <p class="stat-label">Mentors</p>
                                <p class="stat-value" style="color: #667eea;"><?php echo $user_count_by_role['mentor'] ?? 0; ?></p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <p class="stat-label">Mentees</p>
                                <p class="stat-value" style="color: #667eea;"><?php echo $user_count_by_role['mentee'] ?? 0; ?></p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <p class="stat-label">Admins</p>
                                <p class="stat-value" style="color: #667eea;"><?php echo $user_count_by_role['admin'] ?? 0; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mentorship Statistics -->
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-handshake"></i> Mentorship Statistics</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <p class="stat-label">Total Mentorships</p>
                                <p class="stat-value"><?php echo $mentorship_stats['total']; ?></p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <p class="stat-label">Active</p>
                                <p class="stat-value" style="color: #48bb78;"><?php echo $mentorship_stats['active']; ?></p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <p class="stat-label">Completed</p>
                                <p class="stat-value" style="color: #4299e1;"><?php echo $mentorship_stats['completed']; ?></p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <p class="stat-label">Cancelled</p>
                                <p class="stat-value" style="color: #f56565;"><?php echo $mentorship_stats['cancelled']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Session Statistics -->
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-calendar"></i> Session Statistics</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="stat-card">
                                <p class="stat-label">Scheduled Sessions</p>
                                <p class="stat-value"><?php echo $session_stats['scheduled']; ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card">
                                <p class="stat-label">Completed Sessions</p>
                                <p class="stat-value" style="color: #48bb78;"><?php echo $session_stats['completed']; ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card">
                                <p class="stat-label">Completion Rate</p>
                                <p class="stat-value">
                                    <?php 
                                    $total = $session_stats['scheduled'] + $session_stats['completed'];
                                    $rate = $total > 0 ? round(($session_stats['completed'] / $total) * 100) : 0;
                                    echo $rate . '%';
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Feedback Statistics -->
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-star"></i> Feedback Statistics</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="stat-card">
                                <p class="stat-label">Total Feedback</p>
                                <p class="stat-value"><?php echo $feedback_stats['total_feedback']; ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card">
                                <p class="stat-label">Average Rating</p>
                                <p class="stat-value"><?php echo $feedback_stats['average_rating']; ?>/5</p>
                                <div class="rating">
                                    <?php for ($i = 0; $i < 5; $i++): ?>
                                        <i class="fas fa-star<?php echo $i < floor($feedback_stats['average_rating']) ? '' : '-o'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card">
                                <p class="stat-label">Distribution</p>
                                <small class="text-muted">
                                    <?php foreach ($feedback_stats['distribution'] as $dist): ?>
                                        <span class="d-block"><?php echo $dist['rating']; ?>⭐: <?php echo $dist['count']; ?></span>
                                    <?php endforeach; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Metrics -->
            <div class="card">
                <div class="card-header">Quick Metrics</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <tr>
                                <td><strong>Average Mentors per Mentee:</strong></td>
                                <td><?php echo $user_count_by_role['mentee'] > 0 ? round($mentorship_stats['total'] / $user_count_by_role['mentee'], 2) : 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Average Mentees per Mentor:</strong></td>
                                <td><?php echo $user_count_by_role['mentor'] > 0 ? round($mentorship_stats['active'] / $user_count_by_role['mentor'], 2) : 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Total Sessions Created:</strong></td>
                                <td><?php echo $session_stats['scheduled'] + $session_stats['completed'] + $session_stats['rescheduled']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Feedback Submission Rate:</strong></td>
                                <td><?php echo $session_stats['completed'] > 0 ? round(($feedback_stats['total_feedback'] / $session_stats['completed']) * 100) : 0; ?>%</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
