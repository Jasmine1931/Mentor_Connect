<?php
// ============================================================
// pages/session_booking.php
// Dedicated Session Booking Page for Mentor, Mentee, and Admin
// Monthly Session Control: Mentee can book ONE session per month.
// After that month's session is COMPLETED → Book & Reschedule
// are DISABLED until the next calendar month.
// ============================================================

session_start();

require_once "../config/database.php";
require_once "../config/config.php";
require_once "../includes/MySessionHandler.php";
require_once "../includes/Security.php";
require_once "../includes/email_helper.php"; // TASK 5: email notifications

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
   MONTHLY SESSION CONTROL — Helper function
   Returns whether booking/rescheduling is LOCKED for a given
   mentor_request this month (i.e. a session was already
   completed this calendar month for that pairing).
============================================================ */
function isMonthlySessionLocked($db, $request_id, $mentee_id) {
    $currentYear  = date('Y');
    $currentMonth = date('m');

    // Check if a session in mentor_requests row was completed this month
    // "completed" means: session_date is in current month AND session_date < NOW()
    $stmt = $db->prepare("
        SELECT id FROM mentor_requests
        WHERE id = ?
          AND mentee_id = ?
          AND status = 'accepted'
          AND session_date IS NOT NULL
          AND YEAR(session_date)  = ?
          AND MONTH(session_date) = ?
          AND CONCAT(session_date, ' ', IFNULL(start_time, '00:00')) < NOW()
          AND session_completed = 1
    ");
    $stmt->bind_param("iiii", $request_id, $mentee_id, $currentYear, $currentMonth);
    $stmt->execute();
    $result = $stmt->get_result();
    return ($result->num_rows > 0);
}

/* ============================================================
   HANDLE: BOOK SESSION (Mentee only)
============================================================ */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['book_session'])) {
    Security::csrfVerify();

    $request_id   = (int) ($_POST['request_id'] ?? 0);
    $session_date = $_POST['session_date'] ?? '';
    $start_time   = $_POST['start_time']   ?? '';
    $duration     = 60;

    if ($request_id > 0
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date)
        && preg_match('/^\d{2}:\d{2}$/', $start_time)) {

        // Check monthly lock before booking
        if (!isMonthlySessionLocked($db, $request_id, $user_id)) {
            $chk = $db->prepare("SELECT id FROM mentor_requests WHERE id=? AND mentee_id=? AND status='accepted'");
            $chk->bind_param("ii", $request_id, $user_id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $stmt = $db->prepare("
                    UPDATE mentor_requests
                    SET session_date=?, start_time=?, session_duration=?,
                        rescheduled_by=NULL, reschedule_note=NULL, session_completed=0
                    WHERE id=?
                ");
                $stmt->bind_param("ssii", $session_date, $start_time, $duration, $request_id);
                $stmt->execute();
                Security::log('BOOK_SESSION', "Request #{$request_id} booked for {$session_date} {$start_time}", $db);
                $_SESSION['flash_success'] = "Session booked successfully for {$session_date} at {$start_time}!";
                // TASK 5: Email both mentor and mentee
                sendSessionEmail($db, $request_id, $session_date, $start_time, 'booked');
            }
        } else {
            $_SESSION['flash_error'] = "You have already completed a session this month. Booking opens again next month.";
        }
    }
    header('Location: session_booking.php');
    exit();
}

/* ============================================================
   HANDLE: MENTOR RESCHEDULE
============================================================ */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mentor_reschedule'])) {
    Security::csrfVerify();

    $request_id   = (int) ($_POST['request_id']   ?? 0);
    $new_date     = $_POST['new_session_date']      ?? '';
    $new_time     = $_POST['new_start_time']        ?? '';
    $new_duration = 60;

    if ($request_id > 0
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)
        && preg_match('/^\d{2}:\d{2}$/', $new_time)) {

        $chk = $db->prepare("SELECT id, mentee_id FROM mentor_requests WHERE id=? AND mentor_id=? AND status='accepted'");
        $chk->bind_param("ii", $request_id, $user_id);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        if ($row) {
            // Check monthly lock for the mentee of this request
            if (!isMonthlySessionLocked($db, $request_id, $row['mentee_id'])) {
                $upd = $db->prepare("
                    UPDATE mentor_requests
                    SET session_date=?, start_time=?, session_duration=?,
                        rescheduled_by='mentor', reschedule_note='Rescheduled by Mentor'
                    WHERE id=?
                ");
                $upd->bind_param("ssii", $new_date, $new_time, $new_duration, $request_id);
                $upd->execute();
                Security::log('RESCHEDULE', "Mentor rescheduled request #{$request_id}", $db);
                $_SESSION['flash_success'] = "Session rescheduled to {$new_date} at {$new_time}.";
                // TASK 5: Email both mentor and mentee
                sendSessionEmail($db, $request_id, $new_date, $new_time, 'rescheduled_mentor');
            } else {
                $_SESSION['flash_error'] = "Session for this month is already completed. Rescheduling is locked until next month.";
            }
        }
    }
    header('Location: session_booking.php');
    exit();
}

