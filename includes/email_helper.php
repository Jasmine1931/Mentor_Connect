<?php
// ============================================================
// includes/email_helper.php
// TASK 5 — Session booking / reschedule email notifications.
//
// Sends HTML email to BOTH mentor and mentee whenever a
// session is booked or rescheduled.
//
// Usage (called from pages/session_booking.php):
//   require_once "../includes/email_helper.php";
//   sendSessionEmail($db, $request_id, $session_date, $start_time, $type);
//   $type: 'booked' | 'rescheduled_mentor' | 'rescheduled_mentee'
// ============================================================

function sendSessionEmail($db, $request_id, $session_date, $start_time, $type = 'booked') {

    // ── 1. Fetch mentor + mentee details ─────────────────────
    $stmt = $db->prepare(
        "SELECT
             mr.id,
             um.name  AS mentor_name,  um.email AS mentor_email,
             ue.name  AS mentee_name,  ue.email AS mentee_email
         FROM mentor_requests mr
         JOIN users um ON mr.mentor_id = um.id
         JOIN users ue ON mr.mentee_id = ue.id
         WHERE mr.id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        error_log("sendSessionEmail: request #{$request_id} not found");
        return;
    }

    $mentor_name  = $row['mentor_name']  ?? 'Mentor';
    $mentor_email = $row['mentor_email'] ?? '';
    $mentee_name  = $row['mentee_name']  ?? 'Mentee';
    $mentee_email = $row['mentee_email'] ?? '';

    if (empty($mentor_email) && empty($mentee_email)) {
        error_log("sendSessionEmail: no email addresses for request #{$request_id}");
        return;
    }

    // ── 2. Format date / time ─────────────────────────────────
    $date_formatted     = date('l, d F Y', strtotime($session_date));   // e.g. Monday, 14 April 2026
    $time_formatted     = date('h:i A',    strtotime($start_time));     // e.g. 10:30 AM
    $end_ts             = strtotime($start_time) + 3600;                // +60 min
    $end_time_formatted = date('h:i A', $end_ts);

    // ── 3. Subject & action label ─────────────────────────────
    switch ($type) {
        case 'rescheduled_mentor':
            $subject      = "[Mentor Connect] Session Rescheduled by Mentor — {$date_formatted}";
            $action_label = 'rescheduled by the Mentor';
            break;
        case 'rescheduled_mentee':
            $subject      = "[Mentor Connect] Session Rescheduled by Mentee — {$date_formatted}";
            $action_label = 'rescheduled by the Mentee';
            break;
        default: // 'booked'
            $subject      = "[Mentor Connect] Session Booked — {$date_formatted}";
            $action_label = 'booked';
            break;
    }

    // ── 4. Portal link ────────────────────────────────────────
    $portal_url = defined('APP_URL') ? APP_URL : 'http://localhost/Mentor';

    // ── 5. HTML email template ────────────────────────────────
    $html_template = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Session Notification</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:\'Segoe UI\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:32px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.09);">

      <!-- Header -->
      <tr>
        <td style="background:linear-gradient(135deg,#0f2027,#203a43,#2c5364);padding:26px 36px;">
          <h1 style="color:#ffffff;margin:0;font-size:21px;font-weight:700;">&#128197; Mentor Connect</h1>
          <p style="color:rgba(255,255,255,0.72);margin:4px 0 0;font-size:13px;">Session Notification</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:30px 36px;">
          <p style="color:#334155;font-size:15px;margin:0 0 18px;">Hello <strong>{{RECIPIENT_NAME}}</strong>,</p>
          <p style="color:#475569;font-size:14px;margin:0 0 22px;line-height:1.65;">
            Your mentoring session has been <strong style="color:#1a2b4a;">{{ACTION_LABEL}}</strong>.
            Please block your calendar for the details below.
          </p>

          <!-- Session detail box -->
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;margin-bottom:22px;">
            <tr><td style="padding:18px 22px;">
              <table width="100%" cellpadding="7" cellspacing="0">
                <tr>
                  <td style="color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;width:115px;">Mentor</td>
                  <td style="color:#1a2b4a;font-size:14px;font-weight:600;">{{MENTOR_NAME}}</td>
                </tr>
                <tr>
                  <td style="color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Mentee</td>
                  <td style="color:#1a2b4a;font-size:14px;font-weight:600;">{{MENTEE_NAME}}</td>
                </tr>
                <tr>
                  <td style="color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Date</td>
                  <td style="color:#1a2b4a;font-size:14px;font-weight:600;">{{DATE}}</td>
                </tr>
                <tr>
                  <td style="color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Time</td>
                  <td style="color:#1a2b4a;font-size:14px;font-weight:600;">{{START_TIME}} &ndash; {{END_TIME}}</td>
                </tr>
                <tr>
                  <td style="color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Duration</td>
                  <td style="color:#1a2b4a;font-size:14px;">60 minutes</td>
                </tr>
              </table>
            </td></tr>
          </table>

          <!-- Tip box -->
          <p style="color:#475569;font-size:13px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:11px 15px;margin:0 0 22px;line-height:1.55;">
            &#128161; <strong>Tip:</strong> Block this time in Teams / Outlook / Google Calendar so you do not miss the session.
          </p>

          <!-- Portal button -->
          <div style="text-align:center;">
            <a href="{{PORTAL_URL}}"
               style="display:inline-block;background:linear-gradient(135deg,#0f2027,#2c5364);color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:13px 34px;border-radius:10px;letter-spacing:0.3px;">
              &#128279; Open Mentor Connect Portal
            </a>
          </div>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:16px 36px;text-align:center;">
          <p style="color:#94a3b8;font-size:11.5px;margin:0;">
            This is an automated notification from Mentor Connect. Please do not reply.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>';

    // ── 6. MIME headers ───────────────────────────────────────
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Mentor Connect <no-reply@mentorconnect.local>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    // ── 7. Send to MENTOR ─────────────────────────────────────
    if (!empty($mentor_email)) {
        $body = str_replace(
            ['{{RECIPIENT_NAME}}', '{{ACTION_LABEL}}', '{{MENTOR_NAME}}', '{{MENTEE_NAME}}',
             '{{DATE}}', '{{START_TIME}}', '{{END_TIME}}', '{{PORTAL_URL}}'],
            [htmlspecialchars($mentor_name, ENT_QUOTES), $action_label,
             htmlspecialchars($mentor_name, ENT_QUOTES), htmlspecialchars($mentee_name, ENT_QUOTES),
             $date_formatted, $time_formatted, $end_time_formatted, htmlspecialchars($portal_url, ENT_QUOTES)],
            $html_template
        );
        if (!@mail($mentor_email, $subject, $body, $headers)) {
            error_log("sendSessionEmail: mail() failed for mentor <{$mentor_email}>");
        }
    }

    // ── 8. Send to MENTEE ─────────────────────────────────────
    if (!empty($mentee_email)) {
        $body = str_replace(
            ['{{RECIPIENT_NAME}}', '{{ACTION_LABEL}}', '{{MENTOR_NAME}}', '{{MENTEE_NAME}}',
             '{{DATE}}', '{{START_TIME}}', '{{END_TIME}}', '{{PORTAL_URL}}'],
            [htmlspecialchars($mentee_name, ENT_QUOTES), $action_label,
             htmlspecialchars($mentor_name, ENT_QUOTES), htmlspecialchars($mentee_name, ENT_QUOTES),
             $date_formatted, $time_formatted, $end_time_formatted, htmlspecialchars($portal_url, ENT_QUOTES)],
            $html_template
        );
        if (!@mail($mentee_email, $subject, $body, $headers)) {
            error_log("sendSessionEmail: mail() failed for mentee <{$mentee_email}>");
        }
    }
}
