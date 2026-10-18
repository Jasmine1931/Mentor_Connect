<?php
/**
 * Sessions - View and manage sessions
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/Session.php';
require_once __DIR__ . '/../../models/Feedback.php';

SessionHandler::requireRole('mentee');

$user_id = SessionHandler::getUserId();
$sessionModel = new Session();
$feedbackModel = new Feedback();

$upcoming_sessions = $sessionModel->getUpcomingSessionsForUser($user_id, 'mentee');
$completed_sessions = $sessionModel->getCompletedSessionsForUser($user_id, 'mentee');
$pending_feedback = $feedbackModel->getSessionsPendingFeedback($user_id);

// Handle feedback submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $session_id = intval($_POST['session_id']);
    $mentorship_id = intval($_POST['mentorship_id']);
    $mentor_id = intval($_POST['mentor_id']);
    $rating = intval($_POST['rating']);
    $comments = trim($_POST['comments'] ?? '');

    $result = $feedbackModel->submitFeedback($session_id, $mentorship_id, $user_id, $mentor_id, $rating, $comments);
    if ($result['success']) {
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($result['message']) . '</div>';
        $pending_feedback = $feedbackModel->getSessionsPendingFeedback($user_id);
    } else {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($result['message']) . '</div>';
    }
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
                <a href="find-mentors.php">
                    <i class="fas fa-search"></i> Find Mentors
                </a>
                <a href="my-mentorships.php">
                    <i class="fas fa-handshake"></i> My Mentorships
                </a>
                <a href="sessions.php" class="active">
                    <i class="fas fa-calendar"></i> Sessions
                </a>
                <a href="feedback-history.php">
                    <i class="fas fa-star"></i> Feedback
                </a>
            </div>
        </div>

        <div class="col-md-10 main-content">
            <div class="page-header">
                <h1><i class="fas fa-calendar"></i> Sessions</h1>
            </div>

            <?php echo $message; ?>

            <!-- Pending Feedback -->
            <?php if (!empty($pending_feedback)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <i class="fas fa-star"></i> Feedback Needed
                    </div>
                    <div class="card-body">
                        <p>Please provide feedback for the following completed sessions:</p>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Mentor</th>
                                        <th>Session #</th>
                                        <th>Completed</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_feedback as $session): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?></td>
                                            <td><?php echo $session['session_number']; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($session['scheduled_date'])); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#feedbackModal<?php echo $session['session_id']; ?>">
                                                    <i class="fas fa-comment"></i> Submit Feedback
                                                </button>

                                                <!-- Feedback Modal -->
                                                <div class="modal fade" id="feedbackModal<?php echo $session['session_id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Submit Feedback</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form method="POST">
                                                                <div class="modal-body">
                                                                    <p>Session with <strong><?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?></strong></p>

                                                                    <div class="mb-3">
                                                                        <label for="rating<?php echo $session['session_id']; ?>" class="form-label">Rating</label>
                                                                        <select class="form-select" id="rating<?php echo $session['session_id']; ?>" name="rating" required>
                                                                            <option value="">Select rating</option>
                                                                            <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
                                                                            <option value="4">⭐⭐⭐⭐ Very Good</option>
                                                                            <option value="3">⭐⭐⭐ Good</option>
                                                                            <option value="2">⭐⭐ Fair</option>
                                                                            <option value="1">⭐ Poor</option>
                                                                        </select>
                                                                    </div>

                                                                    <div class="mb-3">
                                                                        <label for="comments<?php echo $session['session_id']; ?>" class="form-label">Comments</label>
                                                                        <textarea class="form-control" id="comments<?php echo $session['session_id']; ?>" name="comments" rows="4" placeholder="Share your feedback..."></textarea>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                                                    <input type="hidden" name="mentorship_id" value="<?php echo $session['mentorship_id']; ?>">
                                                                    <input type="hidden" name="mentor_id" value="<?php echo $session['mentor_id']; ?>">
                                                                    <input type="hidden" name="submit_feedback" value="1">
                                                                    <button type="submit" class="btn btn-primary">Submit</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Upcoming Sessions -->
            <div class="card mb-4">
                <div class="card-header">Upcoming Sessions</div>
                <div class="card-body">
                    <?php if (empty($upcoming_sessions)): ?>
                        <div class="no-data">
                            <i class="fas fa-calendar-times"></i>
                            <p>No upcoming sessions</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Mentor</th>
                                        <th>Session #</th>
                                        <th>Scheduled Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcoming_sessions as $session): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?></td>
                                            <td><?php echo $session['session_number']; ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($session['scheduled_date'])); ?></td>
                                            <td><span class="badge badge-active">Scheduled</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Completed Sessions -->
            <div class="card">
                <div class="card-header">Completed Sessions</div>
                <div class="card-body">
                    <?php if (empty($completed_sessions)): ?>
                        <div class="no-data">
                            <i class="fas fa-folder-open"></i>
                            <p>No completed sessions yet</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Mentor</th>
                                        <th>Session #</th>
                                        <th>Completed</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completed_sessions as $session): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?></td>
                                            <td><?php echo $session['session_number']; ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($session['scheduled_date'])); ?></td>
                                            <td><span class="badge badge-completed">Completed</span></td>
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
