<?php
// ============================================================
// accept_request.php
// MODIFIED:
//   - Cross-department check added inside the DB transaction —
//     blocks accept if mentor and mentee share the same department.
//   - 6-month lock stamped: paired_at = NOW() and
//     pairing_expires_at = DATE_ADD(NOW(), INTERVAL 6 MONTH)
//     are written on every successful accept.
//   - Already-paired check now shows expiry date in error message.
// ============================================================

session_start();

require_once "config/database.php";
require_once "config/config.php";
require_once "includes/Security.php";

// Must be logged in AND be a mentor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mentor') {
    Security::safeRedirect('/Mentor/login.php');
    exit();
}

$db        = (new Database())->connect();
$mentor_id = (int) $_SESSION['user_id'];

// Decrypt the HMAC token
$raw_token  = $_GET['request_id'] ?? '';
$request_id = Security::decryptId($raw_token, 'request');

if ($request_id <= 0) {
    $_SESSION['flash_error'] = 'Invalid or expired request link.';
    Security::safeRedirect('/Mentor/dashboard.php');
    exit();
}

// Verify ownership + pending status before opening transaction
$chk = $db->prepare(
    "SELECT id FROM mentor_requests WHERE id = ? AND mentor_id = ? AND status = 'pending'"
);
$chk->bind_param('ii', $request_id, $mentor_id);
$chk->execute();
$exists = $chk->get_result()->num_rows;
$chk->close();

if ($exists === 0) {
    $chk2 = $db->prepare("SELECT status FROM mentor_requests WHERE id = ? AND mentor_id = ?");
    $chk2->bind_param('ii', $request_id, $mentor_id);
    $chk2->execute();
    $row = $chk2->get_result()->fetch_assoc();
    $chk2->close();

    if ($row && $row['status'] === 'already paired') {
        $_SESSION['flash_error'] = 'This mentee has already been paired with another mentor.';
    } elseif ($row && $row['status'] === 'accepted') {
        $_SESSION['flash_success'] = 'This request was already accepted.';
    } else {
        $_SESSION['flash_error'] = 'Request not found, not authorised, or no longer pending.';
    }
    Security::safeRedirect('/Mentor/dashboard.php');
    exit();
}

// Begin atomic transaction
$db->begin_transaction();

