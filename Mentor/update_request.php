<?php
// ============================================================
// update_request.php
// VAPT Fixes: #1 Full auth check (was only checking role, not session),
//             #4 Action whitelist + CSRF, #6 int cast, #11 Log
// ============================================================

session_start();
require_once 'config/database.php';
require_once 'includes/Security.php';

// FIXED #1: Was only checking $_SESSION['role'] — never verified user_id set
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mentor') {
    Security::safeRedirect('/Mentor/dashboard.php');
}

// FIXED #4: CSRF verification on every POST
Security::csrfVerify();

$db = (new Database())->connect();

$mentor_id  = (int) $_SESSION['user_id'];
// FIXED #6: Cast to int
$request_id = (int) ($_POST['request_id'] ?? 0);
$action     =       $_POST['action']      ?? '';

// FIXED #4: Whitelist — prevents arbitrary status injection via POST
$allowed_actions = ['accepted', 'rejected'];
if (!in_array($action, $allowed_actions, true) || $request_id <= 0) {
    Security::safeRedirect('/Mentor/dashboard.php');
}

// FIXED #6: Parameterized + FIXED #1: mentor_id ownership check added
// (original had no ownership check — any mentor could update any request)
$stmt = $db->prepare(
    "UPDATE mentor_requests
     SET status=?
     WHERE id=? AND mentor_id=? AND status='pending'"
);
$stmt->bind_param('sii', $action, $request_id, $mentor_id);
$stmt->execute();

// FIXED #11: Log state change
if ($stmt->affected_rows > 0) {
    Security::log(
        'UPDATE_REQUEST',
        "Mentor {$mentor_id} set request #{$request_id} to '{$action}'",
        $db
    );
}

// FIXED #3: Hardcoded safe redirect
Security::safeRedirect('/Mentor/dashboard.php');
