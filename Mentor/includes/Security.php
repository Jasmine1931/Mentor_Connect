<?php
/**
 * includes/Security.php
 * =====================================================================
 * Central security helper — covers ALL 11 VAPT points.
 * Include this file AFTER session_start() on every page.
 *
 * VAPT coverage:
 *  #1  Role-based auth          → Security::requireRole()
 *  #2  IDOR / URL ID encryption → Security::encryptId() / decryptId()
 *  #3  Open redirect            → Security::safeRedirect()
 *  #4  HTML injection / XSS     → Security::h() + CSP header
 *  #5  File upload restriction  → Security::validateUpload()
 *  #6  SQL injection            → enforced via PDO/mysqli prepared stmts
 *  #7  Production mode          → Security::setProductionMode()
 *  #8  Concurrent session       → Security::enforceOneSession()
 *  #9  target=_blank noopener   → Security::safeLink()
 *  #10 Origin tag for HTTP      → Security::sendSecurityHeaders()
 *  #11 Log for changes          → Security::log()
 * =====================================================================
 */

// NEW
class Security {

    // ── #7 Production mode ────────────────────────────────────
    // Call once at the very top of config.php or index bootstrap.
    public static function setProductionMode(): void {
        // UPDATED: Turn off ALL error display in production
        error_reporting(0);
        ini_set('display_errors',  '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors',      '1');
        // Errors go to PHP's configured error_log, never the browser
    }

