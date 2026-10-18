<?php
/**
 * Mentor - Sessions Management
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/Session.php';

SessionHandler::requireRole('mentor');

$user_id = SessionHandler::getUserId();
$sessionModel = new Session();

$message = '';

// Handle session completion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $session_id = intval($_POST['session_id']);

    if ($action === 'complete') {
        $notes = trim($_POST['notes'] ?? '');
        $result = $sessionModel->completeSession($session_id, $notes);
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($result['message']) . '</div>';
    }
}

$upcoming = $sessionModel->getUpcomingSessionsForUser($user_id, 'mentor');
$completed = $sessionModel->getCompletedSessionsForUser($user_id, 'mentor');

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
                <a href="my-mentees.php">
                    <i class="fas fa-users"></i> My Mentees
                </a>
                <a href="requests.php">
                    <i class="fas fa-envelope"></i> Requests
                </a>
                <a href="availability.php">
                    <i class="fas fa-calendar"></i> Availability
                </a>
                <a href="sessions.php" class="active">
                    <i class="fas fa-tasks"></i> Sessions
                </a>
                <a href="feedback.php">
                    <i class="fas fa-comments"></i> Feedback
                </a>
            </div>
        </div>

        <div class="col-md-10 main-content">
            <div class="page-header">
                <h1><i class="fas fa-tasks"></i> Sessions Management</h1>
            </div>

            <?php echo $message; ?>

            <!-- Upcoming Sessions -->
            <div class="card mb-4">
                <div class="card-header">Upcoming Sessions</div>
                <div class="card-body">
                    <?php if (empty($upcoming)): ?>
                        <div class="no-data">
                            <i class="fas fa-calendar-times"></i>
                            <p>No upcoming sessions</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Mentee</th>
                                        <th>Session #</th>
                                        <th>Scheduled Date</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcoming as $session): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?></td>
                                            <td><?php echo $session['session_number']; ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($session['scheduled_date'])); ?></td>
                                            <td><span class="badge badge-active">Scheduled</span></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#completeModal<?php echo $session['session_id']; ?>">
                                                    <i class="fas fa-check"></i> Mark Complete
                                                </button>

                                                <!-- Modal -->
                                                <div class="modal fade" id="completeModal<?php echo $session['session_id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Complete Session</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form method="POST">
                                                                <div class="modal-body">
                                                                    <p>Marking session #<?php echo $session['session_number']; ?> with <?php echo htmlspecialchars($session['first_name']); ?> as complete.</p>
                                                                    <div class="mb-3">
                                                                        <label for="notes<?php echo $session['session_id']; ?>" class="form-label">Notes</label>
                                                                        <textarea class="form-control" id="notes<?php echo $session['session_id']; ?>" name="notes" rows="3" placeholder="Session notes..."></textarea>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                                                    <input type="hidden" name="action" value="complete">
                                                                    <button type="submit" class="btn btn-success">Complete Session</button>
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
                    <?php endif; ?>
                </div>
            </div>

            <!-- Completed Sessions -->
            <div class="card">
                <div class="card-header">Completed Sessions</div>
                <div class="card-body">
                    <?php if (empty($completed)): ?>
                        <div class="no-data">
                            <i class="fas fa-folder-open"></i>
                            <p>No completed sessions yet</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Mentee</th>
                                        <th>Session #</th>
                                        <th>Completed Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completed as $session): ?>
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
