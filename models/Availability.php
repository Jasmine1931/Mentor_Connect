<?php
/**
 * Availability Model
 * Handles mentor availability slots and calendar booking
 */

require_once __DIR__ . '/../config/database.php';

class Availability {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Add availability slot for mentor
     */
    public function addAvailability($mentor_id, $date, $start_time, $end_time) {
        // Validate date and time
        if (strtotime($date) < strtotime(date('Y-m-d'))) {
            return ['success' => false, 'message' => 'Cannot set availability in the past'];
        }

        if (strtotime($end_time) <= strtotime($start_time)) {
            return ['success' => false, 'message' => 'End time must be after start time'];
        }

        try {
            // Check for overlap
            $overlap = $this->db->fetchOne(
                'SELECT availability_id FROM availability 
                 WHERE mentor_id = ? AND available_date = ? AND is_booked = 0
                 AND NOT (end_time <= ? OR start_time >= ?)',
                [$mentor_id, $date, $start_time, $end_time]
            );

            if ($overlap) {
                return ['success' => false, 'message' => 'Time slot overlaps with existing availability'];
            }

            $availability_id = $this->db->insert(
                'INSERT INTO availability (mentor_id, available_date, start_time, end_time) 
                 VALUES (?, ?, ?, ?)',
                [$mentor_id, $date, $start_time, $end_time]
            );

            return ['success' => true, 'message' => 'Availability added', 'availability_id' => $availability_id];
        } catch (Exception $e) {
            error_log('Add Availability Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to add availability'];
        }
    }

    /**
     * Get mentor's available slots (not booked)
     */
    public function getMentorAvailability($mentor_id, $from_date = null, $to_date = null) {
        $from_date = $from_date ?? date('Y-m-d');
        $to_date = $to_date ?? date('Y-m-d', strtotime('+30 days'));

        return $this->db->fetchAll(
            'SELECT availability_id, available_date, start_time, end_time, is_booked
             FROM availability
             WHERE mentor_id = ? AND available_date BETWEEN ? AND ? AND is_booked = 0
             ORDER BY available_date ASC, start_time ASC',
            [$mentor_id, $from_date, $to_date]
        );
    }

    /**
     * Get all availability for a mentor (including booked)
     */
    public function getAllMentorAvailability($mentor_id) {
        return $this->db->fetchAll(
            'SELECT availability_id, available_date, start_time, end_time, is_booked, 
                    booked_by_mentee_id, booked_session_id
             FROM availability
             WHERE mentor_id = ?
             ORDER BY available_date DESC, start_time DESC',
            [$mentor_id]
        );
    }

    /**
     * Book a time slot for a mentee
     */
    public function bookSlot($availability_id, $mentee_id, $session_id) {
        try {
            $availability = $this->db->fetchOne(
                'SELECT availability_id, is_booked FROM availability WHERE availability_id = ?',
                [$availability_id]
            );

            if (!$availability) {
                return ['success' => false, 'message' => 'Slot not found'];
            }

            if ($availability['is_booked']) {
                return ['success' => false, 'message' => 'Slot already booked'];
            }

            $this->db->update(
                'UPDATE availability SET is_booked = 1, booked_by_mentee_id = ?, booked_session_id = ?, updated_at = NOW() 
                 WHERE availability_id = ?',
                [$mentee_id, $session_id, $availability_id]
            );

            // Update session with scheduled_date from availability
            $slot_info = $this->db->fetchOne(
                'SELECT available_date, start_time FROM availability WHERE availability_id = ?',
                [$availability_id]
            );

            if ($slot_info) {
                $scheduled_datetime = $slot_info['available_date'] . ' ' . $slot_info['start_time'];
                $this->db->update(
                    'UPDATE sessions SET scheduled_date = ? WHERE session_id = ?',
                    [$scheduled_datetime, $session_id]
                );
            }

            return ['success' => true, 'message' => 'Slot booked successfully'];
        } catch (Exception $e) {
            error_log('Book Slot Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to book slot'];
        }
    }

