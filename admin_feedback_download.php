<?php
// ============================================================
// admin_feedback_download.php
// Handles two actions:
//   ?action=full                     → Download ALL feedback report
//   ?action=individual&mentee_id=X   → Download ONE mentee's feedback
//
// Formats:
//   &format=excel  → Excel (.xls)
//   (default)      → HTML report
//
// FIX: Corrected SQL JOINs to properly fetch mentor details.
//      The feedback table stores only user_id (mentee) + session_date.
//      Mentor is resolved via mentor_requests matched on mentee_id + session_date.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

ob_start();

error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/database.php';

// Admin check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])
    || (string)$_SESSION['role'] !== 'admin') {
    ob_end_clean();
    header('Location: dashboard.php');
    exit();
}

$db = (new Database())->connect();
if (!$db) {
    ob_end_clean();
    header('Location: dashboard.php');
    exit();
}

$action   = $_GET['action']  ?? 'full';
$format   = $_GET['format']  ?? 'html';   // 'html' or 'excel'
$menteeId = isset($_GET['mentee_id']) ? (int)$_GET['mentee_id'] : 0;

// ─────────────────────────────────────────────────────────────
// CORRECTED SQL:
//   feedback.user_id  = mentee's users.id
//   mentor_requests links mentee_id -> mentor_id
//   We join mentor_requests on mentee_id AND session_date match
//   to resolve which mentor conducted the session.
// ─────────────────────────────────────────────────────────────
$baseSQL = "
    SELECT
        f.id              AS feedback_id,
        f.session_date,
        f.rating,
        f.comments,
        f.created_at,

        mentee.id         AS mentee_id,
        mentee.name       AS mentee_name,
        mentee.email      AS mentee_email,
        mentee.department AS mentee_dept,
        mentee.designation AS mentee_desig,

        mentor.id         AS mentor_id,
        mentor.name       AS mentor_name,
        mentor.email      AS mentor_email,
        mentor.department AS mentor_dept,
        mentor.designation AS mentor_desig,

        mr.id             AS request_id,
        mr.skill          AS session_skill,
        mr.session_duration,
        mr.session_completed,
        mr.start_time

    FROM feedback f

    JOIN users mentee ON mentee.id = f.user_id

    LEFT JOIN mentor_requests mr
        ON mr.mentee_id = f.user_id
        AND mr.status   = 'accepted'
        AND (
            mr.session_date = f.session_date
            OR (mr.session_date IS NULL AND f.session_date IS NULL)
        )

    LEFT JOIN users mentor ON mentor.id = mr.mentor_id
";

// ─────────────────────────────────────────────────────────────
// FETCH DATA
// ─────────────────────────────────────────────────────────────
if ($action === 'individual' && $menteeId > 0) {

    $stmtU = $db->prepare("SELECT id, name, email, department, designation FROM users WHERE id = ? LIMIT 1");
    $stmtU->bind_param("i", $menteeId);
    $stmtU->execute();
    $menteeInfo = $stmtU->get_result()->fetch_assoc();
    if (!$menteeInfo) {
        ob_end_clean();
        header('Location: views/admin/feedback.php');
        exit();
    }

    $sql  = $baseSQL . " WHERE f.user_id = ? ORDER BY f.session_date DESC, f.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $menteeId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $safeName    = preg_replace('/\s+/', '_', $menteeInfo['name']);
    $fileBase    = 'Feedback_' . $safeName . '_' . date('Y-m-d');
    $reportTitle = 'Feedback Report — ' . htmlspecialchars($menteeInfo['name']);
    $isSingle    = true;

} else {
    $sql    = $baseSQL . " ORDER BY mentee.name ASC, f.session_date DESC, f.created_at DESC";
    $result = $db->query($sql);
    $rows   = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }

    $fileBase    = 'All_Feedback_Report_' . date('Y-m-d');
    $reportTitle = 'Complete Feedback Report — All Mentees';
    $isSingle    = false;
}

// ─────────────────────────────────────────────────────────────
// GROUP BY MENTEE
// ─────────────────────────────────────────────────────────────
$grouped = [];
foreach ($rows as $row) {
    $mid = $row['mentee_id'];
    if (!isset($grouped[$mid])) {
        $grouped[$mid] = [
            'mentee_name'  => $row['mentee_name'],
            'mentee_email' => $row['mentee_email'],
            'mentee_dept'  => $row['mentee_dept'],
            'mentee_desig' => $row['mentee_desig'],
            'feedbacks'    => [],
        ];
    }
    $grouped[$mid]['feedbacks'][] = $row;
}

