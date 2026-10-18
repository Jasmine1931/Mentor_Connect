<?php
/**
 * Feedback History
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/Feedback.php';

SessionHandler::requireRole('mentee');

$user_id = SessionHandler::getUserId();
$feedbackModel = new Feedback();

$feedback_list = $feedbackModel->getMenteeFeedback($user_id);

$page_title = 'Feedback History';
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
                <a href="find-mentors.php">
                    <i class="fas fa-search"></i> Find Mentors
                </a>
                <a href="my-mentorships.php">
                    <i class="fas fa-handshake"></i> My Mentorships
                </a>
                <a href="sessions.php">
                    <i class="fas fa-calendar"></i> Sessions
                </a>
                <a href="feedback-history.php" class="active">
                    <i class="fas fa-star"></i> Feedback
                </a>
            </div>
        </div>

        <div class="col-md-10 main-content">
            <div class="page-header">
                <h1><i class="fas fa-star"></i> Feedback History</h1>
            </div>

            <div class="card">
                <div class="card-header">Feedback You've Submitted</div>
                <div class="card-body">
                    <?php if (empty($feedback_list)): ?>
                        <div class="no-data">
                            <i class="fas fa-folder-open"></i>
                            <p>No feedback submitted yet</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Mentor</th>
                                        <th>Session #</th>
                                        <th>Rating</th>
                                        <th>Comments</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($feedback_list as $feedback): ?>
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
