<?php
/**
 * Mentor - Requests Management
 * MODIFIED: 
 *   - Reads flash messages set by accept_request.php / reject_request.php
 *   - getPendingRequests() now also fetches 'already paired' rows so the
 *     mentor sees a clear status badge instead of a blank table entry.
 *   - New helper query getAllRequestsForMentor() shows both pending AND
 *     already-paired requests in a unified table.
 *   - Accept button is hidden for 'already paired' rows.
 */
 
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/MentorRequest.php';
require_once __DIR__ . '/../../models/User.php';
 
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    header('Location: ' . APP_URL . '/login.php');
    exit();
}
 
$user_id      = $_SESSION['user_id'];
$requestModel = new MentorRequest();
$userModel    = new User();
 
$request_id = intval($_GET['id'] ?? 0);
 
// ── Flash messages from accept/reject redirects ───────────────
$message = '';
if (!empty($_SESSION['flash_success'])) {
    $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> '
             . htmlspecialchars($_SESSION['flash_success']) . '</div>';
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> '
             . htmlspecialchars($_SESSION['flash_error']) . '</div>';
    unset($_SESSION['flash_error']);
}
 
// ── Inline POST actions (reject only; accept is handled by accept_request.php) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action     = $_POST['action'];
    $request_id = intval($_POST['request_id']);
 
    if ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        $result = $requestModel->rejectRequest($request_id, $user_id, $reason);
        if ($result['success']) {
            $message    = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Request rejected.</div>';
            $request_id = 0;
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> '
                     . htmlspecialchars($result['message']) . '</div>';
        }
    }
}
 
// ── Sidebar HTML helper (DRY) ─────────────────────────────────
function renderSidebar() { ?>
<div class="col-md-2 sidebar">
    <div class="sidebar-menu">
        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="my-mentees.php"><i class="fas fa-users"></i> My Mentees</a>
        <a href="requests.php" class="active"><i class="fas fa-envelope"></i> Requests</a>
        <a href="availability.php"><i class="fas fa-calendar"></i> Availability</a>
        <a href="feedback.php"><i class="fas fa-comments"></i> Feedback</a>
    </div>
</div>
<?php }
 
// ════════════════════════════════════════════════════════════
// SINGLE REQUEST REVIEW VIEW
// ════════════════════════════════════════════════════════════
if ($request_id > 0) {
 
    // Fetch all requests for this mentor (pending + already paired) to find the one
    $all_requests = $requestModel->getAllRequestsForMentor($user_id);
    $request      = null;
    foreach ($all_requests as $req) {
        if ($req['request_id'] == $request_id) {
            $request = $req;
            break;
        }
    }
 
    if (!$request) {
        header('Location: requests.php');
        exit();
    }
 
    $is_already_paired = ($request['status'] === 'already paired');
    $page_title        = 'Review Request';
    ?>
 
    <?php require_once __DIR__ . '/../../includes/header.php'; ?>
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
 
    <div class="container-fluid">
        <div class="row">
            <?php renderSidebar(); ?>
 
            <div class="col-md-10 main-content">
                <div class="page-header">
                    <a href="requests.php" class="btn btn-outline-secondary btn-sm mb-3">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <h1>Review Mentorship Request</h1>
                </div>
 
                <?php echo $message; ?>
 
                <?php if ($is_already_paired): ?>
                <!-- ── Already-Paired Banner ── -->
                <div class="alert alert-warning d-flex align-items-center gap-3" role="alert">
                    <i class="fas fa-user-lock fa-2x text-warning"></i>
                    <div>
                        <strong>This mentee has already been paired with another mentor.</strong><br>
                        <small>No further action is required. The pairing with the other mentor is final.</small>
                    </div>
                </div>
                <?php endif; ?>
 
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header">Mentee Details</div>
                            <div class="card-body">
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($request['email']); ?></p>
                                <p><strong>Domain:</strong> <?php echo htmlspecialchars($request['domain'] ?? '—'); ?></p>
                                <?php if (!empty($request['bio'])): ?>
                                    <p><strong>Bio:</strong></p>
                                    <p><?php echo htmlspecialchars($request['bio']); ?></p>
                                <?php endif; ?>
                                <p><strong>Requested:</strong> <?php echo date('M d, Y H:i', strtotime($request['requested_at'])); ?></p>
                                <p>
                                    <strong>Status:</strong>
                                    <?php if ($is_already_paired): ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="fas fa-user-lock"></i> Already Paired with Another Mentor
                                        </span>
                                    <?php elseif ($request['status'] === 'pending'): ?>
                                        <span class="badge bg-info text-dark">Pending</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($request['status'])); ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
 
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header">Request Message</div>
                            <div class="card-body">
                                <?php if (!empty($request['request_message'])): ?>
                                    <p><?php echo nl2br(htmlspecialchars($request['request_message'])); ?></p>
                                <?php else: ?>
                                    <p class="text-muted"><em>No message provided</em></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
 
                <?php if (!$is_already_paired && $request['status'] === 'pending'): ?>
                <!-- ── Accept / Reject Actions (only for pending, non-paired requests) ── -->
                <div class="card">
                    <div class="card-header">Action</div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <!-- Accept links to accept_request.php which handles the transaction -->
                                <a href="<?php echo APP_URL; ?>/accept_request.php?request_id=<?php echo $request['request_id']; ?>"
                                   class="btn btn-success btn-lg w-100"
                                   onclick="return confirm('Accept this mentorship request? This will officially pair you with this mentee and close all their other pending requests.')">
                                    <i class="fas fa-check"></i> Accept Request
                                </a>
                            </div>
                            <div class="col-md-6">
                                <button type="button" class="btn btn-danger btn-lg w-100"
                                        data-bs-toggle="modal" data-bs-target="#rejectModal">
                                    <i class="fas fa-times"></i> Reject Request
                                </button>
 
                                <!-- Reject Modal -->
                                <div class="modal fade" id="rejectModal" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Reject Request</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label for="reason" class="form-label">Reason (optional)</label>
                                                        <textarea class="form-control" id="reason" name="rejection_reason"
                                                                  rows="4" placeholder="Why are you rejecting this request?"></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                    <button type="submit" class="btn btn-danger">Reject</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php elseif ($is_already_paired): ?>
                <div class="card">
                    <div class="card-body text-center text-muted py-4">
                        <i class="fas fa-lock fa-2x mb-2 d-block text-warning"></i>
                        No actions available — this mentee is already paired with another mentor.
                    </div>
                </div>
                <?php endif; ?>
 
            </div><!-- /main-content -->
        </div><!-- /row -->
    </div><!-- /container -->
 
    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
 