// ─────────────────────────────────────────────────────────────
// SUMMARY STATS
// ─────────────────────────────────────────────────────────────
$totalFeedback = count($rows);
$avgRating     = $totalFeedback > 0
    ? round(array_sum(array_column($rows, 'rating')) / $totalFeedback, 1)
    : 0;
$generatedOn   = date('F d, Y \a\t h:i A');

$stars = function (int $r): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= $i <= $r ? '★' : '☆';
    }
    return $out;
};

if (!function_exists('array_key_with_max')) {
    function array_key_with_max(array $arr) {
        $max = max($arr);
        return array_search($max, $arr);
    }
}

ob_end_clean();

// ═════════════════════════════════════════════════════════════
// EXCEL FORMAT
// ═════════════════════════════════════════════════════════════
if ($format === 'excel') {

    $filename = $fileBase . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM for Excel
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]><xml>
<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name><?php echo $isSingle ? 'Feedback' : 'All_Feedback'; ?></x:Name>
<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>
</xml><![endif]-->
<style>
  td, th { border: 1px solid #bbb; padding: 5px 10px; font-size: 12px; font-family: Arial, sans-serif; }
  .hdr    { background-color: #1a3a6b; color: #ffffff; font-weight: bold; }
  .title  { background-color: #1a3a6b; color: #ffffff; font-size: 14px; font-weight: bold; }
  .meta   { background-color: #e8edf8; color: #333; font-size: 11px; }
  .grp    { background-color: #dbeafe; color: #1e3a8a; font-weight: bold; font-size: 12px; }
  .even   { background-color: #f7f9fc; }
</style>
</head>
<body>
<table>
  <tr>
    <td class="title" colspan="11"><?php echo htmlspecialchars($reportTitle); ?></td>
  </tr>
  <tr>
    <td class="meta" colspan="11">
      Generated: <?php echo $generatedOn; ?> &nbsp;|&nbsp;
      By: Admin (<?php echo htmlspecialchars($_SESSION['name'] ?? 'Administrator'); ?>) &nbsp;|&nbsp;
      Total Feedback: <?php echo $totalFeedback; ?> &nbsp;|&nbsp;
      Avg Rating: <?php echo $avgRating; ?>/5
    </td>
  </tr>
  <tr><td colspan="11"></td></tr>
  <tr>
    <th class="hdr">#</th>
    <th class="hdr">Mentee Name</th>
    <th class="hdr">Mentee Email</th>
    <th class="hdr">Mentee Department</th>
    <th class="hdr">Mentee Designation</th>
    <th class="hdr">Mentor Name</th>
    <th class="hdr">Mentor Email</th>
    <th class="hdr">Mentor Department</th>
    <th class="hdr">Session Date</th>
    <th class="hdr">Rating (out of 5)</th>
    <th class="hdr">Feedback Comments</th>
  </tr>

<?php
$rowNum = 0;
foreach ($grouped as $mId => $menteeData):
?>
  <tr>
    <td class="grp" colspan="11">
      Mentee: <?php echo htmlspecialchars($menteeData['mentee_name'] ?? '—'); ?> &nbsp;|&nbsp;
      Email: <?php echo htmlspecialchars($menteeData['mentee_email'] ?? '—'); ?> &nbsp;|&nbsp;
      Dept: <?php echo htmlspecialchars($menteeData['mentee_dept'] ?? '—'); ?> &nbsp;|&nbsp;
      Desig: <?php echo htmlspecialchars($menteeData['mentee_desig'] ?? '—'); ?> &nbsp;|&nbsp;
      Sessions: <?php echo count($menteeData['feedbacks']); ?>
    </td>
  </tr>
<?php foreach ($menteeData['feedbacks'] as $idx => $fb): $rowNum++; ?>
  <tr class="<?php echo ($idx % 2 === 1) ? 'even' : ''; ?>">
    <td><?php echo $rowNum; ?></td>
    <td><?php echo htmlspecialchars($fb['mentee_name'] ?? '—'); ?></td>
    <td><?php echo htmlspecialchars($fb['mentee_email'] ?? '—'); ?></td>
    <td><?php echo htmlspecialchars($fb['mentee_dept'] ?? '—'); ?></td>
    <td><?php echo htmlspecialchars($fb['mentee_desig'] ?? '—'); ?></td>
    <td><?php echo htmlspecialchars($fb['mentor_name'] ?? '—'); ?></td>
    <td><?php echo htmlspecialchars($fb['mentor_email'] ?? '—'); ?></td>
    <td><?php echo htmlspecialchars($fb['mentor_dept'] ?? '—'); ?></td>
    <td><?php echo $fb['session_date'] ? date('d M Y', strtotime($fb['session_date'])) : '—'; ?></td>
    <td><?php echo (int)$fb['rating']; ?>/5</td>
    <td><?php echo htmlspecialchars($fb['comments'] ?? '—'); ?></td>
  </tr>
<?php endforeach; ?>
  <tr><td colspan="11"></td></tr>
<?php endforeach; ?>

<?php if (empty($grouped)): ?>
  <tr><td colspan="11" style="color:#999;font-style:italic;text-align:center;">No feedback records found.</td></tr>
<?php endif; ?>

</table>
</body>
</html>
<?php
    exit();
}

// ═════════════════════════════════════════════════════════════
// HTML FORMAT (default)
// ═════════════════════════════════════════════════════════════
$filename      = $fileBase . '.html';
$countByRating = $totalFeedback > 0 ? array_count_values(array_column($rows, 'rating')) : [];
$topRating     = $countByRating ? array_key_with_max($countByRating) : 'N/A';

header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($reportTitle); ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f4f6fb; color:#2d3748; font-size:13px; }
  .report-wrapper { max-width:1200px; margin:30px auto; padding:0 20px 40px; }

  .report-header {
    background:linear-gradient(135deg,#1a3a6b 0%,#2563eb 100%);
    color:#fff; border-radius:12px 12px 0 0;
    padding:32px 36px; display:flex; justify-content:space-between; align-items:flex-start;
  }
  .report-header h1 { font-size:22px; font-weight:700; margin-bottom:6px; }
  .report-header .meta { font-size:12px; opacity:0.85; line-height:1.8; }
  .system-name {
    font-size:13px; font-weight:600; opacity:0.9;
    border:1px solid rgba(255,255,255,0.4);
    padding:4px 12px; border-radius:20px; display:inline-block;
  }

  .summary-bar {
    background:#fff; border:1px solid #e2e8f0;
    padding:18px 36px; display:flex; gap:40px; border-top:none; flex-wrap:wrap;
  }
  .sum-item { text-align:center; }
  .sum-item .val { font-size:26px; font-weight:700; color:#1a3a6b; }
  .sum-item .lbl { font-size:11px; color:#718096; text-transform:uppercase; letter-spacing:0.05em; margin-top:2px; }

  .mentee-section { background:#fff; border:1px solid #e2e8f0; border-top:none; padding:24px 36px; }
  .mentee-section + .mentee-section { border-top:3px solid #e2e8f0; }

  .mentee-banner {
    display:flex; justify-content:space-between; align-items:flex-start;
    background:#f0f4ff; border:1px solid #c7d7fd;
    border-radius:8px; padding:14px 20px; margin-bottom:18px;
  }
  .info-grid { display:flex; gap:28px; flex-wrap:wrap; }
  .info-item { min-width:150px; }
  .info-label { font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:2px; }
  .info-value { font-size:13px; font-weight:600; color:#1a3a6b; }
  .mb-count {
    background:#1a3a6b; color:#fff;
    border-radius:20px; padding:4px 14px; font-size:12px; font-weight:600;
    white-space:nowrap; align-self:flex-start;
  }

  table { width:100%; border-collapse:collapse; margin-top:4px; }
  thead tr { background:#1a3a6b; color:#fff; }
  thead th { padding:11px 12px; text-align:left; font-size:11px; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; }
  tbody tr:nth-child(even) { background:#f7f9fc; }
  tbody tr:hover { background:#eef2ff; }
  tbody td { padding:10px 12px; vertical-align:top; border-bottom:1px solid #e8edf5; font-size:12px; }
  .rating-stars { color:#f59e0b; font-size:14px; letter-spacing:1px; }
  .rating-num { font-size:11px; color:#718096; }
  .comments-cell { max-width:200px; word-wrap:break-word; line-height:1.5; }
  .mentor-name { font-weight:600; color:#1a3a6b; }
  .mentor-sub { font-size:11px; color:#64748b; }
  .no-data { color:#a0aec0; font-style:italic; font-size:12px; padding:16px 0; }

  .report-footer {
    background:#2d3748; color:#a0aec0;
    border-radius:0 0 12px 12px;
    padding:14px 36px; font-size:11px;
    display:flex; justify-content:space-between;
    border:1px solid #e2e8f0; border-top:none;
  }
  @media print {
    body { background:#fff; }
    .report-wrapper { margin:0; }
    .mentee-section + .mentee-section { page-break-before:always; }
  }
</style>
</head>
<body>
<div class="report-wrapper">

  <div class="report-header">
    <div>
      <h1><?php echo htmlspecialchars($reportTitle); ?></h1>
      <div class="meta">
        Generated on: <?php echo $generatedOn; ?><br>
        Generated by: Admin (<?php echo htmlspecialchars($_SESSION['name'] ?? 'Administrator'); ?>)<br>
        <?php if (!$isSingle): ?>
          Total Mentees with Feedback: <?php echo count($grouped); ?>
        <?php endif; ?>
      </div>
    </div>
    <div><div class="system-name">Mentor-Mentee Management System</div></div>
  </div>

  <div class="summary-bar">
    <div class="sum-item">
      <div class="val"><?php echo $totalFeedback; ?></div>
      <div class="lbl">Total Feedback</div>
    </div>
    <div class="sum-item">
      <div class="val"><?php echo count($grouped); ?></div>
      <div class="lbl"><?php echo $isSingle ? 'Mentee' : 'Mentees'; ?></div>
    </div>
    <div class="sum-item">
      <div class="val"><?php echo $avgRating; ?>/5</div>
      <div class="lbl">Avg Rating</div>
    </div>
    <div class="sum-item">
      <div class="val"><?php echo $topRating !== 'N/A' ? $topRating . '★' : 'N/A'; ?></div>
      <div class="lbl">Most Common Rating</div>
    </div>
    <div class="sum-item">
      <div class="val" style="color:#1a7a35;"><?php echo $totalFeedback; ?></div>
      <div class="lbl">Feedback Submitted</div>
    </div>
  </div>

  <?php if (empty($grouped)): ?>
  <div class="mentee-section">
    <p class="no-data">No feedback records found.</p>
  </div>
  <?php else: ?>

  <?php foreach ($grouped as $mId => $menteeData): ?>
  <div class="mentee-section">
    <div class="mentee-banner">
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">Mentee Name</div>
          <div class="info-value"><?php echo htmlspecialchars($menteeData['mentee_name'] ?? '—'); ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Email</div>
          <div class="info-value" style="font-size:12px;"><?php echo htmlspecialchars($menteeData['mentee_email'] ?? '—'); ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Department</div>
          <div class="info-value"><?php echo htmlspecialchars($menteeData['mentee_dept'] ?? '—'); ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Designation</div>
          <div class="info-value"><?php echo htmlspecialchars($menteeData['mentee_desig'] ?? '—'); ?></div>
        </div>
      </div>
      <div class="mb-count"><?php echo count($menteeData['feedbacks']); ?> Session<?php echo count($menteeData['feedbacks']) !== 1 ? 's' : ''; ?></div>
    </div>

    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Session Date</th>
          <th>Mentor Name</th>
          <th>Mentor Email</th>
          <th>Mentor Dept.</th>
          <th>Skills / Topic</th>
          <th>Rating</th>
          <th>Feedback Comments</th>
          <th>Submitted On</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($menteeData['feedbacks'] as $idx => $fb): ?>
        <tr>
          <td><?php echo $idx + 1; ?></td>
          <td><?php echo $fb['session_date'] ? date('d M Y', strtotime($fb['session_date'])) : '—'; ?></td>
          <td>
            <div class="mentor-name"><?php echo htmlspecialchars($fb['mentor_name'] ?? '—'); ?></div>
            <div class="mentor-sub"><?php echo htmlspecialchars($fb['mentor_desig'] ?? '—'); ?></div>
          </td>
          <td><span class="mentor-sub"><?php echo htmlspecialchars($fb['mentor_email'] ?? '—'); ?></span></td>
          <td><?php echo htmlspecialchars($fb['mentor_dept'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($fb['session_skill'] ?? '—'); ?></td>
          <td>
            <span class="rating-stars"><?php echo $stars((int)$fb['rating']); ?></span>
            <span class="rating-num"> (<?php echo (int)$fb['rating']; ?>/5)</span>
          </td>
          <td class="comments-cell"><?php echo nl2br(htmlspecialchars($fb['comments'] ?? '—')); ?></td>
          <td><?php echo $fb['created_at'] ? date('d M Y', strtotime($fb['created_at'])) : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <div class="report-footer">
    <span>Mentor-Mentee Management System &mdash; Confidential</span>
    <span>Report generated: <?php echo $generatedOn; ?></span>
  </div>

</div>
</body>
</html>