<?php
/**
 * Session Model
 */

require_once __DIR__ . '/../config/database.php';

class Session {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get all sessions for a mentorship
     */
    public function getMentorshipSessions($mentorship_id) {
        return $this->db->fetchAll(
            'SELECT session_id, mentorship_id, session_number, scheduled_date, status, notes, created_at
             FROM sessions
             WHERE mentorship_id = ?
             ORDER BY session_number ASC',
            [$mentorship_id]
        );
    }

    /**
     * Get session details
     */
    public function getSessionById($session_id) {
        return $this->db->fetchOne(
            'SELECT s.session_id, s.mentorship_id, s.session_number, s.scheduled_date, s.status, s.notes,
                    m.mentee_id, m.mentor_id,
                    mu.first_name as mentor_first_name, mu.last_name as mentor_last_name, mu.email as mentor_email,
                    eu.first_name as mentee_first_name, eu.last_name as mentee_last_name, eu.email as mentee_email
             FROM sessions s
             JOIN mentorships m ON s.mentorship_id = m.mentorship_id
             JOIN users mu ON m.mentor_id = mu.user_id
             JOIN users eu ON m.mentee_id = eu.user_id
             WHERE s.session_id = ?',
            [$session_id]
        );
    }

    /**
     * Mark session as completed
     */
    public function completeSession($session_id, $notes = '') {
        try {
            $this->db->update(
                'UPDATE sessions SET status = "completed", notes = ?, updated_at = NOW() WHERE session_id = ?',
                [$notes, $session_id]
            );

            // Update mentorship sessions_completed count
            $session = $this->db->fetchOne(
                'SELECT mentorship_id FROM sessions WHERE session_id = ?',
                [$session_id]
            );

            if ($session) {
                $result = $this->db->fetchOne(
                    'SELECT COUNT(*) as count FROM sessions WHERE mentorship_id = ? AND status = "completed"',
                    [$session['mentorship_id']]
                );

                $completed_count = $result['count'] ?? 0;

                // Update mentorship
                $this->db->update(
                    'UPDATE mentorships SET sessions_completed = ? WHERE mentorship_id = ?',
                    [$completed_count, $session['mentorship_id']]
                );

                // If all 6 sessions completed, mark mentorship as completed
                if ($completed_count >= SESSIONS_PER_MENTORSHIP) {
                    $this->db->update(
                        'UPDATE mentorships SET status = "completed", completion_notes = ? WHERE mentorship_id = ?',
                        ['All 6 sessions completed successfully', $session['mentorship_id']]
                    );
                }
            }

            return ['success' => true, 'message' => 'Session marked as completed'];
        } catch (Exception $e) {
            error_log('Complete Session Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to complete session'];
        }
    }

    /**
     * Reschedule session
     */
    public function rescheduleSession($session_id, $new_date) {
        try {
            $this->db->update(
                'UPDATE sessions SET scheduled_date = ?, status = "rescheduled", updated_at = NOW() WHERE session_id = ?',
                [$new_date, $session_id]
            );
            return ['success' => true, 'message' => 'Session rescheduled'];
        } catch (Exception $e) {
            error_log('Reschedule Session Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to reschedule session'];
        }
    }

    /**
     * Get upcoming sessions for a user
     */
    public function getUpcomingSessionsForUser($user_id, $role = null) {
        if ($role === 'mentor') {
            return $this->db->fetchAll(
                'SELECT s.session_id, s.mentorship_id, s.session_number, s.scheduled_date, s.status,
                        eu.first_name, eu.last_name, eu.email
                 FROM sessions s
                 JOIN mentorships m ON s.mentorship_id = m.mentorship_id
                 JOIN users eu ON m.mentee_id = eu.user_id
                 WHERE m.mentor_id = ? AND s.status IN ("scheduled", "rescheduled") 
                       AND s.scheduled_date >= NOW()
                 ORDER BY s.scheduled_date ASC',
                [$user_id]
            );
        } elseif ($role === 'mentee') {
            return $this->db->fetchAll(
                'SELECT s.session_id, s.mentorship_id, s.session_number, s.scheduled_date, s.status,
                        mu.first_name, mu.last_name, mu.email
                 FROM sessions s
                 JOIN mentorships m ON s.mentorship_id = m.mentorship_id
                 JOIN users mu ON m.mentor_id = mu.user_id
                 WHERE m.mentee_id = ? AND s.status IN ("scheduled", "rescheduled") 
                       AND s.scheduled_date >= NOW()
                 ORDER BY s.scheduled_date ASC',
                [$user_id]
            );
        }

        return [];
    }

    /**
     * Get completed sessions for a user
     */
    public function getCompletedSessionsForUser($user_id, $role = null) {
        if ($role === 'mentor') {
            return $this->db->fetchAll(
                'SELECT s.session_id, s.mentorship_id, s.session_number, s.scheduled_date, s.status,
                        eu.first_name, eu.last_name
                 FROM sessions s
                 JOIN mentorships m ON s.mentorship_id = m.mentorship_id
                 JOIN users eu ON m.mentee_id = eu.user_id
                 WHERE m.mentor_id = ? AND s.status = "completed"
                 ORDER BY s.scheduled_date DESC',
                [$user_id]
            );
        } elseif ($role === 'mentee') {
            return $this->db->fetchAll(
                'SELECT s.session_id, s.mentorship_id, s.session_number, s.scheduled_date, s.status,
                        mu.first_name, mu.last_name
                 FROM sessions s
                 JOIN mentorships m ON s.mentorship_id = m.mentorship_id
                 JOIN users mu ON m.mentor_id = mu.user_id
                 WHERE m.mentee_id = ? AND s.status = "completed"
                 ORDER BY s.scheduled_date DESC',
                [$user_id]
            );
        }

        return [];
    }

    /**
     * Get session statistics
     */
    public function getSessionStats() {
        $scheduled = $this->db->fetchOne('SELECT COUNT(*) as count FROM sessions WHERE status = "scheduled"');
        $completed = $this->db->fetchOne('SELECT COUNT(*) as count FROM sessions WHERE status = "completed"');
        $rescheduled = $this->db->fetchOne('SELECT COUNT(*) as count FROM sessions WHERE status = "rescheduled"');

        return [
            'scheduled' => $scheduled['count'] ?? 0,
            'completed' => $completed['count'] ?? 0,
            'rescheduled' => $rescheduled['count'] ?? 0
        ];
    }

    /**
     * Get sessions pending feedback
     */
    public function getSessionsPendingFeedback($mentee_id) {
        return $this->db->fetchAll(
            'SELECT s.session_id, s.mentorship_id, s.session_number, s.scheduled_date,
                    m.mentor_id, mu.first_name, mu.last_name
             FROM sessions s
             JOIN mentorships m ON s.mentorship_id = m.mentorship_id
             JOIN users mu ON m.mentor_id = mu.user_id
             WHERE m.mentee_id = ? AND s.status = "completed" 
                   AND NOT EXISTS (SELECT 1 FROM feedback WHERE session_id = s.session_id)
             ORDER BY s.scheduled_date DESC',
            [$mentee_id]
        );
    }

    /**
     * Cancel session
     */
    public function cancelSession($session_id) {
        try {
            $this->db->update(
                'UPDATE sessions SET status = "cancelled", updated_at = NOW() WHERE session_id = ?',
                [$session_id]
            );
            return ['success' => true, 'message' => 'Session cancelled'];
        } catch (Exception $e) {
            error_log('Cancel Session Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to cancel session'];
        }
    }
}
?>
