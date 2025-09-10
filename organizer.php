<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_connect.php';

class Organizer {
    private $conn;

    public function __construct($pdo) {
        $this->conn = $pdo;
    }

    // Get organizer's assigned events
    public function getOrganizerEvents($organizerId) {
        try {
            $organizerId = (int)$organizerId;
            if ($organizerId <= 0) {
                return json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
            }

            // Try the stored procedure first (updated with conflict detection)
            try {
                $stmt = $this->conn->prepare("CALL GetOrganizerEvents(?)");
                $stmt->execute([$organizerId]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Stored procedure failed, using direct query: " . $e->getMessage());

                // Fallback to direct query with conflict detection
                $stmt = $this->conn->prepare("
                    SELECT
                        e.event_id,
                        e.event_title,
                        e.event_date,
                        e.start_time,
                        e.end_time,
                        e.event_status,
                        e.payment_status,
                        e.guest_count,
                        e.total_budget,
                        e.down_payment,
                        et.event_name as event_type_name,
                        v.venue_title,
                        v.venue_location,
                        CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                        c.user_email as client_email,
                        c.user_contact as client_contact,
                        c.user_pfp as client_pfp,
                        eoa.assigned_at,
                        eoa.status as assignment_status,
                        -- Add conflict detection
                        CASE
                            WHEN EXISTS (
                                SELECT 1 FROM tbl_event_organizer_assignments eoa2
                                JOIN tbl_events e2 ON eoa2.event_id = e2.event_id
                                WHERE eoa2.organizer_id = ?
                                AND e2.event_date = e.event_date
                                AND eoa2.event_id != e.event_id
                                AND eoa2.status IN ('assigned', 'accepted')
                                AND e2.event_status NOT IN ('cancelled', 'done')
                            ) THEN TRUE
                            ELSE FALSE
                        END as has_date_conflict
                    FROM tbl_event_organizer_assignments eoa
                    JOIN tbl_events e ON eoa.event_id = e.event_id
                    LEFT JOIN tbl_users c ON e.user_id = c.user_id
                    LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                    WHERE eoa.organizer_id = ?
                    AND eoa.status IN ('assigned', 'accepted')
                    ORDER BY e.event_date ASC, e.start_time ASC
                ");
                $stmt->execute([$organizerId, $organizerId]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            return json_encode([
                "status" => "success",
                "data" => $results
            ]);

        } catch (Exception $e) {
            error_log("getOrganizerEvents error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Failed to get organizer events: " . $e->getMessage()]);
        }
    }

    // Get specific event details for organizer
    public function getOrganizerEventDetails($eventId) {
        try {
            $eventId = (int)$eventId;
            if ($eventId <= 0) {
                return json_encode(["status" => "error", "message" => "Valid event ID required"]);
            }

            $stmt = $this->conn->prepare("
                SELECT
                    e.*,
                    CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                    c.user_firstName as client_first_name,
                    c.user_lastName as client_last_name,
                    c.user_email as client_email,
                    c.user_contact as client_contact,
                    c.user_pfp as client_pfp,
                    et.event_name as event_type_name,
                    v.venue_title,
                    v.venue_location,
                    v.venue_contact,
                    v.venue_capacity,
                    eoa.status as assignment_status,
                    eoa.assigned_at
                FROM tbl_events e
                LEFT JOIN tbl_users c ON e.user_id = c.user_id
                LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                LEFT JOIN tbl_event_organizer_assignments eoa ON e.event_id = eoa.event_id
                WHERE e.event_id = ?
            ");
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                return json_encode([
                    "status" => "error",
                    "message" => "Event not found"
                ]);
            }

            // Parse event_attachments JSON field
            if (!empty($event['event_attachments'])) {
                $event['attachments'] = json_decode($event['event_attachments'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $event['attachments'] = [];
                }
            } else {
                $event['attachments'] = [];
            }

            // Get event components
            $stmt = $this->conn->prepare("
                SELECT * FROM tbl_event_components
                WHERE event_id = ?
                ORDER BY display_order
            ");
            $stmt->execute([$eventId]);
            $event['components'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get event timeline
            $stmt = $this->conn->prepare("
                SELECT * FROM tbl_event_timeline
                WHERE event_id = ?
                ORDER BY display_order
            ");
            $stmt->execute([$eventId]);
            $event['timeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get payment history
            $stmt = $this->conn->prepare("
                SELECT * FROM tbl_payments
                WHERE event_id = ? AND payment_status != 'cancelled'
                ORDER BY payment_date DESC, created_at DESC
            ");
            $stmt->execute([$eventId]);
            $event['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "event" => $event
            ]);

        } catch (Exception $e) {
            error_log("getOrganizerEventDetails error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Failed to get event details: " . $e->getMessage()]);
        }
    }

    // Check for organizer date conflicts
    public function checkOrganizerDateConflict($organizerId, $eventDate, $eventId = null) {
        try {
            $organizerId = (int)$organizerId;
            $eventId = $eventId ? (int)$eventId : null;

            if ($organizerId <= 0) {
                return json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
            }

            // Try the stored procedure first
            try {
                $stmt = $this->conn->prepare("CALL CheckOrganizerDateConflict(?, ?, ?, @has_conflict, @conflict_message)");
                $stmt->execute([$organizerId, $eventDate, $eventId]);

                // Get the output parameters
                $stmt = $this->conn->prepare("SELECT @has_conflict as has_conflict, @conflict_message as conflict_message");
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                return json_encode([
                    "status" => "success",
                    "has_conflict" => (bool)$result['has_conflict'],
                    "conflict_message" => $result['conflict_message']
                ]);

            } catch (Exception $e) {
                error_log("Stored procedure failed, using direct query: " . $e->getMessage());

                // Fallback to direct query
                $sql = "
                    SELECT COUNT(*) as conflict_count, e.event_id, e.event_title
                    FROM tbl_event_organizer_assignments eoa
                    JOIN tbl_events e ON eoa.event_id = e.event_id
                    WHERE eoa.organizer_id = ?
                    AND e.event_date = ?
                    AND eoa.status IN ('assigned', 'accepted')
                    AND e.event_status NOT IN ('cancelled', 'done')
                ";

                $params = [$organizerId, $eventDate];

                if ($eventId) {
                    $sql .= " AND eoa.event_id != ?";
                    $params[] = $eventId;
                }

                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                $hasConflict = $result['conflict_count'] > 0;
                $conflictMessage = $hasConflict
                    ? "Organizer is already assigned to event \"" . $result['event_title'] . "\" on " . $eventDate
                    : "No conflicts found";

                return json_encode([
                    "status" => "success",
                    "has_conflict" => $hasConflict,
                    "conflict_message" => $conflictMessage
                ]);
            }

        } catch (Exception $e) {
            error_log("checkOrganizerDateConflict error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Failed to check organizer date conflict: " . $e->getMessage()]);
        }
    }

    // Get organizer's profile information
    public function getOrganizerProfile($organizerId, $userId = null) {
        try {
            $organizerId = (int)$organizerId;
            $userId = (int)$userId;

            if ($organizerId <= 0 && $userId <= 0) {
                return json_encode(["status" => "error", "message" => "Valid organizer ID or user ID required"]);
            }

            $whereClause = "";
            $params = [];

            if ($userId > 0) {
                // If user_id is provided, find organizer by user_id
                $whereClause = "WHERE o.user_id = ?";
                $params = [$userId];
            } else {
                // Otherwise use organizer_id
                $whereClause = "WHERE o.organizer_id = ?";
                $params = [$organizerId];
            }

            $stmt = $this->conn->prepare("
                SELECT
                    o.organizer_id,
                    o.user_id,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as organizer_name,
                    u.user_email as organizer_email,
                    u.user_contact as organizer_phone,
                    o.organizer_experience,
                    o.organizer_certifications,
                    o.organizer_availability,
                    o.organizer_portfolio_link,
                    o.remarks,
                    u.user_pfp as profile_picture,
                    o.created_at,
                    o.updated_at
                FROM tbl_organizer o
                JOIN tbl_users u ON o.user_id = u.user_id
                $whereClause
            ");
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return json_encode([
                    "status" => "error",
                    "message" => "Organizer not found"
                ]);
            }

            return json_encode([
                "status" => "success",
                "data" => $result
            ]);

        } catch (Exception $e) {
            error_log("getOrganizerProfile error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Failed to get organizer profile: " . $e->getMessage()]);
        }
    }

    // Update organizer assignment status (accept/reject)
    public function updateAssignmentStatus($data) {
        try {
            $assignmentId = (int)($data['assignment_id'] ?? 0);
            $status = $data['status'] ?? '';
            $organizerId = (int)($data['organizer_id'] ?? 0);
            $eventId = (int)($data['event_id'] ?? 0);

            // If we don't have an assignment id, try to find or create one using event_id + organizer_id
            if ($assignmentId <= 0) {
                if ($eventId <= 0) {
                    return json_encode(["status" => "error", "message" => "Valid assignment ID or event ID required"]);
                }

                // Try to find an existing assignment for this event and organizer
                $findStmt = $this->conn->prepare("SELECT assignment_id FROM tbl_event_organizer_assignments WHERE event_id = ? AND organizer_id = ? ORDER BY assignment_id DESC LIMIT 1");
                $findStmt->execute([$eventId, $organizerId]);
                $row = $findStmt->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['assignment_id'])) {
                    $assignmentId = (int)$row['assignment_id'];
                } else {
                    // Create a new assignment so the organizer can accept/reject
                    $createStmt = $this->conn->prepare("\n                        INSERT INTO tbl_event_organizer_assignments (event_id, organizer_id, assigned_by, status, notes, created_at, updated_at)\n                        VALUES (?, ?, ?, ?, NULL, NOW(), NOW())\n                    ");
                    $createStmt->execute([$eventId, $organizerId, $organizerId, $status]);
                    $assignmentId = (int)$this->conn->lastInsertId();

                    // Also set event.organizer_id if accepted
                    if ($status === 'accepted') {
                        $evUpd = $this->conn->prepare("UPDATE tbl_events SET organizer_id = ?, updated_at = NOW() WHERE event_id = ?");
                        $evUpd->execute([$organizerId, $eventId]);
                    }
                }
            }

            if (!in_array($status, ['accepted', 'rejected'])) {
                return json_encode(["status" => "error", "message" => "Invalid status. Must be 'accepted' or 'rejected'"]);
            }

            if ($organizerId <= 0) {
                return json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
            }

            // Try stored procedure first
            try {
                $stmt = $this->conn->prepare("CALL UpdateOrganizerAssignmentStatus(?, ?, ?)");
                $stmt->execute([$assignmentId, $status, $organizerId]);
            } catch (Exception $e) {
                // Fallback to direct update if procedure isn't available
                error_log("Procedure UpdateOrganizerAssignmentStatus failed, using direct update: " . $e->getMessage());
                $stmt = $this->conn->prepare("UPDATE tbl_event_organizer_assignments SET status = ?, updated_at = NOW() WHERE assignment_id = ? AND organizer_id = ?");
                $stmt->execute([$status, $assignmentId, $organizerId]);

                // If rejected or removed, clear organizer_id on the event to free it up
                if (in_array($status, ['rejected', 'removed'])) {
                    // Get the event_id for this assignment
                    $evStmt = $this->conn->prepare("SELECT event_id FROM tbl_event_organizer_assignments WHERE assignment_id = ?");
                    $evStmt->execute([$assignmentId]);
                    $evRow = $evStmt->fetch(PDO::FETCH_ASSOC);
                    if ($evRow && isset($evRow['event_id'])) {
                        $clearStmt = $this->conn->prepare("UPDATE tbl_events SET organizer_id = NULL, updated_at = NOW() WHERE event_id = ?");
                        $clearStmt->execute([$evRow['event_id']]);
                    }
                }
            }

            // Log the activity
            $this->logOrganizerActivity($organizerId, 'assignment_status_updated', "Updated assignment status to $status", $assignmentId);

            // Notify the admin responsible for the event
            try {
                // Fetch admin and event details for notification context
                $infoStmt = $this->conn->prepare("\n                    SELECT\n                        e.event_id, e.event_title, e.admin_id,\n                        CONCAT(u.user_firstName, ' ', u.user_lastName) AS organizer_name\n                    FROM tbl_event_organizer_assignments eoa\n                    JOIN tbl_events e ON e.event_id = eoa.event_id\n                    LEFT JOIN tbl_organizer o ON o.organizer_id = eoa.organizer_id\n                    LEFT JOIN tbl_users u ON u.user_id = o.user_id\n                    WHERE eoa.assignment_id = ?\n                ");
                $infoStmt->execute([$assignmentId]);
                $info = $infoStmt->fetch(PDO::FETCH_ASSOC);

                if ($info && !empty($info['admin_id'])) {
                    $adminId = (int)$info['admin_id'];
                    $eventId = (int)$info['event_id'];
                    $eventTitle = $info['event_title'] ?? 'the event';
                    $organizerName = $info['organizer_name'] ?? 'An organizer';
                    $notifType = 'organizer_assignment_' . $status; // organizer_assignment_accepted / _rejected
                    $notifTitle = 'Organizer responded to assignment';
                    $notifMessage = $organizerName . ' has ' . $status . ' the assignment for event "' . $eventTitle . '".';
                    $notifPriority = 'high';
                    $notifIcon = $status === 'accepted' ? 'user-check' : 'user-x';
                    $notifUrl = '/admin/events/' . $eventId;

                    // Try stored procedure first
                    try {
                        $proc = $this->conn->prepare("CALL CreateNotification(\n                            :p_user_id,\n                            :p_notification_type,\n                            :p_notification_title,\n                            :p_notification_message,\n                            :p_notification_priority,\n                            :p_notification_icon,\n                            :p_notification_url,\n                            :p_event_id,\n                            :p_booking_id,\n                            :p_venue_id,\n                            :p_store_id,\n                            :p_budget_id,\n                            :p_feedback_id,\n                            :p_expires_at\n                        )");
                        $proc->execute([
                            ':p_user_id' => $adminId,
                            ':p_notification_type' => $notifType,
                            ':p_notification_title' => $notifTitle,
                            ':p_notification_message' => $notifMessage,
                            ':p_notification_priority' => $notifPriority,
                            ':p_notification_icon' => $notifIcon,
                            ':p_notification_url' => $notifUrl,
                            ':p_event_id' => $eventId,
                            ':p_booking_id' => null,
                            ':p_venue_id' => null,
                            ':p_store_id' => null,
                            ':p_budget_id' => null,
                            ':p_feedback_id' => null,
                            ':p_expires_at' => null,
                        ]);
                    } catch (Exception $eNotif) {
                        // Fallback direct insert
                        try {
                            $fallback = $this->conn->prepare("INSERT INTO tbl_notifications (\n                                user_id, notification_type, notification_title, notification_message,\n                                notification_priority, notification_icon, notification_url, event_id,\n                                notification_status, created_at\n                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unread', NOW())");
                            $fallback->execute([
                                $adminId,
                                $notifType,
                                $notifTitle,
                                $notifMessage,
                                $notifPriority,
                                $notifIcon,
                                $notifUrl,
                                $eventId,
                            ]);
                        } catch (Exception $eNotifFallback) {
                            error_log('updateAssignmentStatus: admin notification failed: ' . $eNotifFallback->getMessage());
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('updateAssignmentStatus: notification block failed: ' . $e->getMessage());
            }

            return json_encode([
                "status" => "success",
                "message" => "Assignment status updated successfully",
                "data" => [
                    "assignment_id" => $assignmentId,
                    "status" => $status,
                    "organizer_id" => $organizerId
                ]
            ]);

        } catch (Exception $e) {
            error_log("updateAssignmentStatus error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Failed to update assignment status: " . $e->getMessage()]);
        }
    }

    // Get organizer's activity timeline
    public function getOrganizerActivityTimeline($organizerId, $startDate = null, $endDate = null, $page = 1, $limit = 10) {
        try {
            $organizerId = (int)$organizerId;
            if ($organizerId <= 0) {
                return json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
            }

            $offset = ($page - 1) * $limit;

            $whereConditions = ["oal.organizer_id = ?"];
            $params = [$organizerId];

            if ($startDate) {
                $whereConditions[] = "oal.created_at >= ?";
                $params[] = $startDate;
            }

            if ($endDate) {
                $whereConditions[] = "oal.created_at <= ?";
                $params[] = $endDate . ' 23:59:59';
            }

            $whereClause = implode(" AND ", $whereConditions);

            $stmt = $this->conn->prepare("
                SELECT
                    oal.log_id,
                    oal.activity_type,
                    oal.description,
                    oal.related_id,
                    oal.metadata,
                    oal.created_at,
                    e.event_title,
                    e.event_date
                FROM tbl_organizer_activity_logs oal
                LEFT JOIN tbl_events e ON oal.related_id = e.event_id
                WHERE $whereClause
                ORDER BY oal.created_at DESC
                LIMIT ? OFFSET ?
            ");

            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get total count
            $countStmt = $this->conn->prepare("
                SELECT COUNT(*) as total
                FROM tbl_organizer_activity_logs oal
                WHERE $whereClause
            ");
            $countParams = array_slice($params, 0, -2); // Remove limit and offset
            $countStmt->execute($countParams);
            $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            return json_encode([
                "status" => "success",
                "data" => [
                    "activities" => $results,
                    "pagination" => [
                        "page" => $page,
                        "limit" => $limit,
                        "total" => $totalCount,
                        "pages" => ceil($totalCount / $limit)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            error_log("getOrganizerActivityTimeline error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Failed to get activity timeline: " . $e->getMessage()]);
        }
    }

    // Log organizer activity
    private function logOrganizerActivity($organizerId, $activityType, $description, $relatedId = null, $metadata = null) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO tbl_organizer_activity_logs
                (organizer_id, activity_type, description, related_id, metadata, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");

            $metadataJson = $metadata ? json_encode($metadata) : null;
            $stmt->execute([$organizerId, $activityType, $description, $relatedId, $metadataJson]);

            return true;
        } catch (Exception $e) {
            error_log("logOrganizerActivity error: " . $e->getMessage());
            return false;
        }
    }

    // Get organizer dashboard statistics
    public function getOrganizerDashboardStats($organizerId) {
        try {
            $organizerId = (int)$organizerId;
            if ($organizerId <= 0) {
                return json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
            }

            // Get assigned events count
            $assignedStmt = $this->conn->prepare("
                SELECT COUNT(*) as assigned_count
                FROM tbl_event_organizer_assignments eoa
                WHERE eoa.organizer_id = ? AND eoa.status = 'assigned'
            ");
            $assignedStmt->execute([$organizerId]);
            $assignedCount = $assignedStmt->fetch(PDO::FETCH_ASSOC)['assigned_count'];

            // Get accepted events count
            $acceptedStmt = $this->conn->prepare("
                SELECT COUNT(*) as accepted_count
                FROM tbl_event_organizer_assignments eoa
                WHERE eoa.organizer_id = ? AND eoa.status = 'accepted'
            ");
            $acceptedStmt->execute([$organizerId]);
            $acceptedCount = $acceptedStmt->fetch(PDO::FETCH_ASSOC)['accepted_count'];

            // Get upcoming events (next 30 days)
            $upcomingStmt = $this->conn->prepare("
                SELECT COUNT(*) as upcoming_count
                FROM tbl_event_organizer_assignments eoa
                JOIN tbl_events e ON eoa.event_id = e.event_id
                WHERE eoa.organizer_id = ?
                AND eoa.status IN ('assigned', 'accepted')
                AND e.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ");
            $upcomingStmt->execute([$organizerId]);
            $upcomingCount = $upcomingStmt->fetch(PDO::FETCH_ASSOC)['upcoming_count'];

            // Get total events handled
            $totalStmt = $this->conn->prepare("
                SELECT COUNT(*) as total_count
                FROM tbl_event_organizer_assignments eoa
                WHERE eoa.organizer_id = ?
            ");
            $totalStmt->execute([$organizerId]);
            $totalCount = $totalStmt->fetch(PDO::FETCH_ASSOC)['total_count'];

            return json_encode([
                "status" => "success",
                "data" => [
                    "assigned_events" => $assignedCount,
                    "accepted_events" => $acceptedCount,
                    "upcoming_events" => $upcomingCount,
                    "total_events" => $totalCount
                ]
            ]);

        } catch (Exception $e) {
            error_log("getOrganizerDashboardStats error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Failed to get dashboard stats: " . $e->getMessage()]);
        }
    }
}

if (!$pdo) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit();
}

// Read JSON input from frontend (for POST requests)
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

// Handle JSON parsing errors
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid JSON data: " . json_last_error_msg()
    ]);
    exit;
}

// Ensure $data is an array
if (!is_array($data)) {
    $data = [];
}

// Check if operation is provided via GET or POST
$operation = $_POST['operation'] ?? ($_GET['operation'] ?? ($data['operation'] ?? ''));

$organizer = new Organizer($pdo);

// Wrap entire execution in try-catch to handle any uncaught exceptions
try {
    // Handle API actions
    switch ($operation) {
        case "getOrganizerEvents":
            $organizerId = (int)($_GET['organizer_id'] ?? ($data['organizer_id'] ?? 0));
            if ($organizerId <= 0) {
                echo json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
            } else {
                echo $organizer->getOrganizerEvents($organizerId);
            }
            break;

        case "getOrganizerEventDetails":
            $eventId = (int)($_GET['event_id'] ?? ($data['event_id'] ?? 0));
            if ($eventId <= 0) {
                echo json_encode(["status" => "error", "message" => "Valid event ID required"]);
            } else {
                echo $organizer->getOrganizerEventDetails($eventId);
            }
            break;

        case "getOrganizerProfile":
            $organizerId = (int)($_GET['organizer_id'] ?? ($data['organizer_id'] ?? 0));
            $userId = (int)($_GET['user_id'] ?? ($data['user_id'] ?? 0));
            if ($organizerId <= 0 && $userId <= 0) {
                echo json_encode(["status" => "error", "message" => "Valid organizer ID or user ID required"]);
            } else {
                echo $organizer->getOrganizerProfile($organizerId, $userId);
            }
            break;

        case "updateAssignmentStatus":
            echo $organizer->updateAssignmentStatus($data);
            break;

        case "getOrganizerActivityTimeline":
            $organizerId = (int)($_GET['organizer_id'] ?? ($data['organizer_id'] ?? 0));
            $startDate = $_GET['start_date'] ?? ($data['start_date'] ?? null);
            $endDate = $_GET['end_date'] ?? ($data['end_date'] ?? null);
            $page = (int)($_GET['page'] ?? ($data['page'] ?? 1));
            $limit = (int)($_GET['limit'] ?? ($data['limit'] ?? 10));

            if ($organizerId <= 0) {
                echo json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
            } else {
                echo $organizer->getOrganizerActivityTimeline($organizerId, $startDate, $endDate, $page, $limit);
            }
            break;

        case "checkOrganizerDateConflict":
            $organizerId = (int)($_GET['organizer_id'] ?? ($data['organizer_id'] ?? 0));
            $eventDate = $_GET['event_date'] ?? ($data['event_date'] ?? null);
            $eventId = (int)($_GET['event_id'] ?? ($data['event_id'] ?? 0));

            if ($organizerId <= 0 || !$eventDate) {
                echo json_encode(["status" => "error", "message" => "Valid organizer ID and event date required"]);
            } else {
                echo $organizer->checkOrganizerDateConflict($organizerId, $eventDate, $eventId > 0 ? $eventId : null);
            }
            break;

        case "getOrganizerDashboardStats":
            $organizerId = (int)($_GET['organizer_id'] ?? ($data['organizer_id'] ?? 0));
            if ($organizerId <= 0) {
                echo json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
            } else {
                echo $organizer->getOrganizerDashboardStats($organizerId);
            }
            break;

        default:
            echo json_encode([
                "status" => "error",
                "message" => "Invalid or missing operation: '$operation'"
            ]);
            break;
    }
} catch (Exception $e) {
    error_log("Organizer.php - Uncaught exception: " . $e->getMessage());
    echo json_encode([
        "status" => "error",
        "message" => "Server error occurred",
        "debug" => [
            "error" => $e->getMessage(),
            "file" => basename($e->getFile()),
            "line" => $e->getLine()
        ]
    ]);
}
?>
