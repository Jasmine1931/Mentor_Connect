<?php
// ============================================================
// login.php — VAPT Fixed + Bug Fix for status check
// ============================================================
// ROOT CAUSE FIX 1 (Image 1 error):
//   Original DB uses status=0 for ALL company/real accounts
//   (admin, mentor, mentee). status=1 is only for newer test
//   accounts added in test data. The VAPT code wrongly blocked
//   status=0 users. Fix: removed the status check entirely —
//   this project does NOT use status as an approval gate.
//   The VAPT #1 "role-based auth" is enforced by role checks,
//   not by a status field.
// ============================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/MySessionHandler.php';
require_once __DIR__ . '/config/config.php';   // security headers #10

new MySessionHandler();
MySessionHandler::preventLoginRedirect();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // FIXED #4: CSRF verification
    Security::csrfVerify();

    // FIXED #6: sanitize + validate inputs
    $email    = trim(filter_input(INPUT_POST, 'email',    FILTER_SANITIZE_EMAIL) ?? '');
    $password =      filter_input(INPUT_POST, 'password', FILTER_DEFAULT)         ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid credentials.';
    } elseif (empty($password)) {
        $error = 'Invalid credentials.';
    } else {

        $database = new Database();
        $conn     = $database->connect();

        // FIXED #6: Parameterized query
        $stmt = $conn->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // FIXED (Bug 1): REMOVED status !== 1 check.
            // In this project status=0 = active company accounts,
            // status=1 = test accounts. Blocking status=0 locked out
            // ALL real admins, mentors and mentees. Role-based access
            // is enforced after login by dashboard role checks.

            if (password_verify($password, $user['password_hash'])) {

                // FIXED #8: setUser stores concurrent-session token
                MySessionHandler::setUser($user, $conn);

                // FIXED #11: log successful login
                Security::log('LOGIN', 'Login successful', $conn,
                    (int)$user['id'], $user['email']);

                header('Location: dashboard.php');
                exit();

            } else {
                // FIXED #4: same message regardless of which field is wrong
                $error = 'Invalid credentials.';
                Security::log('LOGIN_FAIL', 'Wrong password for: ' . $email, $conn, 0, $email);
            }

        } else {
            $error = 'Invalid credentials.';  // FIXED #4: no user enumeration
            Security::log('LOGIN_FAIL', 'Unknown email: ' . $email, $conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Mentor Connect</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            height: 100vh;
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            display: flex; align-items: center; justify-content: center;
        }
        .login-card {
            background: white; border-radius: 20px; padding: 40px;
            width: 100%; max-width: 420px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: fadeIn 0.8s ease-in-out;
        }
        .logo           { width: 180px; margin-bottom: 20px; }
        .form-control   { border-radius: 10px; padding: 12px; }
        .btn-login      { border-radius: 10px; padding: 12px; font-weight: 500;
                          background: #0d6efd; border: none; }
        .btn-login:hover{ background: #0b5ed7; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .footer-text { font-size: 13px; margin-top: 15px; text-align: center; color: gray; }
    </style>
</head>
<body>
<div class="login-card text-center">

    <img src="assets/images/logo.png" class="logo" alt="Company Logo">
    <h4 class="mb-3">Mentor Connect Portal</h4>

    <?php if (!empty($error)): ?>
        <!-- FIXED #4: htmlspecialchars on output — prevents reflected XSS -->
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <!-- FIXED #4: CSRF token on every form -->
        <?php echo Security::csrfField(); ?>

        <input type="email"    name="email"    class="form-control mb-3"
               placeholder="Email Address" required maxlength="255">
        <input type="password" name="password" class="form-control mb-3"
               placeholder="Password"       required maxlength="128">

        <button type="submit" class="btn btn-login w-100">Login</button>
    </form>

    <div class="footer-text">Secure Access • Internal Use Only</div>
</div>
</body>
</html>
