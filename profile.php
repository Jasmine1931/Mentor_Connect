<?php
/**
 * User Profile Page
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/SessionHandler.php';
require_once __DIR__ . '/models/User.php';

SessionHandler::requireLogin();

$user_id = SessionHandler::getUserId();
$user_role = SessionHandler::getUserRole();

$userModel = new User();
$user = $userModel->getUserById($user_id);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $data = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'bio' => trim($_POST['bio'] ?? ''),
        'expertise_area' => trim($_POST['expertise_area'] ?? '')
    ];

    $result = $userModel->updateProfile($user_id, $data);
    if ($result) {
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Profile updated successfully!</div>';
        $user = $userModel->getUserById($user_id);
    } else {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Failed to update profile.</div>';
    }
}

$page_title = 'Profile';
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="page-header">
        <h1><i class="fas fa-user"></i> My Profile</h1>
    </div>

    <?php echo $message; ?>

    <div class="card">
        <div class="card-body">
            <form method="POST">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="first_name" class="form-label">First Name</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="last_name" class="form-label">Last Name</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                    <small class="text-muted">Email cannot be changed</small>
                </div>

                <div class="mb-3">
                    <label for="role" class="form-label">Role</label>
                    <input type="text" class="form-control" id="role" value="<?php echo ucfirst($user['role']); ?>" disabled>
                </div>

                <div class="mb-3">
                    <label for="domain" class="form-label">Domain</label>
                    <input type="text" class="form-control" id="domain" value="<?php echo htmlspecialchars($user['domain']); ?>" disabled>
                </div>

                <div class="mb-3">
                    <label for="bio" class="form-label">Bio</label>
                    <textarea class="form-control" id="bio" name="bio" rows="4" placeholder="Tell us about yourself..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                </div>

                <?php if ($user['role'] === 'mentor'): ?>
                    <div class="mb-3">
                        <label for="expertise_area" class="form-label">Expertise Area</label>
                        <input type="text" class="form-control" id="expertise_area" name="expertise_area" value="<?php echo htmlspecialchars($user['expertise_area'] ?? ''); ?>" placeholder="e.g., Web Development">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Rating</label>
                        <div class="rating">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <i class="fas fa-star<?php echo $i < floor($user['average_rating']) ? '' : '-o'; ?>"></i>
                            <?php endfor; ?>
                            <span class="ms-2"><?php echo $user['average_rating']; ?>/5 (<?php echo $user['total_sessions_completed']; ?> sessions)</span>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">Member Since</label>
                    <p><?php echo date('F d, Y', strtotime($user['created_at'])); ?></p>
                </div>

                <button type="submit" name="update_profile" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Account Actions</div>
        <div class="card-body">
            <a href="settings.php" class="btn btn-outline-primary">
                <i class="fas fa-cog"></i> Account Settings
            </a>
            <a href="logout.php" class="btn btn-outline-danger">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
