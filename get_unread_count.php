<?php
// ============================================================
// get_unread_count.php  (place in project ROOT: /Mentor/)
// TASK 6 — AJAX endpoint: returns unread message count for
//           the currently logged-in user.
//
// Called by sidenav JS every 30 seconds.
// Response JSON: {"unread": 3, "senders": [{"id":5,"name":"Alice","count":2}]}
//
// Requires: messages.is_read column (run chat_notification_migration.sql)
// ============================================================

session_start();
require_once 'config/database.php';
require_once 'includes/Security.php';

header('Content-Type: application/json');

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['unread' => 0, 'senders' => []]);
    exit();
}

// Admins do not use chat
if ($_SESSION['role'] === 'admin') {
    echo json_encode(['unread' => 0, 'senders' => []]);
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$db      = (new Database())->connect();

// Safely handle case where is_read column doesn't exist yet
try {
    $stmt = $db->prepare(
        "SELECT m.sender_id, u.name AS sender_name, COUNT(*) AS cnt
         FROM messages m
         JOIN users u ON m.sender_id = u.id
         WHERE m.receiver_id = ?
           AND m.is_read = 0
         GROUP BY m.sender_id, u.name
         ORDER BY MAX(m.created_at) DESC
         LIMIT 10"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $total   = 0;
    $senders = [];
    while ($row = $result->fetch_assoc()) {
        $total += (int)$row['cnt'];
        $senders[] = [
            'id'    => (int)$row['sender_id'],
            'name'  => $row['sender_name'],
            'count' => (int)$row['cnt'],
        ];
    }
    $stmt->close();

    echo json_encode(['unread' => $total, 'senders' => $senders]);

} catch (Exception $e) {
    // is_read column may not exist yet — return 0 gracefully
    error_log('get_unread_count: ' . $e->getMessage());
    echo json_encode(['unread' => 0, 'senders' => []]);
}
exit();
