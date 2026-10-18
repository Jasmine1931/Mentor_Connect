<?php
// ============================================================
// fetch_messages.php
// VAPT Fixes: #1 Auth + pairing check, #4 XSS output,
//             #6 SQL injection (int cast + parameterized)
// TASK 6 ADD: Mark fetched messages as is_read = 1
//             (so sidenav badge count drops to 0 after chat opened)
// BUGFIX: Use 'content' column (matches live DB schema)
// ============================================================

session_start();
require_once 'config/database.php';
require_once 'includes/Security.php';

// FIXED #1: Was missing auth check entirely (used bare session_start + include)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit();
}

$db = (new Database())->connect();

$current_user = (int) $_SESSION['user_id'];
// FIXED #6: Cast to int — was raw GET used in bind_param
$other_user   = (int) ($_GET['user_id'] ?? 0);

if ($other_user <= 0) {
    http_response_code(400);
    exit();
}

// FIXED #1 (IDOR): Verify the two users are actually paired before
// letting them read each other's messages.
$chk = $db->prepare(
    "SELECT id FROM mentor_requests
     WHERE status = 'accepted'
       AND ((mentor_id=? AND mentee_id=?) OR (mentor_id=? AND mentee_id=?))
     LIMIT 1"
);
$chk->bind_param('iiii', $current_user, $other_user, $other_user, $current_user);
$chk->execute();
if ($chk->get_result()->num_rows === 0) {
    // FIXED #1: Not paired — block access
    http_response_code(403);
    exit();
}

// FIXED #6: Fully parameterized query
$stmt = $db->prepare(
    "SELECT * FROM messages
     WHERE (sender_id=? AND receiver_id=?)
        OR (sender_id=? AND receiver_id=?)
     ORDER BY created_at ASC"
);
$stmt->bind_param('iiii', $current_user, $other_user, $other_user, $current_user);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $class = ($row['sender_id'] == $current_user) ? 'mine' : '';
    // Support both 'content' (live DB) and 'message' (legacy) column names
    $msgText = $row['content'] ?? $row['message'] ?? '';
    // FIXED #4: htmlspecialchars prevents stored XSS
    echo "<div class='message " . Security::h($class) . "'>"
       . Security::h($msgText)
       . "</div>";
}

// TASK 6: Mark all messages FROM other_user TO current_user as read.
// This clears the unread badge in the sidenav after the chat is opened.
// Wrapped in try/catch so it fails silently if is_read column doesn't exist yet.
try {
    $markRead = $db->prepare(
        "UPDATE messages SET is_read = 1
         WHERE receiver_id = ? AND sender_id = ? AND is_read = 0"
    );
    $markRead->bind_param('ii', $current_user, $other_user);
    $markRead->execute();
    $markRead->close();
} catch (Exception $e) {
    error_log('fetch_messages mark_read: ' . $e->getMessage());
}
