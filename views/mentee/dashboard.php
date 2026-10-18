<?php
// views/mentee/dashboard.php — FIXED: $conn → Database()->connect()
// Also fixed: role check was checking 'mentee' correctly now, redirects
// to main dashboard.php which has the full feature set + sidenav.
session_start();

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'mentee') {
    header("Location: ../../login.php");
    exit();
}

// This view is a legacy stub — redirect to the main dashboard
// which has the full sidenav + session booking + feedback links.
header("Location: ../../dashboard.php");
exit();
