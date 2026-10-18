<?php
/**
 * My Mentorships - View active and completed mentorships
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/Mentorship.php';
require_once __DIR__ . '/../../models/Session.php';

SessionHandler::requireRole('mentee');

$user_id = SessionHandler::getUserId();
$mentorshipModel = new Mentorship();
$sessionModel = new Session();

$mentorship_id = intval($_GET['id'] ?? 0);

if ($mentorship_id) {
    $mentorship = $mentorshipModel->getMentorshipById($mentorship_id);
    if (!$mentorship || $mentorship['mentee_id'] != $user_id) {
        header('Location: dashboard.php');
        exit();
    }

    $sessions = $sessionModel->getMentorshipSessions($mentorship_id);
    $page_title = 'Mentorship Details';
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
                    <a href="my-mentorships.php" class="active">
                        <i class="fas fa-handshake"></i> My Mentorships
                    </a>
                    <a href="sessions.php">
                        <i class="fas fa-calendar"></i> Sessions
                    </a>
                    <a href="feedback-history.php">
                        <i class="fas fa-star"></i> Feedback
                    </a>
                </div>
            </div>

            <div class="col-md-10 main-content">
                <div class="page-header">
                    <a href="my-mentorships.php" class="btn btn-outline-secondary btn-sm mb-3">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <h1><?php echo htmlspecialchars($mentorship['mentor_first_name'] . ' ' . $mentorship['mentor_last_name']); ?></h1>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">Mentorship Details</div>
                            <div class="card-body">
                                <p><strong>Status:</strong> <span class="badge badge-active"><?php echo ucfirst($mentorship['status']); ?></span></p>
                                <p><strong>Start Date:</strong> <?php echo date('M d, Y', strtotime($mentorship['start_date'])); ?></p>
                                <p><strong>End Date:</strong> <?php echo date('M d, Y', strtotime($mentorship['end_date'])); ?></p>
                                <p><strong>Duration:</strong> <?php echo MENTORSHIP_DURATION_MONTHS; ?> months</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">Progress</div>
                            <div class="card-body">
                                <p><strong>Sessions Completed:</strong> <?php echo $mentorship['sessions_completed']; ?>/6</p>
                                <div class="progress mb-3" style="height: 25px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo ($mentorship['sessions_completed'] / 6) * 100; ?>%">
                                        <?php echo round(($mentorship['sessions_completed'] / 6) * 100); ?>%
                                    </div>
                                </div>
                                <?php if ($mentorship['sessions_completed'] >= 6): ?>
                                    <p class="text-success"><i class="fas fa-check-circle"></i> <strong>Completed!</strong></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sessions Table -->
                <div class="card">
                    <div class="card-header">Sessions</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Session #</th>
                                        <th>Scheduled Date</th>
                                        <th>Status</th>
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
                                                <?php elseif ($session['status'] === 'scheduled'): ?>
                                                    <span class="badge badge-active">Scheduled</span>
                                                <?php else: ?>
                                                    <span class="badge"><?php echo ucfirst($session['status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>

    <?php
} else {
    // List all mentorships
    $active_mentorships = $mentorshipModel->getMenteeMentorships($user_id, 'active');
    $completed_mentorships = $mentorshipModel->getMenteeMentorships($user_id, 'completed');

    $page_title = 'My Mentorships';
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
                    <a href="my-mentorships.php" class="active">
                        <i class="fas fa-handshake"></i> My Mentorships
                    </a>
                    <a href="sessions.php">
                        <i class="fas fa-calendar"></i> Sessions
                    </a>
                    <a href="feedback-history.php">
                        <i class="fas fa-star"></i> Feedback
                    </a>
                </div>
            </div>

            <div class="col-md-10 main-content">
                <div class="page-header">
                    <h1><i class="fas fa-handshake"></i> My Mentorships</h1>
                </div>

                <!-- Active Mentorships -->
                <div class="card mb-4">
                    <div class="card-header">Active Mentorships</div>
                    <div class="card-body">
                        <?php if (empty($active_mentorships)): ?>
                            <div class="no-data">
                                <i class="fas fa-folder-open"></i>
                                <p>No active mentorships</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Mentor</th>
                                            <th>Domain</th>
                                            <th>Progress</th>
                                            <th>Started</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($active_mentorships as $m): ?>
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
                                                <td><?php echo date('M d, Y', strtotime($m['start_date'])); ?></td>
                                                <td>
                                                    <a href="?id=<?php echo $m['mentorship_id']; ?>" class="btn btn-sm btn-info">
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

                <!-- Completed Mentorships -->
                <div class="card">
                    <div class="card-header">Completed Mentorships</div>
                    <div class="card-body">
                        <?php if (empty($completed_mentorships)): ?>
                            <div class="no-data">
                                <i class="fas fa-folder-open"></i>
                                <p>No completed mentorships yet</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Mentor</th>
                                            <th>Domain</th>
                                            <th>Completed</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($completed_mentorships as $m): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($m['domain']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($m['end_date'])); ?></td>
                                                <td>
                                                    <a href="?id=<?php echo $m['mentorship_id']; ?>" class="btn btn-sm btn-info">
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
