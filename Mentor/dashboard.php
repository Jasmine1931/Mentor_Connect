<?php
// ============================================================
// dashboard.php — VAPT Fixed + Bug Fix for HTTP 500
// ============================================================
// ROOT CAUSE FIX 2 (Image 2 — HTTP 500 error):
//   Three problems in the previous version:
//   A) MySessionHandler::requireLogin() was called as STATIC
//      before the class was loaded (class_exists check came
//      AFTER the static call — too late, fatal error).
//   B) enforceOneSession() queries the `session_token` column
//      which does NOT exist in the DB until the SQL migration
//      is run. If migration hasn't run → fatal DB error → 500.
//   C) config.php was included AFTER Security.php but Security
//      needs APP_SECRET which is defined inside config.php.
//
//   Fix: correct include order, remove premature static call,
//   wrap enforceOneSession in a try/catch so a missing column
//   gives a graceful fallback instead of a white-screen 500.
// ============================================================
 
session_start();
 
// FIXED (Bug 2-C): correct load order
require_once "config/database.php";
require_once "config/config.php";          // defines APP_SECRET, security headers
require_once "includes/MySessionHandler.php"; // FIXED (Bug 2-A): load BEFORE any static call
require_once "includes/Security.php";
 
date_default_timezone_set('Asia/Kolkata');
 
// FIXED #1: Proper auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
 
$db        = (new Database())->connect();
 
// Start session handler (sets secure cookie params)
new MySessionHandler();
 
// FIXED (Bug 2-B): Wrap enforceOneSession in try/catch.
// If `session_token` column doesn't exist yet (migration not run),
// this will fail silently instead of throwing an HTTP 500.
// Run vapt_security_migration.sql in phpMyAdmin to fully enable.
try {
    MySessionHandler::enforceOneSession($db); // FIXED #8: concurrent session control
} catch (Exception $e) {
    // Column may not exist yet — log and continue
    error_log('enforceOneSession skipped: ' . $e->getMessage());
}
 
$user_id   = (int) $_SESSION['user_id'];   // FIXED #6: always int
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'];
 
/* =========================
   BOOK SESSION
========================= */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['book_session'])) {
    // FIXED #4: CSRF check
    Security::csrfVerify();
 
    $request_id   = (int) ($_POST['request_id'] ?? 0);  // FIXED #6
    $session_date = $_POST['session_date'] ?? '';
    $start_time   = $_POST['start_time']   ?? '';
    $duration     = 60;
 
    // FIXED #4: Validate date/time format
    if ($request_id > 0
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date)
        && preg_match('/^\d{2}:\d{2}$/', $start_time)) {
 
        // Monthly lock REMOVED — mentee can book freely
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
        }
    }
}

 
// ============================================================
// MENTOR RESCHEDULE
// ============================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mentor_reschedule'])) {
    Security::csrfVerify(); // FIXED #4
 
    $request_id   = (int) ($_POST['request_id']      ?? 0);
    $new_date     = $_POST['new_session_date']         ?? '';
    $new_time     = $_POST['new_start_time']           ?? '';
    $new_duration = 60;
 
    if ($request_id > 0
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)
        && preg_match('/^\d{2}:\d{2}$/', $new_time)) {
 
        $chk = $db->prepare("SELECT id FROM mentor_requests WHERE id=? AND mentor_id=? AND status='accepted'");
        $chk->bind_param("ii", $request_id, $user_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $upd = $db->prepare("
                UPDATE mentor_requests
                SET session_date=?, start_time=?, session_duration=?,
                    rescheduled_by='mentor', reschedule_note='Rescheduled by Mentor'
                WHERE id=?
            ");
            $upd->bind_param("ssii", $new_date, $new_time, $new_duration, $request_id);
            $upd->execute();
            Security::log('RESCHEDULE', "Mentor rescheduled request #{$request_id}", $db); // FIXED #11
        }
    }
}
 
// ============================================================
// MENTEE RESCHEDULE
// ============================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mentee_reschedule'])) {
    Security::csrfVerify(); // FIXED #4
 
    $request_id   = (int) ($_POST['request_id']      ?? 0);
    $new_date     = $_POST['new_session_date']         ?? '';
    $new_time     = $_POST['new_start_time']           ?? '';
    $new_duration = 60;
 
    if ($request_id > 0
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)
        && preg_match('/^\d{2}:\d{2}$/', $new_time)) {
 
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
            Security::log('RESCHEDULE', "Mentee rescheduled request #{$request_id}", $db); // FIXED #11
        }
    }
}
 
/* =========================
   SUBMIT FEEDBACK
========================= */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_feedback'])) {
    Security::csrfVerify(); // FIXED #4
 
    $mentor_id           = (int)   ($_POST['mentor_id']          ?? 0);  // FIXED #6
    $session_date        =          $_POST['session_date']        ?? '';
    $rating              = (int)   ($_POST['rating']             ?? 0);  // FIXED #6
    // FIXED #4: strip_tags on all free-text inputs
    $q1                  = strip_tags($_POST['q1']               ?? '');
    $q2                  = strip_tags($_POST['q2']               ?? '');
    $q3                  = strip_tags($_POST['q3']               ?? '');
    $q4                  = strip_tags($_POST['q4']               ?? '');
    $feedback_comment    = strip_tags($_POST['feedback_comment']    ?? '');
    $next_focus          = strip_tags($_POST['next_focus']          ?? '');
    $actions             = strip_tags($_POST['actions']             ?? '');
    $support             = strip_tags($_POST['support']             ?? '');
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
    Security::log('FEEDBACK', "Feedback submitted for mentor #{$mentor_id}", $db); // FIXED #11
}
 
