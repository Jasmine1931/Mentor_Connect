<?php
/**
 * register.php
 * VAPT Fixes: #1 Role whitelist, #4 XSS/HTML injection,
 *             #6 SQL injection, #7 Production mode, #11 Activity log
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/MySessionHandler.php';
require_once __DIR__ . '/config/config.php';   // sends security headers #10
require_once __DIR__ . '/includes/Auth.php';

// FIXED #7: Removed error_reporting(E_ALL) / display_errors=1
// Production error suppression is handled inside config.php

new MySessionHandler();
MySessionHandler::preventLoginRedirect();

$error   = '';
$success = '';

// FIXED #1: Whitelist of roles a self-registering user may select.
// Prevents attackers from POSTing role=admin to elevate privileges.
$allowed_roles = ['mentor', 'mentee'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // FIXED #4: CSRF check on all POST requests
    Security::csrfVerify();

    // FIXED #4 + #6: trim + validate every input, never trust raw $_POST
    $first_name  = trim($_POST['first_name']  ?? '');
    $last_name   = trim($_POST['last_name']   ?? '');
    $email       = trim($_POST['email']       ?? '');
    $password    =      $_POST['password']    ?? '';
    $role        =      $_POST['role']        ?? '';
    $department  = trim($_POST['department']  ?? '');
    $designation = trim($_POST['designation'] ?? '');

    // FIXED #4: Validate email format server-side
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';

    // FIXED #1: Server-side role whitelist — not just client-side dropdown
    } elseif (!in_array($role, $allowed_roles, true)) {
        $error = 'Invalid role selected.';

    } elseif (!$first_name || !$last_name || !$email || !$password || !$role) {
        $error = 'Please fill in all required fields.';

    } elseif (strlen($password) < PASSWORD_MIN_LENGTH) {
        $error = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';

    // FIXED #4: Enforce max-length to prevent oversized payloads
    } elseif (strlen($first_name) > 60 || strlen($last_name) > 60) {
        $error = 'Name fields cannot exceed 60 characters.';

    } else {
        $auth   = new Auth(getPDO());
        $result = $auth->register(
            $first_name, $last_name, $email, $password,
            $role, $department, $designation
        );

        if ($result['success']) {
            // FIXED #11: Log new registration
            $db = (new Database())->connect();
            Security::log('REGISTER', "New {$role} registered: {$email}", $db, 0, $email);
            $success = 'Registration successful! Your account is pending admin approval.';
        } else {
            // FIXED #4: htmlspecialchars on any message from model
            $error = htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8');
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- FIXED #4: APP_NAME escaped — prevents title injection -->
    <title>Register — <?php echo Security::h(APP_NAME); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh; display: flex;
            align-items: center; justify-content: center;
        }
        .register-container {
            background: #fff; padding: 40px; border-radius: 10px;
            width: 100%; max-width: 500px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        .btn-register {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none; color: #fff; padding: 12px;
            width: 100%; font-weight: 600;
        }
    </style>
</head>
<body>
<div class="register-container">
    <h2 class="text-center mb-4">Create Account</h2>

    <?php if ($error): ?>
        <!-- FIXED #4: already escaped above before assignment -->
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo Security::h($success); ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">

        <!-- FIXED #4: CSRF hidden field on every form -->
        <?php echo Security::csrfField(); ?>

        <!-- FIXED #4: maxlength enforced in HTML AND PHP above -->
        <input type="text"     name="first_name"  class="form-control mb-3"
               placeholder="First Name"  required maxlength="60">
        <input type="text"     name="last_name"   class="form-control mb-3"
               placeholder="Last Name"   required maxlength="60">
        <input type="email"    name="email"        class="form-control mb-3"
               placeholder="Email"       required maxlength="100">
        <input type="password" name="password"     class="form-control mb-3"
               placeholder="Password (min <?php echo PASSWORD_MIN_LENGTH; ?> chars)"
               required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">

        <!-- FIXED #1: Only mentor/mentee visible; admin not an option -->
        <select name="role" class="form-control mb-3" required>
            <option value="">Select Role</option>
            <option value="mentor">Mentor</option>
            <option value="mentee">Mentee</option>
        </select>

        <input type="text" name="department"  class="form-control mb-3"
               placeholder="Department"  maxlength="100">
        <input type="text" name="designation" class="form-control mb-3"
               placeholder="Designation" maxlength="100">

        <button type="submit" class="btn btn-register">Register</button>
    </form>

    <div class="text-center mt-3">
        Already have an account?
        <!-- FIXED #9: Internal link — no target=_blank needed -->
        <a href="login.php">Login here</a>
    </div>
</div>
</body>
</html>
