<?php
/**
 * Mentorship Model
 * MODIFIED:
 *   - validateDomains()       — now checks BOTH `domain` and `department`
 *                               columns for cross-department enforcement.
 *   - getPairingLockStatus()  — NEW: returns 6-month lock info for a mentee.
 *   - All other methods unchanged.
 */

require_once __DIR__ . '/../config/database.php';

class Mentorship {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get all active mentorships for a mentor
     */
    public function getMentorMentorships($mentor_id, $status = 'active') {
        $query = 'SELECT m.mentorship_id, m.mentee_id, m.start_date, m.end_date, m.status,
                         m.sessions_completed, u.first_name, u.last_name, u.email, u.domain
                  FROM mentorships m
                  JOIN users u ON m.mentee_id = u.user_id
                  WHERE m.mentor_id = ?';
        $params = [$mentor_id];

        if ($status) {
            $query .= ' AND m.status = ?';
            $params[] = $status;
        }

        $query .= ' ORDER BY m.start_date DESC';
        return $this->db->fetchAll($query, $params);
    }

    /**
     * Get all active mentorships for a mentee
     */
    public function getMenteeMentorships($mentee_id, $status = 'active') {
        $query = 'SELECT m.mentorship_id, m.mentor_id, m.start_date, m.end_date, m.status,
                         m.sessions_completed, u.first_name, u.last_name, u.email, u.domain, u.expertise_area, u.average_rating
                  FROM mentorships m
                  JOIN users u ON m.mentor_id = u.user_id
                  WHERE m.mentee_id = ?';
        $params = [$mentee_id];

        if ($status) {
            $query .= ' AND m.status = ?';
            $params[] = $status;
        }

        $query .= ' ORDER BY m.start_date DESC';
        return $this->db->fetchAll($query, $params);
    }

    /**
     * Get mentorship details
     */
    public function getMentorshipById($mentorship_id) {
        return $this->db->fetchOne(
            'SELECT m.mentorship_id, m.mentee_id, m.mentor_id, m.status, m.start_date, m.end_date,
                    m.sessions_completed, m.created_at, m.completion_notes,
                    mu.first_name as mentor_first_name, mu.last_name as mentor_last_name, mu.email as mentor_email,
                    eu.first_name as mentee_first_name, eu.last_name as mentee_last_name, eu.email as mentee_email
             FROM mentorships m
             JOIN users mu ON m.mentor_id = mu.user_id
             JOIN users eu ON m.mentee_id = eu.user_id
             WHERE m.mentorship_id = ?',
            [$mentorship_id]
        );
    }

    /**
     * Get all active mentorships (for admin)
     */
    public function getAllActiveMentorships($limit = null, $offset = 0) {
        $query = 'SELECT m.mentorship_id, m.mentee_id, m.mentor_id, m.status, m.start_date, m.end_date,
                         m.sessions_completed, m.created_at,
                         mu.first_name as mentor_first_name, mu.last_name as mentor_last_name,
                         eu.first_name as mentee_first_name, eu.last_name as mentee_last_name
                  FROM mentorships m
                  JOIN users mu ON m.mentor_id = mu.user_id
                  JOIN users eu ON m.mentee_id = eu.user_id
                  WHERE m.status = "active"
                  ORDER BY m.created_at DESC';

        $params = [];
        if ($limit) {
            $query .= ' LIMIT ? OFFSET ?';
            $params = [$limit, $offset];
        }

        return $this->db->fetchAll($query, $params);
    }

    /**
     * Get completed mentorships (for admin)
     */
    public function getCompletedMentorships($limit = null, $offset = 0) {
        $query = 'SELECT m.mentorship_id, m.mentee_id, m.mentor_id, m.status, m.start_date, m.end_date,
                         m.sessions_completed, m.created_at, m.completion_notes,
                         mu.first_name as mentor_first_name, mu.last_name as mentor_last_name,
                         eu.first_name as mentee_first_name, eu.last_name as mentee_last_name
                  FROM mentorships m
                  JOIN users mu ON m.mentor_id = mu.user_id
                  JOIN users eu ON m.mentee_id = eu.user_id
                  WHERE m.status = "completed"
                  ORDER BY m.end_date DESC';

        $params = [];
        if ($limit) {
            $query .= ' LIMIT ? OFFSET ?';
            $params = [$limit, $offset];
        }

        return $this->db->fetchAll($query, $params);
    }

    /**
     * Update sessions completed and check if mentorship should be completed
     */
    public function updateSessionsCompleted($mentorship_id) {
        try {
            $result = $this->db->fetchOne(
                'SELECT COUNT(*) as count FROM sessions WHERE mentorship_id = ? AND status = "completed"',
                [$mentorship_id]
            );

            $sessions_completed = $result['count'] ?? 0;

            $this->db->update(
                'UPDATE mentorships SET sessions_completed = ? WHERE mentorship_id = ?',
                [$sessions_completed, $mentorship_id]
            );

            if ($sessions_completed >= SESSIONS_PER_MENTORSHIP) {
                $this->db->update(
                    'UPDATE mentorships SET status = "completed", completion_notes = ? WHERE mentorship_id = ?',
                    ['All 6 sessions completed successfully', $mentorship_id]
                );
                return ['status' => 'completed'];
            }

            return ['status' => 'active', 'sessions_completed' => $sessions_completed];
        } catch (Exception $e) {
            error_log('Update Sessions Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update sessions'];
        }
    }

