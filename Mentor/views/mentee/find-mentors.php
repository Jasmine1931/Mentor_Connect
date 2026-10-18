<?php
/**
 * Find Mentors - Mentee can search and request mentors
 * MODIFIED:
 *   - After a mentor accepts any request, the mentee is PAIRED and the
 *     "Send Request" button is replaced with a locked banner on every
 *     mentor card. No new requests can be sent.
 *   - isMenteePaired() check added at the top of the page.
 *   - sendRequest() already enforces this server-side; this is the UI layer.
 */
 
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/MentorRequest.php';
require_once __DIR__ . '/../../models/Mentorship.php';
 
// Check if user is logged in and has mentee role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mentee') {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login.php');
    exit;
}

$user_id          = $_SESSION['user_id'];
$userModel        = new User();
$requestModel     = new MentorRequest();
$mentorshipModel  = new Mentorship();
 
$user   = $userModel->getUserById($user_id);
$domain = $user['domain'];
 
// ── Check if this mentee is already officially paired ────────
$mentee_is_paired = $requestModel->isMenteePaired($user_id);
 
// ── Handle request submission ────────────────────────────────
$message           = '';
$show_success_popup = false;
 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_request'])) {

    // ── Guard 1: 6-Month Pairing Lock ────────────────────────────
    if ($mentee_is_paired) {
        // Check expiry details for a useful message
        $lock_status = $mentorshipModel->getPairingLockStatus($user_id);
        $expires_display = '';
        if (!empty($lock_status['expires_at'])) {
            $expires_display = ' Your 6-month program runs until ' .
                date('d M Y', strtotime($lock_status['expires_at'])) .
                ' (' . $lock_status['days_remaining'] . ' days remaining).';
        }
        $message = '<div class="alert alert-warning">
                        <i class="fas fa-lock"></i>
                        <strong>6-Month Lock Active:</strong>
                        You are currently paired with a mentor and cannot send new requests.' .
                        htmlspecialchars($expires_display) .
                    '</div>';
    } else {
        $mentor_id       = intval($_POST['mentor_id']);
        $request_message = trim($_POST['request_message'] ?? '');
        $competencies    = $_POST['competencies'] ?? [];

        if (count($competencies) != 3) {
            $message = '<div class="alert alert-danger">Please select exactly 3 competencies.</div>';
        } else {
            $competency_list = implode(', ', $competencies);
            $final_message   = 'Competencies Selected: ' . $competency_list
                             . "\n\nMessage:\n" . $request_message;

            // ── Guard 2: Cross-Department Validation ─────────────
            if (!$mentorshipModel->validateDomains($mentor_id, $user_id)) {
                $message = '<div class="alert alert-danger">
                                <i class="fas fa-ban"></i>
                                <strong>Cross-Department Rule:</strong>
                                You cannot request a mentor from the same department.
                                Please select a mentor from a <em>different</em> department.
                            </div>';
            } elseif (!$userModel->canMenteeRequestMore($user_id)) {
                $message = '<div class="alert alert-danger">You have reached the maximum number of mentor requests (2).</div>';
            } else {
                $result = $requestModel->sendRequest($user_id, $mentor_id, $final_message);
                if ($result['success']) {
                    $show_success_popup = true;
                    $mentee_is_paired   = $requestModel->isMenteePaired($user_id);
                } else {
                    $message = '<div class="alert alert-danger">'
                             . htmlspecialchars($result['message']) . '</div>';
                }
            }
        }
    }
}
 
// ── Fetch all available mentors ───────────────────────────────
$mentors = $userModel->getMentorsByDomain($domain, $user_id);
 
// ── Build a quick lookup: which mentors already have a request from this mentee?
$existing_requests = $requestModel->getMenteeRequests($user_id);
$already_requested = []; // mentor_id => status
foreach ($existing_requests as $req) {
    $already_requested[(int)$req['mentor_id']] = $req['status'];
}
 
$page_title = 'Find Mentors';
?>
 
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
 
<!-- ── Success Popup ── -->
<style>
.success-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    animation: fadeInBg .25s ease;
}
.success-overlay.active { display: flex; }
 
@keyframes fadeInBg {
    from { opacity: 0; }
    to   { opacity: 1; }
}
 
.success-popup {
    background: #fff;
    border-radius: 18px;
    padding: 44px 40px 36px;
    max-width: 400px;
    width: 90%;
    text-align: center;
    box-shadow: 0 24px 60px rgba(0,0,0,.2);
    animation: popIn .3s cubic-bezier(.34,1.56,.64,1);
}
 
@keyframes popIn {
    from { transform: scale(.7); opacity: 0; }
    to   { transform: scale(1);  opacity: 1; }
}
 
