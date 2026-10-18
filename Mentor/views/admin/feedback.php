<?php
/**
 * Admin - Feedback Reports
 * MODIFIED: Added "Download All Feedback" button + per-mentee individual Download buttons
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/Feedback.php';

SessionHandler::requireRole('admin');

$feedbackModel = new Feedback();

$page  = intval($_GET['page'] ?? 1);
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$all_feedback = $feedbackModel->getAllFeedback($limit, $offset);
$stats        = $feedbackModel->getFeedbackStats();

// Fetch distinct mentees who have feedback (direct DB query using real schema)
$db = (new Database())->connect();
$menteeFeedbackList = [];
if ($db) {
    $res = $db->query("
        SELECT DISTINCT f.user_id AS mentee_id, u.name AS mentee_name
        FROM feedback f
        JOIN users u ON f.user_id = u.id
        ORDER BY u.name ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $menteeFeedbackList[] = $row;
        }
    }
}

$page_title = 'Feedback Reports';
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
                <a href="feedback.php" class="active">
                    <i class="fas fa-star"></i> Feedback
                </a>
                <a href="reports.php">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </div>
        </div>

        <div class="col-md-10 main-content">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap" style="gap:10px;">
                <h1><i class="fas fa-star"></i> Feedback Reports</h1>
                <a href="<?php echo rtrim(APP_URL, '/'); ?>/admin_feedback_download.php?action=full"
                   class="btn btn-success btn-sm"
                   title="Download complete feedback report for all mentees">
                    <i class="fas fa-download"></i>&nbsp; Download Full Feedback Report
                </a>
            </div>

            <!-- Stats -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <p class="stat-label">Total Feedback</p>
                        <p class="stat-value"><?php echo $stats['total_feedback']; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <p class="stat-label">Average Rating</p>
                        <p class="stat-value"><?php echo $stats['average_rating']; ?>/5</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <p class="stat-label">Rating Distribution</p>
                        <small class="text-muted">
                            <?php foreach ($stats['distribution'] as $dist): ?>
                                <?php echo $dist['rating']; ?>&#9733;: <?php echo $dist['count']; ?> 
                            <?php endforeach; ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- Per-Mentee Individual Download Panel -->
            <?php if (!empty($menteeFeedbackList)): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-user-graduate"></i> Individual Mentee Feedback Download</span>
                    <small class="text-muted"><?php echo count($menteeFeedbackList); ?> mentee(s) with feedback</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width:50px;">#</th>
                                    <th>Mentee Name</th>
                                    <th style="width:180px;text-align:center;">Download</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($menteeFeedbackList as $i => $ml): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars($ml['mentee_name']); ?></td>
                                    <td style="text-align:center;">
                                        <a href="<?php echo rtrim(APP_URL, '/'); ?>/admin_feedback_download.php?action=individual&amp;mentee_id=<?php echo (int)$ml['mentee_id']; ?>"
                                           class="btn btn-sm btn-outline-primary"
                                           title="Download feedback for <?php echo htmlspecialchars($ml['mentee_name']); ?>">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- All Feedback Table -->
            <div class="card">
                <div class="card-header">All Feedback</div>
                <div class="card-body">
                    <?php if (empty($all_feedback)): ?>
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
                                        <th>Mentee</th>
                                        <th>Session #</th>
                                        <th>Rating</th>
                                        <th>Comments</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_feedback as $feedback): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($feedback['mentor_first_name'] . ' ' . $feedback['mentor_last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($feedback['mentee_first_name'] . ' ' . $feedback['mentee_last_name']); ?></td>
                                            <td><?php echo $feedback['session_number']; ?></td>
                                            <td>
                                                <div class="rating">
                                                    <?php for ($i = 0; $i < 5; $i++): ?>
                                                        <i class="fas fa-star<?php echo $i < $feedback['rating'] ? '' : '-o'; ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                            </td>
                                            <td>
    <?php echo htmlspecialchars(substr($feedback['comments'], 0, 40)); ?>...

    <button class="btn btn-sm btn-primary"
        onclick="openFeedbackModal(
            '<?php echo htmlspecialchars(addslashes($feedback['mentor_first_name'].' '.$feedback['mentor_last_name'])); ?>',
            '<?php echo htmlspecialchars(addslashes($feedback['mentee_first_name'].' '.$feedback['mentee_last_name'])); ?>',
            '<?php echo $feedback['rating']; ?>',
            '<?php echo htmlspecialchars(addslashes($feedback['comments'])); ?>'
        )">
        View
    </button>
</td>
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

<!-- Feedback Detail Modal -->
<div class="modal fade" id="feedbackModal" tabindex="-1" role="dialog" aria-labelledby="feedbackModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="feedbackModalLabel">Feedback Details</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p><strong>Mentor:</strong> <span id="modal-mentor"></span></p>
        <p><strong>Mentee:</strong> <span id="modal-mentee"></span></p>
        <p><strong>Rating:</strong> <span id="modal-rating"></span></p>
        <p><strong>Comments:</strong></p>
        <p id="modal-comments" style="white-space:pre-wrap;"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
function openFeedbackModal(mentor, mentee, rating, comments) {
    document.getElementById('modal-mentor').textContent   = mentor;
    document.getElementById('modal-mentee').textContent   = mentee;
    document.getElementById('modal-rating').textContent   = rating + ' / 5';
    document.getElementById('modal-comments').textContent = comments;
    if (typeof $ !== 'undefined') {
        $('#feedbackModal').modal('show');
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
