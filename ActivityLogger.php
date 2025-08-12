<?php
/**
 * ActivityLogger Class
 * Comprehensive activity logging system for all user actions
 */
class ActivityLogger {
    private $conn;

    public function __construct($pdo) {
        $this->conn = $pdo;
    }

    /**
     * Log a general user activity
     */
    public function logActivity($userId, $actionType, $actionCategory, $description, $userRole = null, $relatedEntityType = null, $relatedEntityId = null, $metadata = null, $success = true, $failureReason = null) {
        try {
            // Get IP address and user agent
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $sessionId = session_id() ?: null;

            // If user role not provided, fetch it
            if (!$userRole && $userId) {
                $stmt = $this->conn->prepare("SELECT user_role FROM tbl_users WHERE user_id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $userRole = $user['user_role'] ?? 'unknown';
            }

            $sql = "INSERT INTO tbl_user_activity_logs
                    (user_id, session_id, action_type, action_category, description, user_role,
                     ip_address, user_agent, related_entity_type, related_entity_id, metadata,
                     success, failure_reason)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $userId,
                $sessionId,
                $actionType,
                $actionCategory,
                $description,
                $userRole,
                $ipAddress,
                $userAgent,
                $relatedEntityType,
                $relatedEntityId,
                $metadata ? json_encode($metadata) : null,
                $success,
                $failureReason
            ]);

            return true;
        } catch (Exception $e) {
            error_log("Activity logging failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log authentication activities (login, logout, signup)
     */
    public function logAuth($userId, $action, $description, $userRole, $success = true, $failureReason = null) {
        return $this->logActivity($userId, $action, 'authentication', $description, $userRole, null, null, null, $success, $failureReason);
    }

    /**
     * Log event-related activities
     */
    public function logEvent($userId, $eventId, $action, $description, $metadata = null) {
        $userRole = $this->getUserRole($userId);
        return $this->logActivity($userId, $action, 'event', $description, $userRole, 'event', $eventId, $metadata);

        // Also log to detailed event activity table
        try {
            $sql = "INSERT INTO tbl_event_activity_logs (event_id, user_id, action, description, metadata)
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$eventId, $userId, $action, $description, $metadata ? json_encode($metadata) : null]);
        } catch (Exception $e) {
            error_log("Event activity logging failed: " . $e->getMessage());
        }
    }

