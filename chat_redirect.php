<?php
// chat_redirect.php
// Called by "Messages" sidebar link.
// Finds the current user's accepted mentor/mentee pair and redirects
// to chat.php with the correct encrypted user_id token.
// If no accepted pair exists, redirects back to dashboard.php.

error_reporting(0);
ini_set('display_errors', 0);

session_start();
require_once "config/config.php";
require_once "includes/Security.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';

// Admin cannot use chat
if ($user_role === 'admin') {
    header("Location: dashboard.php");
    exit();
}

$pdo = getPDO();

// Find paired user: mentor → get mentee_id; mentee → get mentor_id
if ($user_role === 'mentor') {
    $stmt = $pdo->prepare("
        SELECT mentee_id AS paired_id
        FROM mentor_requests
        WHERE mentor_id = ? AND status = 'accepted'
        ORDER BY id DESC
        LIMIT 1
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT mentor_id AS paired_id
        FROM mentor_requests
        WHERE mentee_id = ? AND status = 'accepted'
        ORDER BY id DESC
        LIMIT 1
    ");
}

$stmt->execute([$user_id]);
$row = $stmt->fetch();

if (!$row || empty($row['paired_id'])) {
    header("Location: dashboard.php");
    exit();
}

$paired_id    = (int)$row['paired_id'];
$encrypted_id = Security::encryptId($paired_id, 'user');

header("Location: chat.php?user_id=" . urlencode($encrypted_id));
exit();