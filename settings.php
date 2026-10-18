<?php
/**
 * Account Settings Page
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/SessionHandler.php';
require_once __DIR__ . '/includes/Auth.php';

SessionHandler::requireLogin();

$user_id = SessionHandler::getUserId();
$auth = new Auth();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($new_password !== $confirm_password) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Passwords do not match.</div>';
    } else {
        $result = $auth->changePassword($user_id, $old_password, $new_password);
        if ($result['success']) {
            $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($result['message']) . '</div>';
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($result['message']) . '</div>';
        }
    }
}

$page_title = 'Account Settings';
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="page-header">
        <h1><i class="fas fa-cog"></i> Account Settings</h1>
    </div>

    <?php echo $message; ?>

    <div class="card mb-4">
        <div class="card-header">Change Password</div>
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label for="old_password" class="form-label">Current Password</label>
                    <input type="password" class="form-control" id="old_password" name="old_password" required>
                </div>

                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
                    <small class="text-muted">At least <?php echo PASSWORD_MIN_LENGTH; ?> characters</small>
                </div>

                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                </div>

                <button type="submit" name="change_password" class="btn btn-primary">
                    <i class="fas fa-lock"></i> Update Password
                </button>
            </form>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Other Actions</div>
        <div class="card-body">
            <p><strong>Need help?</strong> Contact the admin for additional support.</p>
            <a href="profile.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to Profile
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