try {
    // Lock the row so no other transaction can sneak in
    $stmt = $db->prepare(
        "SELECT id, mentee_id FROM mentor_requests
         WHERE id = ? AND mentor_id = ? AND status = 'pending' FOR UPDATE"
    );
    $stmt->bind_param('ii', $request_id, $mentor_id);
    $stmt->execute();
    $locked = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$locked) {
        $db->rollback();
        $_SESSION['flash_error'] = 'Request status changed — please refresh and try again.';
        Security::safeRedirect('/Mentor/dashboard.php');
        exit();
    }

    $mentee_id = (int) $locked['mentee_id'];

    // ── CROSS-DEPARTMENT CHECK (NEW) ───────────────────────────
    // Mentor and mentee must belong to different departments.
    $dept_stmt = $db->prepare(
        "SELECT u1.department AS mentor_dept, u2.department AS mentee_dept
         FROM users u1
         CROSS JOIN users u2
         WHERE u1.id = ? AND u2.id = ?"
    );
    $dept_stmt->bind_param('ii', $mentor_id, $mentee_id);
    $dept_stmt->execute();
    $dept_row = $dept_stmt->get_result()->fetch_assoc();
    $dept_stmt->close();

    if ($dept_row) {
        $m_dept  = strtolower(trim($dept_row['mentor_dept']  ?? ''));
        $ee_dept = strtolower(trim($dept_row['mentee_dept'] ?? ''));

        if ($m_dept !== '' && $ee_dept !== '' && $m_dept === $ee_dept) {
            $db->rollback();
            Security::log(
                'ACCEPT_CROSS_DEPT_BLOCKED',
                "Mentor {$mentor_id} and mentee {$mentee_id} share department '{$dept_row['mentor_dept']}'. Accept blocked.",
                $db
            );
            $_SESSION['flash_error'] = 'Cannot accept: you and the mentee are in the same department (' .
                htmlspecialchars($dept_row['mentor_dept']) .
                '). Cross-department pairing rule enforced.';
            Security::safeRedirect('/Mentor/dashboard.php');
            exit();
        }
    }

    // ── 6-MONTH LOCK: Check if mentee already has active accepted pairing ──
    $stmt = $db->prepare(
        "SELECT id, paired_at, pairing_expires_at
         FROM mentor_requests
         WHERE mentee_id = ? AND status = 'accepted'
         FOR UPDATE"
    );
    $stmt->bind_param('i', $mentee_id);
    $stmt->execute();
    $already_paired = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($already_paired) {
        // Mark this request as 'already paired' and bail
        $upd = $db->prepare("UPDATE mentor_requests SET status = 'already paired' WHERE id = ?");
        $upd->bind_param('i', $request_id);
        $upd->execute();
        $upd->close();
        $db->commit();

        $expires_display = '';
        if ($already_paired['pairing_expires_at']) {
            $expires_display = ' (6-month lock until ' .
                date('d M Y', strtotime($already_paired['pairing_expires_at'])) . ')';
        }

        Security::log(
            'ACCEPT_BLOCKED',
            "Mentee {$mentee_id} already paired{$expires_display}. Request #{$request_id} closed.",
            $db
        );
        $_SESSION['flash_error'] = "This mentee is already paired with another mentor{$expires_display}.";
        Security::safeRedirect('/Mentor/dashboard.php');
        exit();
    }

    // Check mentor capacity (max 2 active mentees)
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM mentor_requests WHERE mentor_id = ? AND status = 'accepted'"
    );
    $stmt->bind_param('i', $mentor_id);
    $stmt->execute();
    $cnt = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    if ($cnt >= 2) {
        $db->rollback();
        $_SESSION['flash_error'] = 'You are fully booked (2 active mentees).';
        Security::safeRedirect('/Mentor/dashboard.php');
        exit();
    }

    // ── Accept this request — stamp 6-month lock timestamps ───
    $stmt = $db->prepare(
        "UPDATE mentor_requests
         SET status             = 'accepted',
             paired_at          = NOW(),
             pairing_expires_at = DATE_ADD(NOW(), INTERVAL 6 MONTH)
         WHERE id = ? AND mentor_id = ? AND status = 'pending'"
    );
    $stmt->bind_param('ii', $request_id, $mentor_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        $db->rollback();
        $_SESSION['flash_error'] = 'Could not accept — please try again.';
        Security::safeRedirect('/Mentor/dashboard.php');
        exit();
    }

    // Mark all other pending requests from this mentee as 'already paired'
    $stmt = $db->prepare(
        "UPDATE mentor_requests
         SET status = 'already paired'
         WHERE mentee_id = ? AND id != ? AND status = 'pending'"
    );
    $stmt->bind_param('ii', $mentee_id, $request_id);
    $stmt->execute();
    $other_closed = $stmt->affected_rows;
    $stmt->close();

    $db->commit();

    $expires_date = date('d M Y', strtotime('+6 months'));
    Security::log(
        'ACCEPT_REQUEST',
        "Mentor {$mentor_id} accepted request #{$request_id} for mentee {$mentee_id}. " .
        "6-month lock applied (expires {$expires_date}). {$other_closed} other(s) marked already-paired.",
        $db
    );

    $_SESSION['flash_success'] = "Request accepted! Mentee is now paired with you for 6 months (until {$expires_date}).";
    Security::safeRedirect('/Mentor/dashboard.php');

} catch (Exception $e) {
    $db->rollback();
    error_log('Accept Request Error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
    Security::safeRedirect('/Mentor/dashboard.php');
}
?>