    // ── #10 Origin / security headers ────────────────────────
    // VAPT item 10 = "origintag for http" means HTTP security
    // headers (X-Frame-Options, HSTS, CSP, Referrer, Origin-Agent).
    public static function sendSecurityHeaders(): void {
        // NEW: Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        // NEW: Prevent MIME-sniffing
        header('X-Content-Type-Options: nosniff');
        // NEW: Legacy XSS filter
        header('X-XSS-Protection: 1; mode=block');
        // NEW: Control referrer sent to third parties
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // NEW: Origin isolation
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        // NEW: Content Security Policy — restrict external resources
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline' cdnjs.cloudflare.com cdn.jsdelivr.net fonts.googleapis.com; " .
            "style-src 'self' 'unsafe-inline' cdnjs.cloudflare.com cdn.jsdelivr.net fonts.googleapis.com fonts.gstatic.com; " .
            "font-src 'self' fonts.gstatic.com cdnjs.cloudflare.com; " .
            "img-src 'self' data:; " .
            "connect-src 'self';"
        );
    }

    // ── #4 HTML injection / XSS output escaping ──────────────
    // Use Security::h($val) instead of bare echo anywhere
    // user-supplied data is rendered in HTML.
    public static function h(?string $val): string {
        // FIXED: ENT_QUOTES escapes both single and double quotes
        return htmlspecialchars((string)$val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ── #1 Role-based authentication & approval ───────────────
    // Validates that the logged-in user has the required role.
    // Supports single role string OR array of allowed roles.
    public static function requireRole($allowed_roles, string $redirect = '/Mentor/login.php'): void {
        if (!isset($_SESSION['user_id'])) {
            self::safeRedirect($redirect);
        }
        $role = $_SESSION['role'] ?? '';
        $allowed = is_array($allowed_roles) ? $allowed_roles : [$allowed_roles];
        if (!in_array($role, $allowed, true)) {
            // FIXED: Redirect, never expose 403 details to client
            http_response_code(403);
            self::safeRedirect($redirect);
        }
    }

    // ── #3 Open redirect protection ──────────────────────────
    // Only redirects to same-origin or a whitelist.
    // Never redirects to user-supplied URLs.
    public static function safeRedirect(string $url): void {
        // Strip whitespace and null bytes
        $url = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $url));

        // Allow only relative paths or same-origin absolute URLs
        $allowed_host = $_SERVER['HTTP_HOST'] ?? '';
        $parsed       = parse_url($url);

        $is_relative  = !isset($parsed['scheme']) && !isset($parsed['host']);
        $is_same_host = isset($parsed['host']) && $parsed['host'] === $allowed_host;

        if (!$is_relative && !$is_same_host) {
            // FIXED: Suspicious URL — send to safe default
            $url = '/Mentor/dashboard.php';
        }

        header('Location: ' . $url, true, 302);
        exit();
    }

    // ── #2 IDOR — opaque URL tokens ──────────────────────────
    // Encrypt a real DB integer ID into a URL-safe token.
    // Uses HMAC-SHA256 with APP_SECRET so tokens cannot be forged.
    public static function encryptId(int $id, string $entity = 'generic'): string {
        $secret  = defined('APP_SECRET') ? APP_SECRET : 'change_me_in_config';
        $payload = base64_encode(json_encode(['id' => $id, 'e' => $entity, 't' => time()]));
        $sig     = hash_hmac('sha256', $payload, $secret);
        return $payload . '.' . substr($sig, 0, 16); // compact token
    }

    // Decrypt and verify an opaque URL token back to its integer ID.
    // Returns 0 on invalid/tampered token.
    public static function decryptId(string $token, string $entity = 'generic'): int {
        $secret = defined('APP_SECRET') ? APP_SECRET : 'change_me_in_config';
        $parts  = explode('.', $token, 2);
        if (count($parts) !== 2) return 0;

        [$payload, $sig] = $parts;
        $expected = substr(hash_hmac('sha256', $payload, $secret), 0, 16);
        if (!hash_equals($expected, $sig)) return 0; // FIXED: timing-safe compare

        $data = json_decode(base64_decode($payload), true);
        if (!$data || ($data['e'] ?? '') !== $entity) return 0;

        return (int)($data['id'] ?? 0);
    }

    // ── #8 Concurrent session — one active login per user ─────
    // On login: generate token, store in DB + session.
    // On each page load: verify session token matches DB.
    // A second login from another browser/device invalidates the first.
    public static function generateSessionToken(int $user_id, $db): string {
        // NEW: 64-char cryptographically random token
        $token = bin2hex(random_bytes(32));
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
        $now   = date('Y-m-d H:i:s');

        // Store in users table (single-session enforcement)
        $stmt = $db->prepare(
            "UPDATE users SET session_token=?, last_login_at=?, last_login_ip=? WHERE id=?"
        );
        $stmt->bind_param('sssi', $token, $now, $ip, $user_id);
        $stmt->execute();

        return $token;
    }

    // Call on EVERY authenticated page load to detect concurrent sessions.
    public static function enforceOneSession(int $user_id, $db): void {
        $stored_token   = $_SESSION['session_token'] ?? '';
        if (empty($stored_token)) {
            // No token in session — force re-login
            self::invalidateAndRedirect($db, $user_id);
        }

        $stmt = $db->prepare("SELECT session_token FROM users WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        // FIXED: If DB token differs, this session was superseded by another login
        if (!$row || !hash_equals((string)$row['session_token'], $stored_token)) {
            session_unset();
            session_destroy();
            header('Location: /Mentor/login.php?reason=concurrent');
            exit();
        }
    }

    private static function invalidateAndRedirect($db, int $user_id): void {
        session_unset();
        session_destroy();
        header('Location: /Mentor/login.php?reason=session');
        exit();
    }

    // Invalidate session token in DB on logout.
    public static function clearSessionToken(int $user_id, $db): void {
        $stmt = $db->prepare("UPDATE users SET session_token=NULL WHERE id=?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
    }

    // ── #9 target=_blank rel=noopener ────────────────────────
    // Always use this helper to render external links.
    // Prevents tab-napping via opener access.
    public static function safeLink(string $url, string $label, string $class = ''): string {
        $url   = self::h($url);
        $label = self::h($label);
        $class = $class ? ' class="' . self::h($class) . '"' : '';
        // NEW: rel="noopener noreferrer" mandatory on target=_blank
        return "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener noreferrer\"{$class}>{$label}</a>";
    }

    // ── #5 Malicious file upload — extension restriction ──────
    // Returns ['ok'=>true] or ['ok'=>false,'error'=>'...'].
    // Call before move_uploaded_file().
    public static function validateUpload(array $file, array $options = []): array {
        $allowed_ext   = $options['extensions'] ?? ['jpg','jpeg','png','gif','pdf'];
        $max_bytes     = $options['max_size']   ?? 2 * 1024 * 1024; // 2 MB default
        $allowed_mime  = $options['mime_types'] ?? [
            'image/jpeg','image/png','image/gif','application/pdf'
        ];

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }
        if ($file['size'] > $max_bytes) {
            return ['ok' => false, 'error' => 'File too large.'];
        }

        // FIXED: Get extension from the REAL filename, lower-cased
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) {
            return ['ok' => false, 'error' => 'File type not allowed: .' . $ext];
        }

        // FIXED: Verify actual MIME via finfo — cannot be spoofed by renaming
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, $allowed_mime, true)) {
            return ['ok' => false, 'error' => 'File content does not match extension.'];
        }

        // FIXED: Generate a safe filename — never use the user-supplied name directly
        $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
        return ['ok' => true, 'safe_name' => $safe_name, 'ext' => $ext, 'mime' => $realMime];
    }

    // ── #11 Activity / change log ─────────────────────────────
    // Writes an audit entry to `activity_log`.
    // $db can be mysqli connection or null (skips silently).
    public static function log(
        string $action,
        string $detail = '',
        $db    = null,
        int    $user_id   = 0,
        string $user_email = ''
    ): void {
        // Resolve from session if not supplied
        if (!$user_id)    $user_id    = (int)   ($_SESSION['user_id'] ?? 0);
        if (!$user_email) $user_email = (string) ($_SESSION['email']  ?? '');

        $ip  = $_SERVER['REMOTE_ADDR']     ?? '';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $now = date('Y-m-d H:i:s');

        // Truncate to column lengths
        $action = substr($action, 0, 100);
        $detail = substr($detail, 0, 500);
        $ua     = substr($ua, 0, 255);

        if ($db) {
            try {
                $stmt = $db->prepare(
                    "INSERT INTO activity_log
                         (user_id, user_email, action, detail, ip_address, user_agent, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('issssss',
                    $user_id, $user_email, $action, $detail, $ip, $ua, $now
                );
                $stmt->execute();
            } catch (Exception $e) {
                // NEW: Never let logging failure break the page
                error_log('ActivityLog error: ' . $e->getMessage());
            }
        } else {
            // Fallback: write to PHP error log if no DB
            error_log("[ACTIVITY] user={$user_id} action={$action} detail={$detail} ip={$ip}");
        }
    }

    // ── CSRF helpers ──────────────────────────────────────────
    // Generate once per session, embed in every form.
    public static function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // Verify submitted CSRF token — call at top of every POST handler.
    public static function csrfVerify(): void {
        $submitted = $_POST['csrf_token'] ?? '';
        $stored    = $_SESSION['csrf_token'] ?? '';
        if (empty($stored) || !hash_equals($stored, $submitted)) {
            http_response_code(403);
            die('Invalid request token. Please <a href="javascript:history.back()">go back</a> and try again.');
        }
    }

    // Return an HTML hidden input with the CSRF token — put in every <form>.
    public static function csrfField(): string {
        return '<input type="hidden" name="csrf_token" value="'
             . self::h(self::csrfToken()) . '">';
    }
}