<?php
// ════════════════════════════════════════════════════════════
// REQUEST LIST VIEW
// ════════════════════════════════════════════════════════════
} else {
 
    // Fetch pending requests + already-paired requests for this mentor
    $pending_requests       = $requestModel->getPendingRequests($user_id);
    $already_paired_requests = $requestModel->getAlreadyPairedRequests($user_id);
 
    $page_title = 'Mentorship Requests';
    ?>
 
    <?php require_once __DIR__ . '/../../includes/header.php'; ?>
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
 
    <style>
    /* Badge for already-paired rows */
    .badge-already-paired {
        background-color: #f59e0b;
        color: #1f2937;
        font-size: 0.75rem;
        padding: 4px 8px;
        border-radius: 6px;
        font-weight: 600;
        white-space: nowrap;
    }
    .row-already-paired {
        background-color: #fffbeb !important;
        opacity: 0.85;
    }
    </style>
 
    <div class="container-fluid">
        <div class="row">
            <?php renderSidebar(); ?>
 
            <div class="col-md-10 main-content">
                <div class="page-header">
                    <h1><i class="fas fa-envelope"></i> Mentorship Requests</h1>
                </div>
 
                <?php echo $message; ?>
 
                <!-- ── Pending Requests ── -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Pending Requests</span>
                        <span class="badge bg-info text-dark"><?php echo count($pending_requests); ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_requests)): ?>
                            <div class="no-data">
                                <i class="fas fa-inbox"></i>
                                <p>No pending requests</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Mentee</th>
                                            <th>Domain</th>
                                            <th>Message</th>
                                            <th>Requested</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_requests as $req): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($req['domain'] ?? '—'); ?></td>
                                                <td><?php echo $req['request_message']
                                                        ? htmlspecialchars(substr($req['request_message'], 0, 50)) . '...'
                                                        : '<em class="text-muted">No message</em>'; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($req['requested_at'])); ?></td>
                                                <td>
                                                    <a href="?id=<?php echo $req['request_id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-eye"></i> Review
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
 
                <!-- ── Already-Paired Requests (informational) ── -->
                <?php if (!empty($already_paired_requests)): ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fas fa-user-lock text-warning"></i>
                            Mentees Already Paired with Another Mentor
                        </span>
                        <span class="badge bg-warning text-dark"><?php echo count($already_paired_requests); ?></span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            <i class="fas fa-info-circle"></i>
                            These mentees had also sent you a request, but they were officially paired with a different mentor first. No action is required.
                        </p>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Mentee</th>
                                        <th>Domain</th>
                                        <th>Message</th>
                                        <th>Requested</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($already_paired_requests as $req): ?>
                                        <tr class="row-already-paired">
                                            <td><strong><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($req['domain'] ?? '—'); ?></td>
                                            <td><?php echo $req['request_message']
                                                    ? htmlspecialchars(substr($req['request_message'], 0, 50)) . '...'
                                                    : '<em class="text-muted">No message</em>'; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($req['requested_at'])); ?></td>
                                            <td>
                                                <span class="badge-already-paired">
                                                    <i class="fas fa-user-lock"></i> Paired with Another Mentor
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
 
            </div><!-- /main-content -->
        </div><!-- /row -->
    </div><!-- /container -->
 
    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
    <?php
}
?>