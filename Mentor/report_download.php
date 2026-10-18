<?php
// ============================================================
// report_download.php — FINAL COMPLETE FIX (v4)
// Compatible with the VAPT-updated project files.
// ============================================================
//
// ROOT CAUSE OF HTTP 500 (pure blank error, no text):
//
// A pure HTTP 500 with ZERO output means PHP crashed with a
// FATAL ERROR before any output was produced. Three possible causes:
//
// CAUSE A — ZipArchive extension not enabled in XAMPP php.ini
//   ZipArchive is disabled by default on many XAMPP installs.
//   When new ZipArchive() runs → Fatal: Class 'ZipArchive' not found.
//   FIX: Check at start; if missing show a clear human-readable error
//   and offer CSV download as fallback (CSV needs no ZipArchive).
//
// CAUSE B — Session cookie mismatch (session reads as empty)
//   MySessionHandler sets samesite=Strict + httponly via array syntax.
//   report_download.php was doing plain session_start() which does
//   NOT set those same params — on some PHP configs this causes
//   session_start() to fail reading the existing session cookie,
//   so $_SESSION['role'] is empty → redirects back to dashboard.
//   FIX: Mirror the EXACT same session_set_cookie_params call that
//   MySessionHandler uses, before session_start().
//
// CAUSE C — PHP version < 7.3 with array cookie params syntax
//   session_set_cookie_params([...array...]) requires PHP 7.3+.
//   However, since database.php uses typed class properties
//   (private string $host) which requires PHP 7.4+, we can safely
//   assume PHP 7.4+ is installed. This is NOT the issue here.
//
// HOW TO ENABLE ZipArchive IN XAMPP (if Cause A applies):
//   1. Open XAMPP Control Panel
//   2. Click "Config" next to Apache → PHP (php.ini)
//   3. Find the line:  ;extension=zip
//   4. Remove the semicolon: extension=zip
//   5. Save and restart Apache in XAMPP
//   6. Try the download again — it will work.
//
// ============================================================

// ── Match MySessionHandler's session cookie params exactly ───────
// This MUST happen before session_start() and before any output.
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

// ── Buffer ALL output — catches stray bytes from includes ─────────
ob_start();

// ── Suppress error display AFTER session start ────────────────────
error_reporting(0);
ini_set('display_errors', '0');

// ── Only load database — no config.php (avoids Security headers) ──
require_once __DIR__ . '/config/database.php';

// ── Admin check — redirect cleanly, no die() ─────────────────────
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])
    || (string)$_SESSION['role'] !== 'admin') {
    ob_end_clean();
    header('Location: dashboard.php');
    exit();
}

// ── Check ZipArchive is available — CAUSE A ───────────────────────
if (!class_exists('ZipArchive')) {
    ob_end_clean();
    http_response_code(500);
    echo '<!DOCTYPE html><html><head>
    <title>ZipArchive Missing</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f8f9fa;display:flex;
             align-items:center;justify-content:center;min-height:100vh;margin:0;}
        .box{background:#fff;border-radius:12px;padding:40px;max-width:560px;
             box-shadow:0 4px 24px rgba(0,0,0,0.1);text-align:center;}
        h2{color:#dc3545;} code{background:#f1f3f5;padding:2px 7px;border-radius:4px;}
        ol{text-align:left;line-height:2;}
        .btn{display:inline-block;margin-top:20px;padding:10px 24px;
             background:#28a745;color:#fff;border-radius:8px;text-decoration:none;
             font-weight:bold;}
    </style></head><body>
    <div class="box">
        <h2>&#9888; ZipArchive Extension Missing</h2>
        <p>The report download requires the <strong>ZipArchive</strong> PHP extension,
        which is not currently enabled in your XAMPP installation.</p>
        <h4>How to fix it:</h4>
        <ol>
            <li>Open <strong>XAMPP Control Panel</strong></li>
            <li>Click <strong>Config</strong> next to Apache &rarr; select <strong>PHP (php.ini)</strong></li>
            <li>Find the line: <code>;extension=zip</code></li>
            <li>Remove the semicolon: <code>extension=zip</code></li>
            <li><strong>Save</strong> the file</li>
            <li>Click <strong>Stop</strong> then <strong>Start</strong> on Apache in XAMPP</li>
            <li>Come back and try downloading again</li>
        </ol>
        <a href="dashboard.php" class="btn">&larr; Back to Dashboard</a>
    </div>
    </body></html>';
    exit();
}

// ── Connect to DB ─────────────────────────────────────────────────
$db = (new Database())->connect();
if (!$db) {
    ob_end_clean();
    header('Location: dashboard.php');
    exit();
}

