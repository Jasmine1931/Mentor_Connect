<?php
// ============================================================
// fetch_messages.php
// VAPT Fixes: #1 Auth + pairing check, #4 XSS output,
//             #6 SQL injection (int cast + parameterized)
// ============================================================

session_start();
require_once 'config/database.php';
require_once 'includes/Security.php';

// FIXED #1: Was missing auth check entirely (used bare session_start + include)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit();
}

// FIXED #6: removed broken   include "config.php"   — file doesn't exist at root
$db = (new Database())->connect();

$current_user = (int) $_SESSION['user_id'];
// FIXED #6: Cast to int — was raw GET used in bind_param
$other_user   = (int) ($_GET['user_id'] ?? 0);

if ($other_user <= 0) {
    http_response_code(400);
    exit();
}

// FIXED #1 (IDOR): Verify the two users are actually paired before
// letting them read each other's messages. Without this, any
// authenticated user could read any other user's chat by guessing IDs.
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

// FIXED #6: Fully parameterized query — no raw user input in SQL
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
    // FIXED #4: htmlspecialchars prevents stored XSS
    echo "<div class='message " . Security::h($class) . "'>"
       . Security::h($row['message'])
       . "</div>";
}
