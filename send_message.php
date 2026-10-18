<?php
// ============================================================
// send_message.php
// VAPT Fixes: #1 Auth, #3 Open redirect, #4 XSS/input,
//             #6 SQL injection, #11 Log
// ============================================================

session_start();
require_once 'config/database.php';
require_once 'includes/Security.php';

// FIXED #1: Auth check — proper redirect, not silent exit()
if (!isset($_SESSION['user_id'])) {
    Security::safeRedirect('/Mentor/login.php');
}

// FIXED #4: CSRF token verification
Security::csrfVerify();

$db = (new Database())->connect();

$sender_id = (int) $_SESSION['user_id'];

// FIXED #6: Cast receiver_id to int immediately
$receiver_id = (int) ($_POST['receiver_id'] ?? 0);

// FIXED #4: Strip tags + limit length to prevent HTML injection & flooding
$message = trim(strip_tags($_POST['message'] ?? ''));

if ($receiver_id <= 0 || $message === '') {
    // FIXED #3: Safe redirect
    Security::safeRedirect('/Mentor/dashboard.php');
}

// FIXED #4: Enforce max message length (2000 chars)
if (mb_strlen($message) > 2000) {
    $message = mb_substr($message, 0, 2000);
}

// FIXED #6: Verify sender and receiver are actually paired
// Prevents any user from sending messages to arbitrary users (IDOR)
$chk = $db->prepare(
    "SELECT id FROM mentor_requests
     WHERE status='accepted'
       AND ((mentor_id=? AND mentee_id=?) OR (mentor_id=? AND mentee_id=?))
     LIMIT 1"
);
$chk->bind_param('iiii', $sender_id, $receiver_id, $receiver_id, $sender_id);
$chk->execute();
if ($chk->get_result()->num_rows === 0) {
    // FIXED #1 + #3: Not paired — redirect silently
    Security::safeRedirect('/Mentor/dashboard.php');
}

// FIXED #6: Fully parameterized insert
$stmt = $db->prepare(
    'INSERT INTO messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())'
);
$stmt->bind_param('iis', $sender_id, $receiver_id, $message);
$stmt->execute();

// FIXED #11: Log message sent
Security::log(
    'SEND_MESSAGE',
    "From user {$sender_id} to user {$receiver_id}",
    $db
);

// FIXED #3: receiver_id is int — safe to append; no open redirect
header('Location: chat.php?user_id=' . $receiver_id);
exit();
