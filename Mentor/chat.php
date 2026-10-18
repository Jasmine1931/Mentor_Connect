<?php
// UPDATED: Professional Enterprise Chat — Bug Fixed + Premium UI
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require_once "config/config.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$sender_id   = (int)$_SESSION['user_id'];
$sender_role = $_SESSION['role'];

// FIXED: Admin cannot access chat
if ($sender_role === 'admin') {
    http_response_code(403);
    die("<div style='font-family:sans-serif;padding:40px;text-align:center;color:#e74c3c;'><h3>Access Denied</h3><p>Admins cannot view private chats.</p></div>");
}

// FIX: user_id in URL is an encrypted HMAC token (Security::encryptId).
// ctype_digit() was silently rejecting every token and redirecting to dashboard.
$raw_user_token = $_GET['user_id'] ?? '';
$receiver_id    = 0;

// Accept either: encrypted token (normal page load) or plain int (AJAX sub-requests)
if (ctype_digit($raw_user_token)) {
    // AJAX re-request from JS — already a plain int
    $receiver_id = (int)$raw_user_token;
} else {
    // Initial page load — decrypt the HMAC token
    $receiver_id = Security::decryptId($raw_user_token, 'user');
}

if ($receiver_id <= 0) {
    header("Location: dashboard.php");
    exit();
}

// Get PDO on-demand — only chat.php needs PDO
$pdo = getPDO();

