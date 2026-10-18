<?php
/**
 * includes/SessionHandler.php
 * Compatibility shim — maps the static SessionHandler API used by
 * views/mentor/* and views/mentee/* files to the existing
 * MySessionHandler class and plain $_SESSION variables.
 *
 * Created to fix: "Class SessionHandler not found" fatal errors
 * that appeared in views that reference this class.
 */

// Ensure MySessionHandler is available
if (!class_exists('MySessionHandler')) {
    require_once __DIR__ . '/MySessionHandler.php';
}

class SessionHandler {

    /**
     * Require a specific role, redirect to login if not matched.
     */
    public static function requireRole(string $role): void {
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . self::loginPath());
            exit();
        }
        if ($_SESSION['role'] !== $role) {
            header('Location: ' . self::loginPath());
            exit();
        }
    }

    /**
     * Return the current logged-in user's ID (int).
     */
    public static function getUserId(): int {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    /**
     * Return the current logged-in user's role string.
     */
    public static function getRole(): string {
        return $_SESSION['role'] ?? '';
    }

    /**
     * Return the current logged-in user's name.
     */
    public static function getName(): string {
        return $_SESSION['name'] ?? '';
    }

    /**
     * Resolve the correct path to login.php regardless of depth.
     * Works from root, views/mentor/, views/mentee/, views/admin/ etc.
     */
    private static function loginPath(): string {
        // Walk up from the calling script to find login.php
        $scriptDir = dirname($_SERVER['SCRIPT_FILENAME']);
        $depth = 0;
        $dir = $scriptDir;
        while ($depth < 5) {
            if (file_exists($dir . '/login.php')) {
                // Build a relative URL
                $rel = str_repeat('../', $depth) . 'login.php';
                return $rel;
            }
            $dir = dirname($dir);
            $depth++;
        }
        return '/login.php';
    }
}