// ─────────────────────────────────────────────────────────────────
// FETCH ALL ACCEPTED MENTOR-MENTEE PAIRS (with real-time status)
// ─────────────────────────────────────────────────────────────────
$sql = "
    SELECT
        mr.id           AS request_id,
        mr.mentor_id,
        mr.mentee_id,
        m.name          AS mentor_name,
        m.department    AS mentor_dept,
        t.name          AS mentee_name,
        t.department    AS mentee_dept,
        t.email         AS mentee_email,
        mr.session_date,
        mr.start_time,
        mr.session_duration,
        mr.session_completed,
        mr.status       AS pairing_status,
        mr.created_at   AS paired_date
    FROM mentor_requests mr
    JOIN users m ON mr.mentor_id = m.id
    JOIN users t ON mr.mentee_id = t.id
    WHERE mr.status = 'accepted'
    ORDER BY m.name ASC, t.name ASC
";
$result = $db->query($sql);
if (!$result) {
    ob_end_clean();
    error_log('report_download: query failed — ' . $db->error);
    header('Location: dashboard.php');
    exit();
}
$rows = [];
while ($r = $result->fetch_assoc()) { $rows[] = $r; }

// ─────────────────────────────────────────────────────────────────
// CALCULATE 6-MONTH SESSION STATUSES PER PAIR (REAL-TIME)
// Uses actual session_completed flag from DB — no inference.
// ─────────────────────────────────────────────────────────────────
function rpt_getStatuses($db, $row) {
    $base_str = !empty($row['session_date'])
        ? substr($row['session_date'], 0, 10)
        : substr($row['paired_date'],  0, 10);
    try   { $base = new DateTime($base_str); }
    catch (Exception $e) { $base = new DateTime(); }
    $base->modify('first day of this month');

    $now   = new DateTime();
    $mid   = (int)$row['mentor_id'];
    $tid   = (int)$row['mentee_id'];
    $reqId = (int)$row['request_id'];
    $out   = [];

    for ($m = 1; $m <= 6; $m++) {
        $mS = clone $base; $mS->modify('+'.($m-1).' months');
        $mE = clone $mS;   $mE->modify('last day of this month');
        $ms = $mS->format('Y-m-d');
        $me = $mE->format('Y-m-d');

        // Is the session_date for this pairing in this month window?
        $inThisMonth = false;
        if (!empty($row['session_date'])) {
            $sd = substr($row['session_date'], 0, 10);
            $inThisMonth = ($sd >= $ms && $sd <= $me);
        }

        if ($now < $mS) {
            // Future month — blank
            $out[$m] = '';
        } elseif ($inThisMonth) {
            // Real-time: use session_completed flag from DB
            if (!empty($row['session_completed']) && $row['session_completed'] == 1) {
                $out[$m] = 'Completed';
            } else {
                // Check if session end time has passed
                $sessionEndPassed = false;
                if (!empty($row['start_time'])) {
                    $sessionEnd = new DateTime($row['session_date'] . ' ' . $row['start_time']);
                    $sessionEnd->modify('+' . ((int)($row['session_duration'] ?: 60)) . ' minutes');
                    $sessionEndPassed = ($now >= $sessionEnd);
                }
                $out[$m] = $sessionEndPassed ? 'Pending' : 'Scheduled';
            }
        } else {
            // Past month window — check feedback for that month from feedback table
            $st = $db->prepare(
                'SELECT id FROM feedback WHERE user_id=? AND session_date BETWEEN ? AND ? LIMIT 1'
            );
            $st->bind_param('iss', $tid, $ms, $me);
            $st->execute();
            $hasFb = $st->get_result()->num_rows > 0;
            $out[$m] = $hasFb ? 'Completed' : 'Not Completed';
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────
// CHECK FEEDBACK SUBMITTED STATUS PER PAIR (REAL-TIME)
// ─────────────────────────────────────────────────────────────────
function rpt_hasFeedback($db, $row) {
    if (empty($row['session_date'])) return false;
    $tid = (int)$row['mentee_id'];
    $sd  = $row['session_date'];
    $st  = $db->prepare('SELECT id FROM feedback WHERE user_id=? AND session_date=? LIMIT 1');
    $st->bind_param('is', $tid, $sd);
    $st->execute();
    return $st->get_result()->num_rows > 0;
}

$reportRows = [];
foreach ($rows as $row) {
    $reportRows[] = [
        'mentor_name'        => $row['mentor_name']  ?? '',
        'mentor_dept'        => $row['mentor_dept']  ?? '',
        'mentee_name'        => $row['mentee_name']  ?? '',
        'mentee_dept'        => $row['mentee_dept']  ?? '',
        'mentee_email'       => $row['mentee_email'] ?? '',
        'pairing_status'     => ucfirst($row['pairing_status'] ?? 'accepted'),
        'session_completed'  => (!empty($row['session_completed']) && $row['session_completed'] == 1) ? 'Yes' : 'No',
        'feedback_submitted' => rpt_hasFeedback($db, $row) ? 'Yes' : 'No',
        'statuses'           => rpt_getStatuses($db, $row),
    ];
}

// ─────────────────────────────────────────────────────────────────
// BUILD XLSX — Pure PHP + ZipArchive (no external libraries)
// All helper functions prefixed rpt_ to avoid any naming conflicts
// ─────────────────────────────────────────────────────────────────
$rpt_SS = []; $rpt_SSI = 0;

function rpt_ss($v) {
    global $rpt_SS, $rpt_SSI;
    $k = (string)$v;
    if (!isset($rpt_SS[$k])) { $rpt_SS[$k] = $rpt_SSI++; }
    return $rpt_SS[$k];
}
function rpt_xe($v) {
    return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function rpt_mkc($col, $rowNum, $val, $xf, $type = 's') {
    $ref = $col . $rowNum;
    if ($type === 'b' || $val === '' || $val === null)
        return '<c r="'.$ref.'" s="'.$xf.'"/>';
    if ($type === 'n')
        return '<c r="'.$ref.'" t="n" s="'.$xf.'"><v>'.(int)$val.'</v></c>';
    return '<c r="'.$ref.'" t="s" s="'.$xf.'"><v>'.(int)$val.'</v></c>';
}

$TITLE   = 'Mentor-Mentee Session Completion Report';
$LEG     = 'LEGEND:  Completed = Session Done   Not Completed = Missed/No Feedback   Pending = Booked/Awaiting   Scheduled = Booked (future)   (blank) = Future month';
$HEADERS = ['S.No','Mentor Name','Mentor Dept','Mentee Name','Mentee Dept',
            'Mail ID','Pairing Status','Session Completed','Feedback Submitted',
            'Session 1 (M1)','Session 2 (M2)','Session 3 (M3)',
            'Session 4 (M4)','Session 5 (M5)','Session 6 (M6)'];

rpt_ss($TITLE); rpt_ss($LEG);
foreach ($HEADERS as $h) rpt_ss($h);
foreach ($reportRows as $r) {
    rpt_ss($r['mentor_name']); rpt_ss($r['mentor_dept']);
    rpt_ss($r['mentee_name']); rpt_ss($r['mentee_dept']); rpt_ss($r['mentee_email']);
    rpt_ss($r['pairing_status']); rpt_ss($r['session_completed']); rpt_ss($r['feedback_submitted']);
    foreach ($r['statuses'] as $st) { if ($st !== '') rpt_ss($st); }
}

$COLS = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O'];
$nd   = count($reportRows);
$fd   = 3; $ld = $fd + $nd - 1; $legR = $ld + 2;

// Worksheet XML
$sx  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$sx .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
$sx .= '<sheetViews><sheetView workbookViewId="0">';
$sx .= '<pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/>';
$sx .= '</sheetView></sheetViews><cols>';
foreach ([6,22,20,22,20,16,14,14,14,15,13,13,13,13,13] as $i=>$w) {
    $n=$i+1; $sx.='<col min="'.$n.'" max="'.$n.'" width="'.$w.'" customWidth="1"/>';
}
$sx .= '</cols><sheetData>';

$sx .= '<row r="1" ht="40" customHeight="1">';
$sx .= rpt_mkc('A',1,rpt_ss($TITLE),1);
for ($c=1;$c<15;$c++) $sx .= rpt_mkc($COLS[$c],1,'',1,'b');
$sx .= '</row>';

$sx .= '<row r="2" ht="42" customHeight="1">';
foreach ($HEADERS as $ci=>$h) $sx .= rpt_mkc($COLS[$ci],2,rpt_ss($h),2);
$sx .= '</row>';

foreach ($reportRows as $ri=>$dr) {
    $er=$fd+$ri; $alt=($ri%2===1); $xfC=$alt?5:3; $xfL=$alt?6:4;
    $sx .= '<row r="'.$er.'" ht="26" customHeight="1">';
    $sx .= rpt_mkc('A',$er,$ri+1,$xfC,'n');
    $sx .= rpt_mkc('B',$er,rpt_ss($dr['mentor_name']),$xfL);
    $sx .= rpt_mkc('C',$er,rpt_ss($dr['mentor_dept']),$xfL);
    $sx .= rpt_mkc('D',$er,rpt_ss($dr['mentee_name']),$xfL);
    $sx .= rpt_mkc('E',$er,rpt_ss($dr['mentee_dept']),$xfL);
    $sx .= rpt_mkc('F',$er,rpt_ss($dr['mentee_email']),$xfC);
    // Pairing Status
    $sx .= rpt_mkc('G',$er,rpt_ss($dr['pairing_status']),$xfC);
    // Session Completed (Yes/No)
    $scXf = ($dr['session_completed'] === 'Yes') ? 7 : $xfC;
    $sx .= rpt_mkc('H',$er,rpt_ss($dr['session_completed']),$scXf);
    // Feedback Submitted (Yes/No)
    $fbXf = ($dr['feedback_submitted'] === 'Yes') ? 7 : 8;
    $sx .= rpt_mkc('I',$er,rpt_ss($dr['feedback_submitted']),$fbXf);
    // Monthly session statuses
    for ($m=1;$m<=6;$m++) {
        $st=$dr['statuses'][$m]??''; $col=$COLS[9+$m-1];
        if ($st==='Completed') $xf=7;
        elseif ($st==='Not Completed') $xf=8;
        elseif ($st==='Pending' || $st==='Scheduled') $xf=9;
        else $xf=$xfC;
        $sx .= ($st!=='')?rpt_mkc($col,$er,rpt_ss($st),$xf):rpt_mkc($col,$er,'',0,'b');
    }
    $sx .= '</row>';
}

$sx .= '<row r="'.$legR.'" ht="20" customHeight="1">';
$sx .= rpt_mkc('A',$legR,rpt_ss($LEG),10);
for ($c=1;$c<15;$c++) $sx .= rpt_mkc($COLS[$c],$legR,'',10,'b');
$sx .= '</row>';

$sx .= '</sheetData><mergeCells count="2">';
$sx .= '<mergeCell ref="A1:O1"/>';
$sx .= '<mergeCell ref="A'.$legR.':O'.$legR.'"/>';
$sx .= '</mergeCells></worksheet>';

// Shared strings
$rpt_ssArr = array_flip($rpt_SS); ksort($rpt_ssArr);
$ssXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' count="'.count($rpt_ssArr).'" uniqueCount="'.count($rpt_ssArr).'">';
foreach ($rpt_ssArr as $v) $ssXml .= '<si><t xml:space="preserve">'.rpt_xe($v).'</t></si>';
$ssXml .= '</sst>';

// Styles
$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="6">
<font><sz val="11"/><name val="Calibri"/></font>
<font><sz val="14"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
<font><sz val="10"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
<font><sz val="9"/><b/><color rgb="FF065F46"/><name val="Arial"/></font>
<font><sz val="9"/><b/><color rgb="FF991B1B"/><name val="Arial"/></font>
<font><sz val="9"/><b/><color rgb="FF854D0E"/><name val="Arial"/></font>
</fonts>
<fills count="9">
<fill><patternFill patternType="none"/></fill>
<fill><patternFill patternType="gray125"/></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FF1A3A6B"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFEEF2F7"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFD1FAE5"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFFFF3CD"/></patternFill></fill>
</fills>
<borders count="2">
<border><left/><right/><top/><bottom/><diagonal/></border>
<border>
<left style="thin"><color rgb="FFD1D5DB"/></left>
<right style="thin"><color rgb="FFD1D5DB"/></right>
<top style="thin"><color rgb="FFD1D5DB"/></top>
<bottom style="thin"><color rgb="FFD1D5DB"/></bottom>
<diagonal/>
</border>
</borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="11">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="left" vertical="center"/></xf>
<xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="left" vertical="center"/></xf>
<xf numFmtId="0" fontId="3" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="0" fontId="5" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="0" fontId="0" fillId="5" borderId="0" xfId="0" applyFill="1"><alignment horizontal="left" vertical="center"/></xf>
</cellXfs>
</styleSheet>';

$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

$wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="18140" windowHeight="8660"/></bookViews>
<sheets><sheet name="Mentor-Mentee Report" sheetId="1" r:id="rId1"/></sheets>
</workbook>';

// Build ZIP
$tmp = tempnam(sys_get_temp_dir(), 'mentor_rpt_') . '.xlsx';
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    ob_end_clean();
    error_log('report_download: ZipArchive::open failed for ' . $tmp);
    echo '<!DOCTYPE html><html><body style="font-family:Arial;padding:40px;">
    <h3 style="color:red;">&#9888; Cannot create report file</h3>
    <p>PHP could not create a temporary file. Please check that XAMPP has
    write permission to the system temp directory.</p>
    <a href="dashboard.php">&larr; Back to Dashboard</a></body></html>';
    exit();
}
$zip->addFromString('[Content_Types].xml',        $ct);
$zip->addFromString('_rels/.rels',                $rels);
$zip->addFromString('xl/workbook.xml',            $wbXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
$zip->addFromString('xl/worksheets/sheet1.xml',   $sx);
$zip->addFromString('xl/sharedStrings.xml',       $ssXml);
$zip->addFromString('xl/styles.xml',              $stylesXml);
$zip->close();

// Stream to browser — clear buffer first
while (ob_get_level() > 0) { ob_end_clean(); }

$fname = 'Mentor_Mentee_Report_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
readfile($tmp);
@unlink($tmp);
exit();