// FIXED: Verify mentor–mentee pairing
$stmtPair = $pdo->prepare("
    SELECT id FROM mentor_requests
    WHERE status = 'accepted'
      AND (
            (mentor_id = ? AND mentee_id = ?)
         OR (mentor_id = ? AND mentee_id = ?)
          )
    LIMIT 1
");
$stmtPair->execute([$sender_id, $receiver_id, $receiver_id, $sender_id]);
if ($stmtPair->rowCount() == 0) {
    header("Location: dashboard.php");
    exit();
}

// Fetch receiver info
$stmtUser = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
$stmtUser->execute([$receiver_id]);
$receiver = $stmtUser->fetch();
if (!$receiver) {
    header("Location: dashboard.php");
    exit();
}
$receiver_name    = htmlspecialchars($receiver['name']);
$receiver_role    = htmlspecialchars(ucfirst($receiver['role']));
$receiver_initial = strtoupper(mb_substr($receiver['name'], 0, 1));

/* =====================================================
   SEND MESSAGE — AJAX POST
   Returns JSON {status, id, time}
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajax'])
    && $_POST['ajax'] === 'send') {

    $content = strip_tags(trim($_POST['message'] ?? ''));

    if ($content !== '' && mb_strlen($content) <= 2000) {
        $stmt = $pdo->prepare(
            "INSERT INTO messages (sender_id, receiver_id, content, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([$sender_id, $receiver_id, $content]);
        $newId = $pdo->lastInsertId();

        // Fetch the exact server timestamp for the new row so client can sync
        $stmtTs = $pdo->prepare("SELECT created_at FROM messages WHERE id = ?");
        $stmtTs->execute([$newId]);
        $row = $stmtTs->fetch();
        $serverTs = $row ? $row['created_at'] : date('Y-m-d H:i:s');

        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'id'     => $newId,
            'ts'     => $serverTs,
            'time'   => date('h:i A', strtotime($serverTs)),
        ]);
    } else {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['status' => 'error']);
    }
    exit();
}

/* =====================================================
   FETCH MESSAGES — AJAX GET
   BUG FIX: Uses last known message ID (not timestamp)
   so a just-sent message is never missed.
===================================================== */
if (isset($_GET['fetch'])) {
    // FIXED: Use last message ID instead of timestamp to avoid same-second miss
    $after_id = isset($_GET['after']) && ctype_digit($_GET['after'])
        ? (int)$_GET['after']
        : 0;

    $stmt = $pdo->prepare("
        SELECT m.id, m.sender_id, m.content, m.created_at,
               u.name AS sender_name
        FROM   messages m
        JOIN   users    u ON u.id = m.sender_id
        WHERE  (
                  (m.sender_id = ? AND m.receiver_id = ?)
               OR (m.sender_id = ? AND m.receiver_id = ?)
               )
          AND  m.id > ?
        ORDER  BY m.created_at ASC, m.id ASC
    ");
    $stmt->execute([$sender_id, $receiver_id, $receiver_id, $sender_id, $after_id]);
    $messages = $stmt->fetchAll();

    $items    = [];
    $prev_date = null;

    foreach ($messages as $row) {
        $msg_date  = date('D, d M Y', strtotime($row['created_at']));
        $msg_time  = date('h:i A',   strtotime($row['created_at']));
        $is_mine   = ((int)$row['sender_id'] === $sender_id);

        $items[] = [
            'id'        => (int)$row['id'],
            'content'   => htmlspecialchars($row['content'], ENT_QUOTES, 'UTF-8'),
            'time'      => $msg_time,
            'date'      => $msg_date,
            'show_date' => ($msg_date !== $prev_date),
            'is_mine'   => $is_mine,
            'name'      => htmlspecialchars($row['sender_name'], ENT_QUOTES, 'UTF-8'),
            'initial'   => strtoupper(mb_substr($row['sender_name'], 0, 1)),
        ];
        $prev_date = $msg_date;
    }

    header('Content-Type: application/json');
    echo json_encode(['messages' => $items, 'count' => count($items)]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
    <title>Chat — <?php echo $receiver_name; ?> · Mentor Connect</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           RESET & BASE
        ============================================================ */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --navy:       #0d1b2a;
            --navy-mid:   #1b2e42;
            --navy-light: #243b55;
            --brand:      #2563eb;
            --brand-dark: #1d4ed8;
            --brand-glow: rgba(37,99,235,.22);
            --teal:       #0ea5e9;
            --teal-light: #38bdf8;
            --surface:    #f1f5f9;
            --card:       #ffffff;
            --border:     #e2e8f0;
            --text-1:     #0f172a;
            --text-2:     #475569;
            --text-3:     #94a3b8;
            --green:      #22c55e;
            --out-bg:     linear-gradient(135deg, #2563eb, #0ea5e9);
            --in-bg:      #ffffff;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            background: var(--navy);
            color: var(--text-1);
            overflow: hidden;
        }

        /* ============================================================
           FULL-SCREEN LAYOUT
        ============================================================ */
        .page {
            display: grid;
            grid-template-columns: 300px 1fr;
            height: 100vh;
            overflow: hidden;
        }

        /* ============================================================
           LEFT SIDEBAR — contact card
        ============================================================ */
        .sidebar {
            background: var(--navy-mid);
            display: flex;
            flex-direction: column;
            border-right: 1px solid rgba(255,255,255,.06);
            overflow: hidden;
        }

        .sidebar-top {
            padding: 28px 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }

        .app-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }
        .app-logo-icon {
            width: 36px; height: 36px;
            background: var(--out-bg);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
        }
        .app-logo-icon svg { width: 20px; height: 20px; fill: none; stroke: #fff; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
        .app-logo-text { font-size: 15px; font-weight: 700; color: #fff; letter-spacing: -.2px; }

        /* Active contact card */
        .contact-card {
            background: rgba(255,255,255,.06);
            border-radius: 14px;
            padding: 16px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid rgba(255,255,255,.08);
        }
        .contact-ava {
            width: 46px; height: 46px;
            border-radius: 50%;
            background: var(--out-bg);
            display: flex; align-items: center; justify-content: center;
            font-size: 19px; font-weight: 700; color: #fff; flex-shrink: 0;
            box-shadow: 0 0 0 3px rgba(37,99,235,.35);
        }
        .contact-info { flex: 1; min-width: 0; }
        .contact-name { font-size: 15px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .contact-role { font-size: 12px; color: var(--text-3); margin-top: 2px; display: flex; align-items: center; gap: 5px; }
        .contact-role::before { content: ''; width: 7px; height: 7px; background: var(--green); border-radius: 50%; flex-shrink: 0; animation: blink 2s infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.35} }

        /* Info tiles */
        .info-tiles {
            padding: 20px 24px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
        }
        .info-tile {
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.06);
            border-radius: 10px;
            padding: 12px 14px;
        }
        .info-tile-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-3); margin-bottom: 4px; }
        .info-tile-val   { font-size: 13px; color: rgba(255,255,255,.75); font-weight: 500; }

        .sidebar-footer {
            padding: 16px 24px;
            border-top: 1px solid rgba(255,255,255,.06);
        }
        .back-link {
            display: flex; align-items: center; gap: 8px;
            color: var(--text-3); text-decoration: none; font-size: 13px;
            padding: 9px 12px; border-radius: 9px;
            transition: background .2s, color .2s;
        }
        .back-link:hover { background: rgba(255,255,255,.07); color: #fff; }
        .back-link svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* ============================================================
           RIGHT PANEL — chat area
        ============================================================ */
        .chat-panel {
            display: flex;
            flex-direction: column;
            height: 100vh;
            background: var(--surface);
            overflow: hidden;
        }

        /* Top bar */
        .chat-topbar {
            background: var(--card);
            padding: 0 28px;
            height: 68px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 6px rgba(0,0,0,.04);
        }
        .topbar-ava {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--out-bg);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; font-weight: 700; color: #fff; flex-shrink: 0;
        }
        .topbar-info { flex: 1; }
        .topbar-name { font-size: 16px; font-weight: 600; color: var(--text-1); }
        .topbar-sub  { font-size: 12px; color: var(--text-3); margin-top: 1px; display: flex; align-items: center; gap: 5px; }
        .topbar-sub .dot { width: 7px; height: 7px; background: var(--green); border-radius: 50%; animation: blink 2s infinite; }
        .topbar-actions { display: flex; gap: 8px; }
        .topbar-btn {
            width: 36px; height: 36px;
            border: 1.5px solid var(--border);
            border-radius: 9px; background: transparent;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: var(--text-2);
            transition: background .2s, color .2s, border-color .2s;
        }
        .topbar-btn:hover { background: var(--surface); color: var(--brand); border-color: var(--brand); }
        .topbar-btn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* ============================================================
           MESSAGE AREA
        ============================================================ */
        .chat-body {
            flex: 1;
            overflow-y: auto;
            padding: 28px 32px 12px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            scroll-behavior: smooth;
        }
        .chat-body::-webkit-scrollbar { width: 5px; }
        .chat-body::-webkit-scrollbar-track { background: transparent; }
        .chat-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 6px; }

        /* Empty state */
        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            color: var(--text-3);
            user-select: none;
        }
        .empty-state-icon {
            width: 72px; height: 72px;
            background: linear-gradient(135deg, #e0f2fe, #dbeafe);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
        }
        .empty-state-icon svg { width: 36px; height: 36px; fill: none; stroke: #93c5fd; stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round; }
        .empty-state h4 { font-size: 16px; font-weight: 600; color: var(--text-2); }
        .empty-state p  { font-size: 13px; }

        /* Date divider */
        .date-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0 10px;
        }
        .date-divider::before, .date-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }
        .date-divider span {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-3);
            background: var(--surface);
            padding: 3px 12px;
            border-radius: 20px;
            border: 1px solid var(--border);
            white-space: nowrap;
        }

        /* Message rows */
        .msg-row {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            margin-bottom: 4px;
            animation: msgIn .18s ease;
        }
        @keyframes msgIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0);   }
        }

        .msg-row.out { flex-direction: row-reverse; }
        .msg-row.in  { flex-direction: row; }

        /* Avatar in message row */
        .msg-ava {
            width: 30px; height: 30px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700; color: #fff;
            flex-shrink: 0; align-self: flex-end;
            margin-bottom: 2px;
        }
        .msg-ava.out { background: var(--out-bg); }
        .msg-ava.in  { background: linear-gradient(135deg, #7c3aed, #6d28d9); }

        /* Group — hide avatar if previous same sender */
        .msg-row.grouped .msg-ava { visibility: hidden; }

        /* Bubble */
        .bubble {
            max-width: 62%;
            padding: 10px 15px 8px;
            font-size: 14px;
            line-height: 1.58;
            word-break: break-word;
            position: relative;
        }
        .msg-row.out .bubble {
            background: var(--out-bg);
            color: #fff;
            border-radius: 18px 18px 4px 18px;
            box-shadow: 0 4px 16px rgba(37,99,235,.28);
        }
        .msg-row.in .bubble {
            background: var(--in-bg);
            color: var(--text-1);
            border-radius: 18px 18px 18px 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
        }
        /* Sending (optimistic) */
        .msg-row.sending .bubble {
            opacity: .65;
        }

        .bubble-sender {
            font-size: 11px;
            font-weight: 700;
            color: #818cf8;
            margin-bottom: 3px;
        }
        .bubble-text { }
        .bubble-meta {
            font-size: 10px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 4px;
        }
        .msg-row.out .bubble-meta { color: rgba(255,255,255,.6); }
        .msg-row.in  .bubble-meta { color: var(--text-3); }

        /* Tick icon */
        .tick-icon { width: 14px; height: 14px; display: flex; align-items: center; }
        .tick-icon svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* ============================================================
           INPUT BAR
        ============================================================ */
        .chat-footer {
            flex-shrink: 0;
            background: var(--card);
            border-top: 1px solid var(--border);
            padding: 14px 28px 18px;
        }
        .input-row {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 18px;
            padding: 10px 10px 10px 18px;
            transition: border-color .2s, box-shadow .2s;
        }
        .input-row:focus-within {
            border-color: var(--brand);
            box-shadow: 0 0 0 4px var(--brand-glow);
        }
        .msg-input {
            flex: 1;
            border: none;
            background: transparent;
            outline: none;
            font-size: 14.5px;
            font-family: 'Inter', sans-serif;
            color: var(--text-1);
            resize: none;
            max-height: 130px;
            line-height: 1.55;
        }
        .msg-input::placeholder { color: var(--text-3); }
        .input-extras {
            display: flex;
            align-items: flex-end;
            gap: 6px;
        }
        .char-badge {
            font-size: 11px;
            color: var(--text-3);
            padding: 0 4px;
            align-self: center;
        }
        .char-badge.warn { color: #f97316; }
        .send-button {
            width: 42px; height: 42px;
            border: none; border-radius: 13px;
            background: var(--out-bg);
            color: #fff;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: transform .15s, box-shadow .15s;
            box-shadow: 0 4px 14px rgba(37,99,235,.35);
            flex-shrink: 0;
        }
        .send-button:hover  { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(37,99,235,.45); }
        .send-button:active { transform: scale(.93); }
        .send-button svg { width: 18px; height: 18px; fill: none; stroke: #fff; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
        .hint-text {
            text-align: right;
            font-size: 11px;
            color: var(--text-3);
            margin-top: 7px;
            letter-spacing: .2px;
        }

        /* ============================================================
           SCROLLBAR — Firefox
        ============================================================ */
        .chat-body { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }

        /* ============================================================
           RESPONSIVE
        ============================================================ */
        @media (max-width: 720px) {
            .page { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .chat-body { padding: 16px 14px 8px; }
            .chat-footer { padding: 10px 14px 14px; }
            .chat-topbar { padding: 0 16px; }
        }
    </style>
</head>
<body>
<div class="page">

    <!-- ====================================================
         LEFT SIDEBAR
    ==================================================== -->
    <aside class="sidebar">
        <div class="sidebar-top">
            <!-- Logo -->
            <div class="app-logo">
                <div class="app-logo-icon">
                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <span class="app-logo-text">Mentor Connect</span>
            </div>

            <!-- Active contact -->
            <div class="contact-card">
                <div class="contact-ava"><?php echo $receiver_initial; ?></div>
                <div class="contact-info">
                    <div class="contact-name"><?php echo $receiver_name; ?></div>
                    <div class="contact-role"><?php echo $receiver_role; ?></div>
                </div>
            </div>
        </div>

        <!-- Info tiles -->
        <div class="info-tiles">
            <div class="info-tile">
                <div class="info-tile-label">Conversation type</div>
                <div class="info-tile-val">Mentor – Mentee (1:1)</div>
            </div>
            <div class="info-tile">
                <div class="info-tile-label">Connection</div>
                <div class="info-tile-val">Accepted &amp; Active</div>
            </div>
            <div class="info-tile">
                <div class="info-tile-label">Messages</div>
                <div class="info-tile-val" id="sidebarMsgCount">Loading…</div>
            </div>
        </div>

        <div class="sidebar-footer">
            <a href="dashboard.php" class="back-link">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                Back to Dashboard
            </a>
        </div>
    </aside>

    <!-- ====================================================
         RIGHT: CHAT PANEL
    ==================================================== -->
    <main class="chat-panel">

        <!-- Topbar -->
        <div class="chat-topbar">
            <div class="topbar-ava"><?php echo $receiver_initial; ?></div>
            <div class="topbar-info">
                <div class="topbar-name"><?php echo $receiver_name; ?></div>
                <div class="topbar-sub">
                    <span class="dot"></span>
                    <?php echo $receiver_role; ?> · Mentor Connect
                </div>
            </div>
            <div class="topbar-actions">
                <!-- Mobile back button -->
                <a href="dashboard.php" style="display:none" class="topbar-btn" id="mobileBack" title="Back">
                    <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                </a>
            </div>
        </div>

        <!-- Messages -->
        <div class="chat-body" id="chatBody">
            <div class="empty-state" id="emptyState">
                <div class="empty-state-icon">
                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <h4>Start the conversation</h4>
                <p>Messages are private between you and <?php echo $receiver_name; ?>.</p>
            </div>
        </div>

        <!-- Input bar -->
        <div class="chat-footer">
            <div class="input-row" id="inputRow">
                <textarea
                    class="msg-input"
                    id="msgInput"
                    placeholder="Write a message…"
                    rows="1"
                    maxlength="2000"
                    autocomplete="off"
                    spellcheck="true"
                ></textarea>
                <div class="input-extras">
                    <span class="char-badge" id="charBadge"></span>
                    <button class="send-button" id="sendBtn" title="Send (Enter)">
                        <svg viewBox="0 0 24 24">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="hint-text">Press <strong>Enter</strong> to send &nbsp;·&nbsp; <strong>Shift + Enter</strong> for new line</div>
        </div>

    </main>
</div>

<script>
(function () {
    'use strict';

    /* ---- Config ---- */
    var RECEIVER_ID  = <?php echo $receiver_id; ?>;
    var MY_INITIAL   = '<?php echo strtoupper(mb_substr($_SESSION['name'], 0, 1)); ?>';
    var THEM_INITIAL = '<?php echo $receiver_initial; ?>';
    var POLL_MS      = 3000;

    /* ---- State ---- */
    var lastMsgId      = 0;   // FIXED: track by ID not timestamp
    var totalMsgCount  = 0;
    var lastSenderId   = null;
    var lastDateLabel  = null;
    var pollTimer      = null;
    var sending        = false;

    /* ---- DOM refs ---- */
    var chatBody   = document.getElementById('chatBody');
    var emptyState = document.getElementById('emptyState');
    var msgInput   = document.getElementById('msgInput');
    var sendBtn    = document.getElementById('sendBtn');
    var charBadge  = document.getElementById('charBadge');
    var sideCount  = document.getElementById('sidebarMsgCount');

    /* ---- Auto-grow textarea ---- */
    msgInput.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 130) + 'px';
        var rem = 2000 - this.value.length;
        charBadge.textContent = rem < 200 ? rem + ' left' : '';
        charBadge.classList.toggle('warn', rem < 50);
    });

    /* ====================================================
       RENDER — append new message rows to DOM
    ==================================================== */
    function appendMessages(messages) {
        if (!messages || messages.length === 0) return;

        // Remove empty state on first real message
        if (emptyState && emptyState.parentNode) {
            emptyState.parentNode.removeChild(emptyState);
        }

        var fragment = document.createDocumentFragment();

        messages.forEach(function (m) {
            // Date divider
            if (m.show_date && m.date !== lastDateLabel) {
                lastDateLabel = m.date;
                var div = document.createElement('div');
                div.className = 'date-divider';
                div.innerHTML = '<span>' + m.date + '</span>';
                fragment.appendChild(div);
            }

            var isOut    = m.is_mine;
            var sameUser = (lastSenderId === (isOut ? 'me' : m.name));
            lastSenderId = isOut ? 'me' : m.name;

            // Row
            var row = document.createElement('div');
            row.className = 'msg-row ' + (isOut ? 'out' : 'in') + (sameUser ? ' grouped' : '');

            // Avatar
            var ava = document.createElement('div');
            ava.className = 'msg-ava ' + (isOut ? 'out' : 'in');
            ava.textContent = isOut ? MY_INITIAL : THEM_INITIAL;

            // Bubble
            var bbl = document.createElement('div');
            bbl.className = 'bubble';

            // Sender name (only for incoming, only if not grouped)
            if (!isOut && !sameUser) {
                var senderEl = document.createElement('div');
                senderEl.className = 'bubble-sender';
                senderEl.textContent = m.name;
                bbl.appendChild(senderEl);
            }

            // Text
            var textEl = document.createElement('div');
            textEl.className = 'bubble-text';
            textEl.textContent = m.content; // safe — already htmlspecialchars from PHP
            bbl.appendChild(textEl);

            // Meta: time + tick
            var meta = document.createElement('div');
            meta.className = 'bubble-meta';
            meta.innerHTML = m.time +
                (isOut ? ' <span class="tick-icon"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>' : '');
            bbl.appendChild(meta);

            if (isOut) {
                row.appendChild(bbl);
                row.appendChild(ava);
            } else {
                row.appendChild(ava);
                row.appendChild(bbl);
            }

            fragment.appendChild(row);
            totalMsgCount++;

            // Track highest ID
            if (m.id > lastMsgId) lastMsgId = m.id;
        });

        chatBody.appendChild(fragment);
        chatBody.scrollTop = chatBody.scrollHeight;

        if (sideCount) sideCount.textContent = totalMsgCount + ' message' + (totalMsgCount !== 1 ? 's' : '');
    }

    /* ====================================================
       FETCH — poll for new messages (by ID, not timestamp)
       BUG FIX: Using ID avoids the same-second miss
    ==================================================== */
    function fetchMessages() {
        fetch('chat.php?user_id=' + RECEIVER_ID + '&fetch=1&after=' + lastMsgId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.count > 0) {
                    appendMessages(data.messages);
                }
            })
            .catch(function () {}); // silent fail — next poll will catch up
    }

    /* ====================================================
       SEND — optimistic UI, then confirm from server
    ==================================================== */
    function sendMessage() {
        var text = msgInput.value.trim();
        if (!text || sending) return;

        sending = true;
        sendBtn.style.opacity = '0.5';

        // --- OPTIMISTIC: show bubble instantly ---
        var now     = new Date();
        var timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

        var optimisticMsg = [{
            id:        -1,           // temp
            content:   text,
            time:      timeStr,
            date:      now.toDateString(),
            show_date: false,        // date already shown or will be from server
            is_mine:   true,
            name:      '',
            initial:   MY_INITIAL,
        }];

        // Add optimistic bubble with sending class
        var prevLastId = lastMsgId; // save before append
        appendMessages(optimisticMsg);

        // Mark last bubble as "sending" visually
        var rows = chatBody.querySelectorAll('.msg-row.out');
        var lastRow = rows[rows.length - 1];
        if (lastRow) lastRow.classList.add('sending');

        // Clear input immediately
        msgInput.value = '';
        msgInput.style.height = 'auto';
        charBadge.textContent = '';

        // --- POST to server ---
        var fd = new FormData();
        fd.append('ajax', 'send');
        fd.append('message', text);

        fetch('chat.php?user_id=' + RECEIVER_ID, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status === 'ok') {
                    // Remove optimistic bubble
                    if (lastRow && lastRow.parentNode) {
                        lastRow.parentNode.removeChild(lastRow);
                    }
                    totalMsgCount--;                // will be re-added by fetch
                    lastSenderId = null;            // reset grouping so real bubble renders correctly
                    lastMsgId = prevLastId;         // reset so fetch gets the new message

                    // FIXED: Immediately fetch to get the real server row (correct ID + ts)
                    fetchMessages();
                }
            })
            .catch(function () {
                // On network error: remove sending class so user knows it failed
                if (lastRow) lastRow.classList.remove('sending');
                msgInput.value = text; // restore text
            })
            .finally(function () {
                sending = false;
                sendBtn.style.opacity = '1';
            });
    }

    /* ---- Event listeners ---- */
    sendBtn.addEventListener('click', sendMessage);

    msgInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    /* ---- Mobile back button ---- */
    if (window.innerWidth <= 720) {
        var mb = document.getElementById('mobileBack');
        if (mb) mb.style.display = 'flex';
    }

    /* ---- Boot: initial load + start polling ---- */
    fetchMessages();
    pollTimer = setInterval(fetchMessages, POLL_MS);

    /* ---- Focus input on load ---- */
    msgInput.focus();
})();
</script>
</body>
</html>