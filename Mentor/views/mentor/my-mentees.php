<?php
/**
 * Mentor - My Mentees
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/Mentorship.php';
require_once __DIR__ . '/../../models/Session.php';
require_once __DIR__ . '/../../models/Feedback.php';

SessionHandler::requireRole('mentor');

$user_id = SessionHandler::getUserId();
$mentorshipModel = new Mentorship();
$sessionModel = new Session();
$feedbackModel = new Feedback();

$mentee_id = intval($_GET['id'] ?? 0);

if ($mentee_id > 0) {
    // View specific mentee
    $mentorships = $mentorshipModel->getMentorMentorships($user_id, null);
    $mentorship = null;
    
    foreach ($mentorships as $m) {
        if ($m['mentee_id'] == $mentee_id) {
            $mentorship = $m;
            break;
        }
    }

    if (!$mentorship) {
        header('Location: my-mentees.php');
        exit();
    }

    $sessions = $sessionModel->getMentorshipSessions($mentorship['mentorship_id']);
    $feedback = $feedbackModel->getMentorshipFeedback($mentorship['mentorship_id']);

    $page_title = 'Mentee Details';
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
                    <a href="my-mentees.php" class="active">
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
                    <a href="feedback.php">
                        <i class="fas fa-comments"></i> Feedback
                    </a>
                </div>
            </div>

            <div class="col-md-10 main-content">
                <div class="page-header">
                    <a href="my-mentees.php" class="btn btn-outline-secondary btn-sm mb-3">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <h1><?php echo htmlspecialchars($mentorship['first_name'] . ' ' . $mentorship['last_name']); ?></h1>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">Mentorship Info</div>
                            <div class="card-body">
                                <p><strong>Status:</strong> <span class="badge badge-active"><?php echo ucfirst($mentorship['status']); ?></span></p>
                                <p><strong>Period:</strong> <?php echo date('M d, Y', strtotime($mentorship['start_date'])); ?> - <?php echo date('M d, Y', strtotime($mentorship['end_date'])); ?></p>
                                <p><strong>Sessions Completed:</strong> <?php echo $mentorship['sessions_completed']; ?>/6</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">Progress</div>
                            <div class="card-body">
                                <div class="progress" style="height: 30px;">
                                    <div class="progress-bar" style="width: <?php echo ($mentorship['sessions_completed'] / 6) * 100; ?>%">
                                        <?php echo round(($mentorship['sessions_completed'] / 6) * 100); ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sessions -->
                <div class="card mb-4">
                    <div class="card-header">Sessions</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Scheduled</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sessions as $session): ?>
                                        <tr>
                                            <td><?php echo $session['session_number']; ?></td>
                                            <td><?php echo $session['scheduled_date'] ? date('M d, Y H:i', strtotime($session['scheduled_date'])) : 'Not scheduled'; ?></td>
                                            <td>
                                                <?php if ($session['status'] === 'completed'): ?>
                                                    <span class="badge badge-completed">Completed</span>
                                                <?php else: ?>
                                                    <span class="badge badge-active"><?php echo ucfirst($session['status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($session['status'] === 'scheduled'): ?>
                                                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#completeModal<?php echo $session['session_id']; ?>">
                                                        <i class="fas fa-check"></i> Complete
                                                    </button>

                                                    <!-- Complete Session Modal -->
                                                    <div class="modal fade" id="completeModal<?php echo $session['session_id']; ?>" tabindex="-1">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title">Mark Session as Completed</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <form method="POST" action="sessions.php">
                                                                    <div class="modal-body">
                                                                        <p>Session #<?php echo $session['session_number']; ?> with <?php echo htmlspecialchars($mentorship['first_name']); ?></p>
                                                                        <div class="mb-3">
                                                                            <label for="notes<?php echo $session['session_id']; ?>" class="form-label">Notes (optional)</label>
                                                                            <textarea class="form-control" id="notes<?php echo $session['session_id']; ?>" name="notes" rows="3" placeholder="Session notes..."></textarea>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                        <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                                                        <input type="hidden" name="action" value="complete">
                                                                        <button type="submit" class="btn btn-success">Complete</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Feedback -->
                <div class="card">
                    <div class="card-header">Feedback Received</div>
                    <div class="card-body">
                        <?php if (empty($feedback)): ?>
                            <div class="no-data">
                                <i class="fas fa-folder-open"></i>
                                <p>No feedback yet</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Session #</th>
                                            <th>Rating</th>
                                            <th>Comments</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($feedback as $fb): ?>
                                            <tr>
                                                <td><?php echo $fb['session_number']; ?></td>
                                                <td>
                                                    <div class="rating">
                                                        <?php for ($i = 0; $i < 5; $i++): ?>
                                                            <i class="fas fa-star<?php echo $i < $fb['rating'] ? '' : '-o'; ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($fb['comments']); ?></td>
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

    <?php
} else {
    // List all mentees
    $mentorships = $mentorshipModel->getMentorMentorships($user_id, 'active');
    $page_title = 'My Mentees';
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
                    <a href="my-mentees.php" class="active">
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
                    <a href="feedback.php">
                        <i class="fas fa-comments"></i> Feedback
                    </a>
                </div>
            </div>

            <div class="col-md-10 main-content">
                <div class="page-header">
                    <h1><i class="fas fa-users"></i> My Mentees</h1>
                </div>

                <div class="card">
                    <div class="card-header">Active Mentees</div>
                    <div class="card-body">
                        <?php if (empty($mentorships)): ?>
                            <div class="no-data">
                                <i class="fas fa-folder-open"></i>
                                <p>No active mentees</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Mentee</th>
                                            <th>Domain</th>
                                            <th>Progress</th>
                                            <th>Period</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mentorships as $m): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($m['domain']); ?></td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar" style="width: <?php echo ($m['sessions_completed'] / 6) * 100; ?>%">
                                                            <?php echo $m['sessions_completed']; ?>/6
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M d', strtotime($m['start_date'])); ?> - <?php echo date('M d, Y', strtotime($m['end_date'])); ?></td>
                                                <td>
                                                    <a href="?id=<?php echo $m['mentee_id']; ?>" class="btn btn-sm btn-info">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </td>
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
    <?php
}
?>