.success-popup .check-circle {
    width: 72px; height: 72px;
    background: linear-gradient(135deg, #22c55e, #16a34a);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 20px;
    box-shadow: 0 6px 20px rgba(34,197,94,.35);
    animation: bounceIn .45s .1s both cubic-bezier(.34,1.56,.64,1);
}
 
@keyframes bounceIn {
    from { transform: scale(0); }
    to   { transform: scale(1); }
}
 
.success-popup .check-circle svg { color: #fff; }
 
.success-popup h4 {
    font-size: 22px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 8px;
}
 
.success-popup p {
    color: #6b7280;
    font-size: 14px;
    margin-bottom: 28px;
    line-height: 1.6;
}
 
.success-popup .popup-ok-btn {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 12px 36px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity .2s, transform .1s;
}
.success-popup .popup-ok-btn:hover  { opacity: .9; }
.success-popup .popup-ok-btn:active { transform: scale(.96); }
 
/* Already-paired global banner */
.paired-global-banner {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border: 1.5px solid #f59e0b;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 14px;
}
.paired-global-banner i { font-size: 1.6rem; color: #b45309; }
.paired-global-banner .paired-text strong { display: block; font-size: 1rem; color: #92400e; }
.paired-global-banner .paired-text small  { color: #78350f; }
 
/* Per-card locked state */
.btn-paired-locked {
    background: #f3f4f6;
    color: #9ca3af;
    border: 1.5px solid #d1d5db;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.85rem;
    font-weight: 600;
    width: 100%;
    cursor: not-allowed;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
</style>
 
<div class="success-overlay <?php echo $show_success_popup ? 'active' : ''; ?>" id="successOverlay">
    <div class="success-popup">
        <div class="check-circle">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </div>
        <h4>Request Sent Successfully!</h4>
        <p>Your mentorship request has been sent.<br>You'll be notified once the mentor responds.</p>
        <button class="popup-ok-btn" onclick="closeSuccessPopup()">OK, Got it!</button>
    </div>
</div>
 
<script>
function validateCompetencies(form) {
    let checked = form.querySelectorAll('input[name="competencies[]"]:checked');
    if (checked.length != 3) {
        alert('Please select exactly 3 competencies.');
        return false;
    }
    return true;
}
 
function closeSuccessPopup() {
    document.getElementById('successOverlay').classList.remove('active');
    window.location.href = 'find-mentors.php';
}
 
document.getElementById('successOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeSuccessPopup();
});
</script>
 
<div class="container-fluid">
<div class="row">
 
<div class="col-md-2 sidebar">
<div class="sidebar-menu">
<a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
<a href="find-mentors.php" class="active"><i class="fas fa-search"></i> Find Mentors</a>
<a href="my-mentorships.php"><i class="fas fa-handshake"></i> My Mentorships</a>
<a href="sessions.php"><i class="fas fa-calendar"></i> Sessions</a>
<a href="feedback-history.php"><i class="fas fa-star"></i> Feedback</a>
</div>
</div>
 
<div class="col-md-10 main-content">
 
<div class="page-header">
<h1><i class="fas fa-search"></i> Find Mentors in <?php echo htmlspecialchars($domain); ?></h1>
</div>
 
<?php echo $message; ?>
 
<?php if ($mentee_is_paired): ?>
<!-- ── Global "Already Paired" Banner ── -->
<div class="paired-global-banner">
    <i class="fas fa-handshake"></i>
    <div class="paired-text">
        <strong>You are officially paired with a mentor!</strong>
        <small>Your mentorship is confirmed. You cannot send new requests while you have an active pairing.</small>
    </div>
</div>
<?php endif; ?>
 
<div class="row">
 
<?php if (empty($mentors)): ?>
<div class="col-12">
<div class="alert alert-info">
<i class="fas fa-info-circle"></i> No mentors available in your domain yet.
</div>
</div>
 
<?php else: ?>
 
<?php foreach ($mentors as $mentor): ?>
<?php
$is_fully_booked  = $userModel->isMentorFullyBooked($mentor['user_id']);
$active_mentees   = $userModel->getActiveMenteeCount($mentor['user_id']);
$mentor_id_int    = (int) $mentor['user_id'];
$req_status       = $already_requested[$mentor_id_int] ?? null;
 
// Determine the button state for this specific mentor card
$btn_state = 'send';        // default: show Send Request
if ($mentee_is_paired) {
    $btn_state = 'paired_locked';     // mentee is officially paired → lock all cards
} elseif ($req_status === 'accepted') {
    $btn_state = 'accepted';          // this mentor accepted → show success state
} elseif ($req_status === 'pending') {
    $btn_state = 'pending';           // request already sent to this mentor
} elseif ($req_status === 'already paired') {
    $btn_state = 'other_paired';      // this mentee went with someone else
} elseif ($is_fully_booked) {
    $btn_state = 'fully_booked';
}
?>
 
<div class="col-md-4 mb-4">
<div class="card h-100">
<div class="card-body">
 
<h5 class="card-title">
<?php echo htmlspecialchars($mentor['first_name'] . ' ' . $mentor['last_name']); ?>
<?php if ($is_fully_booked): ?>
<span class="badge-fully-booked">Fully Booked</span>
<?php endif; ?>
</h5>
 
<p class="text-muted"><small><?php echo htmlspecialchars($mentor['expertise_area']); ?></small></p>
 
<div class="mb-3">
<div class="rating">
<?php for ($i = 0; $i < 5; $i++): ?>
<i class="fas fa-star<?php echo $i < floor($mentor['average_rating']) ? '' : '-o'; ?>"></i>
<?php endfor; ?>
<span class="text-muted"><small>(<?php echo $mentor['total_sessions_completed']; ?> sessions)</small></span>
</div>
</div>
 
<?php if (!empty($mentor['bio'])): ?>
<p class="card-text">
<small><?php echo htmlspecialchars(substr($mentor['bio'], 0, 100)); ?>...</small>
</p>
<?php endif; ?>
 
<div class="mb-3">
<small class="text-muted">
<strong>Mentees:</strong> <?php echo $active_mentees; ?>/<?php echo MAX_MENTEES_PER_MENTOR; ?>
</small>
</div>
 
<!-- ── Button / Status per card ── -->
<?php if ($btn_state === 'accepted'): ?>
    <div class="alert alert-success py-2 mb-0 text-center">
        <i class="fas fa-check-circle"></i> <strong>Paired!</strong> This mentor accepted your request.
    </div>
 
<?php elseif ($btn_state === 'pending'): ?>
    <div class="alert alert-info py-2 mb-0 text-center">
        <i class="fas fa-clock"></i> Request sent — awaiting response.
    </div>
 
<?php elseif ($btn_state === 'paired_locked'): ?>
    <div class="btn-paired-locked">
        <i class="fas fa-lock"></i> Pairing Complete — No New Requests
    </div>
 
<?php elseif ($btn_state === 'other_paired'): ?>
    <div class="alert alert-warning py-2 mb-0 text-center">
        <i class="fas fa-user-lock"></i> You were paired with another mentor.
    </div>
 
<?php elseif ($btn_state === 'fully_booked'): ?>
    <button class="btn btn-secondary btn-sm w-100" disabled>
        <i class="fas fa-ban"></i> Fully Booked
    </button>
 
<?php else: /* $btn_state === 'send' */ ?>
    <button type="button"
            class="btn btn-primary btn-sm w-100"
            data-bs-toggle="modal"
            data-bs-target="#requestModal<?php echo $mentor['user_id']; ?>">
        <i class="fas fa-handshake"></i> Send Request
    </button>
 
    <!-- Request Modal -->
    <div class="modal fade"
         id="requestModal<?php echo $mentor['user_id']; ?>"
         tabindex="-1">
    <div class="modal-dialog">
    <div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title">Send Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST" onsubmit="return validateCompetencies(this)">
    <div class="modal-body">
        <p>Requesting <strong><?php echo htmlspecialchars($mentor['first_name'] . ' ' . $mentor['last_name']); ?></strong></p>
 
        <label class="form-label">Skills (Select exactly 3)</label>
        <div class="row">
        <div class="col-md-6">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="competencies[]" value="Personal Effectiveness">
                <label class="form-check-label">Personal Effectiveness</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="competencies[]" value="Leadership &amp; People Skills">
                <label class="form-check-label">Leadership &amp; People Skills</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="competencies[]" value="Organizational Awareness">
                <label class="form-check-label">Organizational Awareness</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="competencies[]" value="Time &amp; Priority Management">
                <label class="form-check-label">Time &amp; Priority Management</label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="competencies[]" value="Communication &amp; Influence">
                <label class="form-check-label">Communication &amp; Influence</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="competencies[]" value="Career Navigation &amp; Visibility">
                <label class="form-check-label">Career Navigation &amp; Visibility</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="competencies[]" value="Strategic Thinking &amp; Innovation">
                <label class="form-check-label">Strategic Thinking &amp; Innovation</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="competencies[]" value="Feedback &amp; Self-Development">
                <label class="form-check-label">Feedback &amp; Self-Development</label>
            </div>
        </div>
        </div>
 
        <div class="mt-3">
            <label class="form-label">Message</label>
            <textarea class="form-control" name="request_message" rows="3" placeholder="Write your message..."></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <input type="hidden" name="mentor_id" value="<?php echo $mentor['user_id']; ?>">
        <input type="hidden" name="send_request" value="1">
        <button type="submit" class="btn btn-primary">Send Request</button>
    </div>
    </form>
    </div>
    </div>
    </div><!-- /modal -->
<?php endif; ?>
 
</div><!-- /card-body -->
</div><!-- /card -->
</div><!-- /col -->
 
<?php endforeach; ?>
<?php endif; ?>
 
</div><!-- /row -->
</div><!-- /main-content -->
</div><!-- /row -->
</div><!-- /container -->
 
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>