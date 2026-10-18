<?php
// ============================================================
// logout.php
// VAPT Fixes: #8 Concurrent session cleared, #11 Log logout
// ============================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/MySessionHandler.php';
require_once __DIR__ . '/includes/Security.php';

new MySessionHandler();

// FIXED #8: Pass $db so destroy() clears session_token in DB
$db = (new Database())->connect();

// FIXED #11: Log logout BEFORE session is destroyed
if (isset($_SESSION['user_id'])) {
    Security::log('LOGOUT', 'User logged out', $db); // NEW #11
}

// UPDATED #8: destroy() now clears session_token in users table
MySessionHandler::destroy($db);

// FIXED #3: Safe redirect — hardcoded destination, not user-supplied
header('Location: login.php');
exit();
