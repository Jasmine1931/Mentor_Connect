<?php
/**
 * views/mentor/set_availability.php
 * FIXED: replaced $pdo with Database()->connect() (mysqli)
 *        replaced $_SESSION['user']['id'] with $_SESSION['user_id']
 *        removed duplicate session_start() (config.php handles it)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mentor') {
    header("Location: ../../login.php");
    exit();
}

$db        = (new Database())->connect();
$mentor_id = (int) $_SESSION['user_id'];
$message   = "";
$error     = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $date  = $_POST['date']       ?? '';
    $start = $_POST['start_time'] ?? '';
    $end   = $_POST['end_time']   ?? '';

    if ($date && $start && $end) {
        // Check mentor_availability table exists before inserting
        $stmt = $db->prepare(
            "INSERT INTO mentor_availability (mentor_id, available_date, start_time, end_time)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("isss", $mentor_id, $date, $start, $end);
        if ($stmt->execute()) {
            $message = "Availability slot added for {$date} ({$start} – {$end}).";
        } else {
            $error = "Failed to save availability. Please try again.";
        }
    } else {
        $error = "All fields are required.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Availability — Mentor Connect</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
<div class="layout">
<?php
    $user_name = $_SESSION['name'];
    $user_role = $_SESSION['role'];
    $current_page = 'dashboard';
    require_once __DIR__ . '/../../includes/sidenav.php';
?>
    <div class="main">
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="sidenav-toggle" onclick="toggleSidenav()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <h2>Set Availability</h2>
            </div>
            <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['name']); ?></strong></span>
        </div>

        <?php if ($message): ?>
        <div class="flash-success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="flash-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="request-card" style="max-width:480px;">
            <h3 style="font-size:16px;color:#1a2b4a;margin-bottom:20px;">Add an Availability Slot</h3>
            <form method="POST">
                <div class="book-form-fields" style="flex-direction:column;gap:14px;">
                    <div class="book-form-field">
                        <label>Date</label>
                        <input type="date" name="date" min="<?php echo date('Y-m-d'); ?>" required
                               style="width:100%;padding:10px 13px;border-radius:8px;border:1.5px solid #e2e8f0;font-family:Poppins,sans-serif;font-size:14px;">
                    </div>
                    <div class="book-form-field">
                        <label>Start Time</label>
                        <input type="time" name="start_time" required
                               style="width:100%;padding:10px 13px;border-radius:8px;border:1.5px solid #e2e8f0;font-family:Poppins,sans-serif;font-size:14px;">
                    </div>
                    <div class="book-form-field">
                        <label>End Time</label>
                        <input type="time" name="end_time" required
                               style="width:100%;padding:10px 13px;border-radius:8px;border:1.5px solid #e2e8f0;font-family:Poppins,sans-serif;font-size:14px;">
                    </div>
                    <button type="submit" class="btn-book" style="align-self:flex-start;">Add Slot</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function toggleSidenav(){document.getElementById('sideNav').classList.toggle('open');document.getElementById('sideNavOverlay').classList.toggle('active');}
function closeSidenav(){document.getElementById('sideNav').classList.remove('open');document.getElementById('sideNavOverlay').classList.remove('active');}
</script>
</body>
</html>
