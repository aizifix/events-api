<?php
// Include the database connection
require_once 'db_connect.php';

// Set headers for CORS and content type
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Function to handle CORS
function handleCors() {
    // Already handled above
}

// Function to get all event types
function getEventTypes() {
    global $pdo;

    try {
        $sql = "SELECT * FROM tbl_event_type ORDER BY event_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $eventTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ["status" => "success", "event_types" => $eventTypes];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to get available packages by event type
function getPackagesByEventType($eventTypeId, $guestCount) {
    global $pdo;

    try {
        $sql = "SELECT p.*,
                GROUP_CONCAT(DISTINCT et.event_type_id) as event_type_ids,
                GROUP_CONCAT(DISTINCT et.event_name) as event_type_names,
                COUNT(DISTINCT pv.venue_id) as venue_count
                FROM tbl_packages p
                JOIN tbl_package_event_types pet ON p.package_id = pet.package_id
                JOIN tbl_event_type et ON pet.event_type_id = et.event_type_id
                LEFT JOIN tbl_package_venues pv ON p.package_id = pv.package_id
                WHERE p.is_active = 1
                AND p.guest_capacity >= :guest_count
                AND pet.event_type_id = :event_type_id
                GROUP BY p.package_id
                ORDER BY p.package_price ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':guest_count', $guestCount, PDO::PARAM_INT);
        $stmt->bindParam(':event_type_id', $eventTypeId, PDO::PARAM_INT);
        $stmt->execute();
        $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // For each package, get components, freebies, and venue previews
        foreach ($packages as &$package) {
            // Get package components (limited for preview)
            $componentsSql = "SELECT * FROM tbl_package_components
                             WHERE package_id = :package_id
                             ORDER BY display_order ASC
                             LIMIT 5";
            $componentsStmt = $pdo->prepare($componentsSql);
            $componentsStmt->bindParam(':package_id', $package['package_id'], PDO::PARAM_INT);
            $componentsStmt->execute();
            $package['components'] = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get package freebies (limited for preview)
            $freebiesSql = "SELECT * FROM tbl_package_freebies
                           WHERE package_id = :package_id
                           ORDER BY display_order ASC
                           LIMIT 3";
            $freebiesStmt = $pdo->prepare($freebiesSql);
            $freebiesStmt->bindParam(':package_id', $package['package_id'], PDO::PARAM_INT);
            $freebiesStmt->execute();
            $package['freebies'] = $freebiesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get venue previews for this package
            $venuesSql = "SELECT v.venue_id, v.venue_title, v.venue_location,
                                v.venue_capacity, v.venue_price, v.venue_profile_picture
                         FROM tbl_package_venues pv
                         JOIN tbl_venue v ON pv.venue_id = v.venue_id
                         WHERE pv.package_id = :package_id
                         AND v.venue_status = 'available'
                         ORDER BY v.venue_title ASC";
            $venuesStmt = $pdo->prepare($venuesSql);
            $venuesStmt->bindParam(':package_id', $package['package_id'], PDO::PARAM_INT);
            $venuesStmt->execute();
            $package['venue_previews'] = $venuesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Convert comma-separated values to arrays
            $package['event_type_ids'] = explode(',', $package['event_type_ids']);
            $package['event_type_names'] = explode(',', $package['event_type_names']);
        }

        return ["status" => "success", "packages" => $packages];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to get venues available for a specific package
function getVenuesByPackage($packageId, $eventDate, $guestCount) {
    global $pdo;

    try {
        $sql = "SELECT v.*,
                vi.inclusion_name, vi.inclusion_price, vi.inclusion_description
                FROM tbl_package_venues pv
                JOIN tbl_venue v ON pv.venue_id = v.venue_id
                LEFT JOIN tbl_venue_inclusions vi ON v.venue_id = vi.venue_id
                WHERE pv.package_id = :package_id
                AND v.venue_status = 'available'
                AND v.venue_capacity >= :guest_count
                AND v.venue_id NOT IN (
                    SELECT b.venue_id FROM tbl_bookings b
                    WHERE b.event_date = :event_date
                    AND b.booking_status IN ('pending', 'confirmed')
                    AND b.venue_id IS NOT NULL
                )
                ORDER BY v.venue_title ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':package_id', $packageId, PDO::PARAM_INT);
        $stmt->bindParam(':guest_count', $guestCount, PDO::PARAM_INT);
        $stmt->bindParam(':event_date', $eventDate, PDO::PARAM_STR);
        $stmt->execute();
        $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group venues and their inclusions
        $groupedVenues = [];
        foreach ($venues as $venue) {
            $venueId = $venue['venue_id'];

            if (!isset($groupedVenues[$venueId])) {
                $groupedVenues[$venueId] = [
                    'venue_id' => $venue['venue_id'],
                    'venue_title' => $venue['venue_title'],
                    'venue_details' => $venue['venue_details'],
                    'venue_location' => $venue['venue_location'],
                    'venue_contact' => $venue['venue_contact'],
                    'venue_capacity' => $venue['venue_capacity'],
                    'venue_price' => $venue['venue_price'],
                    'venue_type' => $venue['venue_type'],
                    'venue_profile_picture' => $venue['venue_profile_picture'],
                    'venue_cover_photo' => $venue['venue_cover_photo'],
                    'inclusions' => []
                ];
            }

            // Add inclusions if they exist
            if ($venue['inclusion_name']) {
                $groupedVenues[$venueId]['inclusions'][] = [
                    'inclusion_name' => $venue['inclusion_name'],
                    'inclusion_price' => $venue['inclusion_price'],
                    'inclusion_description' => $venue['inclusion_description']
                ];
            }
        }

        return ["status" => "success", "venues" => array_values($groupedVenues)];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to get package details by ID
function getPackageDetails($packageId) {
    global $pdo;

    try {
        $sql = "SELECT p.*,
                GROUP_CONCAT(DISTINCT et.event_name) as event_type_names
                FROM tbl_packages p
                JOIN tbl_package_event_types pet ON p.package_id = pet.package_id
                JOIN tbl_event_type et ON pet.event_type_id = et.event_type_id
                WHERE p.package_id = :package_id
                GROUP BY p.package_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':package_id', $packageId, PDO::PARAM_INT);
        $stmt->execute();
        $package = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$package) {
            return ["status" => "error", "message" => "Package not found"];
        }

        // Get all components
        $componentsSql = "SELECT * FROM tbl_package_components
                         WHERE package_id = :package_id
                         ORDER BY display_order ASC";
        $componentsStmt = $pdo->prepare($componentsSql);
        $componentsStmt->bindParam(':package_id', $packageId, PDO::PARAM_INT);
        $componentsStmt->execute();
        $package['components'] = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get all freebies
        $freebiesSql = "SELECT * FROM tbl_package_freebies
                       WHERE package_id = :package_id
                       ORDER BY display_order ASC";
        $freebiesStmt = $pdo->prepare($freebiesSql);
        $freebiesStmt->bindParam(':package_id', $packageId, PDO::PARAM_INT);
        $freebiesStmt->execute();
        $package['freebies'] = $freebiesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get all venues for this package
        $venuesSql = "SELECT v.venue_id, v.venue_title, v.venue_location,
                            v.venue_capacity, v.venue_price as total_price,
                            v.venue_profile_picture, v.venue_cover_photo
                     FROM tbl_package_venues pv
                     JOIN tbl_venue v ON pv.venue_id = v.venue_id
                     WHERE pv.package_id = :package_id
                     AND v.venue_status = 'available'
                     ORDER BY v.venue_title ASC";
        $venuesStmt = $pdo->prepare($venuesSql);
        $venuesStmt->bindParam(':package_id', $packageId, PDO::PARAM_INT);
        $venuesStmt->execute();
        $package['venues'] = $venuesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert event type names to array
        if ($package['event_type_names']) {
            $package['event_type_names'] = explode(',', $package['event_type_names']);
        } else {
            $package['event_type_names'] = [];
        }

        return ["status" => "success", "package" => $package];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Legacy function - keeping for backward compatibility but now gets packages by event type
function getAvailableVenues($eventTypeId, $eventDate, $guestCount) {
    // Redirect to package-based approach
    return getPackagesByEventType($eventTypeId, $guestCount);
}

// Legacy function - keeping for backward compatibility
function getAvailablePackages($venueId, $eventTypeId, $guestCount) {
    return getPackagesByEventType($eventTypeId, $guestCount);
}

// Function to create a new booking
function createBooking($data) {
    global $pdo;

    try {
        // Log the incoming data for debugging
        error_log("createBooking: Received data: " . json_encode($data));

        // Validate required fields
        $requiredFields = ['user_id', 'event_type_id', 'event_name', 'event_date', 'event_time', 'guest_count'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                return ["status" => "error", "message" => "Missing required field: " . $field];
            }
        }

        // Validate data types
        if (!is_numeric($data['user_id']) || $data['user_id'] <= 0) {
            return ["status" => "error", "message" => "Invalid user ID"];
        }

        if (!is_numeric($data['event_type_id']) || $data['event_type_id'] <= 0) {
            return ["status" => "error", "message" => "Invalid event type ID"];
        }

        if (!is_numeric($data['guest_count']) || $data['guest_count'] <= 0) {
            return ["status" => "error", "message" => "Invalid guest count"];
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['event_date'])) {
            return ["status" => "error", "message" => "Invalid date format. Use YYYY-MM-DD"];
        }

        // Validate time format
        if (!preg_match('/^\d{2}:\d{2}$/', $data['event_time'])) {
            return ["status" => "error", "message" => "Invalid time format. Use HH:MM"];
        }

        $pdo->beginTransaction();

        // Generate unique booking reference with timestamp
        do {
            $bookingReference = 'BK-' . date('Ymd') . '-' . rand(1000, 9999);
            $checkRef = $pdo->prepare("SELECT COUNT(*) FROM tbl_bookings WHERE booking_reference = ?");
            $checkRef->execute([$bookingReference]);
        } while ($checkRef->fetchColumn() > 0);

        // Get current timestamp for booking creation
        $createdAt = date('Y-m-d H:i:s');

        // Check if the new columns exist in the table
        $columnCheckSql = "SHOW COLUMNS FROM tbl_bookings LIKE 'start_time'";
        $columnCheckStmt = $pdo->prepare($columnCheckSql);
        $columnCheckStmt->execute();
        $hasNewColumns = $columnCheckStmt->rowCount() > 0;

        // Check if created_at column exists
        $createdAtCheckSql = "SHOW COLUMNS FROM tbl_bookings LIKE 'created_at'";
        $createdAtCheckStmt = $pdo->prepare($createdAtCheckSql);
        $createdAtCheckStmt->execute();
        $hasCreatedAt = $createdAtCheckStmt->rowCount() > 0;

        error_log("createBooking: Has new columns: " . ($hasNewColumns ? 'yes' : 'no'));
        error_log("createBooking: Has created_at: " . ($hasCreatedAt ? 'yes' : 'no'));

        // Build SQL query based on available columns
        if ($hasNewColumns) {
            // Use new schema with enhanced columns
            $columns = "booking_reference, user_id, event_type_id, event_name, event_date, event_time, start_time, end_time, guest_count, venue_id, package_id, notes, booking_status";
            $values = ":booking_reference, :user_id, :event_type_id, :event_name, :event_date, :event_time, :start_time, :end_time, :guest_count, :venue_id, :package_id, :notes, :booking_status";

            if ($hasCreatedAt) {
                $columns .= ", created_at";
                $values .= ", :created_at";
            }

            $sql = "INSERT INTO tbl_bookings ($columns) VALUES ($values)";
        } else {
            // Use legacy schema without new columns
            $columns = "booking_reference, user_id, event_type_id, event_name, event_date, event_time, guest_count, venue_id, package_id, notes";
            $values = ":booking_reference, :user_id, :event_type_id, :event_name, :event_date, :event_time, :guest_count, :venue_id, :package_id, :notes";

            if ($hasCreatedAt) {
                $columns .= ", created_at";
                $values .= ", :created_at";
            }

            $sql = "INSERT INTO tbl_bookings ($columns) VALUES ($values)";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':booking_reference', $bookingReference, PDO::PARAM_STR);
        $stmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':event_type_id', $data['event_type_id'], PDO::PARAM_INT);
        $stmt->bindParam(':event_name', $data['event_name'], PDO::PARAM_STR);
        $stmt->bindParam(':event_date', $data['event_date'], PDO::PARAM_STR);
        $stmt->bindParam(':event_time', $data['event_time'], PDO::PARAM_STR);
        $stmt->bindParam(':guest_count', $data['guest_count'], PDO::PARAM_INT);

        // Handle nullable venue_id and package_id
        $venueId = isset($data['venue_id']) && $data['venue_id'] ? (int)$data['venue_id'] : null;
        $packageId = isset($data['package_id']) && $data['package_id'] ? (int)$data['package_id'] : null;

        if ($venueId === null) {
            $stmt->bindValue(':venue_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':venue_id', $venueId, PDO::PARAM_INT);
        }

        if ($packageId === null) {
            $stmt->bindValue(':package_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':package_id', $packageId, PDO::PARAM_INT);
        }

        $notes = $data['notes'] ?? '';
        $stmt->bindParam(':notes', $notes, PDO::PARAM_STR);

        // Bind additional parameters only if new columns exist
        if ($hasNewColumns) {
            $startTime = $data['start_time'] ?? $data['event_time'];
            $endTime = $data['end_time'] ?? null;
            $bookingStatus = $data['booking_status'] ?? 'pending';

            $stmt->bindParam(':start_time', $startTime, PDO::PARAM_STR);
            if ($endTime === null) {
                $stmt->bindValue(':end_time', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindParam(':end_time', $endTime, PDO::PARAM_STR);
            }
            $stmt->bindParam(':booking_status', $bookingStatus, PDO::PARAM_STR);
        }

        // Bind created_at if column exists
        if ($hasCreatedAt) {
            $stmt->bindParam(':created_at', $createdAt, PDO::PARAM_STR);
        }

        error_log("createBooking: Executing SQL with reference: " . $bookingReference);
        $stmt->execute();
        $bookingId = $pdo->lastInsertId();

        // Create notification for admin (with error handling)
        try {
            $notificationSql = "INSERT INTO tbl_notifications (
                    user_id, booking_id, notification_message, notification_status
                ) VALUES (
                    (SELECT user_id FROM tbl_users WHERE user_role = 'admin' LIMIT 1),
                    :booking_id,
                    :notification_message,
                    'unread'
                )";

            $notificationStmt = $pdo->prepare($notificationSql);
            $notificationStmt->bindParam(':booking_id', $bookingId, PDO::PARAM_INT);
            $notificationMessage = 'New booking created: ' . $bookingReference;
            $notificationStmt->bindParam(':notification_message', $notificationMessage, PDO::PARAM_STR);
            $notificationStmt->execute();
        } catch (PDOException $notifError) {
            // Log notification error but don't fail the booking
            error_log("createBooking: Notification creation failed: " . $notifError->getMessage());
        }

        $pdo->commit();

        error_log("createBooking: Success! Booking ID: " . $bookingId . ", Reference: " . $bookingReference);

        return [
            "status" => "success",
            "booking_id" => $bookingId,
            "booking_reference" => $bookingReference,
            "message" => "Booking created successfully"
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("createBooking: Database error: " . $e->getMessage());
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("createBooking: General error: " . $e->getMessage());
        return ["status" => "error", "message" => "Error creating booking: " . $e->getMessage()];
    }
}

// Function to get client bookings
function getClientBookings($userId) {
    global $pdo;

    try {
        $sql = "SELECT b.*,
                et.event_name as event_type_name,
                v.venue_title as venue_name,
                p.package_title as package_name
                FROM tbl_bookings b
                LEFT JOIN tbl_event_type et ON b.event_type_id = et.event_type_id
                LEFT JOIN tbl_venue v ON b.venue_id = v.venue_id
                LEFT JOIN tbl_packages p ON b.package_id = p.package_id
                WHERE b.user_id = :user_id
                ORDER BY b.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ["status" => "success", "bookings" => $bookings];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to get client's events with payment information
function getClientEvents($userId) {
    global $pdo;

    try {
        $sql = "SELECT e.*,
                CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                et.event_name as event_type_name,
                v.venue_title as venue_name,
                p.package_title as package_name,
                COALESCE(SUM(CASE WHEN pay.payment_status = 'completed' THEN pay.payment_amount ELSE 0 END), 0) as total_paid,
                COUNT(pay.payment_id) as payment_count
                FROM tbl_events e
                LEFT JOIN tbl_users a ON e.admin_id = a.user_id
                LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                LEFT JOIN tbl_payments pay ON e.event_id = pay.event_id
                WHERE e.user_id = :user_id
                GROUP BY e.event_id
                ORDER BY e.event_date ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate payment percentages and remaining balance
        foreach ($events as &$event) {
            $event['total_paid'] = (float)$event['total_paid'];
            $event['total_budget'] = (float)$event['total_budget'];
            $event['remaining_balance'] = $event['total_budget'] - $event['total_paid'];
            $event['payment_percentage'] = $event['total_budget'] > 0
                ? round(($event['total_paid'] / $event['total_budget']) * 100, 2)
                : 0;
        }

        return ["status" => "success", "events" => $events];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to get client's payment history
function getClientPaymentHistory($userId) {
    global $pdo;

    try {
        $sql = "SELECT p.*, e.event_title, e.event_date, e.total_budget,
                CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                eps.installment_number, eps.due_date,
                pst.schedule_name
                FROM tbl_payments p
                JOIN tbl_events e ON p.event_id = e.event_id
                JOIN tbl_users a ON e.admin_id = a.user_id
                LEFT JOIN tbl_event_payment_schedules eps ON p.schedule_id = eps.schedule_id
                LEFT JOIN tbl_payment_schedule_types pst ON e.payment_schedule_type_id = pst.schedule_type_id
                WHERE p.client_id = :user_id
                ORDER BY p.payment_date DESC, p.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ["status" => "success", "payments" => $payments];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to get payment schedule for a client's event
function getClientPaymentSchedule($userId, $eventId) {
    global $pdo;

    try {
        // Verify the event belongs to the client
        $verifySql = "SELECT COUNT(*) FROM tbl_events WHERE event_id = :event_id AND user_id = :user_id";
        $verifyStmt = $pdo->prepare($verifySql);
        $verifyStmt->bindParam(':event_id', $eventId, PDO::PARAM_INT);
        $verifyStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $verifyStmt->execute();

        if ($verifyStmt->fetchColumn() == 0) {
            return ["status" => "error", "message" => "Event not found or access denied"];
        }

        // Get event details with schedule
        $sql = "SELECT e.*, pst.schedule_name, pst.schedule_description,
                COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) as total_paid
                FROM tbl_events e
                LEFT JOIN tbl_payment_schedule_types pst ON e.payment_schedule_type_id = pst.schedule_type_id
                LEFT JOIN tbl_payments p ON e.event_id = p.event_id
                WHERE e.event_id = :event_id AND e.user_id = :user_id
                GROUP BY e.event_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            return ["status" => "error", "message" => "Event not found"];
        }

        // Get payment schedule items
        $scheduleSql = "SELECT eps.*,
                               CASE
                                   WHEN eps.due_date < CURDATE() AND eps.payment_status != 'paid' THEN 'overdue'
                                   WHEN eps.due_date = CURDATE() AND eps.payment_status != 'paid' THEN 'due_today'
                                   ELSE 'current'
                               END as urgency_status
                        FROM tbl_event_payment_schedules eps
                        WHERE eps.event_id = :event_id
                        ORDER BY eps.installment_number";

        $scheduleStmt = $pdo->prepare($scheduleSql);
        $scheduleStmt->bindParam(':event_id', $eventId, PDO::PARAM_INT);
        $scheduleStmt->execute();
        $scheduleItems = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalBudget = (float)$event['total_budget'];
        $totalPaid = (float)$event['total_paid'];
        $remainingBalance = $totalBudget - $totalPaid;
        $paymentPercentage = $totalBudget > 0 ? ($totalPaid / $totalBudget) * 100 : 0;

        $schedule = [
            "event_title" => $event['event_title'],
            "event_date" => $event['event_date'],
            "total_budget" => $totalBudget,
            "total_paid" => $totalPaid,
            "remaining_balance" => $remainingBalance,
            "payment_percentage" => round($paymentPercentage, 2),
            "schedule_name" => $event['schedule_name'],
            "schedule_description" => $event['schedule_description'],
            "payment_status" => $event['payment_status'],
            "schedule_items" => $scheduleItems
        ];

        return ["status" => "success", "schedule" => $schedule];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to get client's payment dashboard
function getClientPaymentDashboard($userId) {
    global $pdo;

    try {
        $sql = "SELECT e.event_id, e.event_title, e.event_date, e.total_budget,
                       pst.schedule_name,
                       COUNT(eps.schedule_id) as total_installments,
                       SUM(CASE WHEN eps.payment_status = 'paid' THEN 1 ELSE 0 END) as paid_installments,
                       SUM(CASE WHEN eps.due_date < CURDATE() AND eps.payment_status != 'paid' THEN 1 ELSE 0 END) as overdue_installments,
                       SUM(CASE WHEN eps.due_date = CURDATE() AND eps.payment_status != 'paid' THEN 1 ELSE 0 END) as due_today_installments,
                       COALESCE(SUM(p.payment_amount), 0) as total_paid,
                       MIN(CASE WHEN eps.payment_status != 'paid' THEN eps.due_date END) as next_payment_due,
                       MIN(CASE WHEN eps.payment_status != 'paid' THEN eps.amount_due END) as next_payment_amount
                FROM tbl_events e
                LEFT JOIN tbl_payment_schedule_types pst ON e.payment_schedule_type_id = pst.schedule_type_id
                LEFT JOIN tbl_event_payment_schedules eps ON e.event_id = eps.event_id
                LEFT JOIN tbl_payments p ON eps.schedule_id = p.schedule_id AND p.payment_status = 'completed'
                WHERE e.user_id = :user_id
                GROUP BY e.event_id
                ORDER BY e.event_date ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate additional metrics
        foreach ($events as &$event) {
            $event['remaining_balance'] = $event['total_budget'] - $event['total_paid'];
            $event['payment_progress'] = $event['total_budget'] > 0
                ? round(($event['total_paid'] / $event['total_budget']) * 100, 2)
                : 0;
        }

        return ["status" => "success", "events" => $events];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to get next payment due for client
function getClientNextPayments($userId) {
    global $pdo;

    try {
        $sql = "SELECT eps.*, e.event_title, e.event_date,
                       CASE
                           WHEN eps.due_date < CURDATE() THEN 'overdue'
                           WHEN eps.due_date = CURDATE() THEN 'due_today'
                           WHEN eps.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'due_soon'
                           ELSE 'upcoming'
                       END as urgency_status,
                       DATEDIFF(eps.due_date, CURDATE()) as days_until_due
                FROM tbl_event_payment_schedules eps
                JOIN tbl_events e ON eps.event_id = e.event_id
                WHERE e.user_id = :user_id
                AND eps.payment_status != 'paid'
                ORDER BY eps.due_date ASC
                LIMIT 10";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $nextPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ["status" => "success", "next_payments" => $nextPayments];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to get all available packages for dashboard
function getAllPackages() {
    global $pdo;

    try {
        $sql = "SELECT p.*,
                GROUP_CONCAT(DISTINCT et.event_name) as event_type_names,
                COUNT(DISTINCT pv.venue_id) as venue_count,
                COUNT(DISTINCT pc.component_id) as component_count,
                COUNT(DISTINCT pf.freebie_id) as freebie_count
                FROM tbl_packages p
                LEFT JOIN tbl_package_event_types pet ON p.package_id = pet.package_id
                LEFT JOIN tbl_event_type et ON pet.event_type_id = et.event_type_id
                LEFT JOIN tbl_package_venues pv ON p.package_id = pv.package_id
                LEFT JOIN tbl_package_components pc ON p.package_id = pc.package_id
                LEFT JOIN tbl_package_freebies pf ON p.package_id = pf.package_id
                WHERE p.is_active = 1
                GROUP BY p.package_id
                ORDER BY p.package_price ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // For each package, get limited preview data
        foreach ($packages as &$package) {
            // Get package components (limited for preview)
            $componentsSql = "SELECT component_name, component_price
                             FROM tbl_package_components
                             WHERE package_id = :package_id
                             ORDER BY display_order ASC
                             LIMIT 5";
            $componentsStmt = $pdo->prepare($componentsSql);
            $componentsStmt->bindParam(':package_id', $package['package_id'], PDO::PARAM_INT);
            $componentsStmt->execute();
            $package['components'] = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get package freebies (limited for preview)
            $freebiesSql = "SELECT freebie_name, freebie_description, freebie_value
                           FROM tbl_package_freebies
                           WHERE package_id = :package_id
                           ORDER BY display_order ASC
                           LIMIT 3";
            $freebiesStmt = $pdo->prepare($freebiesSql);
            $freebiesStmt->bindParam(':package_id', $package['package_id'], PDO::PARAM_INT);
            $freebiesStmt->execute();
            $package['freebies'] = $freebiesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Convert comma-separated values to arrays
            if ($package['event_type_names']) {
                $package['event_type_names'] = explode(',', $package['event_type_names']);
            } else {
                $package['event_type_names'] = [];
            }

            // Add creator name
            $creatorSql = "SELECT CONCAT(user_firstName, ' ', user_lastName) as created_by_name
                          FROM tbl_users
                          WHERE user_id = :created_by";
            $creatorStmt = $pdo->prepare($creatorSql);
            $creatorStmt->bindParam(':created_by', $package['created_by'], PDO::PARAM_INT);
            $creatorStmt->execute();
            $creatorResult = $creatorStmt->fetch(PDO::FETCH_ASSOC);
            $package['created_by_name'] = $creatorResult ? $creatorResult['created_by_name'] : 'Unknown';
        }

        return ["status" => "success", "packages" => $packages];
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to check event conflicts
function checkEventConflicts($eventDate, $startTime, $endTime, $excludeEventId = null) {
    global $pdo;

    try {
        $sql = "
            SELECT
                e.event_id,
                e.event_title,
                e.event_date,
                e.start_time,
                e.end_time,
                e.event_type_id,
                et.event_name as event_type_name,
                CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                COALESCE(v.venue_title, 'TBD') as venue_name
            FROM tbl_events e
            LEFT JOIN tbl_users c ON e.user_id = c.user_id
            LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
            LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
            WHERE e.event_date = ?
            AND e.event_status NOT IN ('cancelled', 'completed')
            AND (
                (e.start_time < ? AND e.end_time > ?) OR
                (e.start_time < ? AND e.end_time > ?) OR
                (e.start_time >= ? AND e.end_time <= ?)
            )
        ";

        $params = [$eventDate, $endTime, $startTime, $endTime, $startTime, $startTime, $endTime];

        if ($excludeEventId) {
            $sql .= " AND e.event_id != ?";
            $params[] = $excludeEventId;
        }

        $sql .= " ORDER BY e.start_time";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format conflicts for frontend
        $formattedConflicts = [];
        $hasWedding = false;
        $hasOtherEvents = false;

        foreach ($conflicts as $conflict) {
            $formattedConflicts[] = [
                'event_id' => (int)$conflict['event_id'],
                'event_title' => $conflict['event_title'],
                'event_date' => $conflict['event_date'],
                'start_time' => $conflict['start_time'],
                'end_time' => $conflict['end_time'],
                'event_type_id' => (int)$conflict['event_type_id'],
                'event_type_name' => $conflict['event_type_name'],
                'client_name' => $conflict['client_name'] ?: 'Unknown Client',
                'venue_name' => $conflict['venue_name'] ?: 'TBD'
            ];

            // Check for wedding conflicts (business rule: only one wedding per day)
            if ($conflict['event_type_id'] == 1) {
                $hasWedding = true;
            } else {
                $hasOtherEvents = true;
            }
        }

        return [
            "status" => "success",
            "hasConflicts" => count($formattedConflicts) > 0,
            "hasWedding" => $hasWedding,
            "hasOtherEvents" => $hasOtherEvents,
            "conflicts" => $formattedConflicts
        ];
    } catch (Exception $e) {
        error_log("checkEventConflicts error: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Failed to check event conflicts: " . $e->getMessage(),
            "hasConflicts" => false,
            "hasWedding" => false,
            "hasOtherEvents" => false,
            "conflicts" => []
        ];
    }
}

// Function to get calendar conflict data for heat map
function getCalendarConflictData($startDate, $endDate) {
    global $pdo;

    try {
        $sql = "
            SELECT
                e.event_date,
                e.event_type_id,
                et.event_name as event_type_name,
                COUNT(*) as event_count
            FROM tbl_events e
            LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
            WHERE e.event_date BETWEEN ? AND ?
            AND e.event_status NOT IN ('cancelled', 'completed')
            GROUP BY e.event_date, e.event_type_id, et.event_name
            ORDER BY e.event_date
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Structure the data for frontend calendar
        $calendarData = [];

        foreach ($events as $event) {
            $date = $event['event_date'];
            $eventTypeId = (int)$event['event_type_id'];

            if (!isset($calendarData[$date])) {
                $calendarData[$date] = [
                    'hasWedding' => false,
                    'hasOtherEvents' => false,
                    'eventCount' => 0,
                    'events' => []
                ];
            }

            $calendarData[$date]['eventCount'] += $event['event_count'];

            if ($eventTypeId == 1) { // Wedding
                $calendarData[$date]['hasWedding'] = true;
            } else {
                $calendarData[$date]['hasOtherEvents'] = true;
            }

            $calendarData[$date]['events'][] = [
                'event_type_id' => $eventTypeId,
                'event_type_name' => $event['event_type_name'],
                'count' => $event['event_count']
            ];
        }

        return [
            "status" => "success",
            "calendarData" => $calendarData,
            "dateRange" => [
                "startDate" => $startDate,
                "endDate" => $endDate
            ]
        ];
    } catch (Exception $e) {
        error_log("getCalendarConflictData error: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Failed to get calendar conflict data: " . $e->getMessage(),
            "calendarData" => []
        ];
    }
}

// Function to get user profile
function getUserProfile($userId) {
    global $pdo;

    try {
        $sql = "SELECT user_id, user_firstName, user_lastName, user_email, user_contact, user_pfp, created_at
                FROM tbl_users WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($profile) {
            return ["status" => "success", "profile" => $profile];
        } else {
            return ["status" => "error", "message" => "User not found"];
        }
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to update user profile
function updateUserProfile($data) {
    global $pdo;

    try {
        // Build dynamic update query to handle optional profile picture
        $updateFields = [
            "user_firstName = ?",
            "user_lastName = ?",
            "user_email = ?",
            "user_contact = ?"
        ];

        $params = [
            $data['firstName'],
            $data['lastName'],
            $data['email'],
            $data['contact']
        ];

        // Add profile picture if provided
        if (isset($data['user_pfp'])) {
            $updateFields[] = "user_pfp = ?";
            $params[] = $data['user_pfp'];
        }

        $params[] = $data['user_id'];

        $sql = "UPDATE tbl_users SET " . implode(", ", $updateFields) . " WHERE user_id = ?";

        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);

        if ($result) {
            return ["status" => "success", "message" => "Profile updated successfully"];
        } else {
            return ["status" => "error", "message" => "Failed to update profile"];
        }
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to change password
function changePassword($data) {
    global $pdo;

    try {
        // Verify current password
        $sql = "SELECT user_pwd FROM tbl_users WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($data['currentPassword'], $user['user_pwd'])) {
            return ["status" => "error", "message" => "Current password is incorrect"];
        }

        // Update password
        $hashedPassword = password_hash($data['newPassword'], PASSWORD_DEFAULT);
        $updateSql = "UPDATE tbl_users SET user_pwd = ? WHERE user_id = ?";
        $updateStmt = $pdo->prepare($updateSql);
        $result = $updateStmt->execute([$hashedPassword, $data['user_id']]);

        if ($result) {
            return ["status" => "success", "message" => "Password changed successfully"];
        } else {
            return ["status" => "error", "message" => "Failed to change password"];
        }
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Function to upload profile picture
function uploadProfilePicture($file, $userId) {
    global $pdo;

    try {
        $uploadDir = "uploads/profile_pictures/";

        // Create directory if it doesn't exist
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $fileType = $file['type'] ?? mime_content_type($file['tmp_name']);

        if (!in_array($fileType, $allowedTypes)) {
            return ["status" => "error", "message" => "Invalid file type. Only images are allowed."];
        }

        // Generate unique filename
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (empty($fileExtension)) {
            $fileExtension = 'jpg'; // Default for blob uploads
        }
        $fileName = 'profile_' . $userId . '_' . time() . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;

        // Delete old profile picture if exists
        $getUserSql = "SELECT user_pfp FROM tbl_users WHERE user_id = ?";
        $getUserStmt = $pdo->prepare($getUserSql);
        $getUserStmt->execute([$userId]);
        $userData = $getUserStmt->fetch(PDO::FETCH_ASSOC);

        if ($userData && $userData['user_pfp'] && file_exists($userData['user_pfp'])) {
            unlink($userData['user_pfp']);
        }

        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            // Update user profile picture in database
            $updateSql = "UPDATE tbl_users SET user_pfp = ? WHERE user_id = ?";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([$filePath, $userId]);

            return ["status" => "success", "filePath" => $filePath, "message" => "Profile picture uploaded successfully"];
        } else {
            return ["status" => "error", "message" => "Failed to upload file"];
        }
    } catch (PDOException $e) {
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

// Handle request
handleCors();

$method = $_SERVER['REQUEST_METHOD'];
$operation = isset($_GET['operation']) ? $_GET['operation'] : '';
// For backward compatibility
if (empty($operation) && isset($_GET['action'])) {
    $operation = $_GET['action'];
}

// For POST requests, check if operation is in the request body
if ($method === 'POST') {
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    // Log the raw input for debugging
    error_log("Raw POST input: " . $jsonInput);
    error_log("JSON decode result: " . json_encode($data));
    error_log("JSON last error: " . json_last_error_msg());

    if (!$data || json_last_error() !== JSON_ERROR_NONE) {
        // If JSON parsing fails, try to get form data
        $data = $_POST;
        error_log("Falling back to \$_POST: " . json_encode($data));

        // If still no data, return error
        if (empty($data)) {
            echo json_encode([
                "status" => "error",
                "message" => "No data received or invalid JSON format"
            ]);
            exit;
        }
    }

    if (empty($operation) && isset($data['operation'])) {
        $operation = $data['operation'];
    }
}

switch ($method) {
    case 'GET':
        switch ($operation) {
            case 'getEventTypes':
            case 'event-types': // For backward compatibility
                echo json_encode(getEventTypes());
                break;

            case 'getPackagesByEventType':
            case 'packages-by-event-type':
                $eventTypeId = isset($_GET['event_type_id']) ? intval($_GET['event_type_id']) : 0;
                $guestCount = isset($_GET['guest_count']) ? intval($_GET['guest_count']) : 0;

                echo json_encode(getPackagesByEventType($eventTypeId, $guestCount));
                break;

            case 'getVenuesByPackage':
            case 'venues-by-package':
                $packageId = isset($_GET['package_id']) ? intval($_GET['package_id']) : 0;
                $eventDate = isset($_GET['event_date']) ? $_GET['event_date'] : date('Y-m-d');
                $guestCount = isset($_GET['guest_count']) ? intval($_GET['guest_count']) : 0;

                echo json_encode(getVenuesByPackage($packageId, $eventDate, $guestCount));
                break;

            case 'getPackageDetails':
            case 'package-details':
                $packageId = isset($_GET['package_id']) ? intval($_GET['package_id']) : 0;
                echo json_encode(getPackageDetails($packageId));
                break;

            case 'getVenues':
            case 'venues': // For backward compatibility - now returns packages
                $eventTypeId = isset($_GET['event_type_id']) ? intval($_GET['event_type_id']) : 0;
                $eventDate = isset($_GET['event_date']) ? $_GET['event_date'] : date('Y-m-d');
                $guestCount = isset($_GET['guest_count']) ? intval($_GET['guest_count']) : 0;

                echo json_encode(getPackagesByEventType($eventTypeId, $guestCount));
                break;

            case 'getPackages':
            case 'packages': // For backward compatibility
                $eventTypeId = isset($_GET['event_type_id']) ? intval($_GET['event_type_id']) : 0;
                $guestCount = isset($_GET['guest_count']) ? intval($_GET['guest_count']) : 0;

                echo json_encode(getPackagesByEventType($eventTypeId, $guestCount));
                break;

            case 'getClientBookings':
            case 'bookings': // For backward compatibility
                $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

                if ($userId > 0) {
                    echo json_encode(getClientBookings($userId));
                } else {
                    echo json_encode([
                        "status" => "error",
                        "message" => "User ID is required"
                    ]);
                }
                break;

            case 'getClientEvents':
            case 'client-events':
                $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

                if ($userId > 0) {
                    echo json_encode(getClientEvents($userId));
                } else {
                    echo json_encode([
                        "status" => "error",
                        "message" => "User ID is required"
                    ]);
                }
                break;

            case 'getClientPaymentHistory':
            case 'payment-history':
                $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

                if ($userId > 0) {
                    echo json_encode(getClientPaymentHistory($userId));
                } else {
                    echo json_encode([
                        "status" => "error",
                        "message" => "User ID is required"
                    ]);
                }
                break;

            case 'getClientPaymentSchedule':
            case 'payment-schedule':
                $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
                $eventId = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;

                if ($userId > 0 && $eventId > 0) {
                    echo json_encode(getClientPaymentSchedule($userId, $eventId));
                } else {
                    echo json_encode([
                        "status" => "error",
                        "message" => "User ID and Event ID are required"
                    ]);
                }
                break;

            case 'getClientPaymentDashboard':
            case 'payment-dashboard':
                $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

                if ($userId > 0) {
                    echo json_encode(getClientPaymentDashboard($userId));
                } else {
                    echo json_encode([
                        "status" => "error",
                        "message" => "User ID is required"
                    ]);
                }
                break;

            case 'getClientNextPayments':
            case 'next-payments':
                $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

                if ($userId > 0) {
                    echo json_encode(getClientNextPayments($userId));
                } else {
                    echo json_encode([
                        "status" => "error",
                        "message" => "User ID is required"
                    ]);
                }
                break;

            case 'getAllPackages':
                echo json_encode(getAllPackages());
                break;

            case 'getUserProfile':
                $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

                if ($userId > 0) {
                    echo json_encode(getUserProfile($userId));
                } else {
                    echo json_encode([
                        "status" => "error",
                        "message" => "User ID is required"
                    ]);
                }
                break;

            default:
                echo json_encode([
                    "status" => "error",
                    "message" => "Unknown operation: " . $operation
                ]);
                break;
        }
        break;

    case 'POST':
        // Make sure we have an operation
        if (empty($operation)) {
            echo json_encode([
                "status" => "error",
                "message" => "No operation specified"
            ]);
            break;
        }

        switch ($operation) {
            case 'createBooking':
            case 'create-booking': // For backward compatibility
                // Log the incoming request for debugging
                error_log("POST createBooking: Raw input: " . file_get_contents('php://input'));
                error_log("POST createBooking: Parsed data: " . json_encode($data));
                error_log("POST createBooking: Operation: " . $operation);

                // Additional validation is now handled in the createBooking function
                $result = createBooking($data);
                error_log("POST createBooking: Result: " . json_encode($result));
                echo json_encode($result);
                break;

            case 'checkEventConflicts':
                $eventDate = isset($data['event_date']) ? $data['event_date'] : '';
                $startTime = isset($data['start_time']) ? $data['start_time'] : '';
                $endTime = isset($data['end_time']) ? $data['end_time'] : '';
                $excludeEventId = isset($data['exclude_event_id']) ? $data['exclude_event_id'] : null;

                if ($eventDate && $startTime && $endTime) {
                    echo json_encode(checkEventConflicts($eventDate, $startTime, $endTime, $excludeEventId));
                } else {
                    echo json_encode([
                        "status" => "error",
                        "message" => "Event date, start time, and end time are required"
                    ]);
                }
                break;

            case 'getCalendarConflictData':
                $startDate = isset($data['start_date']) ? $data['start_date'] : '';
                $endDate = isset($data['end_date']) ? $data['end_date'] : '';

                if ($startDate && $endDate) {
                    echo json_encode(getCalendarConflictData($startDate, $endDate));
                } else {
                    echo json_encode([
                        "status" => "error",
                        "message" => "Start date and end date are required"
                    ]);
                }
                break;

            case 'updateUserProfile':
                if (!isset($data['user_id']) || !isset($data['firstName']) || !isset($data['lastName']) ||
                    !isset($data['email']) || !isset($data['contact'])) {
                    echo json_encode([
                        "status" => "error",
                        "message" => "All profile fields are required"
                    ]);
                } else {
                    echo json_encode(updateUserProfile($data));
                }
                break;

            case 'changePassword':
                if (!isset($data['user_id']) || !isset($data['currentPassword']) ||
                    !isset($data['newPassword']) || !isset($data['confirmPassword'])) {
                    echo json_encode([
                        "status" => "error",
                        "message" => "All password fields are required"
                    ]);
                } else {
                    echo json_encode(changePassword($data));
                }
                break;

            case 'uploadProfilePicture':
                if (isset($_FILES['file'])) {
                    $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
                    if ($userId > 0) {
                        echo json_encode(uploadProfilePicture($_FILES['file'], $userId));
                    } else {
                        echo json_encode([
                            "status" => "error",
                            "message" => "User ID is required"
                        ]);
                    }
                } else {
                    echo json_encode([
                        "status" => "error",
                        "message" => "No file uploaded"
                    ]);
                }
                break;

            default:
                echo json_encode([
                    "status" => "error",
                    "message" => "Unknown operation: " . $operation
                ]);
                break;
        }
        break;

    default:
        echo json_encode([
            "status" => "error",
            "message" => "Method not allowed"
        ]);
        break;
}
