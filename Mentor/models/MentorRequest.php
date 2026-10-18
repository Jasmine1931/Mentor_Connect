<?php
/**
 * MentorRequest Model
 * MODIFIED:
 *   - sendRequest()   — now blocks if mentee already has an accepted pairing.
 *   - acceptRequest() — full atomic transaction with SELECT FOR UPDATE to
 *                       prevent race conditions; marks other pending requests
 *                       for the same mentee as 'already paired'.
 *   - getPendingRequests()         — unchanged (pending only).
 *   - getAlreadyPairedRequests()   — NEW: returns 'already paired' rows for a mentor.
 *   - getAllRequestsForMentor()     — NEW: returns pending + already paired rows.
 *   - getMenteeRequests()          — unchanged.
 *   - rejectRequest()              — unchanged.
 *   - cancelRequest()              — unchanged.
 *   - getRequestStats()            — unchanged.
 *   - isMenteePaired()             — NEW: checks if a mentee has an accepted pairing.
 */
 
require_once __DIR__ . '/../config/database.php';
 
class MentorRequest {
    private $db;
 
    public function __construct() {
        $this->db = Database::getInstance();
    }
 
    // ──────────────────────────────────────────────────────────────
    // SEND REQUEST
    // ──────────────────────────────────────────────────────────────
 
    /**
     * Send a mentor request.
     * Blocked if:
     *   (a) mentee is already officially paired (has any 'accepted' request), OR
     *   (b) mentee already sent 2 pending/accepted requests, OR
     *   (c) a request to this specific mentor already exists.
     */
    public function sendRequest($mentee_id, $mentor_id, $message = '') {
 
        // (a) Block if mentee is already paired
        if ($this->isMenteePaired($mentee_id)) {
            return [
                'success' => false,
                'message' => 'You have already been paired with a mentor. No further requests can be sent.'
            ];
        }
 
        // (b) Count active (pending/accepted) requests
        $count = $this->db->fetchOne(
            'SELECT COUNT(*) as cnt FROM mentor_requests
             WHERE mentee_id = ? AND status IN ("pending","accepted")',
            [$mentee_id]
        );
        if (($count['cnt'] ?? 0) >= 2) {
            return ['success' => false, 'message' => 'You can only send requests to up to 2 mentors.'];
        }
 
        // (c) Duplicate check
        $existing = $this->db->fetchOne(
            'SELECT request_id FROM mentor_requests
             WHERE mentee_id = ? AND mentor_id = ? AND status IN ("pending","accepted")',
            [$mentee_id, $mentor_id]
        );
        if ($existing) {
            return ['success' => false, 'message' => 'You have already sent a request to this mentor.'];
        }
 
        try {
            $request_id = $this->db->insert(
                'INSERT INTO mentor_requests (mentee_id, mentor_id, request_message, status)
                 VALUES (?, ?, ?, "pending")',
                [$mentee_id, $mentor_id, $message]
            );
            return ['success' => true, 'message' => 'Request sent successfully', 'request_id' => $request_id];
        } catch (Exception $e) {
            error_log('Send Request Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send request'];
        }
    }
 
    // ──────────────────────────────────────────────────────────────
    // ACCEPT REQUEST  (atomic, race-condition-safe)
    // ──────────────────────────────────────────────────────────────
 
    /**
     * Accept a mentorship request.
     * Uses a DB transaction with SELECT FOR UPDATE to ensure only ONE
     * mentor can pair with a mentee even under concurrent load.
     * On success, all other pending requests from the same mentee are
     * automatically marked 'already paired'.
     */
    public function acceptRequest($request_id, $mentor_id) {
        try {
            $this->db->beginTransaction();
 
            // Lock the row
            $request = $this->db->fetchOne(
                'SELECT request_id, mentee_id, mentor_id, status
                 FROM mentor_requests
                 WHERE request_id = ? AND mentor_id = ?
                 FOR UPDATE',
                [$request_id, $mentor_id]
            );
 
            if (!$request) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'Request not found or unauthorised.'];
            }
 
            if ($request['status'] !== 'pending') {
                $this->db->rollback();
                if ($request['status'] === 'already paired') {
                    return ['success' => false, 'message' => 'This mentee has already been paired with another mentor.'];
                }
                return ['success' => false, 'message' => 'Request is no longer pending.'];
            }
 
