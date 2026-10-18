<?php
// views/mentor/dashboard.php — FIXED: $conn → redirect to main dashboard
// The old file used undefined $conn and had a broken role check
// (was checking 'mentee' instead of 'mentor'). Since the main
// dashboard.php handles all roles with the full sidenav and feature
// set, we redirect there directly.
session_start();

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'mentor') {
    header("Location: ../../login.php");
    exit();
}

// Redirect to the main dashboard which handles mentor role fully
header("Location: ../../dashboard.php");
exit();
