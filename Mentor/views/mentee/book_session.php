<?php
/**
 * views/mentee/book_session.php
 * FIXED: replaced $pdo with Database()->connect() (mysqli)
 *        replaced $_SESSION['user']['id'] with $_SESSION['user_id']
 *        replaced references to non-existent 'mentorships' table
 *        redirects to pages/session_booking.php which handles booking properly
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mentee') {
    header("Location: ../../login.php");
    exit();
}

// The full session booking workflow is handled by pages/session_booking.php
// Redirect there to avoid duplicating logic
header("Location: ../../pages/session_booking.php");
exit();
