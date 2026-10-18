<?php
/**
 * User Model
 */

require_once __DIR__ . '/../config/database.php';

class User {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get all users with optional filters
     */
    public function getAllUsers($role = null, $limit = null, $offset = 0) {
        $query = 'SELECT user_id, first_name, last_name, email, role, domain, average_rating, 
                         total_sessions_completed, is_active, created_at 
                  FROM users WHERE 1=1';
        $params = [];

        if ($role) {
            $query .= ' AND role = ?';
            $params[] = $role;
        }

        $query .= ' ORDER BY created_at DESC';

        if ($limit) {
            $query .= ' LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = $offset;
        }

        return $this->db->fetchAll($query, $params);
    }

    /**
     * Get user by ID
     */
    public function getUserById($user_id) {
        return $this->db->fetchOne(
            'SELECT user_id, first_name, last_name, email, role, domain, bio, profile_picture, 
                    expertise_area, average_rating, total_sessions_completed, is_active, created_at 
             FROM users WHERE user_id = ?',
            [$user_id]
        );
    }

    /**
     * Get mentors by domain (excluding the requesting mentee)
     */
    public function getMentorsByDomain($domain, $exclude_user_id = null) {
        $query = 'SELECT user_id, first_name, last_name, email, domain, bio, expertise_area, 
                         average_rating, total_sessions_completed
                  FROM users 
                  WHERE role = "mentor" AND domain = ? AND is_active = 1';
        $params = [$domain];

        if ($exclude_user_id) {
            $query .= ' AND user_id != ?';
            $params[] = $exclude_user_id;
        }

        $query .= ' ORDER BY average_rating DESC';
        return $this->db->fetchAll($query, $params);
    }

    /**
     * Check how many active mentees a mentor has
     */
    public function getActiveMenteeCount($mentor_id) {
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) as count FROM mentorships 
             WHERE mentor_id = ? AND status = "active"',
            [$mentor_id]
        );
        return $result['count'] ?? 0;
    }

    /**
     * Check if mentor is fully booked
     */
    public function isMentorFullyBooked($mentor_id) {
        $count = $this->getActiveMenteeCount($mentor_id);
        return $count >= MAX_MENTEES_PER_MENTOR;
    }

    /**
     * Count active mentorships for a mentee
     */
    public function getActiveMentorshipCount($mentee_id) {
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) as count FROM mentorships 
             WHERE mentee_id = ? AND status = "active"',
            [$mentee_id]
        );
        return $result['count'] ?? 0;
    }

    /**
     * Check if mentee can request more mentors
     */
    public function canMenteeRequestMore($mentee_id) {
        $count = $this->db->fetchOne(
            'SELECT COUNT(*) as count FROM mentor_requests 
             WHERE mentee_id = ? AND status IN ("pending", "accepted")',
            [$mentee_id]
        );
        return ($count['count'] ?? 0) < MAX_MENTORS_PER_MENTEE;
    }

    /**
     * Update user profile
     */
    public function updateProfile($user_id, $data) {
        $allowed_fields = ['first_name', 'last_name', 'bio', 'expertise_area', 'profile_picture'];
        $update_fields = [];
        $params = [];

        foreach ($allowed_fields as $field) {
            if (isset($data[$field]) && $data[$field] !== null) {
                $update_fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($update_fields)) {
            return false;
        }

        $params[] = $user_id;
        $query = 'UPDATE users SET ' . implode(', ', $update_fields) . ', updated_at = NOW() WHERE user_id = ?';
        
        return $this->db->update($query, $params) > 0;
    }

    /**
     * Update user rating (calculated from feedback)
     */
    public function updateMentorRating($mentor_id) {
        $result = $this->db->fetchOne(
            'SELECT AVG(rating) as avg_rating, COUNT(*) as total 
             FROM feedback WHERE mentor_id = ?',
            [$mentor_id]
        );

        if ($result && $result['total'] > 0) {
            $avg_rating = round($result['avg_rating'], 2);
            $this->db->update(
                'UPDATE users SET average_rating = ? WHERE user_id = ?',
                [$avg_rating, $mentor_id]
            );
        }
    }

    /**
     * Deactivate user account
     */
    public function deactivateUser($user_id) {
        return $this->db->update('UPDATE users SET is_active = 0 WHERE user_id = ?', [$user_id]) > 0;
    }

    /**
     * Activate user account
     */
    public function activateUser($user_id) {
        return $this->db->update('UPDATE users SET is_active = 1 WHERE user_id = ?', [$user_id]) > 0;
    }

    /**
     * Get total user count by role
     */
    public function getUserCountByRole() {
        return $this->db->fetchAll(
            'SELECT role, COUNT(*) as count FROM users GROUP BY role'
        );
    }
}
?>