/* ============================================================
   HANDLE: MENTEE RESCHEDULE
============================================================ */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mentee_reschedule'])) {
    Security::csrfVerify();

    $request_id   = (int) ($_POST['request_id']   ?? 0);
    $new_date     = $_POST['new_session_date']      ?? '';
    $new_time     = $_POST['new_start_time']        ?? '';
    $new_duration = 60;

    if ($request_id > 0
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)
        && preg_match('/^\d{2}:\d{2}$/', $new_time)) {

        // Check monthly lock
        if (!isMonthlySessionLocked($db, $request_id, $user_id)) {
            $chk = $db->prepare("SELECT id FROM mentor_requests WHERE id=? AND mentee_id=? AND status='accepted'");
            $chk->bind_param("ii", $request_id, $user_id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $upd = $db->prepare("
                    UPDATE mentor_requests
                    SET session_date=?, start_time=?, session_duration=?,
                        rescheduled_by='mentee', reschedule_note='Rescheduled by Mentee'
                    WHERE id=?
                ");
                $upd->bind_param("ssii", $new_date, $new_time, $new_duration, $request_id);
                $upd->execute();
                Security::log('RESCHEDULE', "Mentee rescheduled request #{$request_id}", $db);
                $_SESSION['flash_success'] = "Session rescheduled to {$new_date} at {$new_time}.";
                // TASK 5: Email both mentor and mentee
                sendSessionEmail($db, $request_id, $new_date, $new_time, 'rescheduled_mentee');
            }
        } else {
            $_SESSION['flash_error'] = "You have already completed a session this month. Rescheduling is locked until next month.";
        }
    }
    header('Location: session_booking.php');
    exit();
}

/* ============================================================
   HANDLE: MARK SESSION COMPLETE (Mentor marks session done)
============================================================ */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mark_complete'])) {
    Security::csrfVerify();
    $request_id = (int) ($_POST['request_id'] ?? 0);

    if ($request_id > 0) {
        // Only mentor can mark complete
        if ($user_role === 'mentor') {
            $stmt = $db->prepare("
                UPDATE mentor_requests SET session_completed=1
                WHERE id=? AND mentor_id=? AND status='accepted'
            ");
            $stmt->bind_param("ii", $request_id, $user_id);
            $stmt->execute();
            Security::log('COMPLETE_SESSION', "Mentor marked request #{$request_id} as completed", $db);
            $_SESSION['flash_success'] = "Session marked as completed.";
        }
    }
    header('Location: session_booking.php');
    exit();
}