            $mentee_id = (int) $request['mentee_id'];
 
            // Check if mentee already accepted elsewhere (lock those rows too)
            $paired = $this->db->fetchOne(
                'SELECT request_id FROM mentor_requests
                 WHERE mentee_id = ? AND status = "accepted"
                 FOR UPDATE',
                [$mentee_id]
            );
 
            if ($paired) {
                // Mark this row as already paired and bail out
                $this->db->update(
                    'UPDATE mentor_requests SET status = "already paired" WHERE request_id = ?',
                    [$request_id]
                );
                $this->db->commit();
                return ['success' => false, 'message' => 'This mentee has already been paired with another mentor.'];
            }
 
            // Check mentor capacity
            $mentor_count = $this->db->fetchOne(
                'SELECT COUNT(*) as cnt FROM mentor_requests
                 WHERE mentor_id = ? AND status = "accepted"',
                [$mentor_id]
            );
            if (($mentor_count['cnt'] ?? 0) >= 2) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'You are fully booked (2 active mentees).'];
            }
 
            // Accept this request
            $affected = $this->db->update(
                'UPDATE mentor_requests SET status = "accepted", responded_at = NOW()
                 WHERE request_id = ? AND mentor_id = ? AND status = "pending"',
                [$request_id, $mentor_id]
            );
 
            if ($affected === 0) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'Could not accept — request may have just been processed.'];
            }
 
            // Mark all other pending requests for this mentee as 'already paired'
            $this->db->update(
                'UPDATE mentor_requests
                 SET status = "already paired"
                 WHERE mentee_id = ? AND request_id != ? AND status = "pending"',
                [$mentee_id, $request_id]
            );
 
            // Create the mentorship record
            $start_date = date('Y-m-d');
            $end_date   = date('Y-m-d', strtotime('+' . (defined('MENTORSHIP_DURATION_MONTHS') ? MENTORSHIP_DURATION_MONTHS : 6) . ' months'));
 
            $mentorship_id = $this->db->insert(
                'INSERT INTO mentorships (mentee_id, mentor_id, start_date, end_date, status)
                 VALUES (?, ?, ?, ?, "active")',
                [$mentee_id, $mentor_id, $start_date, $end_date]
            );
 
            // Schedule sessions
            for ($i = 1; $i <= (defined('SESSIONS_PER_MENTORSHIP') ? SESSIONS_PER_MENTORSHIP : 6); $i++) {
                $session_date = date('Y-m-d H:i:s', strtotime("+$i months", strtotime($start_date)));
                $this->db->insert(
                    'INSERT INTO sessions (mentorship_id, session_number, scheduled_date, status)
                     VALUES (?, ?, ?, "scheduled")',
                    [$mentorship_id, $i, $session_date]
                );
            }
 
            $this->db->commit();
            return ['success' => true, 'message' => 'Request accepted! Mentee officially paired.', 'mentorship_id' => $mentorship_id];
 
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Accept Request Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to accept request.'];
        }
    }
 
    // ──────────────────────────────────────────────────────────────
    // QUERIES
    // ──────────────────────────────────────────────────────────────
 
    /**
     * Get all PENDING requests for a mentor (unchanged behaviour).
     */
    public function getPendingRequests($mentor_id) {
        return $this->db->fetchAll(
            'SELECT mr.request_id, mr.mentee_id, mr.mentor_id, mr.request_message,
                    mr.requested_at, mr.status,
                    u.first_name, u.last_name, u.email, u.domain, u.bio
             FROM mentor_requests mr
             JOIN users u ON mr.mentee_id = u.user_id
             WHERE mr.mentor_id = ? AND mr.status = "pending"
             ORDER BY mr.requested_at DESC',
            [$mentor_id]
        );
    }
 
    /**
     * NEW: Get all 'already paired' requests for a mentor
     * so the mentor sees which mentees got paired elsewhere.
     */
    public function getAlreadyPairedRequests($mentor_id) {
        return $this->db->fetchAll(
            'SELECT mr.request_id, mr.mentee_id, mr.mentor_id, mr.request_message,
                    mr.requested_at, mr.status,
                    u.first_name, u.last_name, u.email, u.domain, u.bio
             FROM mentor_requests mr
             JOIN users u ON mr.mentee_id = u.user_id
             WHERE mr.mentor_id = ? AND mr.status = "already paired"
             ORDER BY mr.requested_at DESC',
            [$mentor_id]
        );
    }
 
    /**
     * NEW: Get pending + already-paired requests for a mentor
     * (used by the single-request review view).
     */
    public function getAllRequestsForMentor($mentor_id) {
        return $this->db->fetchAll(
            'SELECT mr.request_id, mr.mentee_id, mr.mentor_id, mr.request_message,
                    mr.requested_at, mr.status,
                    u.first_name, u.last_name, u.email, u.domain, u.bio
             FROM mentor_requests mr
             JOIN users u ON mr.mentee_id = u.user_id
             WHERE mr.mentor_id = ? AND mr.status IN ("pending","already paired")
             ORDER BY mr.requested_at DESC',
            [$mentor_id]
        );
    }
 
    /**
     * NEW: Check whether a mentee already has an accepted (official) pairing.
     */
    public function isMenteePaired($mentee_id) {
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) as cnt FROM mentor_requests
             WHERE mentee_id = ? AND status = "accepted"',
            [$mentee_id]
        );
        return ($result['cnt'] ?? 0) > 0;
    }
 
    /**
     * Get requests for a mentee (with optional status filter).
     */
    public function getMenteeRequests($mentee_id, $status = null) {
        $query  = 'SELECT mr.request_id, mr.mentor_id, mr.status, mr.request_message,
                          mr.requested_at,
                          u.first_name, u.last_name, u.email, u.domain,
                          u.expertise_area, u.average_rating
                   FROM mentor_requests mr
                   JOIN users u ON mr.mentor_id = u.user_id
                   WHERE mr.mentee_id = ?';
        $params = [$mentee_id];
 
        if ($status) {
            $query  .= ' AND mr.status = ?';
            $params[] = $status;
        }
 
        $query .= ' ORDER BY mr.requested_at DESC';
        return $this->db->fetchAll($query, $params);
    }
 
    // ──────────────────────────────────────────────────────────────
    // REJECT / CANCEL
    // ──────────────────────────────────────────────────────────────
 
    public function rejectRequest($request_id, $mentor_id, $reason = '') {
        try {
            $request = $this->db->fetchOne(
                'SELECT request_id, mentor_id FROM mentor_requests
                 WHERE request_id = ? AND status = "pending"',
                [$request_id]
            );
 
            if (!$request) {
                return ['success' => false, 'message' => 'Request not found or no longer pending.'];
            }
            if ($request['mentor_id'] != $mentor_id) {
                return ['success' => false, 'message' => 'Unauthorised.'];
            }
 
            $this->db->update(
                'UPDATE mentor_requests
                 SET status = "rejected", rejection_reason = ?, responded_at = NOW()
                 WHERE request_id = ?',
                [$reason, $request_id]
            );
            return ['success' => true, 'message' => 'Request rejected.'];
        } catch (Exception $e) {
            error_log('Reject Request Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to reject request.'];
        }
    }
 
    public function cancelRequest($request_id, $mentee_id) {
        try {
            $request = $this->db->fetchOne(
                'SELECT request_id, mentee_id FROM mentor_requests
                 WHERE request_id = ? AND status = "pending"',
                [$request_id]
            );
 
            if (!$request || $request['mentee_id'] != $mentee_id) {
                return ['success' => false, 'message' => 'Cannot cancel this request.'];
            }
 
            $this->db->update(
                'UPDATE mentor_requests SET status = "cancelled" WHERE request_id = ?',
                [$request_id]
            );
            return ['success' => true, 'message' => 'Request cancelled.'];
        } catch (Exception $e) {
            error_log('Cancel Request Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to cancel request.'];
        }
    }
 
    // ──────────────────────────────────────────────────────────────
    // STATS
    // ──────────────────────────────────────────────────────────────
 
    public function getRequestStats($mentor_id) {
        $pending = $this->db->fetchOne(
            'SELECT COUNT(*) as count FROM mentor_requests
             WHERE mentor_id = ? AND status = "pending"',
            [$mentor_id]
        );
        $accepted = $this->db->fetchOne(
            'SELECT COUNT(*) as count FROM mentor_requests
             WHERE mentor_id = ? AND status = "accepted"',
            [$mentor_id]
        );
        return [
            'pending'  => $pending['count']  ?? 0,
            'accepted' => $accepted['count'] ?? 0,
        ];
    }
}
?>
 