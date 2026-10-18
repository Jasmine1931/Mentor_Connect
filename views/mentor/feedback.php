<?php
/**
 * Mentor - Feedback Report
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/Feedback.php';

SessionHandler::requireRole('mentor');

$user_id = SessionHandler::getUserId();
$feedbackModel = new Feedback();

$report = $feedbackModel->getMentorFeedbackReport($user_id);

$page_title = 'Feedback';
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
                <a href="my-mentees.php">
                    <i class="fas fa-users"></i> My Mentees
                </a>
                <a href="requests.php">
                    <i class="fas fa-envelope"></i> Requests
                </a>
                <a href="availability.php">
                    <i class="fas fa-calendar"></i> Availability
                </a>
                <a href="sessions.php">
                    <i class="fas fa-tasks"></i> Sessions
                </a>
                <a href="feedback.php" class="active">
                    <i class="fas fa-comments"></i> Feedback
                </a>
            </div>
        </div>

        <div class="col-md-10 main-content">
            <div class="page-header">
                <h1><i class="fas fa-star"></i> Feedback Report</h1>
            </div>

            <!-- Stats -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <p class="stat-label">Average Rating</p>
                        <p class="stat-value"><?php echo $report['average_rating']; ?>/5</p>
                        <div class="rating">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <i class="fas fa-star<?php echo $i < floor($report['average_rating']) ? '' : '-o'; ?>"></i>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <p class="stat-label">Total Feedback</p>
                        <p class="stat-value"><?php echo $report['total_feedback']; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <p class="stat-label">Rating Distribution</p>
                        <small class="text-muted">
                            <?php foreach ($report['distribution'] as $dist): ?>
                                <span class="d-block"><?php echo $dist['rating']; ?>⭐: <?php echo $dist['count']; ?></span>
                            <?php endforeach; ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- Recent Feedback -->
            <div class="card">
                <div class="card-header">Recent Feedback</div>
                <div class="card-body">
                    <?php if (empty($report['recent_feedback'])): ?>
                        <div class="no-data">
                            <i class="fas fa-folder-open"></i>
                            <p>No feedback yet</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Mentee</th>
                                        <th>Session #</th>
                                        <th>Rating</th>
                                        <th>Comments</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report['recent_feedback'] as $feedback): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($feedback['first_name'] . ' ' . $feedback['last_name']); ?></td>
                                            <td><?php echo $feedback['session_number']; ?></td>
                                            <td>
                                                <div class="rating">
                                                    <?php for ($i = 0; $i < 5; $i++): ?>
                                                        <i class="fas fa-star<?php echo $i < $feedback['rating'] ? '' : '-o'; ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars(substr($feedback['comments'], 0, 50)); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($feedback['submitted_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
