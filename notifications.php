<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Robust error handling: never output HTML errors in JSON endpoints
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error occurred',
        'debug' => [
            'error' => $errstr,
            'file' => basename($errfile),
            'line' => $errline,
        ],
    ]);
    exit;
});

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Load Composer autoload if present in the current directory's vendor folder
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// Use shared DB connection (es_v3) and error handling
require_once __DIR__ . '/db_connect.php';

class NotificationManager
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get notifications for a user with pagination and filtering
     */
    public function getUserNotifications($userId, $limit = 20, $offset = 0, $status = null, $type = null)
    {
        try {
            $conditions = ['user_id = :user_id'];
            $params = [':user_id' => $userId];

            // Add status filter
            if ($status !== null) {
                $conditions[] = 'notification_status = :status';
                $params[':status'] = $status;
            }

            // Add type filter
            if ($type !== null) {
                $conditions[] = 'notification_type = :type';
                $params[':type'] = $type;
            }

            // Add expiration filter
            $conditions[] = '(expires_at IS NULL OR expires_at > NOW())';

            $whereClause = 'WHERE ' . implode(' AND ', $conditions);

            $sql = "
                SELECT
                    notification_id,
                    notification_type,
                    notification_title,
                    notification_message,
                    notification_priority,
                    notification_icon,
                    notification_url,
                    notification_status,
                    event_id,
                    booking_id,
                    venue_id,
                    store_id,
                    budget_id,
                    feedback_id,
                    created_at,
                    read_at,
                    expires_at
                FROM tbl_notifications
                {$whereClause}
                ORDER BY
                    CASE notification_priority
                        WHEN 'urgent' THEN 4
                        WHEN 'high' THEN 3
                        WHEN 'medium' THEN 2
                        ELSE 1
                    END DESC,
                    created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $notifications = $stmt->fetchAll();

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM tbl_notifications {$whereClause}";
            $countStmt = $this->pdo->prepare($countSql);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $total = $countStmt->fetch()['total'];

            return [
                'status' => 'success',
                'notifications' => $notifications,
                'pagination' => [
                    'total' => (int) $total,
                    'limit' => (int) $limit,
                    'offset' => (int) $offset,
                    'has_more' => ($offset + $limit) < $total
                ]
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to fetch notifications: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get notification counts for a user
     */
    public function getNotificationCounts($userId)
    {
        try {
            $sql = "
                SELECT
                    COUNT(*) as total,
                    COUNT(CASE WHEN notification_status = 'unread' THEN 1 END) as unread,
                    COUNT(CASE WHEN notification_priority = 'urgent' AND notification_status = 'unread' THEN 1 END) as urgent_unread,
                    COUNT(CASE WHEN notification_priority = 'high' AND notification_status = 'unread' THEN 1 END) as high_unread,
                    COUNT(CASE WHEN notification_type LIKE 'booking_%' AND notification_status = 'unread' THEN 1 END) as booking_unread,
                    COUNT(CASE WHEN notification_type LIKE 'event_%' AND notification_status = 'unread' THEN 1 END) as event_unread,
                    COUNT(CASE WHEN notification_type LIKE 'payment_%' AND notification_status = 'unread' THEN 1 END) as payment_unread
                FROM tbl_notifications
                WHERE user_id = :user_id
                AND (expires_at IS NULL OR expires_at > NOW())
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $counts = $stmt->fetch();

            return [
                'status' => 'success',
                'counts' => [
                    'total' => (int) $counts['total'],
                    'unread' => (int) $counts['unread'],
                    'urgent_unread' => (int) $counts['urgent_unread'],
                    'high_unread' => (int) $counts['high_unread'],
                    'by_type' => [
                        'booking' => (int) $counts['booking_unread'],
                        'event' => (int) $counts['event_unread'],
                        'payment' => (int) $counts['payment_unread']
                    ]
                ]
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to fetch notification counts: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Mark notification(s) as read
     */
    public function markAsRead($userId, $notificationId = null)
    {
        try {
            if ($notificationId !== null) {
                // Mark specific notification as read
                $sql = "
                    UPDATE tbl_notifications
                    SET notification_status = 'read', read_at = NOW()
                    WHERE notification_id = :notification_id AND user_id = :user_id
                ";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':notification_id', $notificationId, PDO::PARAM_INT);
                $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            } else {
                // Mark all notifications as read for user
                $sql = "
                    UPDATE tbl_notifications
                    SET notification_status = 'read', read_at = NOW()
                    WHERE user_id = :user_id AND notification_status = 'unread'
                ";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            }

            $stmt->execute();
            $affected = $stmt->rowCount();

            return [
                'status' => 'success',
                'message' => $notificationId ? 'Notification marked as read' : 'All notifications marked as read',
                'affected_rows' => $affected
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to mark notification(s) as read: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Delete notification(s)
     */
    public function deleteNotification($userId, $notificationId = null)
    {
        try {
            if ($notificationId !== null) {
                // Delete specific notification
                $sql = "DELETE FROM tbl_notifications WHERE notification_id = :notification_id AND user_id = :user_id";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':notification_id', $notificationId, PDO::PARAM_INT);
                $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            } else {
                // Delete all read notifications for user
                $sql = "DELETE FROM tbl_notifications WHERE user_id = :user_id AND notification_status = 'read'";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            }

            $stmt->execute();
            $affected = $stmt->rowCount();

            return [
                'status' => 'success',
                'message' => $notificationId ? 'Notification deleted' : 'Read notifications cleared',
                'affected_rows' => $affected
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to delete notification(s): ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create a new notification
     */
    public function createNotification($data)
    {
        try {
            $sql = "
                INSERT INTO tbl_notifications (
                    user_id, notification_type, notification_title, notification_message,
                    notification_priority, notification_icon, notification_url,
                    event_id, booking_id, venue_id, store_id, budget_id, feedback_id,
                    expires_at
                ) VALUES (
                    :user_id, :type, :title, :message, :priority, :icon, :url,
                    :event_id, :booking_id, :venue_id, :store_id, :budget_id, :feedback_id,
                    :expires_at
                )
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $data['user_id'],
                ':type' => $data['type'] ?? 'general',
                ':title' => $data['title'],
                ':message' => $data['message'],
                ':priority' => $data['priority'] ?? 'medium',
                ':icon' => $data['icon'] ?? null,
                ':url' => $data['url'] ?? null,
                ':event_id' => $data['event_id'] ?? null,
                ':booking_id' => $data['booking_id'] ?? null,
                ':venue_id' => $data['venue_id'] ?? null,
                ':store_id' => $data['store_id'] ?? null,
                ':budget_id' => $data['budget_id'] ?? null,
                ':feedback_id' => $data['feedback_id'] ?? null,
                ':expires_at' => isset($data['expires_hours']) && $data['expires_hours'] > 0
                    ? date('Y-m-d H:i:s', strtotime("+{$data['expires_hours']} hours"))
                    : null
            ]);

            $notificationId = $this->pdo->lastInsertId();

            return [
                'status' => 'success',
                'message' => 'Notification created successfully',
                'notification_id' => $notificationId
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to create notification: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get recent activity notifications (for real-time updates)
     */
    public function getRecentActivity($userId, $since = null)
    {
        try {
            $conditions = ['user_id = :user_id'];
            $params = [':user_id' => $userId];

            if ($since !== null) {
                $conditions[] = 'created_at > :since';
                $params[':since'] = $since;
            }

            $conditions[] = '(expires_at IS NULL OR expires_at > NOW())';

            $whereClause = 'WHERE ' . implode(' AND ', $conditions);

            $sql = "
                SELECT
                    notification_id,
                    notification_type,
                    notification_title,
                    notification_message,
                    notification_priority,
                    notification_icon,
                    notification_url,
                    created_at
                FROM tbl_notifications
                {$whereClause}
                ORDER BY created_at DESC
                LIMIT 50
            ";

            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();

            $notifications = $stmt->fetchAll();

            return [
                'status' => 'success',
                'notifications' => $notifications,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to fetch recent activity: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Run payment due notifications check
     */
    public function checkPaymentDueNotifications()
    {
        try {
            $stmt = $this->pdo->prepare("CALL CreatePaymentDueNotifications()");
            $stmt->execute();

            return [
                'status' => 'success',
                'message' => 'Payment due notifications checked and created'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to check payment due notifications: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up expired notifications
     */
    public function cleanupExpiredNotifications()
    {
        try {
            $stmt = $this->pdo->prepare("CALL CleanupExpiredNotifications()");
            $stmt->execute();

            return [
                'status' => 'success',
                'message' => 'Expired notifications cleaned up'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to cleanup notifications: ' . $e->getMessage()
            ];
        }
    }
}

// Initialize notification manager
$notificationManager = new NotificationManager($pdo);

// Get request data
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$userId = $_GET['user_id'] ?? $input['user_id'] ?? null;

// Validate user ID
if (!$userId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
    exit;
}

// Route requests based on method and operation
$operation = $_GET['operation'] ?? $input['operation'] ?? '';

switch ($method) {
    case 'GET':
        switch ($operation) {
            case 'get_notifications':
                $limit = (int) ($_GET['limit'] ?? 20);
                $offset = (int) ($_GET['offset'] ?? 0);
                $status = $_GET['status'] ?? null;
                $type = $_GET['type'] ?? null;

                $result = $notificationManager->getUserNotifications($userId, $limit, $offset, $status, $type);
                break;

            case 'get_counts':
                $result = $notificationManager->getNotificationCounts($userId);
                break;

            case 'get_recent':
                $since = $_GET['since'] ?? null;
                $result = $notificationManager->getRecentActivity($userId, $since);
                break;

            case 'check_payment_due':
                $result = $notificationManager->checkPaymentDueNotifications();
                break;

            case 'cleanup_expired':
                $result = $notificationManager->cleanupExpiredNotifications();
                break;

            default:
                $result = $notificationManager->getUserNotifications($userId);
                break;
        }
        break;

    case 'POST':
        switch ($operation) {
            case 'create_notification':
                $result = $notificationManager->createNotification($input);
                break;

            default:
                http_response_code(400);
                $result = ['status' => 'error', 'message' => 'Invalid operation for POST request'];
                break;
        }
        break;

    case 'PUT':
        switch ($operation) {
            case 'mark_read':
                $notificationId = $input['notification_id'] ?? null;
                $result = $notificationManager->markAsRead($userId, $notificationId);
                break;

            default:
                http_response_code(400);
                $result = ['status' => 'error', 'message' => 'Invalid operation for PUT request'];
                break;
        }
        break;

    case 'DELETE':
        switch ($operation) {
            case 'delete_notification':
                $notificationId = $_GET['notification_id'] ?? $input['notification_id'] ?? null;
                $result = $notificationManager->deleteNotification($userId, $notificationId);
                break;

            default:
                http_response_code(400);
                $result = ['status' => 'error', 'message' => 'Invalid operation for DELETE request'];
                break;
        }
        break;

    default:
        http_response_code(405);
        $result = ['status' => 'error', 'message' => 'Method not allowed'];
        break;
}

// Return response
echo json_encode($result);
?>