/* ============================================================
   FETCH REQUESTS BASED ON ROLE
============================================================ */
if ($user_role === 'admin') {
    $requests = $db->query("
        SELECT mr.*,
               m.name AS mentor_name,
               t.name AS mentee_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        JOIN users t ON mr.mentee_id = t.id
        WHERE mr.status = 'accepted'
        ORDER BY mr.created_at DESC
    ");
} elseif ($user_role === 'mentor') {
    $stmt = $db->prepare("
        SELECT mr.*,
               m.name AS mentor_name,
               t.name AS mentee_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        JOIN users t ON mr.mentee_id = t.id
        WHERE mr.mentor_id=? AND mr.status='accepted'
        ORDER BY mr.created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $requests = $stmt->get_result();
} else {
    // mentee
    $stmt = $db->prepare("
        SELECT mr.*,
               m.name AS mentor_name,
               t.name AS mentee_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        JOIN users t ON mr.mentee_id = t.id
        WHERE mr.mentee_id=? AND mr.status='accepted'
        ORDER BY mr.created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $requests = $stmt->get_result();
}

$current_page = 'session_booking';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Booking — Mentor Connect</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ── Page-specific styles ── */
        .page-hero {
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            border-radius: 18px;
            padding: 28px 32px;
            margin-bottom: 30px;
            color: white;
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .page-hero-icon {
            width: 54px;
            height: 54px;
            background: rgba(109,213,250,0.2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .page-hero h1 { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
        .page-hero p  { font-size: 13px; opacity: 0.75; margin: 0; }

        /* Monthly lock banner */
        .monthly-lock-banner {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            background: #fef3c7;
            border: 1.5px solid #fbbf24;
            border-left: 5px solid #f59e0b;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 16px;
        }
        .monthly-lock-banner .mlb-icon { font-size: 22px; flex-shrink: 0; }
        .monthly-lock-banner .mlb-body { font-size: 13.5px; color: #78350f; line-height: 1.6; }
        .monthly-lock-banner .mlb-body strong { color: #92400e; font-weight: 700; }

        /* Monthly unlock badge */
        .monthly-unlock-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #dcfce7;
            color: #166534;
            border: 1.5px solid #86efac;
            border-radius: 20px;
            padding: 5px 14px;
            font-size: 12.5px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        /* Disabled button state */
        .btn-disabled {
            background: #e2e8f0 !important;
            color: #94a3b8 !important;
            cursor: not-allowed !important;
            pointer-events: none;
            opacity: 0.75;
        }

        /* Complete session button */
        .btn-complete {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 9px 20px;
            border: none;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            font-family: "Poppins", sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-complete:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(16,185,129,0.35);
        }

        /* Completed badge */
        .session-completed-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #d1fae5;
            color: #065f46;
            border: 1.5px solid #6ee7b7;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #fff;
            border-radius: 18px;
            border: 1.5px dashed #e2e8f0;
        }
        .empty-state .es-icon { font-size: 48px; margin-bottom: 16px; }
        .empty-state h3 { font-size: 18px; color: #1a2b4a; margin-bottom: 8px; }
        .empty-state p  { font-size: 14px; color: #64748b; }

        /* Admin table view */
        .admin-sessions-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }
        .admin-sessions-table th {
            background: #f8fafc;
            color: #1a2b4a;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 14px 20px;
            text-align: left;
            border-bottom: 2px solid #e8ecf4;
        }
        .admin-sessions-table td {
            padding: 14px 20px;
            font-size: 14px;
            color: #475569;
            border-bottom: 1px solid #f0f4f8;
            vertical-align: middle;
        }
        .admin-sessions-table tr:last-child td { border-bottom: none; }
        .admin-sessions-table tr:hover td { background: #fafbff; }

        .no-session-text { font-style: italic; color: #94a3b8; font-size: 13px; }

        /* Reschedule styles (reused from dashboard) */
        .reschedule-banner { display:flex; align-items:flex-start; gap:10px; background:#fffbeb; border:1px solid #fcd34d; border-left:4px solid #f59e0b; border-radius:10px; padding:12px 16px; margin:12px 0 4px; font-size:13px; color:#78350f; }
        .reschedule-banner .rb-icon { font-size:17px; flex-shrink:0; margin-top:1px; }
        .btn-reschedule { background:linear-gradient(135deg,#f59e0b,#fbbf24); color:white; padding:9px 20px; border:none; border-radius:8px; font-size:13.5px; font-weight:500; cursor:pointer; font-family:"Poppins",sans-serif; display:inline-flex; align-items:center; gap:6px; transition:transform 0.2s,box-shadow 0.2s; }
        .btn-reschedule:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(245,158,11,0.38); }
        .reschedule-panel { display:none; background:#fffdf5; border:1.5px solid #fcd34d; border-radius:12px; padding:20px 22px; margin-top:14px; animation:rsSlideDown 0.22s ease; }
        .reschedule-panel.open { display:block; }
        @keyframes rsSlideDown { from{opacity:0;transform:translateY(-10px);} to{opacity:1;transform:translateY(0);} }
        .reschedule-panel h5 { font-size:13px; font-weight:700; color:#92400e; margin:0 0 16px; display:flex; align-items:center; gap:6px; }
        .duration-locked-note { display:inline-flex; align-items:center; gap:6px; background:#f0f4ff; color:#3730a3; border:1px solid #c7d2fe; border-radius:8px; padding:8px 14px; font-size:12px; font-weight:500; margin-top:4px; }
        .btn-reschedule-submit { background:linear-gradient(135deg,#f59e0b,#d97706); color:white; padding:10px 24px; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; font-family:"Poppins",sans-serif; transition:transform 0.2s,box-shadow 0.2s; }
        .btn-reschedule-submit:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(217,119,6,0.38); }
        .btn-reschedule-cancel { background:#f1f5f9; color:#64748b; padding:10px 18px; border:none; border-radius:8px; font-size:13px; cursor:pointer; font-family:"Poppins",sans-serif; }
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
                <h2>Session Booking</h2>
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
        <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="flash-error">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
        </div>
        <?php endif; ?>

        <!-- Page Hero -->
        <div class="page-hero">
            <div class="page-hero-icon">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#6dd5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                    <line x1="8" y1="14" x2="8" y2="14" stroke-width="3"/><line x1="12" y1="14" x2="12" y2="14" stroke-width="3"/>
                    <line x1="8" y1="18" x2="8" y2="18" stroke-width="3"/><line x1="12" y1="18" x2="12" y2="18" stroke-width="3"/>
                </svg>
            </div>
            <div>
                <h1>Session Booking</h1>
                <p>
                    <?php if ($user_role === 'mentee'): ?>
                        Book one session per month with your mentor. Booking and rescheduling are automatically disabled after your monthly session is completed.
                    <?php elseif ($user_role === 'mentor'): ?>
                        View and manage sessions booked by your mentees. You can reschedule if needed.
                    <?php else: ?>
                        View all booked sessions across all mentor-mentee pairs.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php
        $requestsArr = [];
        while ($req = $requests->fetch_assoc()) {
            $requestsArr[] = $req;
        }

        /* ============================================================
           BUILD POPUP DATA: sessions where end time has passed
           and session_completed = 0 (not yet marked done)
           Applies to mentor and mentee ONLY — NOT admin.
        ============================================================ */
        $popupSessions = [];
        if ($user_role === 'mentor' || $user_role === 'mentee') :
        $now = new DateTime();
        foreach ($requestsArr as $req) {
            // Only sessions that have a date/time booked
            if (empty($req['session_date']) || empty($req['start_time'])) continue;
            // Skip already completed sessions
            if (!empty($req['session_completed']) && $req['session_completed'] == 1) continue;

            $sessionStart = new DateTime($req['session_date'] . ' ' . $req['start_time']);
            $sessionEnd   = clone $sessionStart;
            $sessionEnd->modify('+' . ((int)($req['session_duration'] ?: 60)) . ' minutes');

            // Popup triggers only AFTER session end time
            if ($now >= $sessionEnd) {
                // Check if popup was already dismissed for this session (stored in session)
                $dismissKey = 'popup_dismissed_' . (int)$req['id'];
                if (!isset($_SESSION[$dismissKey])) {
                    $popupSessions[] = [
                        'request_id'   => (int)$req['id'],
                        'mentor_name'  => $req['mentor_name'],
                        'mentee_name'  => $req['mentee_name'],
                        'session_date' => $req['session_date'],
                        'start_time'   => $req['start_time'],
                        'session_end'  => $sessionEnd->format('Y-m-d H:i'),
                    ];
                }
            }
        }
        endif; // end: only mentor/mentee build popupSessions
        ?>

        <?php if (empty($requestsArr)): ?>
        <div class="empty-state">
            <div class="es-icon">📅</div>
            <h3>No Active Pairings Found</h3>
            <p>
                <?php if ($user_role === 'mentee'): ?>
                    You don't have any accepted mentors yet. Go to your dashboard to connect with a mentor.
                <?php elseif ($user_role === 'mentor'): ?>
                    You don't have any active mentees yet.
                <?php else: ?>
                    No accepted mentorship pairings exist in the system yet.
                <?php endif; ?>
            </p>
        </div>

        <?php elseif ($user_role === 'admin'): ?>
        <!-- ─── ADMIN: Table View ─── -->
        <div class="section-heading">All Session Bookings</div>
        <div style="overflow-x:auto;">
        <table class="admin-sessions-table">
            <thead>
                <tr>
                    <th>Mentor</th>
                    <th>Mentee</th>
                    <th>Session Date</th>
                    <th>Start Time</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Completed</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requestsArr as $req): ?>
                <?php
                    $isCompleted = !empty($req['session_completed']) && $req['session_completed'] == 1;
                    $hasSession  = !empty($req['session_date']);
                    $currentMonth = date('Y-m');
                    $sessionMonth = $hasSession ? substr($req['session_date'], 0, 7) : '';
                    $lockedThisMonth = ($isCompleted && $sessionMonth === $currentMonth);
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($req['mentor_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($req['mentee_name']); ?></td>
                    <td><?php echo $hasSession ? htmlspecialchars($req['session_date']) : '<span class="no-session-text">Not booked</span>'; ?></td>
                    <td><?php echo $hasSession ? htmlspecialchars($req['start_time']) : '—'; ?></td>
                    <td><?php echo $hasSession ? ((int)$req['session_duration'] ?: 60) . ' min' : '—'; ?></td>
                    <td>
                        <?php if ($lockedThisMonth): ?>
                            <span class="status-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">🔒 Locked (This Month)</span>
                        <?php elseif ($isCompleted): ?>
                            <span class="status-badge accepted">✅ Completed</span>
                        <?php elseif ($hasSession): ?>
                            <span class="status-badge" style="background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;">📅 Scheduled</span>
                        <?php else: ?>
                            <span class="status-badge pending">⏳ Pending Booking</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $isCompleted ? '<span style="color:#059669;font-weight:600;">Yes</span>' : '<span style="color:#94a3b8;">No</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php else: ?>
        <!-- ─── MENTOR / MENTEE: Card View ─── -->
        <div class="section-heading">
            <?php echo ($user_role === 'mentor') ? 'Your Mentee Sessions' : 'Your Booked Sessions'; ?>
        </div>

        <?php foreach ($requestsArr as $req): ?>
        <?php
            $isCompleted  = !empty($req['session_completed']) && $req['session_completed'] == 1;
            $hasSession   = !empty($req['session_date']);
            $currentMonth = date('Y-m');
            $sessionMonth = $hasSession ? substr($req['session_date'], 0, 7) : '';

            // Monthly lock: only active if session was completed THIS month
            $monthlyLocked = ($isCompleted && $sessionMonth === $currentMonth);

            // ── MONTHLY RESET: Allow new booking when month has changed ──
            // If the last session was completed in a PREVIOUS month, the mentee
            // can book a fresh session for the current month.
            // canBookNewSession = true when: session was completed, but in a past month.
            $canBookNewSession = ($isCompleted && $hasSession && $sessionMonth !== $currentMonth);

            // For mentor: check mentee's lock
            $menteeMonthlyLocked = false;
            if ($user_role === 'mentor' && $hasSession) {
                $menteeMonthlyLocked = isMonthlySessionLocked($db, (int)$req['id'], (int)$req['mentee_id']);
            }

            // Past session (datetime elapsed)?
            $sessionStart = null; $sessionEnd = null;
            if ($hasSession && !$canBookNewSession) {
                $sessionStart = new DateTime($req['session_date'] . ' ' . ($req['start_time'] ?? '00:00'));
                $sessionEnd   = clone $sessionStart;
                $sessionEnd->modify('+' . ((int)($req['session_duration'] ?: 60)) . ' minutes');
            }
            $now = new DateTime();
            $isPast = $sessionEnd && ($now >= $sessionEnd);
        ?>
        <div class="request-card">
            <div class="req-names">
                <div class="req-name-item"><strong>Mentor:</strong> <?php echo htmlspecialchars($req['mentor_name']); ?></div>
                <div class="req-name-item"><strong>Mentee:</strong> <?php echo htmlspecialchars($req['mentee_name']); ?></div>
            </div>

            <!-- Monthly lock status -->
            <?php if ($monthlyLocked): ?>
            <div class="monthly-lock-banner">
                <div class="mlb-icon">🔒</div>
                <div class="mlb-body">
                    <strong>Monthly Session Completed.</strong>
                    Book &amp; Reschedule are disabled for <?php echo date('F Y'); ?>.
                    They will automatically re-enable on <strong><?php echo date('F', strtotime('first day of next month')); ?> 1</strong>.
                </div>
            </div>
            <?php elseif ($canBookNewSession): ?>
            <div class="monthly-unlock-badge" style="background:#dcfce7;color:#166534;border-color:#86efac;">
                🗓️ New month — Booking reset for <?php echo date('F Y'); ?>
            </div>
            <?php else: ?>
            <div class="monthly-unlock-badge">
                ✅ Booking available for <?php echo date('F Y'); ?>
            </div>
            <?php endif; ?>

            <?php if (!$hasSession || $canBookNewSession): ?>
                <!-- No session booked yet OR new month (prev session done last month) -->
                <?php if ($user_role === 'mentee'): ?>
                <?php if ($canBookNewSession): ?>
                <div style="display:flex;align-items:center;gap:10px;background:#ecfdf5;border:1.5px solid #6ee7b7;border-radius:10px;padding:12px 16px;margin-bottom:14px;">
                    <span style="font-size:20px;">🗓️</span>
                    <div style="font-size:13px;color:#065f46;">
                        <strong>New month — booking open!</strong>
                        Your session for <?php echo htmlspecialchars(date('F', strtotime($req['session_date'].' 00:00:00'))); ?>
                        was completed. Book your <strong><?php echo date('F Y'); ?></strong> session below.
                    </div>
                </div>
                <?php endif; ?>
                <div class="book-form" <?php echo $monthlyLocked ? 'style="opacity:0.55;pointer-events:none;"' : ''; ?>>
                    <h4>📅 Book Your Session for <?php echo date('F Y'); ?></h4>
                    <?php if (!$monthlyLocked): ?>
                    <form method="POST">
                        <?php echo Security::csrfField(); ?>
                        <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                        <div class="book-form-fields">
                            <div class="book-form-field">
                                <label>Date</label>
                                <input type="date" name="session_date"
                                       min="<?php echo date('Y-m-d'); ?>"
                                       required>
                            </div>
                            <div class="book-form-field">
                                <label>Start Time</label>
                                <input type="time" name="start_time" required>
                            </div>
                            <div class="book-form-field">
                                <label>Duration</label>
                                <input type="text" value="60 minutes (fixed)" readonly
                                       style="background:#f0f4f8;color:#555;cursor:not-allowed;">
                            </div>
                            <button type="submit" name="book_session" class="btn-book">📅 Book Session</button>
                        </div>
                    </form>
                    <?php else: ?>
                    <p style="color:#92400e;font-size:13px;">🔒 Booking locked for this month.</p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p style="color:#94a3b8;font-size:14px;font-style:italic;">⏳ Waiting for mentee to book a session...</p>
                <?php endif; ?>

            <?php else: ?>
                <!-- Session is booked -->
                <?php if (!empty($req['rescheduled_by'])): ?>
                <div class="reschedule-banner">
                    <div class="rb-icon">🔄</div>
                    <div class="rb-text">
                        <strong>Rescheduled</strong> by <?php echo ucfirst(htmlspecialchars($req['rescheduled_by'])); ?>.
                        <?php if (!empty($req['reschedule_note'])): ?>&nbsp;<?php echo htmlspecialchars($req['reschedule_note']); ?><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="session-info-block">
                    <span>📅 <strong><?php echo htmlspecialchars($req['session_date'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
                    <span>⏰ <?php echo htmlspecialchars($req['start_time'] ?? '--:--', ENT_QUOTES, 'UTF-8'); ?></span>
                    <span>⏳ <?php echo (int)($req['session_duration'] ?: 60); ?> minutes</span>
                </div>

                <?php if ($sessionEnd): ?>
                <div class="session-end-tag">Session ends: <?php echo $sessionEnd->format("Y-m-d H:i"); ?></div>
                <?php endif; ?>

                <!-- Completed badge -->
                <?php if ($isCompleted): ?>
                <div style="margin: 10px 0;">
                    <span class="session-completed-badge">✅ Session Completed</span>
                    <?php if ($monthlyLocked): ?>
                    <span style="font-size:12px;color:#92400e;margin-left:10px;">🔒 Next booking opens <?php echo date('F 1', strtotime('first day of next month')); ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="req-actions">

                    <!-- Chat button -->
                    <?php $chat_uid = ($user_role === 'mentor') ? $req['mentee_id'] : $req['mentor_id']; ?>
                    <a href="../chat.php?user_id=<?php echo Security::encryptId((int)$chat_uid, 'user'); ?>" class="btn-chat">
                        💬 Open Chat
                    </a>

                    <!-- MENTOR: Mark as Complete -->
                    <?php if ($user_role === 'mentor' && !$isCompleted && $isPast): ?>
                    <form method="POST" style="display:inline;">
                        <?php echo Security::csrfField(); ?>
                        <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                        <button type="submit" name="mark_complete" class="btn-complete"
                                onclick="return confirm('Mark this session as completed? Booking for this month will be locked after this.')">
                            ✅ Mark Complete
                        </button>
                    </form>
                    <?php endif; ?>

                    <!-- MENTOR: Reschedule button -->
                    <?php if ($user_role === 'mentor' && $req['mentor_id'] == $user_id && !$menteeMonthlyLocked && !$isCompleted): ?>
                    <button class="btn-reschedule"
                            onclick="toggleReschedulePanel('mentor-rp-<?php echo $req['id']; ?>')">
                        🔄 Reschedule
                    </button>
                    <?php elseif ($user_role === 'mentor' && ($menteeMonthlyLocked || $isCompleted)): ?>
                    <button class="btn-reschedule btn-disabled" disabled title="Session completed this month — reschedule locked">
                        🔒 Reschedule Locked
                    </button>
                    <?php endif; ?>

                    <!-- MENTEE: Reschedule button -->
                    <?php if ($user_role === 'mentee' && $req['mentee_id'] == $user_id && !$monthlyLocked && !$isCompleted): ?>
                    <button class="btn-reschedule"
                            onclick="toggleReschedulePanel('mentee-rp-<?php echo $req['id']; ?>')">
                        🔄 Reschedule
                    </button>
                    <?php elseif ($user_role === 'mentee' && ($monthlyLocked || $isCompleted)): ?>
                    <button class="btn-reschedule btn-disabled" disabled title="Session completed this month — reschedule locked">
                        🔒 Reschedule Locked
                    </button>
                    <?php endif; ?>

                </div>

                <!-- MENTOR RESCHEDULE PANEL -->
                <?php if ($user_role === 'mentor' && $req['mentor_id'] == $user_id && !$menteeMonthlyLocked && !$isCompleted): ?>
                <div id="mentor-rp-<?php echo (int)$req['id']; ?>" class="reschedule-panel">
                    <h5>🗓 Reschedule Session <span style="font-weight:400;color:#a16207;">(Mentor)</span></h5>
                    <form method="POST">
                        <?php echo Security::csrfField(); ?>
                        <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                        <div class="book-form-fields">
                            <div class="book-form-field">
                                <label>New Date</label>
                                <input type="date" name="new_session_date"
                                       value="<?php echo htmlspecialchars($req['session_date']); ?>"
                                       min="<?php echo date('Y-m-d'); ?>"
                                       required>
                            </div>
                            <div class="book-form-field">
                                <label>New Start Time</label>
                                <input type="time" name="new_start_time"
                                       value="<?php echo htmlspecialchars($req['start_time'] ?? ''); ?>"
                                       required>
                            </div>
                            <div class="book-form-field">
                                <label>Duration</label>
                                <div class="duration-locked-note">🔒 60 minutes (fixed)</div>
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;margin-top:16px;">
                            <button type="submit" name="mentor_reschedule" class="btn-reschedule-submit">✓ Confirm Reschedule</button>
                            <button type="button" class="btn-reschedule-cancel"
                                    onclick="toggleReschedulePanel('mentor-rp-<?php echo $req['id']; ?>')">Cancel</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- MENTEE RESCHEDULE PANEL -->
                <?php if ($user_role === 'mentee' && $req['mentee_id'] == $user_id && !$monthlyLocked && !$isCompleted): ?>
                <div id="mentee-rp-<?php echo (int)$req['id']; ?>" class="reschedule-panel">
                    <h5>🗓 Reschedule Session <span style="font-weight:400;color:#a16207;">(Mentee)</span></h5>
                    <form method="POST">
                        <?php echo Security::csrfField(); ?>
                        <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                        <div class="book-form-fields">
                            <div class="book-form-field">
                                <label>New Date</label>
                                <input type="date" name="new_session_date"
                                       value="<?php echo htmlspecialchars($req['session_date']); ?>"
                                       min="<?php echo date('Y-m-d'); ?>"
                                       required>
                            </div>
                            <div class="book-form-field">
                                <label>New Start Time</label>
                                <input type="time" name="new_start_time"
                                       value="<?php echo htmlspecialchars($req['start_time'] ?? ''); ?>"
                                       required>
                            </div>
                            <div class="book-form-field">
                                <label>Duration</label>
                                <div class="duration-locked-note">🔒 60 minutes (fixed)</div>
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;margin-top:16px;">
                            <button type="submit" name="mentee_reschedule" class="btn-reschedule-submit">✓ Confirm Reschedule</button>
                            <button type="button" class="btn-reschedule-cancel"
                                    onclick="toggleReschedulePanel('mentee-rp-<?php echo $req['id']; ?>')">Cancel</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

            <?php endif; // hasSession ?>

        </div><!-- .request-card -->
        <?php endforeach; ?>
        <?php endif; // empty check ?>

    </div><!-- .main -->
</div><!-- .layout -->

<!-- ============================================================
     SESSION COMPLETION POPUP MODAL
     Shown to mentor and mentee ONLY — NOT admin.
============================================================ -->
<?php if (($user_role === 'mentor' || $user_role === 'mentee') && !empty($popupSessions)): ?>
<style>
.sc-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.55);
    z-index: 9998; display: flex; align-items: center; justify-content: center;
    animation: scFadeIn 0.2s ease;
}
@keyframes scFadeIn { from { opacity:0; } to { opacity:1; } }
.sc-modal {
    background: #fff; border-radius: 18px; padding: 36px 32px 28px;
    max-width: 440px; width: 92%; box-shadow: 0 8px 40px rgba(0,0,0,0.22);
    text-align: center; position: relative; z-index: 9999;
    animation: scSlideUp 0.25s ease;
}
@keyframes scSlideUp { from { transform:translateY(30px); opacity:0; } to { transform:translateY(0); opacity:1; } }
.sc-modal .sc-icon { font-size: 48px; margin-bottom: 10px; }
.sc-modal h2 { font-size: 20px; font-weight: 700; color: #1e293b; margin: 0 0 8px; }
.sc-modal .sc-sub { font-size: 13.5px; color: #64748b; margin-bottom: 6px; }
.sc-modal .sc-session-info {
    background: #f1f5f9; border-radius: 10px; padding: 12px 16px;
    margin: 16px 0 24px; font-size: 13px; color: #334155; text-align: left;
    line-height: 1.8;
}
.sc-modal .sc-session-info strong { color: #0f172a; }
.sc-modal .sc-btns { display: flex; gap: 12px; justify-content: center; }
.sc-btn-yes {
    background: linear-gradient(135deg, #059669, #047857);
    color: #fff; border: none; border-radius: 10px;
    padding: 11px 32px; font-size: 15px; font-weight: 700;
    cursor: pointer; transition: transform 0.15s, box-shadow 0.15s;
}
.sc-btn-yes:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(5,150,105,0.35); }
.sc-btn-no {
    background: #f1f5f9; color: #475569; border: 1.5px solid #cbd5e1;
    border-radius: 10px; padding: 11px 32px; font-size: 15px; font-weight: 600;
    cursor: pointer; transition: background 0.15s;
}
.sc-btn-no:hover { background: #e2e8f0; }
.sc-progress { font-size: 12px; color: #94a3b8; margin-top: 16px; }
</style>

<div class="sc-overlay" id="scOverlay" style="display:none;">
    <div class="sc-modal">
        <div class="sc-icon">📋</div>
        <h2>Is the session completed?</h2>
        <p class="sc-sub">Your scheduled session time has passed.</p>
        <div class="sc-session-info" id="scSessionInfo"></div>
        <div class="sc-btns">
            <button class="sc-btn-yes" onclick="scHandleYes()">✅ Yes, Completed</button>
            <button class="sc-btn-no"  onclick="scHandleNo()">❌ No</button>
        </div>
        <div class="sc-progress" id="scProgress"></div>
    </div>
</div>

<script>
// Popup sessions injected from PHP
var scSessions = <?php echo json_encode(array_values($popupSessions), JSON_HEX_TAG); ?>;
var scIndex    = 0;
var scRole     = <?php echo json_encode($user_role); ?>;

function scShowNext() {
    if (scIndex >= scSessions.length) {
        document.getElementById('scOverlay').style.display = 'none';
        return;
    }
    var s = scSessions[scIndex];
    var info = '<strong>Mentor:</strong> ' + s.mentor_name + '<br>'
             + '<strong>Mentee:</strong> '  + s.mentee_name + '<br>'
             + '<strong>Date:</strong> '    + s.session_date + ' &nbsp;|&nbsp; '
             + '<strong>Time:</strong> '    + s.start_time + '<br>'
             + '<strong>Session ended at:</strong> ' + s.session_end;
    document.getElementById('scSessionInfo').innerHTML = info;
    if (scSessions.length > 1) {
        document.getElementById('scProgress').textContent =
            'Session ' + (scIndex + 1) + ' of ' + scSessions.length;
    } else {
        document.getElementById('scProgress').textContent = '';
    }
    document.getElementById('scOverlay').style.display = 'flex';
}

function scHandleYes() {
    var s = scSessions[scIndex];
    // Dismiss popup for this session
    scDismiss(s.request_id, function() {
        if (scRole === 'mentee') {
            // Mentee → go to feedback page to fill form
            window.location.href = '../pages/feedback.php?request_id=' + s.request_id + '&from_popup=1';
        } else if (scRole === 'mentor') {
            // Mentor → mark session complete via AJAX, then show next popup or reload
            scMarkComplete(s.request_id, function() {
                scIndex++;
                if (scIndex >= scSessions.length) {
                    // Reload page to reflect updated status
                    window.location.reload();
                } else {
                    scShowNext();
                }
            });
        } else {
            scIndex++;
            scShowNext();
        }
    });
}

function scHandleNo() {
    var s = scSessions[scIndex];
    // Dismiss popup without opening feedback
    scDismiss(s.request_id, function() {
        scIndex++;
        scShowNext();
    });
}

function scMarkComplete(requestId, callback) {
    // POST to the mark_complete_ajax endpoint
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '../pages/session_mark_complete_ajax.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() { if (callback) callback(); };
    xhr.onerror = function() { if (callback) callback(); };
    xhr.send('request_id=' + encodeURIComponent(requestId));
}

function scDismiss(requestId, callback) {
    // Tell the server to set the dismissal flag in session
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '../pages/session_popup_dismiss.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() { if (callback) callback(); };
    xhr.onerror = function() { if (callback) callback(); };
    xhr.send('request_id=' + encodeURIComponent(requestId)
           + '&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : ''));
}

// Show popup on page load after short delay
window.addEventListener('DOMContentLoaded', function() {
    if (scSessions.length > 0) {
        setTimeout(scShowNext, 700);
    }
});
</script>
<?php endif; ?>

<script>
function toggleReschedulePanel(panelId) {
    document.querySelectorAll('.reschedule-panel.open').forEach(function(p) {
        if (p.id !== panelId) p.classList.remove('open');
    });
    var panel = document.getElementById(panelId);
    if (panel) {
        panel.classList.toggle('open');
        if (panel.classList.contains('open')) {
            setTimeout(function() {
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 60);
        }
    }
}

function toggleSidenav() {
    document.getElementById('sideNav').classList.toggle('open');
    document.getElementById('sideNavOverlay').classList.toggle('active');
}
function closeSidenav() {
    document.getElementById('sideNav').classList.remove('open');
    document.getElementById('sideNavOverlay').classList.remove('active');
}
</script>
</body>
</html>
