<?php
// ============================================================
// pages/session_mark_complete_ajax.php
// AJAX endpoint: Called when mentor clicks YES on the session
// completion popup. Marks the session_completed = 1.
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

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mentor') {
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit();
}

$user_id    = (int)$_SESSION['user_id'];
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

if ($request_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'invalid_request_id']);
    exit();
}

$db = (new Database())->connect();
if (!$db) {
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit();
}

// Verify this mentor owns the request and session time has passed
$stmt = $db->prepare("
    SELECT id, session_date, start_time, session_duration
    FROM mentor_requests
    WHERE id = ? AND mentor_id = ? AND status = 'accepted' AND session_completed = 0
");
$stmt->bind_param("ii", $request_id, $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'not_found_or_already_done']);
    exit();
}

// Confirm session end time has actually passed
if (!empty($row['session_date']) && !empty($row['start_time'])) {
    $sessionEnd = new DateTime($row['session_date'] . ' ' . $row['start_time']);
    $sessionEnd->modify('+' . ((int)($row['session_duration'] ?: 60)) . ' minutes');
    $now = new DateTime();
    if ($now < $sessionEnd) {
        echo json_encode(['ok' => false, 'error' => 'session_not_ended_yet']);
        exit();
    }
}

// Mark complete
$upd = $db->prepare("UPDATE mentor_requests SET session_completed = 1 WHERE id = ? AND mentor_id = ?");
$upd->bind_param("ii", $request_id, $user_id);
$upd->execute();

Security::log('COMPLETE_SESSION_POPUP', "Mentor marked request #{$request_id} as completed via popup", $db);

// Also dismiss the popup in session
$_SESSION['popup_dismissed_' . $request_id] = time();

echo json_encode(['ok' => true]);
exit();