    /**
     * Cancel a mentorship
     */
    public function cancelMentorship($mentorship_id, $reason = '') {
        try {
            $this->db->update(
                'UPDATE mentorships SET status = "cancelled", completion_notes = ? WHERE mentorship_id = ?',
                [$reason, $mentorship_id]
            );
            return ['success' => true, 'message' => 'Mentorship cancelled'];
        } catch (Exception $e) {
            error_log('Cancel Mentorship Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to cancel mentorship'];
        }
    }

    /**
     * Get mentorship statistics
     */
    public function getMentorshipStats() {
        $total     = $this->db->fetchOne('SELECT COUNT(*) as count FROM mentorships');
        $active    = $this->db->fetchOne('SELECT COUNT(*) as count FROM mentorships WHERE status = "active"');
        $completed = $this->db->fetchOne('SELECT COUNT(*) as count FROM mentorships WHERE status = "completed"');
        $cancelled = $this->db->fetchOne('SELECT COUNT(*) as count FROM mentorships WHERE status = "cancelled"');

        return [
            'total'     => $total['count']     ?? 0,
            'active'    => $active['count']    ?? 0,
            'completed' => $completed['count'] ?? 0,
            'cancelled' => $cancelled['count'] ?? 0,
        ];
    }

    /**
     * MODIFIED: Check if mentor and mentee are from DIFFERENT departments.
     *
     * Returns TRUE  = cross-department (request ALLOWED).
     * Returns FALSE = same department  (request BLOCKED).
     *
     * Supports both schema variants:
     *   - Model-layer DB uses `domain`      column (user_id PK)
     *   - Production DB uses `department`   column (id PK)
     * COALESCE tries `domain` first, falls back to `department`.
     */
    public function validateDomains($mentor_id, $mentee_id) {
        // Try model-layer schema (user_id, domain)
        $mentor = $this->db->fetchOne(
            'SELECT COALESCE(domain, department, "") AS dept FROM users WHERE user_id = ?',
            [$mentor_id]
        );
        $mentee = $this->db->fetchOne(
            'SELECT COALESCE(domain, department, "") AS dept FROM users WHERE user_id = ?',
            [$mentee_id]
        );

        // If user_id lookup fails, fall back to production schema (id, department)
        if (!$mentor) {
            $mentor = $this->db->fetchOne(
                'SELECT COALESCE(department, "") AS dept FROM users WHERE id = ?',
                [$mentor_id]
            );
        }
        if (!$mentee) {
            $mentee = $this->db->fetchOne(
                'SELECT COALESCE(department, "") AS dept FROM users WHERE id = ?',
                [$mentee_id]
            );
        }

        if (!$mentor || !$mentee) {
            return false; // Cannot validate — block to be safe
        }

        $m_dept  = strtolower(trim($mentor['dept'] ?? ''));
        $ee_dept = strtolower(trim($mentee['dept'] ?? ''));

        // Allowed only if BOTH have a department AND they are DIFFERENT
        return ($m_dept !== '' && $ee_dept !== '' && $m_dept !== $ee_dept);
    }

    /**
     * NEW: Get 6-month pairing lock status for a mentee.
     *
     * Returns:
     *   [
     *     'locked'         => bool,
     *     'expires_at'     => 'Y-m-d H:i:s' | null,
     *     'days_remaining' => int,
     *   ]
     */
    public function getPairingLockStatus($mentee_id) {
        // Try model-layer schema first
        $result = $this->db->fetchOne(
            'SELECT paired_at, pairing_expires_at FROM mentor_requests
             WHERE mentee_id = ? AND status = "accepted"
             ORDER BY paired_at DESC LIMIT 1',
            [$mentee_id]
        );

        if (!$result || !isset($result['paired_at'])) {
            return ['locked' => false, 'expires_at' => null, 'days_remaining' => 0];
        }

        $expires_ts     = !empty($result['pairing_expires_at'])
                          ? strtotime($result['pairing_expires_at']) : null;
        $is_locked      = $expires_ts && $expires_ts > time();
        $days_remaining = $expires_ts ? max(0, (int) ceil(($expires_ts - time()) / 86400)) : 0;

        return [
            'locked'         => (bool) $is_locked,
            'expires_at'     => $result['pairing_expires_at'] ?? null,
            'days_remaining' => $days_remaining,
        ];
    }

    /**
     * Get upcoming sessions for all mentorships
     */
    public function getUpcomingSessions($days = 30) {
        $future_date = date('Y-m-d', strtotime("+$days days"));
        return $this->db->fetchAll(
            'SELECT s.session_id, s.mentorship_id, s.session_number, s.scheduled_date, s.status,
                    m.mentee_id, m.mentor_id,
                    mu.first_name as mentor_first_name, mu.last_name as mentor_last_name,
                    eu.first_name as mentee_first_name, eu.last_name as mentee_last_name, eu.email
             FROM sessions s
             JOIN mentorships m ON s.mentorship_id = m.mentorship_id
             JOIN users mu ON m.mentor_id = mu.user_id
             JOIN users eu ON m.mentee_id = eu.user_id
             WHERE s.status = "scheduled" AND DATE(s.scheduled_date) <= ? AND DATE(s.scheduled_date) >= CURDATE()
             ORDER BY s.scheduled_date ASC',
            [$future_date]
        );
    }
}
?>