<?php
// ============================================================
// pages/feedback.php
// Dedicated Feedback Page — Mentor, Mentee, and Admin
// Mentee: submit feedback after session ends
// Mentor: view feedback received from mentees
// Admin: view all feedback in the system
// ============================================================

session_start();

require_once "../config/database.php";
require_once "../config/config.php";
require_once "../includes/MySessionHandler.php";
require_once "../includes/Security.php";

date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$db        = (new Database())->connect();
new MySessionHandler();

try {
    MySessionHandler::enforceOneSession($db);
} catch (Exception $e) {
    error_log('enforceOneSession skipped: ' . $e->getMessage());
}

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'];

/* ============================================================
   HANDLE: SUBMIT FEEDBACK (Mentee only)
============================================================ */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_feedback'])) {
    Security::csrfVerify();

    $mentor_id           = (int)   ($_POST['mentor_id']          ?? 0);
    $session_date        =          $_POST['session_date']        ?? '';
    $rating              = (int)   ($_POST['rating']             ?? 0);
    $q1                  = strip_tags($_POST['q1']               ?? '');
    $q2                  = strip_tags($_POST['q2']               ?? '');
    $q3                  = strip_tags($_POST['q3']               ?? '');
    $q4                  = strip_tags($_POST['q4']               ?? '');
    $feedback_comment    = strip_tags($_POST['feedback_comment']  ?? '');
    $next_focus          = strip_tags($_POST['next_focus']        ?? '');
    $actions             = strip_tags($_POST['actions']           ?? '');
    $support             = strip_tags($_POST['support']           ?? '');
    $feedforward_comment = strip_tags($_POST['feedforward_comment'] ?? '');

    $comments =
        "Feedback:\n" .
        "Q1: $q1\nQ2: $q2\nQ3: $q3\nQ4: $q4\n" .
        "Additional Comments: $feedback_comment\n\n" .
        "Feedforward:\n" .
        "Next Focus: $next_focus\nActions: $actions\nSupport: $support\n" .
        "Additional Notes: $feedforward_comment";

    $stmt = $db->prepare("
        INSERT INTO feedback
        (user_id, session_date, rating, comments, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("isis", $mentor_id, $session_date, $rating, $comments);
    $stmt->execute();
    Security::log('FEEDBACK', "Feedback submitted for mentor #{$mentor_id}", $db);
    $_SESSION['flash_success'] = "Your feedback has been submitted successfully!";
    header('Location: feedback.php');
    exit();
}

/* ============================================================
   FETCH DATA BASED ON ROLE
============================================================ */

// For Mentee: fetch accepted requests where session ended and no feedback yet
$pendingFeedback = [];
$submittedFeedback = [];

if ($user_role === 'mentee') {
    // Requests awaiting feedback
    $stmt = $db->prepare("
        SELECT mr.*, m.name AS mentor_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        WHERE mr.mentee_id=? AND mr.status='accepted' AND mr.session_date IS NOT NULL
        ORDER BY mr.session_date DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $allRequests = $stmt->get_result();

    while ($req = $allRequests->fetch_assoc()) {
        $sessionStart = new DateTime($req['session_date'] . ' ' . ($req['start_time'] ?? '00:00'));
        $sessionEnd   = clone $sessionStart;
        $sessionEnd->modify('+' . ((int)($req['session_duration'] ?: 60)) . ' minutes');
        $now = new DateTime();

        $fbChk = $db->prepare("SELECT id FROM feedback WHERE user_id=? AND session_date=?");
        $fbChk->bind_param("is", $req['mentor_id'], $req['session_date']);
        $fbChk->execute();
        $feedbackExists = $fbChk->get_result()->num_rows > 0;

        if (!$feedbackExists && $now >= $sessionEnd) {
            $req['session_end'] = $sessionEnd->format('Y-m-d H:i');
            $pendingFeedback[] = $req;
        } elseif ($feedbackExists) {
            $submittedFeedback[] = $req;
        }
    }
}

// For Mentor: feedback received from mentees
$mentorReceivedFeedback = [];
if ($user_role === 'mentor') {
    $stmt = $db->prepare("
        SELECT f.*, t.name AS mentee_name
        FROM feedback f
        JOIN mentor_requests mr ON mr.mentor_id = f.user_id AND mr.session_date = f.session_date
        JOIN users t ON mr.mentee_id = t.id
        WHERE f.user_id = ?
        ORDER BY f.created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    while ($fb = $stmt->get_result()->fetch_assoc()) {
        $mentorReceivedFeedback[] = $fb;
    }
}

// For Admin: all feedback
$allFeedback = [];
if ($user_role === 'admin') {
    $result = $db->query("
        SELECT f.*, f.user_id AS feedback_giver_id, m.name AS mentor_name,
               t.id AS mentee_user_id, t.name AS mentee_name
        FROM feedback f
        JOIN users m ON f.user_id = m.id
        LEFT JOIN mentor_requests mr ON mr.mentor_id = m.id AND mr.session_date = f.session_date
        LEFT JOIN users t ON mr.mentee_id = t.id
        ORDER BY f.created_at DESC
    ");
    while ($fb = $result->fetch_assoc()) {
        $allFeedback[] = $fb;
    }

    // Build distinct mentee list for individual download buttons
    $menteesWithFeedback = [];
    $seenMentees = [];
    $menteeFbResult = $db->query("
        SELECT DISTINCT f.user_id AS mentee_id, u.name AS mentee_name
        FROM feedback f
        JOIN users u ON f.user_id = u.id
        ORDER BY u.name ASC
    ");
    if ($menteeFbResult) {
        while ($row = $menteeFbResult->fetch_assoc()) {
            $menteesWithFeedback[] = $row;
        }
    }
}

$current_page = 'feedback';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback — Mentor Connect</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-hero { background:linear-gradient(135deg,#0f2027,#203a43,#2c5364); border-radius:18px; padding:28px 32px; margin-bottom:30px; color:white; display:flex; align-items:center; gap:18px; }
        .page-hero-icon { width:54px; height:54px; background:rgba(109,213,250,0.2); border-radius:14px; display:flex; align-items:center; justify-content:center; }
        .page-hero h1 { font-size:22px; font-weight:700; margin:0 0 4px; }
        .page-hero p  { font-size:13px; opacity:0.75; margin:0; }

        /* Feedback card */
        .fb-card { background:#fff; border-radius:16px; padding:24px 28px; margin-bottom:20px; box-shadow:0 4px 20px rgba(0,0,0,0.06); border-left:4px solid #6f42c1; }
        .fb-card-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; }
        .fb-card-name { font-size:15px; font-weight:600; color:#1a2b4a; }
        .fb-card-date { font-size:12px; color:#94a3b8; background:#f8fafc; padding:4px 10px; border-radius:20px; border:1px solid #e2e8f0; }
        .fb-card-stars { color:#ffb400; font-size:18px; margin-bottom:12px; }
        .fb-card-comments { background:#f8fafc; border-radius:10px; padding:14px 16px; font-size:13.5px; color:#475569; line-height:1.7; white-space:pre-line; }
        .fb-card-submitted { font-size:12px; color:#94a3b8; margin-top:10px; }

        /* Pending feedback card */
        .pending-fb-card { background:#fff; border-radius:16px; padding:24px 28px; margin-bottom:20px; box-shadow:0 4px 20px rgba(0,0,0,0.06); border-left:4px solid #2980b9; }
        .pending-fb-card h4 { font-size:15px; font-weight:600; color:#1a2b4a; margin-bottom:4px; }
        .pending-fb-card .pfc-meta { font-size:13px; color:#64748b; margin-bottom:14px; }

        /* Submitted badge */
        .submitted-badge { display:inline-flex; align-items:center; gap:6px; background:#d4edda; color:#1a7a35; border:1px solid #b8dfc5; border-radius:8px; padding:8px 16px; font-size:13px; font-weight:500; }

        /* Empty state */
        .empty-state { text-align:center; padding:60px 20px; background:#fff; border-radius:18px; border:1.5px dashed #e2e8f0; }
        .empty-state .es-icon { font-size:48px; margin-bottom:16px; }
        .empty-state h3 { font-size:18px; color:#1a2b4a; margin-bottom:8px; }
        .empty-state p  { font-size:14px; color:#64748b; }

        /* Tabs */
        .fb-tabs { display:flex; gap:8px; margin-bottom:24px; }
        .fb-tab { padding:10px 22px; border-radius:10px; border:1.5px solid #e2e8f0; background:#fff; color:#64748b; font-size:14px; font-weight:500; cursor:pointer; font-family:"Poppins",sans-serif; transition:all 0.2s; }
        .fb-tab.active { background:linear-gradient(135deg,#2980b9,#6dd5fa); color:#fff; border-color:transparent; box-shadow:0 4px 14px rgba(41,128,185,0.25); }
        .fb-tab-content { display:none; }
        .fb-tab-content.active { display:block; }

        /* Feedback modal */
        .feedback-modal { display:none; position:fixed; inset:0; background:rgba(15,32,39,0.75); z-index:99999; justify-content:center; align-items:center; backdrop-filter:blur(6px); }
        .feedback-modal.active, .feedback-modal[style*="flex"] { display:flex; }
        .feedback-modal-content { background:#fff; width:580px; max-width:94%; max-height:90vh; overflow-y:auto; border-radius:20px; box-shadow:0 30px 80px rgba(0,0,0,0.2); animation:modalIn 0.3s cubic-bezier(0.34,1.56,0.64,1); position:relative; }
        @keyframes modalIn { from{transform:scale(0.88);opacity:0;} to{transform:scale(1);opacity:1;} }
        .fm-header { background:linear-gradient(135deg,#0f2027,#203a43,#2c5364); padding:28px 32px 22px; border-radius:20px 20px 0 0; color:white; }
        .fm-header h3 { font-size:20px; font-weight:600; margin:0 0 4px; }
        .fm-header p  { font-size:13px; opacity:0.75; margin:0; }
        .fm-steps { display:flex; align-items:center; gap:8px; margin-top:16px; }
        .fm-step  { height:4px; border-radius:4px; flex:1; background:rgba(255,255,255,0.25); transition:background 0.3s; }
        .fm-step.active { background:#6dd5fa; }
        .fm-body { padding:28px 32px 32px; }
        .star-wrap { display:flex; justify-content:center; margin:4px 0 28px; }
        .star-rating { direction:rtl; display:flex; gap:4px; }
        .star-rating input { display:none; }
        .star-rating label { font-size:40px; color:#ddd; cursor:pointer; transition:color 0.2s,transform 0.2s; line-height:1; }
        .star-rating label:hover, .star-rating label:hover ~ label { color:#ffb400; transform:scale(1.15); }
        .star-rating input:checked ~ label { color:#ffb400; text-shadow:0 0 12px rgba(255,180,0,0.5); }
        .fm-question { margin-bottom:20px; }
        .fm-question label { display:flex; align-items:flex-start; font-size:13px; font-weight:600; color:#1a2b4a; margin-bottom:8px; }
        .fm-question label span { padding-top:1px; }
        .q-num { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:linear-gradient(135deg,#2980b9,#6dd5fa); color:white; border-radius:50%; font-size:11px; font-weight:700; margin-right:8px; flex-shrink:0; }
        .fm-question textarea { width:100%; padding:12px 14px; border-radius:10px; border:1.5px solid #e2e8f0; font-family:"Poppins",sans-serif; font-size:13.5px; color:#2c3e50; resize:none; height:90px; transition:border-color 0.2s,box-shadow 0.2s; background:#fafbfd; }
        .fm-question textarea:focus { border-color:#2980b9; box-shadow:0 0 0 3px rgba(41,128,185,0.12); outline:none; background:#fff; }
        .fm-section-label { font-size:11px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:#94a3b8; margin:24px 0 16px; padding-bottom:8px; border-bottom:1px solid #f0f4f8; }
        .fm-footer { display:flex; justify-content:space-between; align-items:center; margin-top:28px; padding-top:20px; border-top:1px solid #f0f4f8; }
        .fm-btn-back { background:#f1f5f9; color:#64748b; padding:11px 22px; border:none; border-radius:10px; font-size:14px; font-weight:500; cursor:pointer; font-family:"Poppins",sans-serif; }
        .fm-btn-next { background:linear-gradient(135deg,#2980b9,#6dd5fa); color:white; padding:11px 28px; border:none; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; font-family:"Poppins",sans-serif; }
        .fm-btn-submit { background:linear-gradient(135deg,#28a745,#20c35e); color:white; padding:11px 28px; border:none; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; font-family:"Poppins",sans-serif; }
        .fm-close { position:absolute; top:16px; right:20px; font-size:22px; cursor:pointer; color:rgba(255,255,255,0.7); line-height:1; }
        .fm-close:hover { color:#fff; }
    </style>
</head>
<body>

<div class="layout">

    <!-- SIDE NAVIGATION -->
    <?php require_once "../includes/sidenav.php"; ?>

    <!-- MAIN CONTENT -->
    <div class="main">

        <!-- TOPBAR -->
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="sidenav-toggle" onclick="toggleSidenav()" title="Toggle menu">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <h2>Feedback</h2>
            </div>
            <span>Welcome, <strong><?php echo htmlspecialchars($user_name); ?></strong></span>
        </div>

        <!-- Flash messages -->
        <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="flash-success">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
        </div>
        <?php endif; ?>

        <!-- Page Hero -->
        <div class="page-hero">
            <div class="page-hero-icon">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#6dd5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
            </div>
            <div>
                <h1>Feedback</h1>
                <p>
                    <?php if ($user_role === 'mentee'): ?>
                        Submit feedback after your sessions are completed. Track your feedback history.
                    <?php elseif ($user_role === 'mentor'): ?>
                        View all feedback submitted by your mentees.
                    <?php else: ?>
                        Review all feedback submitted across the platform.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if ($user_role === 'mentee'): ?>
        <!-- ─── MENTEE VIEW: Tabs for Pending / History ─── -->
        <div class="fb-tabs">
            <button class="fb-tab active" onclick="showTab('pending')">
                📋 Pending Feedback <?php if (!empty($pendingFeedback)): ?><span style="background:#ef4444;color:#fff;border-radius:20px;padding:1px 7px;font-size:11px;margin-left:6px;"><?php echo count($pendingFeedback); ?></span><?php endif; ?>
            </button>
            <button class="fb-tab" onclick="showTab('history')">
                ✅ Submitted History
            </button>
        </div>

        <!-- Pending Feedback Tab -->
        <div id="tab-pending" class="fb-tab-content active">
            <?php if (empty($pendingFeedback)): ?>
            <div class="empty-state">
                <div class="es-icon">🎉</div>
                <h3>No Pending Feedback</h3>
                <p>All your sessions have been reviewed. Great job!</p>
            </div>
            <?php else: ?>
            <div class="section-heading">Sessions Awaiting Your Feedback</div>
            <?php foreach ($pendingFeedback as $req): ?>
            <div class="pending-fb-card">
                <h4>📋 Session with <?php echo htmlspecialchars($req['mentor_name']); ?></h4>
                <div class="pfc-meta">
                    📅 <?php echo htmlspecialchars($req['session_date']); ?> &nbsp;|&nbsp;
                    ⏰ <?php echo htmlspecialchars($req['start_time'] ?? '--:--'); ?> &nbsp;|&nbsp;
                    Session ended: <?php echo htmlspecialchars($req['session_end']); ?>
                </div>
                <button type="button" class="btn-feedback"
                        onclick="openFeedbackModal(<?php echo $req['id']; ?>)">
                    📝 Give Feedback
                </button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Submitted History Tab -->
        <div id="tab-history" class="fb-tab-content">
            <?php if (empty($submittedFeedback)): ?>
            <div class="empty-state">
                <div class="es-icon">📭</div>
                <h3>No Feedback Submitted Yet</h3>
                <p>Your submitted feedback will appear here.</p>
            </div>
            <?php else: ?>
            <div class="section-heading">Your Feedback History</div>
            <?php foreach ($submittedFeedback as $req): ?>
            <div class="request-card">
                <div class="req-names">
                    <div class="req-name-item"><strong>Mentor:</strong> <?php echo htmlspecialchars($req['mentor_name']); ?></div>
                    <div class="req-name-item"><strong>Session Date:</strong> <?php echo htmlspecialchars($req['session_date']); ?></div>
                </div>
                <span class="submitted-badge">✅ Feedback Submitted</span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php elseif ($user_role === 'mentor'): ?>
        <!-- ─── MENTOR VIEW: Feedback Received ─── -->
        <?php if (empty($mentorReceivedFeedback)): ?>
        <div class="empty-state">
            <div class="es-icon">💬</div>
            <h3>No Feedback Received Yet</h3>
            <p>Feedback submitted by your mentees will appear here.</p>
        </div>
        <?php else: ?>
        <div class="section-heading">Feedback from Your Mentees</div>
        <?php foreach ($mentorReceivedFeedback as $fb): ?>
        <div class="fb-card">
            <div class="fb-card-header">
                <div class="fb-card-name">👤 <?php echo htmlspecialchars($fb['mentee_name']); ?></div>
                <div class="fb-card-date">📅 <?php echo htmlspecialchars($fb['session_date']); ?></div>
            </div>
            <div class="fb-card-stars">
                <?php echo str_repeat("★", $fb['rating']) . str_repeat("☆", 5 - $fb['rating']); ?>
                <span style="font-size:13px;color:#64748b;margin-left:6px;"><?php echo $fb['rating']; ?>/5</span>
            </div>
            <div class="fb-card-comments"><?php echo nl2br(htmlspecialchars($fb['comments'])); ?></div>
            <div class="fb-card-submitted">Submitted: <?php echo htmlspecialchars($fb['created_at']); ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php elseif ($user_role === 'admin'): ?>
        <!-- ─── ADMIN VIEW: All Feedback ─── -->

        <!-- Download Toolbar -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
            <div style="font-size:14px;font-weight:600;color:#1a2b4a;">
                📊 Feedback Overview
                <?php if (!empty($allFeedback)): ?>
                <span style="font-size:12px;font-weight:400;color:#64748b;margin-left:8px;"><?php echo count($allFeedback); ?> records</span>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="../admin_feedback_download.php?action=full&format=html"
                   style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;padding:10px 20px;border-radius:10px;font-size:13.5px;font-weight:600;text-decoration:none;box-shadow:0 4px 14px rgba(22,163,74,0.3);transition:all 0.2s;"
                   onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
                    ⬇ HTML Report
                </a>
                <a href="../admin_feedback_download.php?action=full&format=excel"
                   style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;padding:10px 20px;border-radius:10px;font-size:13.5px;font-weight:600;text-decoration:none;box-shadow:0 4px 14px rgba(37,99,235,0.3);transition:all 0.2s;"
                   onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
                    📊 Excel Report
                </a>
            </div>
        </div>

        <!-- Individual Mentee Download Panel -->
        <?php if (!empty($menteesWithFeedback)): ?>
        <div style="background:#fff;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,0.06);margin-bottom:24px;overflow:hidden;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 22px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                <span style="font-size:14px;font-weight:600;color:#1a2b4a;">📥 Individual Mentee Reports</span>
                <span style="font-size:12px;color:#94a3b8;"><?php echo count($menteesWithFeedback); ?> mentee(s)</span>
            </div>
            <div style="padding:16px 22px;">
                <div style="display:flex;flex-wrap:wrap;gap:10px;">
                    <?php foreach ($menteesWithFeedback as $mtee): ?>
                    <a href="../admin_feedback_download.php?action=individual&mentee_id=<?php echo (int)$mtee['mentee_id']; ?>&format=html"
                       style="display:inline-flex;align-items:center;gap:7px;background:#f0f4ff;color:#2563eb;border:1.5px solid #c7d2fe;padding:8px 16px;border-radius:30px;font-size:13px;font-weight:500;text-decoration:none;transition:all 0.2s;"
                       onmouseover="this.style.background='#2563eb';this.style.color='#fff'"
                       onmouseout="this.style.background='#f0f4ff';this.style.color='#2563eb'">
                        ⬇ <?php echo htmlspecialchars($mtee['mentee_name']); ?> (HTML)
                    </a>
                    <a href="../admin_feedback_download.php?action=individual&mentee_id=<?php echo (int)$mtee['mentee_id']; ?>&format=excel"
                       style="display:inline-flex;align-items:center;gap:7px;background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac;padding:8px 16px;border-radius:30px;font-size:13px;font-weight:500;text-decoration:none;transition:all 0.2s;"
                       onmouseover="this.style.background='#16a34a';this.style.color='#fff'"
                       onmouseout="this.style.background='#f0fdf4';this.style.color='#16a34a'">
                        📊 <?php echo htmlspecialchars($mtee['mentee_name']); ?> (Excel)
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($allFeedback)): ?>
        <div class="empty-state">
            <div class="es-icon">📊</div>
            <h3>No Feedback In System Yet</h3>
            <p>All submitted feedback will appear here once mentees start submitting.</p>
        </div>
        <?php else: ?>
        <div class="section-heading">All Feedback (<?php echo count($allFeedback); ?> records)</div>
        <?php foreach ($allFeedback as $fb): ?>
        <div class="fb-card" style="border-left-color:#2980b9;">
            <div class="fb-card-header">
                <div class="fb-card-name">
                    <strong>Mentor:</strong> <?php echo htmlspecialchars($fb['mentor_name']); ?>
                    <?php if (!empty($fb['mentee_name'])): ?>
                    &nbsp;|&nbsp; <strong>Mentee:</strong> <?php echo htmlspecialchars($fb['mentee_name']); ?>
                    <?php endif; ?>
                </div>
                <div class="fb-card-date">📅 <?php echo htmlspecialchars($fb['session_date']); ?></div>
            </div>
            <div class="fb-card-stars">
                <?php echo str_repeat("★", $fb['rating']) . str_repeat("☆", 5 - $fb['rating']); ?>
                <span style="font-size:13px;color:#64748b;margin-left:6px;"><?php echo $fb['rating']; ?>/5</span>
            </div>
            <div class="fb-card-comments"><?php echo nl2br(htmlspecialchars($fb['comments'])); ?></div>
            <div class="fb-card-submitted">Submitted: <?php echo htmlspecialchars($fb['created_at']); ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>

    </div><!-- .main -->
</div><!-- .layout -->

<!-- ============================================================
     FEEDBACK MODALS — one per pending request (mentee only)
============================================================ -->
<?php if ($user_role === 'mentee'): ?>
<?php foreach ($pendingFeedback as $req): ?>

<!-- Step 1: Feedback -->
<div id="feedbackModal_<?php echo $req['id']; ?>" class="feedback-modal">
    <div class="feedback-modal-content">
        <span class="fm-close" onclick="closeFeedbackModal(<?php echo $req['id']; ?>)">&times;</span>
        <div class="fm-header">
            <h3>Session Feedback</h3>
            <p>Session with <?php echo htmlspecialchars($req['mentor_name']); ?> — <?php echo htmlspecialchars($req['session_date']); ?></p>
            <div class="fm-steps">
                <div class="fm-step active" id="step1_<?php echo $req['id']; ?>"></div>
                <div class="fm-step" id="step2_<?php echo $req['id']; ?>"></div>
            </div>
        </div>
        <div class="fm-body">
            <div class="fm-section-label">Overall Rating</div>
            <div class="star-wrap">
                <div class="star-rating">
                    <?php for ($s = 5; $s >= 1; $s--): ?>
                    <input type="radio" name="rating_<?php echo $req['id']; ?>" value="<?php echo $s; ?>" id="star<?php echo $s; ?>_<?php echo $req['id']; ?>">
                    <label for="star<?php echo $s; ?>_<?php echo $req['id']; ?>">★</label>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="fm-section-label">Session Questions</div>
            <div class="fm-question">
                <label><span class="q-num">1</span><span>What was the most valuable insight or takeaway from this session?</span></label>
                <textarea id="q1_<?php echo $req['id']; ?>" placeholder="Share what stood out most..." required></textarea>
            </div>
            <div class="fm-question">
                <label><span class="q-num">2</span><span>Did the mentor address your goals or concerns effectively?</span></label>
                <textarea id="q2_<?php echo $req['id']; ?>" placeholder="Describe how your goals were addressed..." required></textarea>
            </div>
            <div class="fm-question">
                <label><span class="q-num">3</span><span>How comfortable did you feel sharing and discussing your challenges?</span></label>
                <textarea id="q3_<?php echo $req['id']; ?>" placeholder="Describe your comfort level..." required></textarea>
            </div>
            <div class="fm-question">
                <label><span class="q-num">4</span><span>What could have made this session more impactful?</span></label>
                <textarea id="q4_<?php echo $req['id']; ?>" placeholder="Suggestions for improvement..." required></textarea>
            </div>
            <div class="fm-section-label">Additional Comments</div>
            <div class="fm-question">
                <label><span style="font-size:13px;color:#64748b;">Any other thoughts?</span></label>
                <textarea id="fb_comment_<?php echo $req['id']; ?>" placeholder="Optional additional comments..."></textarea>
            </div>
            <div class="fm-footer">
                <button type="button" class="fm-btn-back" onclick="closeFeedbackModal(<?php echo $req['id']; ?>)">Cancel</button>
                <button type="button" class="fm-btn-next" onclick="goToFeedforward(<?php echo $req['id']; ?>)">Next: Feedforward →</button>
            </div>
        </div>
    </div>
</div>

<!-- Step 2: Feedforward -->
<div id="feedforwardModal_<?php echo $req['id']; ?>" class="feedback-modal">
    <div class="feedback-modal-content">
        <span class="fm-close" onclick="closeFeedforwardModal(<?php echo $req['id']; ?>)">&times;</span>
        <div class="fm-header">
            <h3>Feedforward</h3>
            <p>Plan your next steps and set intentions</p>
            <div class="fm-steps">
                <div class="fm-step" id="ff_step1_<?php echo $req['id']; ?>"></div>
                <div class="fm-step active" id="ff_step2_<?php echo $req['id']; ?>"></div>
            </div>
        </div>
        <div class="fm-body">
            <form method="POST">
                <input type="hidden" name="submit_feedback"  value="1">
                <input type="hidden" name="mentor_id"        value="<?php echo $req['mentor_id']; ?>">
                <input type="hidden" name="session_date"     value="<?php echo $req['session_date']; ?>">
                <input type="hidden" name="rating"           id="ff_rating_<?php echo $req['id']; ?>">
                <input type="hidden" name="q1"               id="ff_q1_<?php echo $req['id']; ?>">
                <input type="hidden" name="q2"               id="ff_q2_<?php echo $req['id']; ?>">
                <input type="hidden" name="q3"               id="ff_q3_<?php echo $req['id']; ?>">
                <input type="hidden" name="q4"               id="ff_q4_<?php echo $req['id']; ?>">
                <input type="hidden" name="feedback_comment" id="ff_fbcomment_<?php echo $req['id']; ?>">
                <?php echo Security::csrfField(); ?>
                <div class="fm-section-label">Your Next Steps</div>
                <div class="fm-question">
                    <label><span class="q-num">1</span><span>What will you focus on before your next session?</span></label>
                    <textarea name="next_focus" placeholder="Describe your key focus area..." required></textarea>
                </div>
                <div class="fm-question">
                    <label><span class="q-num">2</span><span>What specific actions will you take?</span></label>
                    <textarea name="actions" placeholder="List your concrete action steps..." required></textarea>
                </div>
                <div class="fm-question">
                    <label><span class="q-num">3</span><span>What support do you need from your mentor?</span></label>
                    <textarea name="support" placeholder="Describe the support you need..." required></textarea>
                </div>
                <div class="fm-section-label">Additional Notes for Mentor</div>
                <div class="fm-question">
                    <label><span style="font-size:13px;color:#64748b;">Anything specific you'd like your mentor to know?</span></label>
                    <textarea name="feedforward_comment" placeholder="Optional notes for your mentor..."></textarea>
                </div>
                <div class="fm-footer">
                    <button type="button" class="fm-btn-back" onclick="backToFeedback(<?php echo $req['id']; ?>)">← Back</button>
                    <button type="submit" class="fm-btn-submit">✓ Submit Feedback</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endforeach; ?>
<?php endif; ?>

<script>
/* ─── Tabs ─── */
function showTab(name) {
    document.querySelectorAll('.fb-tab-content').forEach(function(el) { el.classList.remove('active'); });
    document.querySelectorAll('.fb-tab').forEach(function(el) { el.classList.remove('active'); });
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
}

/* ─── Feedback Modal ─── */
function openFeedbackModal(id) {
    document.getElementById('feedbackModal_' + id).style.display = 'flex';
    document.body.classList.add('modal-open');
}
function closeFeedbackModal(id) {
    document.getElementById('feedbackModal_' + id).style.display = 'none';
    document.body.classList.remove('modal-open');
}
function goToFeedforward(id) {
    var rating = document.querySelector('input[name="rating_' + id + '"]:checked');
    var q1 = document.getElementById('q1_' + id).value.trim();
    var q2 = document.getElementById('q2_' + id).value.trim();
    var q3 = document.getElementById('q3_' + id).value.trim();
    var q4 = document.getElementById('q4_' + id).value.trim();
    if (!rating || !q1 || !q2 || !q3 || !q4) {
        alert('Please fill all required fields and select a star rating.');
        return;
    }
    document.getElementById('ff_rating_' + id).value = rating.value;
    document.getElementById('ff_q1_' + id).value = q1;
    document.getElementById('ff_q2_' + id).value = q2;
    document.getElementById('ff_q3_' + id).value = q3;
    document.getElementById('ff_q4_' + id).value = q4;
    var fbComment = document.getElementById('fb_comment_' + id);
    if (fbComment) document.getElementById('ff_fbcomment_' + id).value = fbComment.value;
    document.getElementById('feedbackModal_' + id).style.display = 'none';
    document.getElementById('feedforwardModal_' + id).style.display = 'flex';
}
function backToFeedback(id) {
    document.getElementById('feedforwardModal_' + id).style.display = 'none';
    document.getElementById('feedbackModal_' + id).style.display = 'flex';
}
function closeFeedforwardModal(id) {
    document.getElementById('feedforwardModal_' + id).style.display = 'none';
    document.body.classList.remove('modal-open');
}

/* ─── Sidenav ─── */
function toggleSidenav() {
    document.getElementById('sideNav').classList.toggle('open');
    document.getElementById('sideNavOverlay').classList.toggle('active');
}
function closeSidenav() {
    document.getElementById('sideNav').classList.remove('open');
    document.getElementById('sideNavOverlay').classList.remove('active');
}

/* ─── Auto-open feedback modal when redirected from session completion popup ─── */
<?php
$fromPopup  = isset($_GET['from_popup']) && $_GET['from_popup'] == '1';
$popupReqId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
if ($fromPopup && $popupReqId > 0 && $user_role === 'mentee'):
?>
window.addEventListener('DOMContentLoaded', function() {
    // Auto-open the feedback modal for the session that just ended
    var targetId = <?php echo $popupReqId; ?>;
    var modal = document.getElementById('feedbackModal_' + targetId);
    if (modal) {
        // Ensure we are on the pending tab first
        var pendingTab = document.getElementById('tab-pending');
        var historyTab = document.getElementById('tab-history');
        if (pendingTab) { pendingTab.classList.add('active'); }
        if (historyTab) { historyTab.classList.remove('active'); }
        // Open the modal
        setTimeout(function() {
            openFeedbackModal(targetId);
        }, 400);
    } else {
        // Session may already have feedback submitted or not found — just show pending tab
        var pendingTab = document.getElementById('tab-pending');
        if (pendingTab) { pendingTab.classList.add('active'); }
    }
});
<?php endif; ?>
</script>
</body>
</html>
