<?php
// ============================================================
// includes/MySessionHandler.php
// VAPT Fixes: #1 Role-based auth, #7 Production mode, #8 Concurrent session
// ============================================================

class MySessionHandler {

    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            // FIXED #8: Secure, HttpOnly, SameSite cookie settings
            session_set_cookie_params([
                'lifetime' => 0,          // browser session only
                'path'     => '/',
                'secure'   => false,      // set true when HTTPS is in use
                'httponly' => true,        // NEW #8: JS cannot access cookie
                'samesite' => 'Strict',   // NEW #8: blocks CSRF via cookie
            ]);
            session_start();
        }
    }

    // FIXED #1: Require login — redirects to login, no info leak
    public static function requireLogin(): void {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /Mentor/login.php');
            exit();
        }
    }

    // FIXED #1: Require a specific role (or array of roles)
    public static function requireRole($allowed_roles): void {
        self::requireLogin();
        $role    = $_SESSION['role'] ?? '';
        $allowed = is_array($allowed_roles) ? $allowed_roles : [$allowed_roles];
        if (!in_array($role, $allowed, true)) {
            http_response_code(403);
            header('Location: /Mentor/login.php');
            exit();
        }
    }

    public static function preventLoginRedirect(): void {
        if (isset($_SESSION['user_id'])) {
            $role = $_SESSION['role'] ?? '';
            if ($role === 'mentor') {
                header('Location: views/mentor/dashboard.php');
            } elseif ($role === 'mentee') {
                header('Location: views/mentee/dashboard.php');
            } else {
                header('Location: views/admin/dashboard.php');
            }
            exit();
        }
    }

    // UPDATED #8: setUser now issues a new session ID and stores a
    // concurrent-session token to enforce one-active-login.
    public static function setUser(array $user, $db = null): void {
        // FIXED #8: Regenerate session ID on login — prevents session fixation
        session_regenerate_id(true);

        $_SESSION['user_id']     = (int)    $user['id'];
        $_SESSION['name']        = (string) ($user['name']        ?? '');
        $_SESSION['email']       = (string) ($user['email']       ?? '');
        $_SESSION['role']        = (string) ($user['role']        ?? '');
        $_SESSION['department']  = (string) ($user['department']  ?? '');
        $_SESSION['designation'] = (string) ($user['designation'] ?? '');
        $_SESSION['login_time']  = time();

        // NEW #8: Generate and store concurrent-session token
        if ($db) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['session_token'] = $token;
            $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare(
                'UPDATE users SET session_token=?, last_login_at=?, last_login_ip=? WHERE id=?'
            );
            $stmt->bind_param('sssi', $token, $now, $ip, $_SESSION['user_id']);
            $stmt->execute();
        }
    }

    // NEW #8: Call on every authenticated page load.
    // If a second login happened, the DB token will differ → force logout.
    public static function enforceOneSession($db): void {
        if (!isset($_SESSION['user_id'], $_SESSION['session_token'])) {
            self::destroy();
            header('Location: /Mentor/login.php?reason=session');
            exit();
        }

        $uid   = (int) $_SESSION['user_id'];
        $token = (string) $_SESSION['session_token'];

        $stmt = $db->prepare('SELECT session_token FROM users WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        // FIXED #8: hash_equals prevents timing attacks
        if (!$row || !hash_equals((string)$row['session_token'], $token)) {
            self::destroy();
            header('Location: /Mentor/login.php?reason=concurrent');
            exit();
        }
    }

    public static function getUser(): ?array {
        if (!isset($_SESSION['user_id'])) return null;
        return [
            'id'          => $_SESSION['user_id'],
            'name'        => $_SESSION['name']        ?? '',
            'email'       => $_SESSION['email']       ?? '',
            'role'        => $_SESSION['role']        ?? '',
            'department'  => $_SESSION['department']  ?? '',
            'designation' => $_SESSION['designation'] ?? '',
        ];
    }

    // UPDATED: Full secure session teardown + clear DB token
    public static function destroy($db = null): void {
        // NEW #8: Clear concurrent-session token in DB on logout
        if ($db && isset($_SESSION['user_id'])) {
            $uid  = (int)$_SESSION['user_id'];
            $stmt = $db->prepare('UPDATE users SET session_token=NULL WHERE id=?');
            $stmt->bind_param('i', $uid);
            $stmt->execute();
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
