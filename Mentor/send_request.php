<?php
// ============================================================
// send_request.php
// MODIFIED:
//   - GUARD 0 (NEW): Cross-department validation — blocks if
//     mentor and mentee are in the same department.
//   - GUARD 2 REMOVED: 6-month lock no longer enforced here.
//     Admin controls lock from the frontend (Reassign page).
//   - Existing guards (role check, limit, duplicate) preserved.
// ============================================================
 
session_start();
require_once 'config/database.php';
require_once 'includes/Security.php';
 
// Must be logged in AND be a mentee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mentee') {
    Security::safeRedirect('/Mentor/login.php');
}
 
$db = (new Database())->connect();
 
$isAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);
 
if ($isAjax) {
    header('Content-Type: application/json');
}
 
// CSRF check on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::csrfVerify();
}
 
$mentee_id = (int) $_SESSION['user_id'];
$mentor_id = (int) ($_POST['mentor_id'] ?? 0);
 
if ($mentor_id <= 0) {
    if ($isAjax) {
        echo json_encode(['success' => false, 'message' => 'Invalid mentor.']);
    } else {
        Security::safeRedirect('/Mentor/dashboard.php');
    }
    exit();
}
 
// -- GUARD 1: Verify mentor exists and has role=mentor ---------
$stmt = $db->prepare("SELECT id, department FROM users WHERE id=? AND role='mentor'");
$stmt->bind_param('i', $mentor_id);
$stmt->execute();
$mentor_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
 
if (!$mentor_row) {
    if ($isAjax) {
        echo json_encode(['success' => false, 'message' => 'Invalid mentor.']);
    } else {
        Security::safeRedirect('/Mentor/dashboard.php');
    }
    exit();
}
 
// -- GUARD 0: Cross-department validation ----------------------
$stmt2 = $db->prepare("SELECT department FROM users WHERE id=? AND role='mentee'");
$stmt2->bind_param('i', $mentee_id);
$stmt2->execute();
$mentee_row = $stmt2->get_result()->fetch_assoc();
$stmt2->close();
 
if ($mentee_row && $mentor_row) {
    $mentor_dept = strtolower(trim($mentor_row['department'] ?? ''));
    $mentee_dept = strtolower(trim($mentee_row['department'] ?? ''));
 
    if ($mentor_dept !== '' && $mentee_dept !== '' && $mentor_dept === $mentee_dept) {
        Security::log(
            'CROSS_DEPT_BLOCKED',
            "Mentee {$mentee_id} (dept: " . ($mentee_row['department'] ?? 'N/A') . ") tried to request " .
            "mentor {$mentor_id} (dept: " . ($mentor_row['department'] ?? 'N/A') . ") -- same department blocked.",
            $db
        );
        $dept_display = htmlspecialchars($mentor_row['department'] ?? '');
        if ($isAjax) {
            echo json_encode([
                'success' => false,
                'message' => "Cross-Department Rule: You cannot request a mentor from the same department ({$dept_display}). Please choose a mentor from a different department."
            ]);
        } else {
            $_SESSION['flash_error'] = "Cross-Department Rule: You cannot request a mentor from your own department ({$dept_display}). Please choose a mentor from a different department.";
            Security::safeRedirect('/Mentor/dashboard.php');
        }
        exit();
    }
}
 
// -- GUARD 3: Check mentee request limit (max 2 pending/accepted) --
$stmt = $db->prepare(
    "SELECT COUNT(*) AS total FROM mentor_requests
     WHERE mentee_id=? AND status IN ('pending','accepted')"
);
$stmt->bind_param('i', $mentee_id);
$stmt->execute();
$total = (int) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
 
if ($total >= 2) {
    if ($isAjax) {
        echo json_encode(['success' => false, 'message' => 'You can only send requests to up to 2 mentors.']);
    } else {
        Security::safeRedirect('/Mentor/dashboard.php');
    }
    exit();
}
 
// -- GUARD 4: Prevent duplicate request to same mentor ---------
$stmt = $db->prepare(
    "SELECT id FROM mentor_requests
     WHERE mentor_id=? AND mentee_id=? AND status IN ('pending','accepted')"
);
$stmt->bind_param('ii', $mentor_id, $mentee_id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    if ($isAjax) {
        echo json_encode(['success' => false, 'message' => 'You have already sent a request to this mentor.']);
    } else {
        Security::safeRedirect('/Mentor/dashboard.php');
    }
    exit();
}
$stmt->close();
 
// -- INSERT the request ----------------------------------------
$rawComps = $_POST['competencies'] ?? [];
$skill    = '';
if (is_array($rawComps)) {
    $cleanComps = array_map('strip_tags', $rawComps);
    $cleanComps = array_filter($cleanComps);
    $skill      = implode(', ', array_slice($cleanComps, 0, 3));
}
$message = strip_tags(trim($_POST['comment'] ?? $_POST['message'] ?? ''));
 
$stmt = $db->prepare(
    "INSERT INTO mentor_requests (mentor_id, mentee_id, skill, message, status)
     VALUES (?, ?, ?, ?, 'pending')"
);
$stmt->bind_param('iiss', $mentor_id, $mentee_id, $skill, $message);
$stmt->execute();
$stmt->close();
 
Security::log(
    'SEND_REQUEST',
    "Mentee {$mentee_id} sent request to mentor {$mentor_id}",
    $db
);
 
if ($isAjax) {
    echo json_encode(['success' => true, 'message' => 'Request Sent Successfully']);
    exit();
}
 
Security::safeRedirect('/Mentor/dashboard.php');
?>
 