    /**
     * Cancel/Release a booked slot
     */
    public function releaseSlot($availability_id) {
        try {
            $slot = $this->db->fetchOne(
                'SELECT availability_id, is_booked, booked_session_id FROM availability WHERE availability_id = ?',
                [$availability_id]
            );

            if (!$slot || !$slot['is_booked']) {
                return ['success' => false, 'message' => 'Slot is not booked'];
            }

            $this->db->update(
                'UPDATE availability SET is_booked = 0, booked_by_mentee_id = NULL, booked_session_id = NULL, updated_at = NOW() 
                 WHERE availability_id = ?',
                [$availability_id]
            );

            return ['success' => true, 'message' => 'Slot released'];
        } catch (Exception $e) {
            error_log('Release Slot Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to release slot'];
        }
    }

    /**
     * Delete availability slot
     */
    public function deleteAvailability($availability_id, $mentor_id) {
        try {
            $slot = $this->db->fetchOne(
                'SELECT availability_id, mentor_id, is_booked FROM availability WHERE availability_id = ?',
                [$availability_id]
            );

            if (!$slot || $slot['mentor_id'] != $mentor_id) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }

            if ($slot['is_booked']) {
                return ['success' => false, 'message' => 'Cannot delete booked slot'];
            }

            $this->db->update('DELETE FROM availability WHERE availability_id = ?', [$availability_id]);
            return ['success' => true, 'message' => 'Availability deleted'];
        } catch (Exception $e) {
            error_log('Delete Availability Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to delete availability'];
        }
    }

    /**
     * Get booked slots for a date range
     */
    public function getBookedSlots($mentor_id, $from_date = null, $to_date = null) {
        $from_date = $from_date ?? date('Y-m-d');
        $to_date = $to_date ?? date('Y-m-d', strtotime('+30 days'));

        return $this->db->fetchAll(
            'SELECT a.availability_id, a.available_date, a.start_time, a.end_time,
                    u.first_name, u.last_name, u.email, s.session_number
             FROM availability a
             JOIN users u ON a.booked_by_mentee_id = u.user_id
             LEFT JOIN sessions s ON a.booked_session_id = s.session_id
             WHERE a.mentor_id = ? AND a.is_booked = 1 
                   AND a.available_date BETWEEN ? AND ?
             ORDER BY a.available_date ASC, a.start_time ASC',
            [$mentor_id, $from_date, $to_date]
        );
    }

    /**
     * Get availability statistics
     */
    public function getAvailabilityStats($mentor_id) {
        $total = $this->db->fetchOne(
            'SELECT COUNT(*) as count FROM availability WHERE mentor_id = ?',
            [$mentor_id]
        );

        $booked = $this->db->fetchOne(
            'SELECT COUNT(*) as count FROM availability WHERE mentor_id = ? AND is_booked = 1',
            [$mentor_id]
        );

        $available = $this->db->fetchOne(
            'SELECT COUNT(*) as count FROM availability 
             WHERE mentor_id = ? AND is_booked = 0 AND available_date >= CURDATE()',
            [$mentor_id]
        );

        return [
            'total_slots' => $total['count'] ?? 0,
            'booked_slots' => $booked['count'] ?? 0,
            'available_slots' => $available['count'] ?? 0
        ];
    }

    /**
     * Check if mentor has available slots for a date
     */
    public function hasAvailableSlots($mentor_id, $date) {
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) as count FROM availability 
             WHERE mentor_id = ? AND available_date = ? AND is_booked = 0',
            [$mentor_id, $date]
        );

        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Get availability for a specific date
     */
    public function getDateAvailability($mentor_id, $date) {
        return $this->db->fetchAll(
            'SELECT availability_id, available_date, start_time, end_time, is_booked
             FROM availability
             WHERE mentor_id = ? AND available_date = ? AND is_booked = 0
             ORDER BY start_time ASC',
            [$mentor_id, $date]
        );
    }
}
?>