    /**
     * Log booking-related activities
     */
    public function logBooking($userId, $bookingId, $action, $description, $oldStatus = null, $newStatus = null, $metadata = null) {
        $userRole = $this->getUserRole($userId);
        $this->logActivity($userId, $action, 'booking', $description, $userRole, 'booking', $bookingId, $metadata);

        // Also log to detailed booking activity table
        try {
            $sql = "INSERT INTO tbl_booking_activity_logs
                    (booking_id, user_id, action, old_status, new_status, description, metadata)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$bookingId, $userId, $action, $oldStatus, $newStatus, $description, $metadata ? json_encode($metadata) : null]);
        } catch (Exception $e) {
            error_log("Booking activity logging failed: " . $e->getMessage());
        }
    }

    /**
     * Log payment-related activities
     */
    public function logPayment($userId, $paymentId, $action, $description, $amount = null, $oldStatus = null, $newStatus = null, $metadata = null) {
        $userRole = $this->getUserRole($userId);
        $this->logActivity($userId, $action, 'payment', $description, $userRole, 'payment', $paymentId, $metadata);

        // Also log to detailed payment activity table
        try {
            $sql = "INSERT INTO tbl_payment_activity_logs
                    (payment_id, user_id, action, old_status, new_status, amount, description, metadata)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$paymentId, $userId, $action, $oldStatus, $newStatus, $amount, $description, $metadata ? json_encode($metadata) : null]);
        } catch (Exception $e) {
            error_log("Payment activity logging failed: " . $e->getMessage());
        }
    }

    /**
     * Log venue-related activities
     */
    public function logVenue($userId, $venueId, $action, $description, $metadata = null) {
        $userRole = $this->getUserRole($userId);
        return $this->logActivity($userId, $action, 'venue', $description, $userRole, 'venue', $venueId, $metadata);
    }

    /**
     * Log package-related activities
     */
    public function logPackage($userId, $packageId, $action, $description, $metadata = null) {
        $userRole = $this->getUserRole($userId);
        return $this->logActivity($userId, $action, 'package', $description, $userRole, 'package', $packageId, $metadata);
    }

    /**
     * Log supplier-related activities
     */
    public function logSupplier($userId, $supplierId, $action, $description, $metadata = null) {
        $userRole = $this->getUserRole($userId);
        return $this->logActivity($userId, $action, 'supplier', $description, $userRole, 'supplier', $supplierId, $metadata);
    }

    /**
     * Log organizer-related activities
     */
    public function logOrganizer($userId, $organizerId, $action, $description, $metadata = null) {
        $userRole = $this->getUserRole($userId);
        return $this->logActivity($userId, $action, 'organizer', $description, $userRole, 'organizer', $organizerId, $metadata);
    }

    /**
     * Log client-related activities
     */
    public function logClient($userId, $clientId, $action, $description, $metadata = null) {
        $userRole = $this->getUserRole($userId);
        return $this->logActivity($userId, $action, 'client', $description, $userRole, 'client', $clientId, $metadata);
    }

    /**
     * Log system/admin activities
     */
    public function logSystem($userId, $actionType, $description, $targetUserId = null, $metadata = null) {
        $userRole = $this->getUserRole($userId);
        $this->logActivity($userId, $actionType, 'system', $description, $userRole, null, null, $metadata);

        // Also log to system activity table
        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $sql = "INSERT INTO tbl_system_activity_logs
                    (user_id, action_type, target_user_id, description, metadata, ip_address)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId, $actionType, $targetUserId, $description, $metadata ? json_encode($metadata) : null, $ipAddress]);
        } catch (Exception $e) {
            error_log("System activity logging failed: " . $e->getMessage());
        }
    }

    /**
     * Get user role from database
     */
    private function getUserRole($userId) {
        try {
            $stmt = $this->conn->prepare("SELECT user_role FROM tbl_users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user['user_role'] ?? 'unknown';
        } catch (Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Get comprehensive activity timeline
     */
    public function getActivityTimeline($filters = []) {
        $sql = "SELECT * FROM vw_activity_timeline WHERE 1=1";
        $params = [];

        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['user_role'])) {
            $sql .= " AND user_role = ?";
            $params[] = $filters['user_role'];
        }

        if (!empty($filters['action_category'])) {
            $sql .= " AND action_category = ?";
            $params[] = $filters['action_category'];
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND timestamp >= ?";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND timestamp <= ?";
            $params[] = $filters['end_date'];
        }

        if (!empty($filters['related_entity_type'])) {
            $sql .= " AND related_entity_type = ?";
            $params[] = $filters['related_entity_type'];
        }

        if (!empty($filters['related_entity_id'])) {
            $sql .= " AND related_entity_id = ?";
            $params[] = $filters['related_entity_id'];
        }

        // Add pagination
        $page = $filters['page'] ?? 1;
        $limit = $filters['limit'] ?? 50;
        $offset = ($page - 1) * $limit;

        $sql .= " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get activity timeline: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get activity statistics
     */
    public function getActivityStats($startDate = null, $endDate = null) {
        try {
            $params = [];
            $dateFilter = "";

            if ($startDate && $endDate) {
                $dateFilter = " WHERE created_at BETWEEN ? AND ?";
                $params = [$startDate, $endDate];
            }

            // Get activity counts by category
            $sql = "SELECT
                        action_category,
                        COUNT(*) as total,
                        COUNT(DISTINCT user_id) as unique_users,
                        COUNT(CASE WHEN success = 0 THEN 1 END) as failures
                    FROM tbl_user_activity_logs" . $dateFilter . "
                    GROUP BY action_category";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $categoryStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get activity counts by user role
            $sql = "SELECT
                        user_role,
                        COUNT(*) as total,
                        COUNT(DISTINCT user_id) as unique_users
                    FROM tbl_user_activity_logs" . $dateFilter . "
                    GROUP BY user_role";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $roleStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get top active users
            $sql = "SELECT
                        u.user_id,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as user_name,
                        u.user_role,
                        COUNT(ual.id) as activity_count
                    FROM tbl_user_activity_logs ual
                    JOIN tbl_users u ON ual.user_id = u.user_id" .
                    ($dateFilter ? str_replace("WHERE", "WHERE ual.", $dateFilter) : "") . "
                    GROUP BY u.user_id
                    ORDER BY activity_count DESC
                    LIMIT 10";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'categoryStats' => $categoryStats,
                'roleStats' => $roleStats,
                'topUsers' => $topUsers
            ];
        } catch (Exception $e) {
            error_log("Failed to get activity stats: " . $e->getMessage());
            return null;
        }
    }
}
