<?php
/**
 * includes/sidenav.php
 * Shared side navigation panel for Mentor, Mentee, and Admin roles.
 * Renders role-appropriate menu items with active-state highlighting.
 *
 * Requires: $_SESSION['role'], $user_name, $user_role to be set before inclusion.
 * $current_page should be set to the current page identifier, e.g. 'session_booking'.
 *
 * TASK 6: Added "Messages" link with live unread badge for mentor/mentee.
 *         JS polls get_unread_count.php every 30 seconds.
 */

// Determine current page for active state
$current_page = $current_page ?? '';

// Determine base path to project root based on current script depth.
// Handles all known locations:
//   /Mentor/                     (root pages)          → base = ''
//   /Mentor/pages/               (session_booking etc)  → base = '../'
//   /Mentor/views/admin|mentor|mentee/ (view files)   → base = '../../'
$_self = $_SERVER['PHP_SELF'];
if (strpos($_self, '/views/') !== false) {
    $base = '../../';        // views/admin/, views/mentor/, views/mentee/
} elseif (strpos($_self, '/pages/') !== false) {
    $base = '../';           // pages/
} else {
    $base = '';              // project root
}
?>
<div class="sidenav" id="sideNav">
    <!-- Brand -->
    <div class="sidenav-brand">
        <div class="sidenav-logo">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
                <circle cx="14" cy="14" r="14" fill="rgba(109,213,250,0.2)"/>
                <path d="M8 10h12M8 14h8M8 18h10" stroke="#6dd5fa" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </div>
        <span>Mentor Connect</span>
    </div>

    <!-- User Info -->
    <div class="sidenav-user">
        <div class="sidenav-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
        <div class="sidenav-userinfo">
            <div class="sidenav-username"><?php echo htmlspecialchars($user_name); ?></div>
            <div class="sidenav-role"><?php echo ucfirst(htmlspecialchars($user_role)); ?></div>
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="sidenav-menu">
        <div class="sidenav-section-label">Main</div>

        <a href="<?php echo $base; ?>dashboard.php"
           class="sidenav-item <?php echo ($current_page === 'dashboard') ? 'active' : ''; ?>">
            <span class="sidenav-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                </svg>
            </span>
            Dashboard
        </a>

        <?php if ($user_role === 'mentor' || $user_role === 'mentee'): ?>
        <!-- ───── Mentor / Mentee Menu ───── -->
        <div class="sidenav-section-label">Actions</div>

        <a href="<?php echo $base; ?>pages/session_booking.php"
           class="sidenav-item <?php echo ($current_page === 'session_booking') ? 'active' : ''; ?>">
            <span class="sidenav-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
            </span>
            Session Booking
        </a>

        <a href="<?php echo $base; ?>pages/feedback.php"
           class="sidenav-item <?php echo ($current_page === 'feedback') ? 'active' : ''; ?>">
            <span class="sidenav-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
            </span>
            Feedback
        </a>

        <!-- TASK 6: Messages link with live unread badge ── -->
        <a href="<?php echo $base; ?>chat_redirect.php"
           class="sidenav-item <?php echo ($current_page === 'chat') ? 'active' : ''; ?>"
           id="sidenav-chat-link"
           style="position:relative; display:flex; align-items:center; gap:0;">
            <span class="sidenav-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
            </span>
            Messages
            <span id="chat-unread-badge"
                  style="display:none; margin-left:auto; background:#e74c3c; color:#fff;
                         border-radius:10px; padding:1px 7px; font-size:11px;
                         font-weight:700; line-height:18px; min-width:18px;
                         text-align:center;">0</span>
        </a>
        <!-- ── /TASK 6 ── -->

        <?php endif; ?>

        <?php if ($user_role === 'admin'): ?>
        <!-- ───── Admin Menu ───── -->
        <div class="sidenav-section-label">Management</div>

        <a href="<?php echo $base; ?>pages/session_booking.php"
           class="sidenav-item <?php echo ($current_page === 'session_booking') ? 'active' : ''; ?>">
            <span class="sidenav-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
            </span>
            Session Booking
        </a>

        <a href="<?php echo $base; ?>pages/feedback.php"
           class="sidenav-item <?php echo ($current_page === 'feedback') ? 'active' : ''; ?>">
            <span class="sidenav-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
            </span>
            Feedback
        </a>

        <a href="<?php echo $base; ?>pages/connection_status.php"
           class="sidenav-item <?php echo ($current_page === 'connection_status') ? 'active' : ''; ?>">
            <span class="sidenav-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </span>
            Connection Status
        </a>

        <a href="<?php echo $base; ?>views/admin/mentorships.php"
           class="sidenav-item sidenav-reassign <?php echo ($current_page === 'reassign') ? 'active' : ''; ?>">
            <span class="sidenav-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 1l4 4-4 4"/>
                    <path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                    <path d="M7 23l-4-4 4-4"/>
                    <path d="M21 13v2a4 4 0 0 1-4 4H3"/>
                </svg>
            </span>
            Reassign
            <!-- <span class="sidenav-reassign-badge">6M</span> -->
        </a>

        <a href="<?php echo $base; ?>views/admin/new_batch.php"
           class="sidenav-item <?php echo ($current_page === 'new_batch') ? 'active' : ''; ?>">
            <span class="sidenav-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <line x1="19" y1="8" x2="19" y2="14"/>
                    <line x1="16" y1="11" x2="22" y2="11"/>
                </svg>
            </span>
            New Batch
        </a>

        <?php endif; ?>

        <!-- ───── Common Bottom Items ───── -->
        <div class="sidenav-divider"></div>

        <a href="<?php echo $base; ?>logout.php" class="sidenav-item sidenav-logout">
            <span class="sidenav-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
            </span>
            Logout
        </a>
    </nav>
</div>

<!-- Mobile overlay -->
<div class="sidenav-overlay" id="sideNavOverlay" onclick="closeSidenav()"></div>

<?php if (isset($user_role) && ($user_role === 'mentor' || $user_role === 'mentee')): ?>
<!-- TASK 6: Poll unread message count and show badge in sidenav -->
<script>
(function () {
    // Resolve root path based on current URL depth
    var rootPath = (function () {
        var p = window.location.pathname;
        if (p.indexOf('/views/') !== -1) return '../../';
        if (p.indexOf('/pages/') !== -1) return '../';
        return '';
    })();

    function refreshUnreadBadge() {
        fetch(rootPath + 'get_unread_count.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { unread: 0 }; })
            .then(function (data) {
                var badge = document.getElementById('chat-unread-badge');
                if (!badge) return;
                var n = parseInt(data.unread) || 0;
                if (n > 0) {
                    badge.textContent = n > 99 ? '99+' : String(n);
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                }
            })
            .catch(function () { /* silent on network error */ });
    }

    // Run immediately on page load
    refreshUnreadBadge();

    // Then poll every 30 seconds
    setInterval(refreshUnreadBadge, 30000);
})();
</script>
<?php endif; ?>