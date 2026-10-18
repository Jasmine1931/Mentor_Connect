<?php
// ============================================================
// pages/session_popup_dismiss.php
// Called via AJAX to dismiss the session-completion popup
// for a given request_id in the current PHP session.
// This prevents the popup from showing again for the same
// session on page refresh.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit();
}

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
if ($request_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'invalid_request_id']);
    exit();
}

// Store dismissal in session so popup won't reappear on refresh
$_SESSION['popup_dismissed_' . $request_id] = time();

echo json_encode(['ok' => true]);
exit();
