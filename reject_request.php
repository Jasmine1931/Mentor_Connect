<?php
session_start();

// Include order matches dashboard.php exactly (proven working order)
require_once "config/database.php";
require_once "config/config.php";
require_once "includes/Security.php";

// Must be logged in AND be a mentor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mentor') {
    Security::safeRedirect('/Mentor/login.php');
    exit();
}

$db        = (new Database())->connect();
$mentor_id = (int) $_SESSION['user_id'];

// Decrypt the HMAC token from the URL
$raw_token  = $_GET['request_id'] ?? '';
$request_id = Security::decryptId($raw_token, 'request');

if ($request_id <= 0) {
    $_SESSION['flash_error'] = 'Invalid or expired request link.';
    Security::safeRedirect('/Mentor/dashboard.php');
    exit();
}

// Verify ownership and pending status
$stmt = $db->prepare(
    "SELECT id FROM mentor_requests WHERE id = ? AND mentor_id = ? AND status = 'pending'"
);
$stmt->bind_param('ii', $request_id, $mentor_id);
$stmt->execute();

if ($stmt->get_result()->num_rows === 0) {
    $_SESSION['flash_error'] = 'Request not found or no longer pending.';
    Security::safeRedirect('/Mentor/dashboard.php');
    exit();
}
$stmt->close();

// Reject the request
$stmt = $db->prepare(
    "UPDATE mentor_requests SET status = 'rejected' WHERE id = ? AND mentor_id = ?"
);
$stmt->bind_param('ii', $request_id, $mentor_id);
$stmt->execute();
$stmt->close();

Security::log('REJECT_REQUEST', "Mentor {$mentor_id} rejected request #{$request_id}", $db);

$_SESSION['flash_success'] = 'Request rejected.';
Security::safeRedirect('/Mentor/dashboard.php');