/* -------------------------
   GET REQUESTS BASED ON ROLE
--------------------------*/
if ($user_role == 'admin') {
    $requests = $db->query("
        SELECT mr.*,
               m.name AS mentor_name,
               t.name AS mentee_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        JOIN users t ON mr.mentee_id = t.id
        ORDER BY mr.created_at DESC
    ");
} elseif ($user_role == 'mentor') {
    $stmt = $db->prepare("
        SELECT mr.*,
               m.name AS mentor_name,
               t.name AS mentee_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        JOIN users t ON mr.mentee_id = t.id
        WHERE mr.mentor_id=?
        ORDER BY mr.created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $requests = $stmt->get_result();
} else {
    $stmt = $db->prepare("
        SELECT mr.*,
               m.name AS mentor_name,
               t.name AS mentee_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        JOIN users t ON mr.mentee_id = t.id
        WHERE mr.mentee_id=?
        ORDER BY mr.created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $requests = $stmt->get_result();
}

/* ============================================================
   DASHBOARD: Build popup session list (separate query so the
   main $requests result set stays intact for the page loop).
   Fetches accepted sessions where:
     - session_date + start_time is set
     - session end time has already passed
     - session_completed = 0 (not yet marked done)
     - popup not yet dismissed this PHP session
============================================================ */
$dashPopupSessions = [];
if ($user_role === 'mentor' || $user_role === 'mentee') {
    $popupCol = ($user_role === 'mentor') ? 'mr.mentor_id' : 'mr.mentee_id';
    $popupStmt = $db->prepare("
        SELECT mr.id, mr.session_date, mr.start_time, mr.session_duration,
               m.name AS mentor_name, t.name AS mentee_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        JOIN users t ON mr.mentee_id = t.id
        WHERE {$popupCol} = ?
          AND mr.status = 'accepted'
          AND mr.session_date IS NOT NULL
          AND mr.start_time IS NOT NULL
          AND mr.session_completed = 0
    ");
    $popupStmt->bind_param("i", $user_id);
    $popupStmt->execute();
    $popupResult = $popupStmt->get_result();
    $now = new DateTime();
    while ($pr = $popupResult->fetch_assoc()) {
        $sessionStart = new DateTime($pr['session_date'] . ' ' . $pr['start_time']);
        $sessionEnd   = clone $sessionStart;
        $sessionEnd->modify('+' . ((int)($pr['session_duration'] ?: 60)) . ' minutes');
        if ($now >= $sessionEnd) {
            $dismissKey = 'popup_dismissed_' . (int)$pr['id'];
            if (!isset($_SESSION[$dismissKey])) {
                $dashPopupSessions[] = [
                    'request_id'   => (int)$pr['id'],
                    'mentor_name'  => $pr['mentor_name'],
                    'mentee_name'  => $pr['mentee_name'],
                    'session_date' => $pr['session_date'],
                    'start_time'   => $pr['start_time'],
                    'session_end'  => $sessionEnd->format('Y-m-d H:i'),
                ];
            }
        }
    }
}
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mentor Connect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ===== SEND REQUEST POPUP ===== */
        .popup {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.65);
            z-index: 9999;
            backdrop-filter: blur(4px);
            justify-content: center;
            align-items: center;
        }
        .popup.active { display: flex; }
        .popup-box {
            background: #fff;
            padding: 35px;
            width: 600px;
            max-width: 92%;
            border-radius: 18px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
        }
        .popup-box h3 { text-align: center; margin-bottom: 22px; font-size: 18px; color: #1a2b4a; }
        .checkbox-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
        .checkbox-grid label {
            background: #f5f7fa; padding: 10px 12px; border-radius: 8px;
            cursor: pointer; font-size: 13px; border: 1px solid #e8ecf0; transition: background 0.2s;
        }
        .checkbox-grid label:hover { background: #e8f0fe; }
        .popup-box textarea {
            width: 100%; margin-top: 10px; padding: 12px; border-radius: 8px;
            border: 1px solid #ddd; font-family: "Poppins", sans-serif;
            font-size: 14px; resize: none; height: 90px;
        }
        .popup-buttons { margin-top: 18px; display: flex; justify-content: space-between; }
        .btn-cancel { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; }
        .btn-send   { background: linear-gradient(135deg, #2980b9, #6dd5fa); color: white; padding: 10px 22px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; }
 
        /* ===== FEEDBACK MODAL ===== */
        .feedback-modal {
            display: none; position: fixed; inset: 0;
            background: rgba(15,32,39,0.75); z-index: 99999;
            justify-content: center; align-items: center; backdrop-filter: blur(6px);
        }
        .feedback-modal.active { display: flex; }
        .feedback-modal-content {
            background: #fff; width: 580px; max-width: 94%; max-height: 90vh;
            overflow-y: auto; border-radius: 20px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.2);
            animation: modalIn 0.3s cubic-bezier(0.34,1.56,0.64,1); position: relative;
        }
        @keyframes modalIn { from { transform: scale(0.88); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .fm-header { background: linear-gradient(135deg,#0f2027,#203a43,#2c5364); padding: 28px 32px 22px; border-radius: 20px 20px 0 0; color: white; }
        .fm-header h3 { font-size: 20px; font-weight: 600; margin: 0 0 4px; letter-spacing: 0.3px; }
        .fm-header p  { font-size: 13px; opacity: 0.75; margin: 0; }
        .fm-steps { display: flex; align-items: center; gap: 8px; margin-top: 16px; }
        .fm-step  { height: 4px; border-radius: 4px; flex: 1; background: rgba(255,255,255,0.25); transition: background 0.3s; }
        .fm-step.active { background: #6dd5fa; }
        .fm-body { padding: 28px 32px 32px; }
        .star-wrap { display: flex; justify-content: center; margin: 4px 0 28px; }
        .star-rating { direction: rtl; display: flex; gap: 4px; }
        .star-rating input { display: none; }
        .star-rating label { font-size: 40px; color: #ddd; cursor: pointer; transition: color 0.2s, transform 0.2s; line-height: 1; }
        .star-rating label:hover, .star-rating label:hover ~ label { color: #ffb400; transform: scale(1.15); }
        .star-rating input:checked ~ label { color: #ffb400; text-shadow: 0 0 12px rgba(255,180,0,0.5); }
        .fm-question { margin-bottom: 20px; }
        .fm-question label { display: flex; align-items: flex-start; font-size: 13px; font-weight: 600; color: #1a2b4a; margin-bottom: 8px; letter-spacing: 0.2px; }
        .fm-question label span { padding-top: 1px; }
        .fm-question .q-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px;
            background: linear-gradient(135deg,#2980b9,#6dd5fa);
            color: white; border-radius: 50%; font-size: 11px; font-weight: 700;
            margin-right: 8px; flex-shrink: 0;
        }
        .fm-question textarea {
            width: 100%; padding: 12px 14px; border-radius: 10px;
            border: 1.5px solid #e2e8f0; font-family: "Poppins", sans-serif;
            font-size: 13.5px; color: #2c3e50; resize: none; height: 90px;
            transition: border-color 0.2s, box-shadow 0.2s; background: #fafbfd;
        }
        .fm-question textarea:focus { border-color: #2980b9; box-shadow: 0 0 0 3px rgba(41,128,185,0.12); outline: none; background: #fff; }
        .fm-section-label {
            font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
            text-transform: uppercase; color: #94a3b8;
            margin: 24px 0 16px; padding-bottom: 8px; border-bottom: 1px solid #f0f4f8;
        }
        .fm-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 28px; padding-top: 20px; border-top: 1px solid #f0f4f8; }
        .fm-btn-back { background: #f1f5f9; color: #64748b; padding: 11px 22px; border: none; border-radius: 10px; font-size: 14px; font-weight: 500; cursor: pointer; font-family: "Poppins", sans-serif; transition: background 0.2s; }
        .fm-btn-back:hover { background: #e2e8f0; }
        .fm-btn-next { background: linear-gradient(135deg,#2980b9,#6dd5fa); color: white; padding: 11px 28px; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: "Poppins", sans-serif; transition: transform 0.2s, box-shadow 0.2s; letter-spacing: 0.3px; }
        .fm-btn-next:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(41,128,185,0.3); }
        .fm-btn-submit { background: linear-gradient(135deg,#28a745,#20c35e); color: white; padding: 11px 28px; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: "Poppins", sans-serif; transition: transform 0.2s, box-shadow 0.2s; }
        .fm-btn-submit:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(40,167,69,0.3); }
        .fm-close { position: absolute; top: 16px; right: 20px; font-size: 22px; cursor: pointer; color: rgba(255,255,255,0.7); line-height: 1; transition: color 0.2s; }
        .fm-close:hover { color: #fff; }
 
        /* ===== MENTOR FEEDBACK CARDS ===== */
        .mentor-feedback-card { background: #fff; border-radius: 16px; padding: 24px 28px; margin-bottom: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border-left: 4px solid #2980b9; }
        .mentor-feedback-card .mfc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
        .mentor-feedback-card .mfc-mentee { font-size: 15px; font-weight: 600; color: #1a2b4a; }
        .mentor-feedback-card .mfc-date { font-size: 12px; color: #94a3b8; background: #f8fafc; padding: 4px 10px; border-radius: 20px; border: 1px solid #e2e8f0; }
        .mentor-feedback-card .mfc-stars { color: #ffb400; font-size: 18px; margin-bottom: 14px; }
        .mentor-feedback-card .mfc-comments { background: #f8fafc; border-radius: 10px; padding: 14px 16px; font-size: 13.5px; color: #475569; line-height: 1.7; white-space: pre-line; }
        .mentor-feedback-card .mfc-submitted { font-size: 12px; color: #94a3b8; margin-top: 12px; }
 
        /* ===== REQUEST CARD ===== */
        .request-card { background: #fff; border-radius: 18px; padding: 28px 32px; margin-bottom: 22px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #f0f4f8; transition: transform 0.25s, box-shadow 0.25s; }
        .request-card:hover { transform: translateY(-3px); box-shadow: 0 10px 35px rgba(0,0,0,0.09); }
        .req-names { display: flex; gap: 28px; margin-bottom: 16px; }
        .req-name-item { font-size: 14px; color: #475569; }
        .req-name-item strong { color: #1a2b4a; }
        .session-info-block { background: #f8fafc; border-radius: 12px; padding: 16px 20px; margin: 16px 0; display: inline-flex; flex-direction: column; gap: 6px; border: 1px solid #e8ecf4; }
        .session-info-block span { font-size: 14px; color: #334155; }
        .session-end-tag { display: inline-block; font-size: 12px; background: #f0f4ff; color: #4a6fa5; border: 1px solid #d0dff5; border-radius: 20px; padding: 4px 12px; margin: 8px 0 12px; }
        .req-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 14px; }
        .btn-accept { background: linear-gradient(135deg,#28a745,#20c35e); color: white; padding: 9px 20px; border: none; border-radius: 8px; font-size: 13.5px; font-weight: 500; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
        .btn-accept:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(40,167,69,0.3); }
        .btn-reject { background: linear-gradient(135deg,#dc3545,#e85d6e); color: white; padding: 9px 20px; border: none; border-radius: 8px; font-size: 13.5px; font-weight: 500; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
        .btn-reject:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(220,53,69,0.3); }
        .btn-chat { background: linear-gradient(135deg,#2980b9,#6dd5fa); color: white; padding: 9px 20px; border: none; border-radius: 8px; font-size: 13.5px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: transform 0.2s, box-shadow 0.2s; }
        .btn-chat:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(41,128,185,0.3); }
        .btn-feedback { background: linear-gradient(135deg,#6f42c1,#9b6dff); color: white; padding: 9px 20px; border: none; border-radius: 8px; font-size: 13.5px; font-weight: 500; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
        .btn-feedback:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(111,66,193,0.3); }
        .btn-feedback-pending { background: linear-gradient(135deg,#ff9800,#ffb74d); color: white; padding: 9px 20px; border: none; border-radius: 8px; font-size: 13.5px; font-weight: 500; cursor: pointer; }
        .feedback-submitted-badge { display: inline-flex; align-items: center; gap: 6px; background: #d4edda; color: #1a7a35; border: 1px solid #b8dfc5; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 500; }
        .book-form { background: #f8fafc; border-radius: 12px; padding: 20px; border: 1px solid #e8ecf4; margin-top: 12px; }
        .book-form h4 { font-size: 14px; color: #1a2b4a; margin-bottom: 14px; }
        .book-form-fields { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .book-form-field { display: flex; flex-direction: column; gap: 5px; }
        .book-form-field label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .book-form-field input { padding: 9px 13px; border-radius: 8px; border: 1.5px solid #e2e8f0; font-size: 14px; font-family: "Poppins", sans-serif; color: #2c3e50; background: #fff; transition: border-color 0.2s; }
        .book-form-field input:focus { border-color: #2980b9; outline: none; }
        .btn-book { background: linear-gradient(135deg,#2980b9,#6dd5fa); color: white; padding: 10px 22px; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; font-family: "Poppins", sans-serif; }
        .section-heading { font-size: 17px; font-weight: 700; color: #1a2b4a; margin: 36px 0 18px; display: flex; align-items: center; gap: 10px; }
        .section-heading::after { content: ''; flex: 1; height: 1px; background: linear-gradient(to right, #e2e8f0, transparent); }
        .already-paired-note { display: inline-block; background: #fff8e1; color: #92680a; border: 1px solid #ffe082; border-radius: 8px; padding: 6px 14px; font-size: 13px; margin-top: 8px; }
 
        /* ===== SUCCESS POPUP ===== */
        .success-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 99999; justify-content: center; align-items: center; backdrop-filter: blur(5px); }
        .success-overlay.active { display: flex; }
        .success-popup { background: #fff; border-radius: 22px; padding: 48px 44px 40px; max-width: 420px; width: 90%; text-align: center; box-shadow: 0 28px 70px rgba(0,0,0,0.22); animation: successPopIn 0.35s cubic-bezier(0.34,1.56,0.64,1) both; }
        @keyframes successPopIn { from { transform: scale(0.65); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .success-check-ring { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg,#28a745,#20c35e); display: flex; align-items: center; justify-content: center; margin: 0 auto 22px; box-shadow: 0 8px 24px rgba(40,167,69,0.38); animation: checkBounce 0.5s 0.2s cubic-bezier(0.34,1.56,0.64,1) both; }
        @keyframes checkBounce { from { transform: scale(0); } to { transform: scale(1); } }
        .success-check-ring svg { width: 40px; height: 40px; stroke: #fff; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; fill: none; }
        .success-popup h3 { font-size: 22px; font-weight: 700; color: #1a2b4a; margin: 0 0 10px; }
        .success-popup p  { font-size: 14px; color: #64748b; margin: 0 0 28px; line-height: 1.6; }
        .success-ok-btn { background: linear-gradient(135deg,#2980b9,#6dd5fa); color: #fff; border: none; border-radius: 12px; padding: 13px 42px; font-size: 15px; font-weight: 600; cursor: pointer; font-family: "Poppins", sans-serif; transition: transform 0.2s, box-shadow 0.2s; }
        .success-ok-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(41,128,185,0.35); }
        .btn-download-report { display: inline-flex; align-items: center; background: linear-gradient(135deg,#28a745,#20c35e); color: #fff; text-decoration: none; padding: 9px 20px; border-radius: 10px; font-size: 13.5px; font-weight: 600; letter-spacing: 0.3px; transition: transform 0.2s, box-shadow 0.2s; box-shadow: 0 4px 14px rgba(40,167,69,0.3); margin-left: auto; }
        .btn-download-report:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(40,167,69,0.4); color: #fff; }
 
        /* ===================================================
           NEW: RESCHEDULE UI STYLES
           These are purely additive — no existing rule touched
        =================================================== */
 
        /* NEW: Amber banner shown when a session was rescheduled */
        .reschedule-banner {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-left: 4px solid #f59e0b;
            border-radius: 10px;
            padding: 12px 16px;
            margin: 12px 0 4px;
            font-size: 13px;
            color: #78350f;
        }
        .reschedule-banner .rb-icon { font-size: 17px; flex-shrink: 0; margin-top: 1px; }
        .reschedule-banner .rb-text { line-height: 1.55; }
        .reschedule-banner .rb-text strong { color: #92400e; font-weight: 700; }
 
        /* NEW: Amber "Reschedule" action button */
        .btn-reschedule {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
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
        .btn-reschedule:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(245,158,11,0.38);
        }
 
        /* NEW: Sliding reschedule form panel */
        .reschedule-panel {
            display: none;
            background: #fffdf5;
            border: 1.5px solid #fcd34d;
            border-radius: 12px;
            padding: 20px 22px;
            margin-top: 14px;
            animation: rsSlideDown 0.22s ease;
        }
        .reschedule-panel.open { display: block; }
 
        @keyframes rsSlideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
 
        /* NEW: Panel heading */
        .reschedule-panel h5 {
            font-size: 13px;
            font-weight: 700;
            color: #92400e;
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
 
        /* NEW: Locked-duration badge inside panel */
        .duration-locked-note {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f0f4ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 4px;
        }
 
        /* NEW: Confirm button inside panel */
        .btn-reschedule-submit {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: "Poppins", sans-serif;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-reschedule-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(217,119,6,0.38);
        }
 
        /* NEW: Cancel link inside panel */
        .btn-reschedule-cancel {
            background: #f1f5f9;
            color: #64748b;
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            font-family: "Poppins", sans-serif;
            transition: background 0.2s;
        }
        .btn-reschedule-cancel:hover { background: #e2e8f0; }
    </style>
</head>
<body>
 
<?php
// Load sidenav variables
$current_page = 'dashboard';
?>
<div class="layout">
 
    <!-- ===== SIDE NAVIGATION (NEW) ===== -->
    <?php require_once "includes/sidenav.php"; ?>
 
    <!-- ===== MAIN ===== -->
    <div class="main">
 
        <!-- TOPBAR -->
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <!-- Mobile sidenav toggle -->
                <button class="sidenav-toggle" onclick="toggleSidenav()" title="Toggle menu">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <h2>
                    <?php
                    if ($user_role == 'admin')      echo "Admin Panel";
                    elseif ($user_role == 'mentor') echo "Mentor Panel";
                    else                            echo "Mentee Dashboard";
                    ?>
                </h2>
            </div>
            <span>Welcome, <strong><?php echo htmlspecialchars($user_name); ?></strong></span>
 
            <!-- Download Report button — admin only -->
            <?php if ($user_role == 'admin'): ?>
            <a href="report_download.php" class="btn-download-report" title="Download Mentor-Mentee Session Report as Excel">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                     style="vertical-align:middle;margin-right:6px;">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Download completion Report
            </a>
            <?php endif; ?>
        </div>
 
        <!-- ===== FLASH MESSAGES (from accept/reject redirects) ===== -->
        <?php if (!empty($_SESSION['flash_success'])): ?>
        <div style="background:#d1fae5;border:1.5px solid #6ee7b7;color:#065f46;padding:14px 20px;border-radius:10px;margin-bottom:18px;display:flex;align-items:center;gap:10px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
        <div style="background:#fee2e2;border:1.5px solid #fca5a5;color:#991b1b;padding:14px 20px;border-radius:10px;margin-bottom:18px;display:flex;align-items:center;gap:10px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
        </div>
        <?php endif; ?>
 
        <!-- ===== AVAILABLE MENTORS (mentee + admin) ===== -->
        <?php if ($user_role == 'mentee' || $user_role == 'admin'): ?>
        <div class="section-heading">Available Mentors</div>
        <div class="card-grid">
        <?php
        $mentors = $db->query("SELECT * FROM users WHERE role='mentor'");
        while ($mentor = $mentors->fetch_assoc()):
            $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM mentor_requests WHERE mentor_id=? AND status='accepted'");
            $stmtCount->bind_param("i", $mentor['id']);
            $stmtCount->execute();
            $resultCount = $stmtCount->get_result()->fetch_assoc();
            $isFull = $resultCount['total'] >= 2;
 
            $alreadyPaired = false;
            if ($user_role == 'mentee') {
                $stmtPaired = $db->prepare("SELECT id FROM mentor_requests WHERE mentor_id=? AND mentee_id=? AND status='accepted'");
                $stmtPaired->bind_param("ii", $mentor['id'], $user_id);
                $stmtPaired->execute();
                $alreadyPaired = $stmtPaired->get_result()->num_rows > 0;
            }
        ?>
            <div class="mentor-card">
                <div class="avatar"><?php echo strtoupper(substr($mentor['name'], 0, 1)); ?></div>
                <h3><?php echo htmlspecialchars($mentor['name']); ?></h3>
                <p><?php echo htmlspecialchars($mentor['designation']); ?></p>
                <div class="skills"><?php echo htmlspecialchars($mentor['skills']); ?></div>
                <?php if ($user_role == 'mentee'): ?>
                    <?php if ($alreadyPaired): ?>
                        <div class="already-paired-note">✓ Already Connected</div>
                    <?php elseif ($isFull): ?>
                        <button disabled>Mentor Full</button>
                    <?php else: ?>
                        <button onclick="openPopup(<?php echo $mentor['id']; ?>)">Send Request</button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
        </div>
        <?php endif; ?>
 
        <!-- ===== YOUR MENTEES (mentor view) ===== -->
        <?php if ($user_role == 'mentor'): ?>
        <div class="section-heading">Your Mentees</div>
        <div class="card-grid">
        <?php
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.designation, u.skills
            FROM mentor_requests mr
            JOIN users u ON mr.mentee_id = u.id
            WHERE mr.mentor_id=? AND mr.status='accepted'
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $mentees = $stmt->get_result();
        while ($mentee = $mentees->fetch_assoc()):
        ?>
            <div class="mentor-card">
                <div class="avatar"><?php echo strtoupper(substr($mentee['name'], 0, 1)); ?></div>
                <h3><?php echo htmlspecialchars($mentee['name']); ?></h3>
                <p><?php echo htmlspecialchars($mentee['designation']); ?></p>
                <div class="skills"><?php echo htmlspecialchars($mentee['skills']); ?></div>
            </div>
        <?php endwhile; ?>
        </div>
 
        <!-- Feedback moved to pages/feedback.php -->
        <div style="margin-top:16px;">
            <a href="pages/feedback.php" style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#6f42c1,#9b6dff);color:#fff;padding:10px 20px;border-radius:10px;text-decoration:none;font-size:13.5px;font-weight:500;transition:transform 0.2s,box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 18px rgba(111,66,193,0.3)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                💬 View Feedback &amp; Feedforward →
            </a>
        </div>
        <?php endif; ?>
 
        <!-- ===== ALL FEEDBACK (admin only) ===== -->
        <?php if ($user_role == 'admin'): ?>
        <div class="section-heading">Feedback</div>
        <div style="margin-bottom:28px;">
            <a href="pages/feedback.php" style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#2980b9,#6dd5fa);color:#fff;padding:11px 22px;border-radius:10px;text-decoration:none;font-size:13.5px;font-weight:600;">
                📋 View All Feedback &amp; Ratings →
            </a>
        </div>
        <?php endif; ?>
 
        <!-- ===== REQUESTS ===== -->
        <div class="section-heading">Requests &amp; Session Overview
            <div style="margin-left:auto;display:flex;gap:10px;flex-wrap:wrap;">
                <a href="pages/session_booking.php" style="font-size:12.5px;font-weight:600;color:#2980b9;text-decoration:none;background:#eff6ff;border:1.5px solid #bfdbfe;padding:5px 14px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;">📅 Session Booking</a>
                <a href="pages/feedback.php" style="font-size:12.5px;font-weight:600;color:#7c3aed;text-decoration:none;background:#f5f3ff;border:1.5px solid #ddd6fe;padding:5px 14px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;">💬 Feedback</a>
                <?php if ($user_role === 'admin'): ?>
                <a href="pages/connection_status.php" style="font-size:12.5px;font-weight:600;color:#059669;text-decoration:none;background:#ecfdf5;border:1.5px solid #a7f3d0;padding:5px 14px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;">🔗 Connections</a>
                <?php endif; ?>
            </div>
        </div>
 
        <?php while ($req = $requests->fetch_assoc()): ?>
        <div class="request-card">
 
            <div class="req-names">
                <div class="req-name-item"><strong>Mentor:</strong> <?php echo htmlspecialchars($req['mentor_name']); ?></div>
                <div class="req-name-item"><strong>Mentee:</strong> <?php echo htmlspecialchars($req['mentee_name']); ?></div>
            </div>
 
            <?php
                // Normalize status to CSS-safe class (spaces → hyphens)
                $status_class = htmlspecialchars(str_replace(' ', '-', $req['status']));
                $status_label = ucwords($req['status']);
            ?>
            <span class="status-badge <?php echo $status_class; ?>">
                <?php echo htmlspecialchars($status_label); ?>
            </span>
 
            <!-- Mentor: Accept / Reject -->
            <?php if ($user_role == 'mentor' && $req['mentor_id'] == $user_id && $req['status'] == 'pending'): ?>
            <div class="req-actions">
                <!-- FIXED #2: Encrypted IDs prevent IDOR enumeration -->
                <a href="accept_request.php?request_id=<?php echo Security::encryptId((int)$req['id'],'request'); ?>">
                    <button class="btn-accept">✓ Accept</button>
                </a>
                <a href="reject_request.php?request_id=<?php echo Security::encryptId((int)$req['id'],'request'); ?>">
                    <button class="btn-reject">✗ Reject</button>
                </a>
            </div>
            <?php endif; ?>
 
            <!-- Accepted request details -->
            <?php if ($req['status'] == 'accepted'): ?>
            <hr style="margin:18px 0; border:none; border-top:1px solid #f0f4f8;">
 
                <?php
                // Monthly lock REMOVED — show completion state only
                $reqIsCompleted   = !empty($req['session_completed']) && $req['session_completed'] == 1;
                $reqHasSession    = !empty($req['session_date']);
                $reqMonthlyLocked = false; // REMOVED: no monthly lock
                ?>
 
                <?php if (!$reqHasSession): ?>
                    <?php if ($user_role == 'mentee'): ?>
                    <div class="book-form">
                        <h4>📅 Book a Session</h4>
                        <form method="POST">
                            <?php echo Security::csrfField(); ?>
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <div class="book-form-fields">
                                <div class="book-form-field">
                                    <label>Date</label>
                                    <input type="date" name="session_date"
                                           min="<?php echo date('Y-m-d'); ?>" required>
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
                                <button type="submit" name="book_session" class="btn-book">Book Session</button>
                            </div>
                        </form>
                    </div>
                    <?php else: ?>
                        <p style="color:#94a3b8;font-size:14px;font-style:italic;">⏳ Waiting for mentee to book a session...</p>
                    <?php endif; ?>
 
                <?php else: ?>
                    <!-- Session already booked — show info + actions -->
 
                    <!-- Reschedule banner — appears only after a reschedule was made -->
                    <?php if (!empty($req['rescheduled_by'])): ?>
                    <div class="reschedule-banner">
                        <div class="rb-icon">🔄</div>
                        <div class="rb-text">
                            <strong>Session Rescheduled</strong> by
                            <?php echo ucfirst(htmlspecialchars($req['rescheduled_by'])); ?>.
                            <?php if (!empty($req['reschedule_note'])): ?>
                            &nbsp;<?php echo htmlspecialchars($req['reschedule_note']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
 
                    <!-- FIXED #4: escape all output -->
                    <div class="session-info-block">
                        <span>📅 <strong><?php echo htmlspecialchars($req['session_date'],ENT_QUOTES,'UTF-8'); ?></strong></span>
                        <span>⏰ <?php echo htmlspecialchars($req['start_time'] ?? '--:--',ENT_QUOTES,'UTF-8'); ?></span>
                        <span>⏳ <?php echo (int)($req['session_duration'] ?: 60); ?> minutes</span>
                    </div>
 
                    <?php
                    $sessionStart = new DateTime($req['session_date'] . ' ' . ($req['start_time'] ?? '00:00'));
                    $sessionEnd   = clone $sessionStart;
                    $sessionEnd->modify('+' . ((int)($req['session_duration'] ?: 60)) . ' minutes');
                    $currentTime  = new DateTime();
 
                    $feedbackStmt = $db->prepare("SELECT id FROM feedback WHERE user_id=? AND session_date=?");
                    $feedbackStmt->bind_param("is", $req['mentor_id'], $req['session_date']);
                    $feedbackStmt->execute();
                    $feedbackExists = $feedbackStmt->get_result()->num_rows > 0;
                    ?>
 
                    <div class="session-end-tag">
                        Session ends: <?php echo $sessionEnd->format("Y-m-d H:i"); ?>
                    </div>
 
                    <!-- Completed badge -->
                    <?php if ($reqIsCompleted): ?>
                    <div style="margin:8px 0;">
                        <span style="display:inline-flex;align-items:center;gap:6px;background:#d1fae5;color:#065f46;border:1.5px solid #6ee7b7;border-radius:8px;padding:7px 14px;font-size:13px;font-weight:600;">✅ Session Completed</span>
                    </div>
                    <?php endif; ?>
 
                    <div class="req-actions">
 
                        <!-- Chat button -->
                        <?php if ($user_role != 'admin'): ?>
                        <?php $chat_user_id = ($user_role == 'mentor') ? $req['mentee_id'] : $req['mentor_id']; ?>
                        <!-- FIXED #2: Encrypted user ID -->
                        <a href="chat.php?user_id=<?php echo Security::encryptId((int)$chat_user_id,'user'); ?>" class="btn-chat">
                            💬 Open Chat
                        </a>
                        <?php endif; ?>
 
                        <!-- Feedback button (mentee only) — redirect to feedback page -->
                        <?php if ($user_role == 'mentee' && !$feedbackExists): ?>
                            <?php if ($currentTime < $sessionEnd): ?>
                                <button class="btn-feedback-pending"
                                        onclick="alert('Session is not yet completed. You can submit feedback after it ends.')">
                                    ⏳ Give Feedback
                                </button>
                            <?php else: ?>
                                <a href="pages/feedback.php" class="btn-feedback" style="text-decoration:none;">
                                    📝 Give Feedback
                                </a>
                            <?php endif; ?>
                        <?php elseif ($user_role == 'mentee' && $feedbackExists): ?>
                            <span class="feedback-submitted-badge">✅ Feedback Submitted</span>
                        <?php endif; ?>
 
                        <!-- RESCHEDULE BUTTON — Mentor (freely allowed, no monthly lock) -->
                        <?php if ($user_role == 'mentor' && $req['mentor_id'] == $user_id && !$reqIsCompleted): ?>
                        <button class="btn-reschedule"
                                onclick="toggleReschedulePanel('mentor-rp-<?php echo $req['id']; ?>')">
                            🔄 Reschedule
                        </button>
                        <?php endif; ?>
 
                        <!-- ================================================
                             NEW: RESCHEDULE BUTTON — Mentee
                             Visible only to the mentee who owns this request.
                        ================================================ -->
                        <!-- RESCHEDULE BUTTON — Mentee (freely allowed, no monthly lock) -->
                        <?php if ($user_role == 'mentee' && $req['mentee_id'] == $user_id && !$reqIsCompleted): ?>
                        <button class="btn-reschedule"
                                onclick="toggleReschedulePanel('mentee-rp-<?php echo $req['id']; ?>')">
                            🔄 Reschedule
                        </button>
                        <?php endif; ?>
 
                    </div><!-- end .req-actions -->
 
                    <!-- ================================================
                         NEW: MENTOR RESCHEDULE PANEL
                         - Hidden by default, shown via toggleReschedulePanel()
                         - Pre-fills current date/time for convenience
                         - Submits to the mentor_reschedule handler above
                         - Does NOT touch any other data on the row
                    ================================================ -->
                    <?php if ($user_role == 'mentor' && $req['mentor_id'] == $user_id && !$reqIsCompleted): ?>
                    <div id="mentor-rp-<?php echo (int)$req['id']; ?>" class="reschedule-panel">
                        <h5>🗓&nbsp; Reschedule Session <span style="font-weight:400;color:#a16207;">(Mentor)</span></h5>
                        <form method="POST">
                            <!-- FIXED #4: CSRF -->
                            <?php echo Security::csrfField(); ?>
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <div class="book-form-fields">
                                <div class="book-form-field">
                                    <label>New Date</label>
                                    <!-- NEW: Pre-filled with current booking date -->
                                    <input type="date" name="new_session_date"
                                           value="<?php echo htmlspecialchars($req['session_date']); ?>"
                                           required>
                                </div>
                                <div class="book-form-field">
                                    <label>New Start Time</label>
                                    <!-- NEW: Pre-filled with current booking time -->
                                    <input type="time" name="new_start_time"
                                           value="<?php echo htmlspecialchars($req['start_time']); ?>"
                                           required>
                                </div>
                                <div class="book-form-field">
                                    <label>Duration</label>
                                    <!-- FIXED: Duration always 60 min, locked -->
                                    <div class="duration-locked-note">🔒 60 minutes (fixed)</div>
                                </div>
                            </div>
                            <div style="display:flex;gap:10px;margin-top:16px;">
                                <button type="submit" name="mentor_reschedule" class="btn-reschedule-submit">
                                    ✓ Confirm Reschedule
                                </button>
                                <button type="button" class="btn-reschedule-cancel"
                                        onclick="toggleReschedulePanel('mentor-rp-<?php echo $req['id']; ?>')">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
 
                    <!-- ================================================
                         NEW: MENTEE RESCHEDULE PANEL
                         - Hidden by default, shown via toggleReschedulePanel()
                         - Pre-fills current date/time for convenience
                         - Updates the same accepted row (pairing preserved)
                         - Does NOT create a new mentor_request row
                    ================================================ -->
                    <?php if ($user_role == 'mentee' && $req['mentee_id'] == $user_id && !$reqIsCompleted): ?>
                    <div id="mentee-rp-<?php echo (int)$req['id']; ?>" class="reschedule-panel">
                        <h5>🗓&nbsp; Reschedule Session <span style="font-weight:400;color:#a16207;">(Mentee)</span></h5>
                        <form method="POST">
                            <!-- FIXED #4: CSRF -->
                            <?php echo Security::csrfField(); ?>
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <div class="book-form-fields">
                                <div class="book-form-field">
                                    <label>New Date</label>
                                    <!-- NEW: Pre-filled with current booking date -->
                                    <input type="date" name="new_session_date"
                                           value="<?php echo htmlspecialchars($req['session_date']); ?>"
                                           required>
                                </div>
                                <div class="book-form-field">
                                    <label>New Start Time</label>
                                    <!-- NEW: Pre-filled with current booking time -->
                                    <input type="time" name="new_start_time"
                                           value="<?php echo htmlspecialchars($req['start_time']); ?>"
                                           required>
                                </div>
                                <div class="book-form-field">
                                    <label>Duration</label>
                                    <!-- FIXED: Duration always 60 min, locked -->
                                    <div class="duration-locked-note">🔒 60 minutes (fixed)</div>
                                </div>
                            </div>
                            <div style="display:flex;gap:10px;margin-top:16px;">
                                <button type="submit" name="mentee_reschedule" class="btn-reschedule-submit">
                                    ✓ Confirm Reschedule
                                </button>
                                <button type="button" class="btn-reschedule-cancel"
                                        onclick="toggleReschedulePanel('mentee-rp-<?php echo $req['id']; ?>')">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
 
                <?php endif; ?>
            <?php endif; ?>
 
        </div><!-- end .request-card -->
        <?php endwhile; ?>
 
    </div><!-- end .main -->
</div><!-- end .layout -->
 
 
<!-- ============================================================
     FEEDBACK + FEEDFORWARD MODALS (outside loop)
============================================================ -->
<?php
if ($user_role == 'admin') {
    $requestsModal = $db->query("
        SELECT mr.*, m.name AS mentor_name, t.name AS mentee_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        JOIN users t ON mr.mentee_id = t.id
        ORDER BY mr.created_at DESC
    ");
} elseif ($user_role == 'mentor') {
    $stmt2 = $db->prepare("
        SELECT mr.*, m.name AS mentor_name, t.name AS mentee_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        JOIN users t ON mr.mentee_id = t.id
        WHERE mr.mentor_id=? ORDER BY mr.created_at DESC
    ");
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $requestsModal = $stmt2->get_result();
} else {
    $stmt2 = $db->prepare("
        SELECT mr.*, m.name AS mentor_name, t.name AS mentee_name
        FROM mentor_requests mr
        JOIN users m ON mr.mentor_id = m.id
        JOIN users t ON mr.mentee_id = t.id
        WHERE mr.mentee_id=? ORDER BY mr.created_at DESC
    ");
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $requestsModal = $stmt2->get_result();
}
 
while ($req = $requestsModal->fetch_assoc()):
    if ($req['status'] != 'accepted' || empty($req['session_date'])) continue;
 
    $sessionStart2 = new DateTime($req['session_date'] . ' ' . $req['start_time']);
    $sessionEnd2   = clone $sessionStart2;
    $sessionEnd2->modify("+{$req['session_duration']} minutes");
    $currentTime2  = new DateTime();
 
    $fbStmt2 = $db->prepare("SELECT id FROM feedback WHERE user_id=? AND session_date=?");
    $fbStmt2->bind_param("is", $req['mentor_id'], $req['session_date']);
    $fbStmt2->execute();
    $feedbackExists2 = $fbStmt2->get_result()->num_rows > 0;
 
    if ($user_role != 'mentee' || $feedbackExists2 || $currentTime2 < $sessionEnd2) continue;
?>
 
<!-- FEEDBACK MODAL — Step 1 -->
<div id="feedbackModal_<?php echo $req['id']; ?>" class="feedback-modal">
    <div class="feedback-modal-content">
        <span class="fm-close" onclick="closeFeedbackModal(<?php echo $req['id']; ?>)">&times;</span>
        <div class="fm-header">
            <h3>Session Feedback</h3>
            <p>Share your experience with this session</p>
            <div class="fm-steps">
                <div class="fm-step active" id="step1_<?php echo $req['id']; ?>"></div>
                <div class="fm-step"        id="step2_<?php echo $req['id']; ?>"></div>
            </div>
        </div>
        <div class="fm-body">
            <div class="fm-section-label">Overall Rating</div>
            <div class="star-wrap">
                <div class="star-rating">
                    <input type="radio" name="rating_<?php echo $req['id']; ?>" value="5" id="star5_<?php echo $req['id']; ?>" required>
                    <label for="star5_<?php echo $req['id']; ?>">★</label>
                    <input type="radio" name="rating_<?php echo $req['id']; ?>" value="4" id="star4_<?php echo $req['id']; ?>">
                    <label for="star4_<?php echo $req['id']; ?>">★</label>
                    <input type="radio" name="rating_<?php echo $req['id']; ?>" value="3" id="star3_<?php echo $req['id']; ?>">
                    <label for="star3_<?php echo $req['id']; ?>">★</label>
                    <input type="radio" name="rating_<?php echo $req['id']; ?>" value="2" id="star2_<?php echo $req['id']; ?>">
                    <label for="star2_<?php echo $req['id']; ?>">★</label>
                    <input type="radio" name="rating_<?php echo $req['id']; ?>" value="1" id="star1_<?php echo $req['id']; ?>">
                    <label for="star1_<?php echo $req['id']; ?>">★</label>
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
                <label><span style="font-size:13px;color:#64748b;">Any other thoughts you'd like to share?</span></label>
                <textarea id="fb_comment_<?php echo $req['id']; ?>" name="feedback_comment" placeholder="Optional additional comments..."></textarea>
            </div>
            <div class="fm-footer">
                <button type="button" class="fm-btn-back" onclick="closeFeedbackModal(<?php echo $req['id']; ?>)">Cancel</button>
                <button type="button" class="fm-btn-next" onclick="goToFeedforward(<?php echo $req['id']; ?>)">Next: Feedforward →</button>
            </div>
        </div>
    </div>
</div>
 
<!-- FEEDFORWARD MODAL — Step 2 -->
<div id="feedforwardModal_<?php echo $req['id']; ?>" class="feedback-modal">
    <div class="feedback-modal-content">
        <span class="fm-close" onclick="closeFeedforwardModal(<?php echo $req['id']; ?>)">&times;</span>
        <div class="fm-header">
            <h3>Feedforward</h3>
            <p>Plan your next steps and set intentions</p>
            <div class="fm-steps">
                <div class="fm-step"        id="ff_step1_<?php echo $req['id']; ?>"></div>
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
 
<?php endwhile; ?>
 
 
<!-- ============================================================
     SEND REQUEST POPUPS (one per mentor)
============================================================ -->
<?php
$mentors = $db->query("SELECT * FROM users WHERE role='mentor'");
while ($mentor = $mentors->fetch_assoc()):
?>
<div id="popup_<?php echo $mentor['id']; ?>" class="popup">
    <div class="popup-box">
        <h3>Select Exactly 3 Competencies</h3>
        <form method="POST" action="send_request.php"
              onsubmit="return validateCompetencies(this)"
              onmouseover="limitCheckboxes(this)">
            <!-- FIXED #4: CSRF on send-request form -->
            <?php echo Security::csrfField(); ?>
            <input type="hidden" name="mentor_id" value="<?php echo (int)$mentor['id']; ?>">
            <div class="checkbox-grid">
                <label><input type="checkbox" name="competencies[]" value="Personal Effectiveness"> Personal Effectiveness</label>
                <label><input type="checkbox" name="competencies[]" value="Communication & Influence"> Communication & Influence</label>
                <label><input type="checkbox" name="competencies[]" value="Leadership & People Skills"> Leadership & People Skills</label>
                <label><input type="checkbox" name="competencies[]" value="Career Navigation & Visibility"> Career Navigation & Visibility</label>
                <label><input type="checkbox" name="competencies[]" value="Organizational Awareness"> Organizational Awareness</label>
                <label><input type="checkbox" name="competencies[]" value="Strategic Thinking & Innovation"> Strategic Thinking & Innovation</label>
                <label><input type="checkbox" name="competencies[]" value="Time & Priority Management"> Time & Priority Management</label>
                <label><input type="checkbox" name="competencies[]" value="Feedback & Self-Development"> Feedback & Self-Development</label>
            </div>
            <textarea name="comment" placeholder="Add a message (optional)..."></textarea>
            <div class="popup-buttons">
                <button type="button" class="btn-cancel" onclick="closePopup(<?php echo $mentor['id']; ?>)">Cancel</button>
                <button type="submit" class="btn-send">Send Request</button>
            </div>
        </form>
    </div>
</div>
<?php endwhile; ?>
 
 
<!-- ============================================================
     REQUEST SENT SUCCESS POPUP
============================================================ -->
<div id="requestSuccessOverlay" class="success-overlay">
    <div class="success-popup">
        <div class="success-check-ring">
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h3>Request Sent Successfully!</h3>
        <p>Your mentorship request has been submitted.<br>You will be notified once the mentor responds.</p>
        <button class="success-ok-btn" onclick="closeSuccessPopup()">OK, Got it!</button>
    </div>
</div>
 
 
<!-- ============================================================
     JAVASCRIPT
============================================================ -->
<script>
 
/* ─── Feedback Modal (Step 1) ─── */
function openFeedbackModal(id) {
    document.getElementById("feedbackModal_" + id).style.display = "flex";
    document.body.classList.add("modal-open");
}
function closeFeedbackModal(id) {
    document.getElementById("feedbackModal_" + id).style.display = "none";
    document.body.classList.remove("modal-open");
}
 
/* ─── Go to Feedforward (Step 2) ─── */
function goToFeedforward(id) {
    let rating = document.querySelector('input[name="rating_' + id + '"]:checked');
    let q1 = document.getElementById("q1_" + id).value.trim();
    let q2 = document.getElementById("q2_" + id).value.trim();
    let q3 = document.getElementById("q3_" + id).value.trim();
    let q4 = document.getElementById("q4_" + id).value.trim();
 
    if (!rating || !q1 || !q2 || !q3 || !q4) {
        alert("Please fill all required fields and select a star rating.");
        return;
    }
 
    document.getElementById("ff_rating_" + id).value = rating.value;
    document.getElementById("ff_q1_"     + id).value = q1;
    document.getElementById("ff_q2_"     + id).value = q2;
    document.getElementById("ff_q3_"     + id).value = q3;
    document.getElementById("ff_q4_"     + id).value = q4;
 
    let fbComment = document.getElementById("fb_comment_" + id);
    if (fbComment) {
        document.getElementById("ff_fbcomment_" + id).value = fbComment.value;
    }
 
    document.getElementById("feedbackModal_"    + id).style.display = "none";
    document.getElementById("feedforwardModal_" + id).style.display = "flex";
}
 
/* ─── Back to Feedback ─── */
function backToFeedback(id) {
    document.getElementById("feedforwardModal_" + id).style.display = "none";
    document.getElementById("feedbackModal_"    + id).style.display = "flex";
}
 
/* ─── Close Feedforward ─── */
function closeFeedforwardModal(id) {
    document.getElementById("feedforwardModal_" + id).style.display = "none";
    document.body.classList.remove("modal-open");
}
 
/* ─── Send Request Popup ─── */
function openPopup(id) {
    document.getElementById("popup_" + id).classList.add("active");
    document.body.classList.add("modal-open");
 
    let form = document.querySelector("#popup_" + id + " form");
    if (form && !form.dataset.ajaxBound) {
        form.dataset.ajaxBound = "1";
        form.addEventListener("submit", function(e) {
            e.preventDefault();
 
            let checked = form.querySelectorAll('input[name="competencies[]"]:checked');
            if (checked.length !== 3) {
                alert("Select exactly 3 competencies");
                return;
            }
 
            let fd = new FormData(form);
            fetch("send_request.php", {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                closePopup(id);
                if (data.success) {
                    document.getElementById("requestSuccessOverlay").classList.add("active");
                } else {
                    alert(data.message || "Could not send request.");
                }
            })
            .catch(function() {
                alert("Something went wrong. Please try again.");
            });
        });
    }
}
function closePopup(id) {
    document.getElementById("popup_" + id).classList.remove("active");
    document.body.classList.remove("modal-open");
}
 
function closeSuccessPopup() {
    document.getElementById("requestSuccessOverlay").classList.remove("active");
    window.location.reload();
}
 
document.getElementById("requestSuccessOverlay").addEventListener("click", function(e) {
    if (e.target === this) closeSuccessPopup();
});
 
/* ─── Competency Checkbox Logic ─── */
function validateCompetencies(form) {
    let checked = form.querySelectorAll('input[name="competencies[]"]:checked');
    if (checked.length !== 3) {
        alert("Select exactly 3 competencies");
        return false;
    }
    return true;
}
function limitCheckboxes(form) {
    let checkboxes = form.querySelectorAll('input[name="competencies[]"]');
    checkboxes.forEach(cb => {
        cb.onclick = function () {
            let checked = form.querySelectorAll('input[name="competencies[]"]:checked');
            if (checked.length >= 3) {
                checkboxes.forEach(c => { if (!c.checked) c.disabled = true; });
            } else {
                checkboxes.forEach(c => c.disabled = false);
            }
        };
    });
}
 
// ============================================================
// NEW: Sidenav toggle (mobile)
// ============================================================
function toggleSidenav() {
    document.getElementById('sideNav').classList.toggle('open');
    document.getElementById('sideNavOverlay').classList.toggle('active');
}
function closeSidenav() {
    document.getElementById('sideNav').classList.remove('open');
    document.getElementById('sideNavOverlay').classList.remove('active');
}

// ============================================================
// NEW: toggleReschedulePanel(panelId)
// Opens/closes the reschedule form for a given request.
// Automatically closes any other open reschedule panel first
// so only one is visible at a time — clean, uncluttered UX.
// ============================================================
function toggleReschedulePanel(panelId) {
    // NEW: Close all other open reschedule panels
    document.querySelectorAll('.reschedule-panel.open').forEach(function(p) {
        if (p.id !== panelId) {
            p.classList.remove('open');
        }
    });
 
    // NEW: Toggle the clicked panel
    var panel = document.getElementById(panelId);
    if (panel) {
        panel.classList.toggle('open');
 
        // NEW: Smooth-scroll into view when opening
        if (panel.classList.contains('open')) {
            setTimeout(function() {
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 60);
        }
    }
}

// ============================================================
// NEW: Side Navigation Toggle (mobile)
// ============================================================
function toggleSidenav() {
    document.getElementById('sideNav').classList.toggle('open');
    document.getElementById('sideNavOverlay').classList.toggle('active');
}
function closeSidenav() {
    document.getElementById('sideNav').classList.remove('open');
    document.getElementById('sideNavOverlay').classList.remove('active');
}
</script>

<!-- ============================================================
     SESSION COMPLETION POPUP — Dashboard
     Shown to mentor/mentee when a session's end time has passed
     and session_completed = 0. Works independently per role.
============================================================ -->
<?php if (!empty($dashPopupSessions)): ?>
<style>
.sc-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9998;display:flex;align-items:center;justify-content:center;animation:scFadeIn 0.2s ease;}
@keyframes scFadeIn{from{opacity:0;}to{opacity:1;}}
.sc-modal{background:#fff;border-radius:18px;padding:36px 32px 28px;max-width:440px;width:92%;box-shadow:0 8px 40px rgba(0,0,0,0.22);text-align:center;position:relative;z-index:9999;animation:scSlideUp 0.25s ease;}
@keyframes scSlideUp{from{transform:translateY(30px);opacity:0;}to{transform:translateY(0);opacity:1;}}
.sc-modal .sc-icon{font-size:48px;margin-bottom:10px;}
.sc-modal h2{font-size:20px;font-weight:700;color:#1e293b;margin:0 0 8px;}
.sc-modal .sc-sub{font-size:13.5px;color:#64748b;margin-bottom:6px;}
.sc-modal .sc-session-info{background:#f1f5f9;border-radius:10px;padding:12px 16px;margin:16px 0 24px;font-size:13px;color:#334155;text-align:left;line-height:1.8;}
.sc-modal .sc-session-info strong{color:#0f172a;}
.sc-modal .sc-btns{display:flex;gap:12px;justify-content:center;}
.sc-btn-yes{background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;border-radius:10px;padding:11px 32px;font-size:15px;font-weight:700;cursor:pointer;transition:transform 0.15s,box-shadow 0.15s;}
.sc-btn-yes:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(5,150,105,0.35);}
.sc-btn-no{background:#f1f5f9;color:#475569;border:1.5px solid #cbd5e1;border-radius:10px;padding:11px 32px;font-size:15px;font-weight:600;cursor:pointer;transition:background 0.15s;}
.sc-btn-no:hover{background:#e2e8f0;}
.sc-progress{font-size:12px;color:#94a3b8;margin-top:16px;}
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
var scSessions = <?php echo json_encode(array_values($dashPopupSessions), JSON_HEX_TAG); ?>;
var scIndex    = 0;
var scRole     = <?php echo json_encode($user_role); ?>;

function scShowNext() {
    if (scIndex >= scSessions.length) {
        document.getElementById('scOverlay').style.display = 'none';
        return;
    }
    var s = scSessions[scIndex];
    document.getElementById('scSessionInfo').innerHTML =
        '<strong>Mentor:</strong> ' + s.mentor_name + '<br>' +
        '<strong>Mentee:</strong> '  + s.mentee_name + '<br>' +
        '<strong>Date:</strong> '    + s.session_date + ' &nbsp;|&nbsp; ' +
        '<strong>Time:</strong> '    + s.start_time   + '<br>' +
        '<strong>Session ended at:</strong> ' + s.session_end;
    document.getElementById('scProgress').textContent =
        scSessions.length > 1 ? 'Session ' + (scIndex + 1) + ' of ' + scSessions.length : '';
    document.getElementById('scOverlay').style.display = 'flex';
}

function scHandleYes() {
    var s = scSessions[scIndex];
    scDismiss(s.request_id, function() {
        if (scRole === 'mentee') {
            window.location.href = 'pages/feedback.php?request_id=' + s.request_id + '&from_popup=1';
        } else if (scRole === 'mentor') {
            scMarkComplete(s.request_id, function() {
                scIndex++;
                if (scIndex >= scSessions.length) { window.location.reload(); }
                else { scShowNext(); }
            });
        } else {
            scIndex++; scShowNext();
        }
    });
}

function scHandleNo() {
    var s = scSessions[scIndex];
    scDismiss(s.request_id, function() { scIndex++; scShowNext(); });
}

function scMarkComplete(requestId, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'pages/session_mark_complete_ajax.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() { if (callback) callback(); };
    xhr.onerror = function() { if (callback) callback(); };
    xhr.send('request_id=' + encodeURIComponent(requestId));
}

function scDismiss(requestId, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'pages/session_popup_dismiss.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() { if (callback) callback(); };
    xhr.onerror = function() { if (callback) callback(); };
    xhr.send('request_id=' + encodeURIComponent(requestId));
}

window.addEventListener('DOMContentLoaded', function() {
    if (scSessions.length > 0) { setTimeout(scShowNext, 800); }
});
</script>
<?php endif; ?>

</body>
</html>