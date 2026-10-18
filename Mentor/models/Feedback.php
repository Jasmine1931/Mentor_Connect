<?php
/**
 * Feedback Model
 */

require_once __DIR__ . '/../config/database.php';

class Feedback {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Submit feedback for a session
     */
    public function submitFeedback($session_id, $mentorship_id, $mentee_id, $mentor_id, $rating, $comments) {
        // Validate rating
        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'message' => 'Rating must be between 1 and 5'];
        }

        try {
            // Check if feedback already exists for this session
            $existing = $this->db->fetchOne(
                'SELECT feedback_id FROM feedback WHERE session_id = ?',
                [$session_id]
            );

            if ($existing) {
                return ['success' => false, 'message' => 'Feedback already submitted for this session'];
            }

            $query = 'INSERT INTO feedback (session_id, mentorship_id, mentee_id, mentor_id, rating, comments) 
                      VALUES (?, ?, ?, ?, ?, ?)';
            $feedback_id = $this->db->insert(
                $query,
                [$session_id, $mentorship_id, $mentee_id, $mentor_id, $rating, $comments]
            );

            // Update mentor's average rating
            $this->updateMentorRating($mentor_id);

            return ['success' => true, 'message' => 'Feedback submitted successfully', 'feedback_id' => $feedback_id];
        } catch (Exception $e) {
            error_log('Submit Feedback Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to submit feedback'];
        }
    }

    /**
     * Get feedback for a mentor
     */
    public function getMentorFeedback($mentor_id, $limit = null, $offset = 0) {
        $query = 'SELECT f.feedback_id, f.rating, f.comments, f.submitted_at,
                         u.first_name, u.last_name, u.email,
                         s.session_number
                  FROM feedback f
                  JOIN users u ON f.mentee_id = u.user_id
                  JOIN sessions s ON f.session_id = s.session_id
                  WHERE f.mentor_id = ?
                  ORDER BY f.submitted_at DESC';

        $params = [$mentor_id];

        if ($limit) {
            $query .= ' LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = $offset;
        }

        return $this->db->fetchAll($query, $params);
    }

    /**
     * Get feedback given by a mentee
     */
    public function getMenteeFeedback($mentee_id) {
        return $this->db->fetchAll(
            'SELECT f.feedback_id, f.mentor_id, f.rating, f.comments, f.submitted_at,
                    mu.first_name, mu.last_name,
                    s.session_number
             FROM feedback f
             JOIN users mu ON f.mentor_id = mu.user_id
             JOIN sessions s ON f.session_id = s.session_id
             WHERE f.mentee_id = ?
             ORDER BY f.submitted_at DESC',
            [$mentee_id]
        );
    }

    /**
     * Get feedback for a specific mentorship
     */
    public function getMentorshipFeedback($mentorship_id) {
        return $this->db->fetchAll(
            'SELECT f.feedback_id, f.session_id, f.rating, f.comments, f.submitted_at,
                    u.first_name, u.last_name, s.session_number
             FROM feedback f
             JOIN users u ON f.mentee_id = u.user_id
             JOIN sessions s ON f.session_id = s.session_id
             WHERE f.mentorship_id = ?
             ORDER BY s.session_number ASC',
            [$mentorship_id]
        );
    }

    /**
     * Get mentor's average rating
     */
    public function getMentorAverageRating($mentor_id) {
        $result = $this->db->fetchOne(
            'SELECT AVG(rating) as avg_rating, COUNT(*) as total_feedback
             FROM feedback WHERE mentor_id = ?',
            [$mentor_id]
        );

        return [
            'average_rating' => $result ? round($result['avg_rating'] ?? 0, 2) : 0,
            'total_feedback' => $result['total_feedback'] ?? 0
        ];
    }

    /**
     * Update mentor rating in users table
     */
    private function updateMentorRating($mentor_id) {
        $stats = $this->getMentorAverageRating($mentor_id);
        $this->db->update(
            'UPDATE users SET average_rating = ? WHERE user_id = ?',
            [$stats['average_rating'], $mentor_id]
        );
    }

    /**
     * Get feedback distribution for a mentor
     */
    public function getFeedbackDistribution($mentor_id) {
        return $this->db->fetchAll(
            'SELECT rating, COUNT(*) as count FROM feedback WHERE mentor_id = ? GROUP BY rating ORDER BY rating ASC',
            [$mentor_id]
        );
    }

    /**
     * Get all feedback (for admin)
     */
    public function getAllFeedback($limit = null, $offset = 0) {
        $query = 'SELECT f.feedback_id, f.rating, f.comments, f.submitted_at,
                         m_user.first_name as mentor_first_name, m_user.last_name as mentor_last_name,
                         e_user.first_name as mentee_first_name, e_user.last_name as mentee_last_name,
                         s.session_number
                  FROM feedback f
                  JOIN users m_user ON f.mentor_id = m_user.user_id
                  JOIN users e_user ON f.mentee_id = e_user.user_id
                  JOIN sessions s ON f.session_id = s.session_id
                  ORDER BY f.submitted_at DESC';

        $params = [];
        if ($limit) {
            $query .= ' LIMIT ? OFFSET ?';
            $params = [$limit, $offset];
        }

        return $this->db->fetchAll($query, $params);
    }

    /**
     * Get feedback statistics
     */
    public function getFeedbackStats() {
        $total = $this->db->fetchOne('SELECT COUNT(*) as count FROM feedback');
        $avg_rating = $this->db->fetchOne('SELECT AVG(rating) as avg FROM feedback');
        
        $distribution = $this->db->fetchAll(
            'SELECT rating, COUNT(*) as count FROM feedback GROUP BY rating ORDER BY rating'
        );

        return [
            'total_feedback' => $total['count'] ?? 0,
            'average_rating' => round($avg_rating['avg'] ?? 0, 2),
            'distribution' => $distribution
        ];
    }

    /**
     * Get mentor feedback report
     */
    public function getMentorFeedbackReport($mentor_id) {
        $stats = $this->getMentorAverageRating($mentor_id);
        $distribution = $this->getFeedbackDistribution($mentor_id);
        $recent_feedback = $this->getMentorFeedback($mentor_id, 10, 0);

        return [
            'average_rating' => $stats['average_rating'],
            'total_feedback' => $stats['total_feedback'],
            'distribution' => $distribution,
            'recent_feedback' => $recent_feedback
        ];
    }
}
?>
