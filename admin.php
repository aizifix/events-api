<?php
require 'db_connect.php';

// Add CORS headers for API access
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Set content type to JSON
header("Content-Type: application/json");

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

class Admin {
    private $conn;
    private $pdo;

    public function __construct($pdo) {
        $this->conn = $pdo;
        $this->pdo = $pdo;  // For compatibility with new methods
    }

    public function createEvent($data) {
        try {
            // Log the incoming data for debugging
            error_log("createEvent received data: " . json_encode($data));

            $this->conn->beginTransaction();

            // Validate required event fields
            $required = ['user_id', 'admin_id', 'event_title', 'event_type_id', 'guest_count', 'event_date'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    error_log("createEvent error: Missing required field: $field");
                    return json_encode(["status" => "error", "message" => "$field is required"]);
                }
            }

            // Validate foreign key references before insertion
            // Check if user exists
            $userCheck = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_id = ?");
            $userCheck->execute([$data['user_id']]);
            if (!$userCheck->fetch()) {
                return json_encode(["status" => "error", "message" => "Invalid user_id: User does not exist"]);
            }

            // Check if admin exists
            $adminCheck = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_id = ? AND user_role = 'Admin'");
            $adminCheck->execute([$data['admin_id']]);
            if (!$adminCheck->fetch()) {
                return json_encode(["status" => "error", "message" => "Invalid admin_id: Admin user does not exist"]);
            }

            // Check if event type exists
            $eventTypeCheck = $this->conn->prepare("SELECT event_type_id FROM tbl_event_type WHERE event_type_id = ?");
            $eventTypeCheck->execute([$data['event_type_id']]);
            if (!$eventTypeCheck->fetch()) {
                return json_encode(["status" => "error", "message" => "Invalid event_type_id: Event type does not exist. Available types: 1=Wedding, 2=Anniversary, 3=Birthday, 4=Corporate, 5=Others"]);
            }

            // Check if package exists (if provided)
            if (!empty($data['package_id'])) {
                $packageCheck = $this->conn->prepare("SELECT package_id FROM tbl_packages WHERE package_id = ?");
                $packageCheck->execute([$data['package_id']]);
                if (!$packageCheck->fetch()) {
                    return json_encode(["status" => "error", "message" => "Invalid package_id: Package does not exist"]);
                }
            }

            // Check if venue exists (if provided)
            if (!empty($data['venue_id'])) {
                $venueCheck = $this->conn->prepare("SELECT venue_id FROM tbl_venue WHERE venue_id = ?");
                $venueCheck->execute([$data['venue_id']]);
                if (!$venueCheck->fetch()) {
                    return json_encode(["status" => "error", "message" => "Invalid venue_id: Venue does not exist"]);
                }
            }

            // Insert the main event
            $sql = "INSERT INTO tbl_events (
                        original_booking_reference, user_id, admin_id, organizer_id, event_title,
                        event_theme, event_description, event_type_id, guest_count, event_date, start_time, end_time,
                        package_id, venue_id, total_budget, down_payment, payment_method,
                        reference_number, additional_notes, event_status, payment_schedule_type_id,
                        is_recurring, recurrence_rule, client_signature, finalized_at, event_attachments
                    ) VALUES (
                        :original_booking_reference, :user_id, :admin_id, :organizer_id, :event_title,
                        :event_theme, :event_description, :event_type_id, :guest_count, :event_date, :start_time, :end_time,
                        :package_id, :venue_id, :total_budget, :down_payment, :payment_method,
                        :reference_number, :additional_notes, :event_status, :payment_schedule_type_id,
                        :is_recurring, :recurrence_rule, :client_signature, :finalized_at, :event_attachments
                    )";

            $stmt = $this->conn->prepare($sql);

            $eventParams = [
                ':original_booking_reference' => $data['original_booking_reference'] ?? null,
                ':user_id' => $data['user_id'],
                ':admin_id' => $data['admin_id'],
                ':organizer_id' => $data['organizer_id'] ?? null,
                ':event_title' => $data['event_title'],
                ':event_theme' => $data['event_theme'] ?? null,
                ':event_description' => $data['event_description'] ?? null,
                ':event_type_id' => $data['event_type_id'],
                ':guest_count' => $data['guest_count'],
                ':event_date' => $data['event_date'],
                ':start_time' => $data['start_time'] ?? '10:00:00',
                ':end_time' => $data['end_time'] ?? '18:00:00',
                ':package_id' => $data['package_id'] ?? null,
                ':venue_id' => $data['venue_id'] ?? null,
                ':total_budget' => $data['total_budget'] ?? 0,
                ':down_payment' => $data['down_payment'] ?? 0,
                ':payment_method' => $data['payment_method'] ?? null,
                ':reference_number' => $data['reference_number'] ?? null,
                ':additional_notes' => $data['additional_notes'] ?? null,
                ':event_status' => $data['event_status'] ?? 'draft',
                ':payment_schedule_type_id' => $data['payment_schedule_type_id'] ?? 2,
                ':is_recurring' => $data['is_recurring'] ?? false,
                ':recurrence_rule' => $data['recurrence_rule'] ?? null,
                ':client_signature' => $data['client_signature'] ?? null,
                ':finalized_at' => $data['finalized_at'] ?? null,
                ':event_attachments' => $data['event_attachments'] ?? null
            ];

            error_log("createEvent SQL params: " . json_encode($eventParams));
            $stmt->execute($eventParams);

            $eventId = $this->conn->lastInsertId();
            error_log("createEvent: Event created with ID: $eventId");

            // Insert event components if provided
            if (!empty($data['components']) && is_array($data['components'])) {
                foreach ($data['components'] as $index => $component) {
                    // Validate component
                    if (empty($component['component_name'])) {
                        continue; // Skip invalid components
                    }

                    $sql = "INSERT INTO tbl_event_components (
                                event_id, component_name, component_description,
                                component_price, is_custom, is_included,
                                original_package_component_id, display_order
                            ) VALUES (
                                :event_id, :name, :description,
                                :price, :is_custom, :is_included,
                                :original_package_component_id, :display_order
                            )";

                    $stmt = $this->conn->prepare($sql);
                    $stmt->execute([
                        ':event_id' => $eventId,
                        ':name' => $component['component_name'],
                        ':description' => $component['component_description'] ?? null,
                        ':price' => $component['component_price'] ?? 0,
                        ':is_custom' => $component['is_custom'] ?? false,
                        ':is_included' => $component['is_included'] ?? true,
                        ':original_package_component_id' => $component['original_package_component_id'] ?? null,
                        ':display_order' => $index
                    ]);
                }
            }

            // Insert timeline items if provided
            if (!empty($data['timeline']) && is_array($data['timeline'])) {
                foreach ($data['timeline'] as $index => $item) {
                    // Validate timeline item
                    if (empty($item['activity_title']) || empty($item['activity_date']) || empty($item['start_time'])) {
                        continue; // Skip invalid timeline items
                    }

                    $sql = "INSERT INTO tbl_event_timeline (
                                event_id, component_id, activity_title,
                                activity_date, start_time, end_time,
                                location, notes, assigned_to,
                                status, display_order
                            ) VALUES (
                                :event_id, :component_id, :title,
                                :date, :start_time, :end_time,
                                :location, :notes, :assigned_to,
                                :status, :display_order
                            )";

                    $stmt = $this->conn->prepare($sql);
                    $stmt->execute([
                        ':event_id' => $eventId,
                        ':component_id' => $item['component_id'] ?? null,
                        ':title' => $item['activity_title'],
                        ':date' => $item['activity_date'],
                        ':start_time' => $item['start_time'],
                        ':end_time' => $item['end_time'] ?? null,
                        ':location' => $item['location'] ?? null,
                        ':notes' => $item['notes'] ?? null,
                        ':assigned_to' => $item['assigned_to'] ?? null,
                        ':status' => $item['status'] ?? 'pending',
                        ':display_order' => $index
                    ]);
                }
            }

            // Create initial payment record if down payment is specified
            error_log("createEvent: Checking payment data - down_payment: " . ($data['down_payment'] ?? 'null') . ", payment_method: " . ($data['payment_method'] ?? 'null'));

            if (!empty($data['down_payment']) && $data['down_payment'] > 0) {
                error_log("createEvent: Creating payment record with amount: " . $data['down_payment']);

                $paymentSql = "INSERT INTO tbl_payments (
                    event_id, client_id, payment_method, payment_amount,
                    payment_notes, payment_status, payment_date, payment_reference
                ) VALUES (
                    :event_id, :client_id, :payment_method, :payment_amount,
                    :payment_notes, :payment_status, :payment_date, :payment_reference
                )";

                $paymentStmt = $this->conn->prepare($paymentSql);
                $paymentParams = [
                    ':event_id' => $eventId,
                    ':client_id' => $data['user_id'],
                    ':payment_method' => $data['payment_method'] ?? 'cash',
                    ':payment_amount' => floatval($data['down_payment']), // Ensure it's a number
                    ':payment_notes' => 'Initial down payment for event creation',
                    ':payment_status' => 'completed', // Always mark down payments as completed since they're being processed during event creation
                    ':payment_date' => date('Y-m-d'),
                    ':payment_reference' => $data['reference_number'] ?? null
                ];

                error_log("createEvent: Payment params: " . json_encode($paymentParams));
                $paymentStmt->execute($paymentParams);

                $paymentId = $this->conn->lastInsertId();
                error_log("createEvent: Payment created with ID: " . $paymentId);

                // Handle payment attachments if any were uploaded
                if (!empty($data['payment_attachments']) && is_array($data['payment_attachments'])) {
                    error_log("createEvent: Processing " . count($data['payment_attachments']) . " payment attachments");
                    $attachments = [];
                    foreach ($data['payment_attachments'] as $attachment) {
                        if (isset($attachment['file_path']) && isset($attachment['original_name'])) {
                            $attachments[] = [
                                'file_name' => basename($attachment['file_path']),
                                'original_name' => $attachment['original_name'],
                                'file_path' => $attachment['file_path'],
                                'file_size' => $attachment['file_size'] ?? 0,
                                'file_type' => $attachment['file_type'] ?? 'application/octet-stream',
                                'description' => $attachment['description'] ?? 'Payment proof for down payment',
                                'proof_type' => $attachment['proof_type'] ?? 'receipt',
                                'uploaded_at' => date('Y-m-d H:i:s'),
                            ];
                        }
                    }

                    if (!empty($attachments)) {
                        $updateAttachmentsSql = "UPDATE tbl_payments SET payment_attachments = ? WHERE payment_id = ?";
                        $updateAttachmentsStmt = $this->conn->prepare($updateAttachmentsSql);
                        $updateAttachmentsStmt->execute([json_encode($attachments), $paymentId]);
                        error_log("createEvent: Payment attachments saved: " . count($attachments) . " files");
                    }
                } else {
                    error_log("createEvent: No payment attachments provided");
                }
            } else {
                error_log("createEvent: No payment record created - down_payment is empty or zero");
            }

            // If this event was created from a booking, mark the booking as converted
            if (!empty($data['original_booking_reference'])) {
                $bookingConvertSql = "UPDATE tbl_bookings
                                     SET booking_status = 'converted', updated_at = NOW()
                                     WHERE booking_reference = :booking_reference";
                $bookingStmt = $this->conn->prepare($bookingConvertSql);
                $bookingStmt->execute([':booking_reference' => $data['original_booking_reference']]);
            }

            $this->conn->commit();
            error_log("createEvent: Transaction committed successfully");

            return json_encode([
                "status" => "success",
                "message" => "Event created successfully",
                "event_id" => $eventId
            ]);

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("createEvent error: " . $e->getMessage());
            error_log("createEvent stack trace: " . $e->getTraceAsString());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    // Add other essential methods that might be needed
    public function getClients() {
        try {
            $sql = "SELECT
                        u.user_id,
                        u.user_firstName,
                        u.user_lastName,
                        u.user_email,
                        u.user_contact,
                        u.user_pfp,
                        u.created_at as registration_date,
                        COUNT(DISTINCT e.event_id) as total_events,
                        COUNT(DISTINCT b.booking_id) as total_bookings,
                        COALESCE(SUM(p.payment_amount), 0) as total_payments,
                        MAX(e.event_date) as last_event_date
                    FROM tbl_users u
                    LEFT JOIN tbl_events e ON u.user_id = e.user_id
                    LEFT JOIN tbl_bookings b ON u.user_id = b.user_id
                    LEFT JOIN tbl_payments p ON e.event_id = p.event_id AND p.payment_status = 'completed'
                    WHERE u.user_role = 'client'
                    GROUP BY u.user_id
                    ORDER BY u.user_firstName, u.user_lastName";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();

            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "clients" => $clients
            ]);
        } catch (Exception $e) {
            error_log("getClients error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getAvailableBookings() {
        try {
            $sql = "SELECT
                        b.booking_id,
                        b.booking_reference,
                        b.user_id,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                        u.user_email as client_email,
                        u.user_contact as client_phone,
                        b.event_type_id,
                        et.event_name as event_type_name,
                        b.event_name,
                        b.event_date,
                        b.event_time,
                        b.guest_count,
                        b.venue_id,
                        v.venue_title as venue_name,
                        b.package_id,
                        p.package_title as package_name,
                        b.notes,
                        b.booking_status,
                        b.created_at
                    FROM tbl_bookings b
                    JOIN tbl_users u ON b.user_id = u.user_id
                    JOIN tbl_event_type et ON b.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON b.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON b.package_id = p.package_id
                    LEFT JOIN tbl_events e ON b.booking_reference = e.original_booking_reference
                    WHERE b.booking_status = 'confirmed'
                    AND e.event_id IS NULL
                    ORDER BY b.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "bookings" => $bookings
            ]);
        } catch (Exception $e) {
            error_log("getAvailableBookings error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getBookingByReference($reference) {
        try {
            $sql = "SELECT b.*,
                        u.user_id, u.user_firstName, u.user_lastName, u.user_email, u.user_contact,
                        et.event_name as event_type_name,
                        v.venue_title as venue_name,
                        p.package_title as package_name,
                        CASE WHEN e.event_id IS NOT NULL THEN 1 ELSE 0 END as is_converted,
                        e.event_id as converted_event_id
                    FROM tbl_bookings b
                    JOIN tbl_users u ON b.user_id = u.user_id
                    JOIN tbl_event_type et ON b.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON b.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON b.package_id = p.package_id
                    LEFT JOIN tbl_events e ON b.booking_reference = e.original_booking_reference
                    WHERE b.booking_reference = :reference";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':reference' => $reference]);

            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($booking) {
                return json_encode(["status" => "success", "booking" => $booking]);
            } else {
                return json_encode(["status" => "error", "message" => "Booking not found"]);
            }
        } catch (Exception $e) {
            error_log("getBookingByReference error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    // Placeholder methods for missing functionality - these prevent fatal errors
    public function getAllVendors() { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function createPackage($data) {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            if (empty($data['package_title']) || empty($data['package_price']) || empty($data['guest_capacity']) || empty($data['created_by'])) {
                return json_encode(["status" => "error", "message" => "Package title, price, guest capacity, and creator are required"]);
            }

            // Insert main package
            $sql = "INSERT INTO tbl_packages (package_title, package_description, package_price, guest_capacity, created_by, is_active)
                    VALUES (:title, :description, :price, :capacity, :created_by, 1)";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':title' => $data['package_title'],
                ':description' => $data['package_description'] ?? '',
                ':price' => $data['package_price'],
                ':capacity' => $data['guest_capacity'],
                ':created_by' => $data['created_by']
            ]);

            $packageId = $this->conn->lastInsertId();

            // Insert components if provided
            if (!empty($data['components']) && is_array($data['components'])) {
                foreach ($data['components'] as $index => $component) {
                    if (!empty($component['component_name'])) {
                        $componentSql = "INSERT INTO tbl_package_components (package_id, component_name, component_description, component_price, display_order)
                                        VALUES (:package_id, :name, :description, :price, :order)";
                        $componentStmt = $this->conn->prepare($componentSql);
                        $componentStmt->execute([
                            ':package_id' => $packageId,
                            ':name' => $component['component_name'],
                            ':description' => $component['component_description'] ?? '',
                            ':price' => $component['component_price'] ?? 0,
                            ':order' => $index
                        ]);
                    }
                }
            }

            // Insert freebies if provided
            if (!empty($data['freebies']) && is_array($data['freebies'])) {
                foreach ($data['freebies'] as $index => $freebie) {
                    if (!empty($freebie['freebie_name'])) {
                        $freebieSql = "INSERT INTO tbl_package_freebies (package_id, freebie_name, freebie_description, freebie_value, display_order)
                                      VALUES (:package_id, :name, :description, :value, :order)";
                        $freebieStmt = $this->conn->prepare($freebieSql);
                        $freebieStmt->execute([
                            ':package_id' => $packageId,
                            ':name' => $freebie['freebie_name'],
                            ':description' => $freebie['freebie_description'] ?? '',
                            ':value' => $freebie['freebie_value'] ?? 0,
                            ':order' => $index
                        ]);
                    }
                }
            }

            // Insert event types if provided
            if (!empty($data['event_types']) && is_array($data['event_types'])) {
                foreach ($data['event_types'] as $eventTypeId) {
                    $eventTypeSql = "INSERT INTO tbl_package_event_types (package_id, event_type_id) VALUES (:package_id, :event_type_id)";
                    $eventTypeStmt = $this->conn->prepare($eventTypeSql);
                    $eventTypeStmt->execute([
                        ':package_id' => $packageId,
                        ':event_type_id' => $eventTypeId
                    ]);
                }
            }

            // Insert venues if provided
            if (!empty($data['venues']) && is_array($data['venues'])) {
                foreach ($data['venues'] as $venueId) {
                    $venueSql = "INSERT INTO tbl_package_venues (package_id, venue_id) VALUES (:package_id, :venue_id)";
                    $venueStmt = $this->conn->prepare($venueSql);
                    $venueStmt->execute([
                        ':package_id' => $packageId,
                        ':venue_id' => $venueId
                    ]);
                }
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Package created successfully",
                "package_id" => $packageId
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("createPackage error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getAllPackages() {
        try {
            $sql = "SELECT
                        p.package_id,
                        p.package_title,
                        p.package_description,
                        p.package_price,
                        p.guest_capacity,
                        p.created_at,
                        p.is_active,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as created_by_name,
                        u.user_firstName,
                        u.user_lastName,
                        COUNT(DISTINCT pc.component_id) as component_count,
                        COUNT(DISTINCT pf.freebie_id) as freebie_count,
                        COUNT(DISTINCT pv.venue_id) as venue_count
                    FROM tbl_packages p
                    LEFT JOIN tbl_users u ON p.created_by = u.user_id
                    LEFT JOIN tbl_package_components pc ON p.package_id = pc.package_id
                    LEFT JOIN tbl_package_freebies pf ON p.package_id = pf.package_id
                    LEFT JOIN tbl_package_venues pv ON p.package_id = pv.package_id
                    WHERE p.is_active = 1
                    GROUP BY p.package_id
                    ORDER BY p.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // For each package, get components and freebies
            foreach ($packages as &$package) {
                // Get components for inclusions preview
                $componentsSql = "SELECT component_name FROM tbl_package_components WHERE package_id = ? ORDER BY display_order LIMIT 5";
                $componentsStmt = $this->conn->prepare($componentsSql);
                $componentsStmt->execute([$package['package_id']]);
                $components = $componentsStmt->fetchAll(PDO::FETCH_COLUMN);
                $package['inclusions'] = $components;

                // Get freebies
                $freebiesSql = "SELECT freebie_name FROM tbl_package_freebies WHERE package_id = ? ORDER BY display_order LIMIT 5";
                $freebiesStmt = $this->conn->prepare($freebiesSql);
                $freebiesStmt->execute([$package['package_id']]);
                $freebies = $freebiesStmt->fetchAll(PDO::FETCH_COLUMN);
                $package['freebies'] = $freebies;
            }

            return json_encode([
                "status" => "success",
                "packages" => $packages,
                "count" => count($packages)
            ]);
        } catch (Exception $e) {
            error_log("getAllPackages error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getPackageById($packageId) {
        try {
            // Get package basic info
            $sql = "SELECT * FROM tbl_packages WHERE package_id = :package_id AND is_active = 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':package_id' => $packageId]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Get package components
            $componentsSql = "SELECT * FROM tbl_package_components WHERE package_id = :package_id ORDER BY display_order";
            $componentsStmt = $this->conn->prepare($componentsSql);
            $componentsStmt->execute([':package_id' => $packageId]);
            $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get package freebies
            $freebiesSql = "SELECT * FROM tbl_package_freebies WHERE package_id = :package_id ORDER BY display_order";
            $freebiesStmt = $this->conn->prepare($freebiesSql);
            $freebiesStmt->execute([':package_id' => $packageId]);
            $freebies = $freebiesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get package venues with their inclusions
            $venuesSql = "SELECT
                            v.venue_id,
                            v.venue_title,
                            v.venue_owner,
                            v.venue_location,
                            v.venue_contact,
                            v.venue_details,
                            v.venue_capacity,
                            v.venue_price,
                            v.venue_type,
                            v.venue_profile_picture,
                            v.venue_cover_photo
                        FROM tbl_package_venues pv
                        JOIN tbl_venue v ON pv.venue_id = v.venue_id
                        WHERE pv.package_id = :package_id AND v.venue_status = 'available'
                        ORDER BY v.venue_title";

            $venuesStmt = $this->conn->prepare($venuesSql);
            $venuesStmt->execute([':package_id' => $packageId]);
            $venues = $venuesStmt->fetchAll(PDO::FETCH_ASSOC);

            // For each venue, get its inclusions and components
            foreach ($venues as &$venue) {
                // Get venue inclusions
                $inclusionsSql = "SELECT * FROM tbl_venue_inclusions WHERE venue_id = :venue_id AND is_active = 1";
                $inclusionsStmt = $this->conn->prepare($inclusionsSql);
                $inclusionsStmt->execute([':venue_id' => $venue['venue_id']]);
                $inclusions = $inclusionsStmt->fetchAll(PDO::FETCH_ASSOC);

                // For each inclusion, get its components
                foreach ($inclusions as &$inclusion) {
                    $componentsSql = "SELECT * FROM tbl_venue_components WHERE inclusion_id = :inclusion_id AND is_active = 1";
                    $componentsStmt = $this->conn->prepare($componentsSql);
                    $componentsStmt->execute([':inclusion_id' => $inclusion['inclusion_id']]);
                    $inclusion['components'] = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
                }

                $venue['inclusions'] = $inclusions;
            }

            // Combine all data
            $packageData = [
                'package_id' => $package['package_id'],
                'package_title' => $package['package_title'],
                'package_description' => $package['package_description'],
                'package_price' => $package['package_price'],
                'guest_capacity' => $package['guest_capacity'],
                'is_active' => $package['is_active'],
                'components' => $components,
                'freebies' => $freebies,
                'venues' => $venues
            ];

            return json_encode([
                "status" => "success",
                "package" => $packageData
            ]);
        } catch (Exception $e) {
            error_log("getPackageById error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getPackageDetails($packageId) {
        try {
            // Get package basic info with creator information
            $sql = "SELECT p.*,
                           u.user_firstName, u.user_lastName,
                           CONCAT(u.user_firstName, ' ', u.user_lastName) as created_by_name
                    FROM tbl_packages p
                    LEFT JOIN tbl_users u ON p.created_by = u.user_id
                    WHERE p.package_id = :package_id AND p.is_active = 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':package_id' => $packageId]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Get package components/inclusions
            $componentsSql = "SELECT * FROM tbl_package_components WHERE package_id = :package_id ORDER BY display_order";
            $componentsStmt = $this->conn->prepare($componentsSql);
            $componentsStmt->execute([':package_id' => $packageId]);
            $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Transform components into the expected structure for detailed view
            $inclusions = [];
            foreach ($components as $component) {
                $inclusions[] = [
                    'name' => $component['component_name'],
                    'price' => (float)$component['component_price'],
                    'components' => [], // For now, components don't have sub-components in this structure
                ];
            }

            // Get package freebies
            $freebiesSql = "SELECT * FROM tbl_package_freebies WHERE package_id = :package_id ORDER BY display_order";
            $freebiesStmt = $this->conn->prepare($freebiesSql);
            $freebiesStmt->execute([':package_id' => $packageId]);
            $freebiesData = $freebiesStmt->fetchAll(PDO::FETCH_ASSOC);

            $freebies = [];
            foreach ($freebiesData as $freebie) {
                $freebies[] = $freebie['freebie_name'];
            }

            // Get package venues with their inclusions
            $venuesSql = "SELECT
                            v.venue_id,
                            v.venue_title,
                            v.venue_owner,
                            v.venue_location,
                            v.venue_contact,
                            v.venue_details,
                            v.venue_capacity,
                            v.venue_price as total_price,
                            v.venue_type,
                            v.venue_profile_picture,
                            v.venue_cover_photo
                        FROM tbl_package_venues pv
                        JOIN tbl_venue v ON pv.venue_id = v.venue_id
                        WHERE pv.package_id = :package_id AND v.venue_status = 'available'
                        ORDER BY v.venue_title";

            $venuesStmt = $this->conn->prepare($venuesSql);
            $venuesStmt->execute([':package_id' => $packageId]);
            $venues = $venuesStmt->fetchAll(PDO::FETCH_ASSOC);

            // For each venue, get its inclusions and components
            foreach ($venues as &$venue) {
                // Get venue inclusions
                $inclusionsSql = "SELECT * FROM tbl_venue_inclusions WHERE venue_id = :venue_id AND is_active = 1";
                $inclusionsStmt = $this->conn->prepare($inclusionsSql);
                $inclusionsStmt->execute([':venue_id' => $venue['venue_id']]);
                $venueInclusions = $inclusionsStmt->fetchAll(PDO::FETCH_ASSOC);

                // For each inclusion, get its components
                foreach ($venueInclusions as &$inclusion) {
                    $componentsSql = "SELECT * FROM tbl_venue_components WHERE inclusion_id = :inclusion_id AND is_active = 1";
                    $componentsStmt = $this->conn->prepare($componentsSql);
                    $componentsStmt->execute([':inclusion_id' => $inclusion['inclusion_id']]);
                    $inclusion['components'] = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
                }

                $venue['inclusions'] = $venueInclusions;
                $venue['total_price'] = (float)$venue['total_price'];
            }

            // Get event types associated with this package
            $eventTypesSql = "SELECT et.*
                             FROM tbl_package_event_types pet
                             JOIN tbl_event_type et ON pet.event_type_id = et.event_type_id
                             WHERE pet.package_id = :package_id";
            $eventTypesStmt = $this->conn->prepare($eventTypesSql);
            $eventTypesStmt->execute([':package_id' => $packageId]);
            $eventTypes = $eventTypesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Combine all data
            $packageData = [
                'package_id' => (int)$package['package_id'],
                'package_title' => $package['package_title'],
                'package_description' => $package['package_description'],
                'package_price' => (float)$package['package_price'],
                'guest_capacity' => (int)$package['guest_capacity'],
                'created_at' => $package['created_at'],
                'user_firstName' => $package['user_firstName'],
                'user_lastName' => $package['user_lastName'],
                'created_by_name' => $package['created_by_name'],
                'is_active' => (int)$package['is_active'],
                'inclusions' => $inclusions,
                'freebies' => $freebies,
                'venues' => $venues,
                'event_types' => $eventTypes
            ];

            return json_encode([
                "status" => "success",
                "package" => $packageData
            ]);
        } catch (Exception $e) {
            error_log("getPackageDetails error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function updatePackage($data) {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            if (empty($data['package_id']) || empty($data['package_title']) || empty($data['package_price']) || empty($data['guest_capacity'])) {
                return json_encode(["status" => "error", "message" => "Package ID, title, price, and guest capacity are required"]);
            }

            // Get current package data to check price lock status
            $currentPackageSql = "SELECT package_price, original_price, is_price_locked FROM tbl_packages WHERE package_id = :package_id";
            $currentStmt = $this->conn->prepare($currentPackageSql);
            $currentStmt->execute([':package_id' => $data['package_id']]);
            $currentPackage = $currentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentPackage) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Enforce non-decreasing price rule
            $newPrice = floatval($data['package_price']);
            $currentPrice = floatval($currentPackage['package_price']);
            $isLocked = intval($currentPackage['is_price_locked']);

            if ($isLocked && $newPrice < $currentPrice) {
                return json_encode([
                    "status" => "error",
                    "message" => "Cannot reduce package price. Package prices are locked and can only increase or remain the same.",
                    "current_price" => $currentPrice,
                    "attempted_price" => $newPrice
                ]);
            }

            // Check for overage warnings if components are being updated
            if (isset($data['components'])) {
                $totalComponentCost = 0;
                foreach ($data['components'] as $component) {
                    $totalComponentCost += floatval($component['component_price'] ?? 0);
                }

                if ($totalComponentCost > $newPrice) {
                    $overage = $totalComponentCost - $newPrice;
                    if (!isset($data['confirm_overage']) || !$data['confirm_overage']) {
                        return json_encode([
                            "status" => "warning",
                            "message" => "Budget overage detected: Inclusions total exceeds package price",
                            "package_price" => $newPrice,
                            "inclusions_total" => $totalComponentCost,
                            "overage_amount" => $overage,
                            "requires_confirmation" => true
                        ]);
                    }
                }
            }

            // Update main package
            $sql = "UPDATE tbl_packages SET
                        package_title = :title,
                        package_description = :description,
                        package_price = :price,
                        guest_capacity = :capacity,
                        is_price_locked = 1,
                        price_lock_date = CASE
                            WHEN is_price_locked = 0 THEN CURRENT_TIMESTAMP
                            ELSE price_lock_date
                        END,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE package_id = :package_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':title' => $data['package_title'],
                ':description' => $data['package_description'] ?? '',
                ':price' => $newPrice,
                ':capacity' => $data['guest_capacity'],
                ':package_id' => $data['package_id']
            ]);

            // Update components - delete existing and insert new ones
            if (isset($data['components'])) {
                // Delete existing components
                $deleteComponentsSql = "DELETE FROM tbl_package_components WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteComponentsSql);
                $deleteStmt->execute([':package_id' => $data['package_id']]);

                // Insert new components
                if (is_array($data['components'])) {
                    foreach ($data['components'] as $index => $component) {
                        if (!empty($component['component_name'])) {
                            $componentSql = "INSERT INTO tbl_package_components (package_id, component_name, component_description, component_price, display_order)
                                            VALUES (:package_id, :name, :description, :price, :order)";
                            $componentStmt = $this->conn->prepare($componentSql);
                            $componentStmt->execute([
                                ':package_id' => $data['package_id'],
                                ':name' => $component['component_name'],
                                ':description' => $component['component_description'] ?? '',
                                ':price' => $component['component_price'] ?? 0,
                                ':order' => $index
                            ]);
                        }
                    }
                }
            }

            // Update freebies - delete existing and insert new ones
            if (isset($data['freebies'])) {
                // Delete existing freebies
                $deleteFreebiesSql = "DELETE FROM tbl_package_freebies WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteFreebiesSql);
                $deleteStmt->execute([':package_id' => $data['package_id']]);

                // Insert new freebies
                if (is_array($data['freebies'])) {
                    foreach ($data['freebies'] as $index => $freebie) {
                        if (!empty($freebie['freebie_name'])) {
                            $freebieSql = "INSERT INTO tbl_package_freebies (package_id, freebie_name, freebie_description, freebie_value, display_order)
                                          VALUES (:package_id, :name, :description, :value, :order)";
                            $freebieStmt = $this->conn->prepare($freebieSql);
                            $freebieStmt->execute([
                                ':package_id' => $data['package_id'],
                                ':name' => $freebie['freebie_name'],
                                ':description' => $freebie['freebie_description'] ?? '',
                                ':value' => $freebie['freebie_value'] ?? 0,
                                ':order' => $index
                            ]);
                        }
                    }
                }
            }

            // Update event types
            if (isset($data['event_types'])) {
                // Delete existing event types
                $deleteEventTypesSql = "DELETE FROM tbl_package_event_types WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteEventTypesSql);
                $deleteStmt->execute([':package_id' => $data['package_id']]);

                // Insert new event types
                if (is_array($data['event_types'])) {
                    foreach ($data['event_types'] as $eventTypeId) {
                        $eventTypeSql = "INSERT INTO tbl_package_event_types (package_id, event_type_id) VALUES (:package_id, :event_type_id)";
                        $eventTypeStmt = $this->conn->prepare($eventTypeSql);
                        $eventTypeStmt->execute([
                            ':package_id' => $data['package_id'],
                            ':event_type_id' => $eventTypeId
                        ]);
                    }
                }
            }

            // Update venues
            if (isset($data['venues'])) {
                // Delete existing venues
                $deleteVenuesSql = "DELETE FROM tbl_package_venues WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteVenuesSql);
                $deleteStmt->execute([':package_id' => $data['package_id']]);

                // Insert new venues
                if (is_array($data['venues'])) {
                    foreach ($data['venues'] as $venueId) {
                        $venueSql = "INSERT INTO tbl_package_venues (package_id, venue_id) VALUES (:package_id, :venue_id)";
                        $venueStmt = $this->conn->prepare($venueSql);
                        $venueStmt->execute([
                            ':package_id' => $data['package_id'],
                            ':venue_id' => $venueId
                        ]);
                    }
                }
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Package updated successfully"
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("updatePackage error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function deletePackage($packageId) {
        try {
            // Check if package exists
            $checkSql = "SELECT package_id FROM tbl_packages WHERE package_id = :package_id";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute([':package_id' => $packageId]);

            if (!$checkStmt->fetch()) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Check if package is being used in any events
            $eventCheckSql = "SELECT COUNT(*) as event_count FROM tbl_events WHERE package_id = :package_id";
            $eventCheckStmt = $this->conn->prepare($eventCheckSql);
            $eventCheckStmt->execute([':package_id' => $packageId]);
            $eventCount = $eventCheckStmt->fetch(PDO::FETCH_ASSOC)['event_count'];

            if ($eventCount > 0) {
                // Soft delete - mark as inactive instead of hard delete
                $softDeleteSql = "UPDATE tbl_packages SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE package_id = :package_id";
                $softDeleteStmt = $this->conn->prepare($softDeleteSql);
                $softDeleteStmt->execute([':package_id' => $packageId]);

                return json_encode([
                    "status" => "success",
                    "message" => "Package deactivated successfully (it was being used in events)"
                ]);
            } else {
                // Hard delete - remove completely
                $this->conn->beginTransaction();

                // Delete related records first
                $deleteComponentsSql = "DELETE FROM tbl_package_components WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteComponentsSql);
                $deleteStmt->execute([':package_id' => $packageId]);

                $deleteFreebiesSql = "DELETE FROM tbl_package_freebies WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteFreebiesSql);
                $deleteStmt->execute([':package_id' => $packageId]);

                $deleteEventTypesSql = "DELETE FROM tbl_package_event_types WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteEventTypesSql);
                $deleteStmt->execute([':package_id' => $packageId]);

                $deleteVenuesSql = "DELETE FROM tbl_package_venues WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteVenuesSql);
                $deleteStmt->execute([':package_id' => $packageId]);

                // Delete the main package
                $deleteSql = "DELETE FROM tbl_packages WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteSql);
                $deleteStmt->execute([':package_id' => $packageId]);

                $this->conn->commit();

                return json_encode([
                    "status" => "success",
                    "message" => "Package deleted successfully"
                ]);
            }
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("deletePackage error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getEventTypes() {
        try {
            $sql = "SELECT event_type_id, event_name, event_description FROM tbl_event_type ORDER BY event_name";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $eventTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return json_encode(["status" => "success", "event_types" => $eventTypes]);
        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getPackagesByEventType($eventTypeId) {
        try {
            error_log("getPackagesByEventType called with eventTypeId: " . $eventTypeId);

            // Enhanced query to get packages with venue and component information
            // If no specific event type relationships exist, return all active packages
            $sql = "SELECT DISTINCT p.package_id,
                        p.package_title,
                        p.package_description,
                        p.package_price,
                        p.guest_capacity,
                        p.created_at,
                        p.is_active,
                        COUNT(DISTINCT pc.component_id) as component_count,
                        COUNT(DISTINCT pf.freebie_id) as freebie_count,
                        COUNT(DISTINCT pv.venue_id) as venue_count
                    FROM tbl_packages p
                    LEFT JOIN tbl_package_event_types pet ON p.package_id = pet.package_id
                    LEFT JOIN tbl_package_components pc ON p.package_id = pc.package_id
                    LEFT JOIN tbl_package_freebies pf ON p.package_id = pf.package_id
                    LEFT JOIN tbl_package_venues pv ON p.package_id = pv.package_id
                    WHERE p.is_active = 1
                    AND (
                        pet.event_type_id = ?
                        OR pet.event_type_id IS NULL
                        OR NOT EXISTS (SELECT 1 FROM tbl_package_event_types WHERE package_id = p.package_id)
                    )
                    GROUP BY p.package_id, p.package_title, p.package_description, p.package_price, p.guest_capacity, p.created_at, p.is_active
                    ORDER BY p.package_title";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$eventTypeId]);
            $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Enhance each package with detailed information
            foreach ($packages as &$package) {
                $packageId = $package['package_id'];

                // Get component names for inclusions preview
                $componentsSql = "SELECT component_name FROM tbl_package_components WHERE package_id = ? ORDER BY display_order LIMIT 10";
                $componentsStmt = $this->conn->prepare($componentsSql);
                $componentsStmt->execute([$packageId]);
                $componentNames = $componentsStmt->fetchAll(PDO::FETCH_COLUMN);
                $package['inclusions'] = $componentNames;

                // Get freebie names
                $freebiesSql = "SELECT freebie_name FROM tbl_package_freebies WHERE package_id = ? ORDER BY display_order LIMIT 10";
                $freebiesStmt = $this->conn->prepare($freebiesSql);
                $freebiesStmt->execute([$packageId]);
                $freebieNames = $freebiesStmt->fetchAll(PDO::FETCH_COLUMN);
                $package['freebies'] = $freebieNames;

                // Get venue previews with pricing
                $venuesSql = "SELECT v.venue_id, v.venue_title, v.venue_profile_picture, v.venue_price,
                                    COALESCE(SUM(vi.inclusion_price), 0) as inclusions_total
                             FROM tbl_package_venues pv
                             JOIN tbl_venue v ON pv.venue_id = v.venue_id
                             LEFT JOIN tbl_venue_inclusions vi ON v.venue_id = vi.venue_id AND vi.is_active = 1
                             WHERE pv.package_id = ? AND v.venue_status = 'available'
                             GROUP BY v.venue_id, v.venue_title, v.venue_profile_picture, v.venue_price
                             ORDER BY v.venue_title";
                $venuesStmt = $this->conn->prepare($venuesSql);
                $venuesStmt->execute([$packageId]);
                $venues = $venuesStmt->fetchAll(PDO::FETCH_ASSOC);

                $package['venue_previews'] = [];
                $venuePrices = [];

                foreach ($venues as $venue) {
                    // Add to previews
                    $package['venue_previews'][] = [
                        'venue_id' => $venue['venue_id'],
                        'venue_title' => $venue['venue_title'],
                        'venue_profile_picture' => $venue['venue_profile_picture']
                    ];

                    // Calculate total venue price (base + inclusions)
                    $totalVenuePrice = floatval($venue['venue_price']) + floatval($venue['inclusions_total']);
                    $venuePrices[] = $totalVenuePrice;
                }

                // Calculate price ranges
                if (!empty($venuePrices)) {
                    $minVenuePrice = min($venuePrices);
                    $maxVenuePrice = max($venuePrices);
                    $packagePrice = floatval($package['package_price']);

                    $package['venue_price_range'] = [
                        'min' => $minVenuePrice,
                        'max' => $maxVenuePrice,
                        'venues' => array_map(function($venue) use ($packageId) {
                            return [
                                'venue_id' => $venue['venue_id'],
                                'venue_title' => $venue['venue_title'],
                                'venue_price' => $venue['venue_price'],
                                'inclusions_total' => $venue['inclusions_total'],
                                'total_venue_price' => strval(floatval($venue['venue_price']) + floatval($venue['inclusions_total']))
                            ];
                        }, $venues)
                    ];

                    $package['total_price_range'] = [
                        'min' => $packagePrice + $minVenuePrice,
                        'max' => $packagePrice + $maxVenuePrice
                    ];
                } else {
                    $package['venue_price_range'] = null;
                    $package['total_price_range'] = null;
                }
            }

            error_log("Returning " . count($packages) . " enhanced packages for eventTypeId {$eventTypeId}");

            return json_encode([
                "status" => "success",
                "packages" => $packages
            ]);
        } catch (Exception $e) {
            error_log("getPackagesByEventType error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getAllEvents() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    e.*,
                    u.user_firstName,
                    u.user_lastName,
                    u.user_email,
                    et.event_name as event_type_name,
                    p.package_title,
                    v.venue_title
                FROM tbl_events e
                LEFT JOIN tbl_users u ON e.user_id = u.user_id
                LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                ORDER BY e.created_at DESC
            ");
            $stmt->execute();
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "events" => $events
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch events: " . $e->getMessage()
            ]);
        }
    }
    public function getEvents($adminId) {
        try {
            $sql = "SELECT
                        e.*,
                        CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                        c.user_firstName as client_first_name,
                        c.user_lastName as client_last_name,
                        c.user_suffix as client_suffix,
                        c.user_email as client_email,
                        c.user_contact as client_contact,
                        c.user_pfp as client_pfp,
                        c.user_birthdate as client_birthdate,
                        c.created_at as client_joined_date,
                        CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                        CONCAT(o.user_firstName, ' ', o.user_lastName) as organizer_name,
                        et.event_name as event_type_name,
                        v.venue_title as venue_name,
                        v.venue_location as venue_location,
                        p.package_title as package_title
                    FROM tbl_events e
                    LEFT JOIN tbl_users c ON e.user_id = c.user_id
                    LEFT JOIN tbl_users a ON e.admin_id = a.user_id
                    LEFT JOIN tbl_users o ON e.organizer_id = o.user_id
                    LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                    WHERE e.admin_id = ?
                    ORDER BY e.event_date ASC, e.start_time ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$adminId]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "events" => $events,
                "count" => count($events)
            ]);
        } catch (Exception $e) {
            error_log("getEvents error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getClientEvents($userId) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function checkEventConflicts($eventDate, $startTime, $endTime, $excludeEventId = null) {
        try {
            $sql = "
                SELECT
                    e.event_id,
                    e.event_title,
                    e.event_date,
                    e.start_time,
                    e.end_time,
                    CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                    COALESCE(v.venue_title, 'TBD') as venue_name
                FROM tbl_events e
                LEFT JOIN tbl_users c ON e.user_id = c.user_id
                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
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

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format conflicts for frontend
            $formattedConflicts = [];
            foreach ($conflicts as $conflict) {
                $formattedConflicts[] = [
                    'event_id' => (int)$conflict['event_id'],
                    'event_title' => $conflict['event_title'],
                    'event_date' => $conflict['event_date'],
                    'start_time' => $conflict['start_time'],
                    'end_time' => $conflict['end_time'],
                    'client_name' => $conflict['client_name'] ?: 'Unknown Client',
                    'venue_name' => $conflict['venue_name'] ?: 'TBD'
                ];
            }

            $response = [
                'hasConflicts' => count($formattedConflicts) > 0,
                'conflicts' => $formattedConflicts,
                'totalConflicts' => count($formattedConflicts),
                'checkDate' => $eventDate,
                'checkStartTime' => $startTime,
                'checkEndTime' => $endTime
            ];

            return json_encode([
                "status" => "success",
                "hasConflicts" => $response['hasConflicts'],
                "conflicts" => $response['conflicts']
            ]);
        } catch (Exception $e) {
            error_log("checkEventConflicts error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Failed to check event conflicts: " . $e->getMessage(),
                "hasConflicts" => false,
                "conflicts" => []
            ]);
        }
    }
        public function getEventById($eventId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    e.*,
                    CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                    c.user_firstName as client_first_name,
                    c.user_lastName as client_last_name,
                    c.user_suffix as client_suffix,
                    c.user_email as client_email,
                    c.user_contact as client_contact,
                    c.user_pfp as client_pfp,
                    c.user_birthdate as client_birthdate,
                    c.created_at as client_joined_date,
                    c.user_username as client_username,
                    CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                    CONCAT(org.user_firstName, ' ', org.user_lastName) as organizer_name,
                    et.event_name as event_type_name,
                    et.event_description as event_type_description,
                    p.package_title,
                    p.package_description,
                    v.venue_title,
                    v.venue_location,
                    v.venue_contact,
                    v.venue_capacity,
                    v.venue_price,
                    pst.schedule_name as payment_schedule_name,
                    pst.schedule_description as payment_schedule_description,
                    pst.installment_count
                FROM tbl_events e
                LEFT JOIN tbl_users c ON e.user_id = c.user_id
                LEFT JOIN tbl_users a ON e.admin_id = a.user_id
                LEFT JOIN tbl_users org ON e.organizer_id = org.user_id
                LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                LEFT JOIN tbl_payment_schedule_types pst ON e.payment_schedule_type_id = pst.schedule_type_id
                WHERE e.event_id = ?
            ");
                        $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($event) {
                // Parse event_attachments JSON field
                if (!empty($event['event_attachments'])) {
                    $event['attachments'] = json_decode($event['event_attachments'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $event['attachments'] = [];
                        error_log("JSON decode error for event attachments: " . json_last_error_msg());
                    }
                } else {
                    $event['attachments'] = [];
                }

                // For testing: Add sample attachments if event has none (only for event 28)
                if ($eventId == 28 && empty($event['attachments'])) {
                    $event['attachments'] = [
                        [
                            'file_name' => 'wedding_contract_2025.pdf',
                            'original_name' => 'Wedding Contract - Laurenz & Partner.pdf',
                            'file_path' => 'uploads/event_attachments/sample_contract.pdf',
                            'file_size' => 245760,
                            'file_type' => 'application/pdf',
                            'upload_date' => '2025-06-25 10:30:00',
                            'description' => 'Signed wedding contract and terms'
                        ],
                        [
                            'file_name' => 'venue_layout_plan.jpg',
                            'original_name' => 'Pearlmont Hotel Layout Plan.jpg',
                            'file_path' => 'uploads/event_attachments/venue_layout.jpg',
                            'file_size' => 512000,
                            'file_type' => 'image/jpeg',
                            'upload_date' => '2025-06-25 11:15:00',
                            'description' => 'Venue seating arrangement and layout'
                        ],
                        [
                            'file_name' => 'menu_preferences.docx',
                            'original_name' => 'Catering Menu Selections.docx',
                            'file_path' => 'uploads/event_attachments/menu_selections.docx',
                            'file_size' => 87040,
                            'file_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'upload_date' => '2025-06-25 14:45:00',
                            'description' => 'Detailed menu preferences and dietary requirements'
                        ]
                    ];

                    error_log("Added sample attachments for event 28");
                }

                // Debug client profile picture
                error_log("Event ID: $eventId, Client PFP: " . ($event['client_pfp'] ?? 'NULL'));

                // Get event components
                $stmt = $this->pdo->prepare("
                    SELECT * FROM tbl_event_components
                    WHERE event_id = ?
                    ORDER BY display_order
                ");
                $stmt->execute([$eventId]);
                $event['components'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get event timeline
                $stmt = $this->pdo->prepare("
                    SELECT * FROM tbl_event_timeline
                    WHERE event_id = ?
                    ORDER BY display_order
                ");
                $stmt->execute([$eventId]);
                $event['timeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get payment history
                $stmt = $this->pdo->prepare("
                    SELECT * FROM tbl_payments
                    WHERE event_id = ?
                    ORDER BY payment_date DESC, created_at DESC
                ");
                $stmt->execute([$eventId]);
                $event['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get payment proofs/attachments
                $stmt = $this->pdo->prepare("
                    SELECT
                        payment_id,
                        payment_amount,
                        payment_date,
                        payment_method,
                        payment_status,
                        payment_reference,
                        payment_notes as description,
                        created_at
                    FROM tbl_payments
                    WHERE event_id = ? AND payment_reference IS NOT NULL
                    ORDER BY payment_date DESC
                ");
                $stmt->execute([$eventId]);
                $paymentProofs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Add payment proofs to attachments if they exist
                foreach ($paymentProofs as $proof) {
                    if (!empty($proof['payment_reference'])) {
                        $event['attachments'][] = [
                            'file_name' => "Payment Proof - " . $proof['payment_reference'],
                            'file_path' => $proof['payment_reference'],
                            'file_type' => 'payment_proof',
                            'upload_date' => $proof['payment_date'],
                            'file_size' => null,
                            'description' => $proof['description'] ?? 'Payment proof document'
                        ];
                    }
                }

                return json_encode([
                    "status" => "success",
                    "event" => $event
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Event not found"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch event: " . $e->getMessage()
            ]);
        }
    }

    public function uploadEventAttachment($eventId, $file, $description = '') {
        try {
            $uploadDir = 'uploads/event_attachments/';

            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileName = time() . '_' . $file['name'];
            $filePath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Get current attachments
                $stmt = $this->pdo->prepare("SELECT event_attachments FROM tbl_events WHERE event_id = ?");
                $stmt->execute([$eventId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                $attachments = [];
                if (!empty($result['event_attachments'])) {
                    $attachments = json_decode($result['event_attachments'], true) ?: [];
                }

                // Add new attachment
                $newAttachment = [
                    'file_name' => $fileName,
                    'original_name' => $file['name'],
                    'file_path' => $filePath,
                    'file_size' => $file['size'],
                    'file_type' => $file['type'],
                    'upload_date' => date('Y-m-d H:i:s'),
                    'description' => $description
                ];

                $attachments[] = $newAttachment;

                // Update event with new attachments
                $stmt = $this->pdo->prepare("UPDATE tbl_events SET event_attachments = ? WHERE event_id = ?");
                $stmt->execute([json_encode($attachments), $eventId]);

                return json_encode([
                    "status" => "success",
                    "message" => "File uploaded successfully",
                    "attachment" => $newAttachment
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to upload file"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Upload error: " . $e->getMessage()
            ]);
        }
    }

    public function getEnhancedEventDetails($eventId) {
        try {
            // Get comprehensive event details using the enhanced view
            $stmt = $this->pdo->prepare("
                SELECT
                    e.*,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                    u.user_firstName as client_first_name,
                    u.user_lastName as client_last_name,
                    u.user_suffix as client_suffix,
                    u.user_email as client_email,
                    u.user_contact as client_contact,
                    u.user_pfp as client_pfp,
                    u.user_birthdate as client_birthdate,
                    u.created_at as client_joined_date,
                    u.user_username as client_username,
                    CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                    CONCAT(org.user_firstName, ' ', org.user_lastName) as organizer_name,
                    CONCAT(cb.user_firstName, ' ', cb.user_lastName) as created_by_name,
                    CONCAT(ub.user_firstName, ' ', ub.user_lastName) as updated_by_name,
                    et.event_name as event_type_name,
                    et.event_description as event_type_description,
                    p.package_title,
                    p.package_description,
                    v.venue_title,
                    v.venue_location,
                    v.venue_contact,
                    v.venue_capacity,
                    v.venue_price,
                    pst.schedule_name as payment_schedule_name,
                    pst.schedule_description as payment_schedule_description,
                    pst.installment_count,
                    wd.id as wedding_details_id
                FROM tbl_events e
                LEFT JOIN tbl_users u ON e.user_id = u.user_id
                LEFT JOIN tbl_users a ON e.admin_id = a.user_id
                LEFT JOIN tbl_users org ON e.organizer_id = org.user_id
                LEFT JOIN tbl_users cb ON e.created_by = cb.user_id
                LEFT JOIN tbl_users ub ON e.updated_by = ub.user_id
                LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                LEFT JOIN tbl_payment_schedule_types pst ON e.payment_schedule_type_id = pst.schedule_type_id
                LEFT JOIN tbl_wedding_details wd ON e.event_wedding_form_id = wd.id
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

            // Get event components
            $stmt = $this->pdo->prepare("
                SELECT
                    ec.*,
                    pc.component_name as original_component_name,
                    pc.component_description as original_component_description
                FROM tbl_event_components ec
                LEFT JOIN tbl_package_components pc ON ec.original_package_component_id = pc.component_id
                WHERE ec.event_id = ?
                ORDER BY ec.display_order
            ");
            $stmt->execute([$eventId]);
            $event['components'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get event timeline
            $stmt = $this->pdo->prepare("
                SELECT
                    et.*,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as assigned_to_name
                FROM tbl_event_timeline et
                LEFT JOIN tbl_users u ON et.assigned_to = u.user_id
                WHERE et.event_id = ?
                ORDER BY et.display_order
            ");
            $stmt->execute([$eventId]);
            $event['timeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get payment schedule
            $stmt = $this->pdo->prepare("
                SELECT * FROM tbl_event_payment_schedules
                WHERE event_id = ?
                ORDER BY installment_number
            ");
            $stmt->execute([$eventId]);
            $event['payment_schedule'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get payments
            $stmt = $this->pdo->prepare("
                SELECT
                    p.*,
                    eps.installment_number,
                    eps.due_date as schedule_due_date
                FROM tbl_payments p
                LEFT JOIN tbl_event_payment_schedules eps ON p.schedule_id = eps.schedule_id
                WHERE p.event_id = ?
                ORDER BY p.payment_date DESC
            ");
            $stmt->execute([$eventId]);
            $event['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get wedding details if this is a wedding event and has wedding form
            if ($event['event_type_id'] == 1 && $event['event_wedding_form_id']) {
                $weddingDetails = $this->getWeddingDetails($eventId);
                $weddingResponse = json_decode($weddingDetails, true);
                if ($weddingResponse['status'] === 'success') {
                    $event['wedding_details'] = $weddingResponse['wedding_details'];
                }
            }

            // Get feedback if available
            if ($event['event_feedback_id']) {
                $stmt = $this->pdo->prepare("
                    SELECT
                        f.*,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as feedback_by_name
                    FROM tbl_feedback f
                    LEFT JOIN tbl_users u ON f.user_id = u.user_id
                    WHERE f.feedback_id = ?
                ");
                $stmt->execute([$event['event_feedback_id']]);
                $event['feedback'] = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // Parse JSON fields if they exist
            if ($event['event_attachments']) {
                $event['event_attachments'] = json_decode($event['event_attachments'], true);
            }
            if ($event['recurrence_rule']) {
                $event['recurrence_rule'] = json_decode($event['recurrence_rule'], true);
            }

            return json_encode([
                "status" => "success",
                "event" => $event
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch event details: " . $e->getMessage()
            ]);
        }
    }

    public function updateBookingStatus($bookingId, $status) {
        try {
            // Validate booking status
            $validStatuses = ['pending', 'confirmed', 'converted', 'cancelled', 'completed'];
            if (!in_array($status, $validStatuses)) {
                return json_encode(["status" => "error", "message" => "Invalid booking status"]);
            }

            // Check if booking exists
            $checkSql = "SELECT booking_id, booking_reference, user_id FROM tbl_bookings WHERE booking_id = :booking_id";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute([':booking_id' => $bookingId]);
            $booking = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                return json_encode(["status" => "error", "message" => "Booking not found"]);
            }

            // Update booking status
            $sql = "UPDATE tbl_bookings SET booking_status = :status, updated_at = NOW() WHERE booking_id = :booking_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':booking_id' => $bookingId
            ]);

            // Create notification for client
            $notificationMessage = '';
            switch ($status) {
                case 'confirmed':
                    $notificationMessage = "Your booking {$booking['booking_reference']} has been accepted! You can now proceed with event planning.";
                    break;
                case 'cancelled':
                    $notificationMessage = "Your booking {$booking['booking_reference']} has been cancelled.";
                    break;
                case 'completed':
                    $notificationMessage = "Your booking {$booking['booking_reference']} has been completed.";
                    break;
                default:
                    $notificationMessage = "Your booking {$booking['booking_reference']} status has been updated to {$status}.";
            }

            // Insert notification
            $notificationSql = "INSERT INTO tbl_notifications (user_id, booking_id, notification_message, notification_status)
                               VALUES (:user_id, :booking_id, :message, 'unread')";
            $notificationStmt = $this->conn->prepare($notificationSql);
            $notificationStmt->execute([
                ':user_id' => $booking['user_id'],
                ':booking_id' => $bookingId,
                ':message' => $notificationMessage
            ]);

            return json_encode([
                "status" => "success",
                "message" => "Booking status updated successfully"
            ]);
        } catch (Exception $e) {
            error_log("updateBookingStatus error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function confirmBooking($bookingReference) {
        try {
            // Check if booking exists
            $checkSql = "SELECT booking_id, user_id, booking_status FROM tbl_bookings WHERE booking_reference = :reference";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute([':reference' => $bookingReference]);
            $booking = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                return json_encode(["status" => "error", "message" => "Booking not found"]);
            }

            if ($booking['booking_status'] === 'confirmed') {
                return json_encode(["status" => "error", "message" => "Booking is already confirmed"]);
            }

            // Update booking status to confirmed
            $sql = "UPDATE tbl_bookings SET booking_status = 'confirmed', updated_at = NOW() WHERE booking_reference = :reference";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':reference' => $bookingReference]);

            // Create notification for client
            $notificationSql = "INSERT INTO tbl_notifications (user_id, booking_id, notification_message, notification_status)
                               VALUES (:user_id, :booking_id, :message, 'unread')";
            $notificationStmt = $this->conn->prepare($notificationSql);
            $notificationStmt->execute([
                ':user_id' => $booking['user_id'],
                ':booking_id' => $booking['booking_id'],
                ':message' => "Your booking {$bookingReference} has been confirmed! You can now proceed with event planning."
            ]);

            return json_encode([
                "status" => "success",
                "message" => "Booking confirmed successfully"
            ]);
        } catch (Exception $e) {
            error_log("confirmBooking error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getAllBookings() {
        try {
            $sql = "SELECT
                        b.booking_id,
                        b.booking_reference,
                        b.user_id,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                        u.user_email as client_email,
                        u.user_contact as client_phone,
                        b.event_type_id,
                        et.event_name as event_type_name,
                        b.event_name,
                        b.event_date,
                        b.event_time,
                        b.start_time,
                        b.end_time,
                        b.guest_count,
                        b.venue_id,
                        v.venue_title as venue_name,
                        b.package_id,
                        p.package_title as package_name,
                        b.notes,
                        b.booking_status,
                        b.created_at,
                        b.updated_at,
                        CASE WHEN e.event_id IS NOT NULL THEN 1 ELSE 0 END as is_converted,
                        e.event_id as converted_event_id
                    FROM tbl_bookings b
                    JOIN tbl_users u ON b.user_id = u.user_id
                    JOIN tbl_event_type et ON b.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON b.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON b.package_id = p.package_id
                    LEFT JOIN tbl_events e ON b.booking_reference = e.original_booking_reference
                    ORDER BY b.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "bookings" => $bookings,
                "count" => count($bookings)
            ]);
        } catch (Exception $e) {
            error_log("getAllBookings error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getConfirmedBookings() {
        try {
            $sql = "SELECT
                        b.booking_id,
                        b.booking_reference,
                        b.user_id,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                        u.user_email as client_email,
                        u.user_contact as client_phone,
                        b.event_type_id,
                        et.event_name as event_type_name,
                        b.event_name,
                        b.event_date,
                        b.event_time,
                        b.start_time,
                        b.end_time,
                        b.guest_count,
                        b.venue_id,
                        v.venue_title as venue_name,
                        b.package_id,
                        p.package_title as package_name,
                        b.notes,
                        b.booking_status,
                        b.created_at,
                        b.updated_at,
                        CASE WHEN e.event_id IS NOT NULL THEN 1 ELSE 0 END as is_converted,
                        e.event_id as converted_event_id
                    FROM tbl_bookings b
                    JOIN tbl_users u ON b.user_id = u.user_id
                    JOIN tbl_event_type et ON b.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON b.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON b.package_id = p.package_id
                    LEFT JOIN tbl_events e ON b.booking_reference = e.original_booking_reference
                    WHERE b.booking_status = 'confirmed'
                    ORDER BY b.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "bookings" => $bookings,
                "count" => count($bookings)
            ]);
        } catch (Exception $e) {
            error_log("getConfirmedBookings error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getEventByBookingReference($bookingReference) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function testBookingsTable() { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function createVenue() {
        try {
            // Validate required fields
            $requiredFields = ['venue_title', 'venue_location', 'venue_contact', 'venue_capacity', 'venue_price'];
            foreach ($requiredFields as $field) {
                if (!isset($_POST[$field]) || empty($_POST[$field])) {
                    return json_encode([
                        "status" => "error",
                        "message" => "Missing required field: " . $field
                    ]);
                }
            }

            // Start transaction
            $this->conn->beginTransaction();

            // Handle file uploads first
            $profilePicture = null;
            $coverPhoto = null;

            if (isset($_FILES['venue_profile_picture']) && $_FILES['venue_profile_picture']['error'] === 0) {
                $uploadResult = json_decode($this->uploadVenueFile($_FILES['venue_profile_picture'], 'venue_profile_pictures'), true);
                if ($uploadResult['status'] === 'success') {
                    $profilePicture = $uploadResult['filePath'];
                } else {
                    throw new Exception("Failed to upload profile picture: " . $uploadResult['message']);
                }
            }

            if (isset($_FILES['venue_cover_photo']) && $_FILES['venue_cover_photo']['error'] === 0) {
                $uploadResult = json_decode($this->uploadVenueFile($_FILES['venue_cover_photo'], 'venue_cover_photos'), true);
                if ($uploadResult['status'] === 'success') {
                    $coverPhoto = $uploadResult['filePath'];
                } else {
                    throw new Exception("Failed to upload cover photo: " . $uploadResult['message']);
                }
            }

            // Set default user_id and venue_owner (can be customized later)
            $userId = isset($_POST['user_id']) ? $_POST['user_id'] : 7; // Default admin user
            $venueOwner = isset($_POST['venue_owner']) ? $_POST['venue_owner'] : 'Admin';

            // Validate venue_type against enum values
            $validVenueTypes = ['indoor', 'outdoor', 'hybrid', 'garden', 'hall', 'pavilion'];
            $venueType = isset($_POST['venue_type']) ? $_POST['venue_type'] : 'indoor';

            // Map frontend venue_type to database enum values
            if ($venueType === 'internal') {
                $venueType = 'indoor';
            } elseif ($venueType === 'external') {
                $venueType = 'outdoor';
            }

            if (!in_array($venueType, $validVenueTypes)) {
                $venueType = 'indoor'; // Default fallback
            }

            // Insert venue with all required fields
            $sql = "INSERT INTO tbl_venue (
                venue_title, venue_details, venue_location, venue_contact,
                venue_type, venue_capacity, venue_price, is_active,
                venue_profile_picture, venue_cover_photo, user_id, venue_owner,
                venue_status
            ) VALUES (
                :venue_title, :venue_details, :venue_location, :venue_contact,
                :venue_type, :venue_capacity, :venue_price, :is_active,
                :venue_profile_picture, :venue_cover_photo, :user_id, :venue_owner,
                :venue_status
            )";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                'venue_title' => $_POST['venue_title'],
                'venue_details' => $_POST['venue_details'] ?? '',
                'venue_location' => $_POST['venue_location'],
                'venue_contact' => $_POST['venue_contact'],
                'venue_type' => $venueType,
                'venue_capacity' => $_POST['venue_capacity'],
                'venue_price' => $_POST['venue_price'],
                'is_active' => isset($_POST['is_active']) ? $_POST['is_active'] : 1,
                'venue_profile_picture' => $profilePicture,
                'venue_cover_photo' => $coverPhoto,
                'user_id' => $userId,
                'venue_owner' => $venueOwner,
                'venue_status' => 'available'
            ]);

            $venueId = $this->conn->lastInsertId();

            // Handle inclusions if provided
            if (isset($_POST['inclusions_data'])) {
                $inclusions = json_decode($_POST['inclusions_data'], true);

                if (is_array($inclusions)) {
                    foreach ($inclusions as $inclusion) {
                        // Insert inclusion
                        $sql = "INSERT INTO tbl_venue_inclusions (
                            venue_id, inclusion_name, inclusion_description, inclusion_price
                        ) VALUES (
                            :venue_id, :inclusion_name, :inclusion_description, :inclusion_price
                        )";

                        $stmt = $this->conn->prepare($sql);
                        $stmt->execute([
                            'venue_id' => $venueId,
                            'inclusion_name' => $inclusion['inclusion_name'],
                            'inclusion_description' => $inclusion['inclusion_description'] ?? '',
                            'inclusion_price' => $inclusion['inclusion_price'] ?? 0
                        ]);

                        $inclusionId = $this->conn->lastInsertId();

                        // Handle components if provided
                        if (isset($inclusion['components']) && is_array($inclusion['components'])) {
                            foreach ($inclusion['components'] as $component) {
                                $sql = "INSERT INTO tbl_venue_components (
                                    inclusion_id, component_name, component_description
                                ) VALUES (
                                    :inclusion_id, :component_name, :component_description
                                )";

                                $stmt = $this->conn->prepare($sql);
                                $stmt->execute([
                                    'inclusion_id' => $inclusionId,
                                    'component_name' => $component['component_name'],
                                    'component_description' => $component['component_description'] ?? ''
                                ]);
                            }
                        }
                    }
                }
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Venue created successfully",
                "venue_id" => $venueId
            ]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return json_encode([
                "status" => "error",
                "message" => "Failed to create venue: " . $e->getMessage()
            ]);
        }
    }
    public function getAllVenues() {
        try {
            $sql = "SELECT v.*,
                    GROUP_CONCAT(DISTINCT vc.component_name) as components,
                    GROUP_CONCAT(DISTINCT vi.inclusion_name) as inclusions
                    FROM tbl_venue v
                    LEFT JOIN tbl_venue_inclusions vi ON v.venue_id = vi.venue_id
                    LEFT JOIN tbl_venue_components vc ON vi.inclusion_id = vc.inclusion_id
                    GROUP BY v.venue_id
                    ORDER BY v.created_at DESC";

            $stmt = $this->conn->query($sql);
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($venues as &$venue) {
                $venue['components'] = $venue['components'] ? explode(',', $venue['components']) : [];
                $venue['inclusions'] = $venue['inclusions'] ? explode(',', $venue['inclusions']) : [];
                $venue['is_active'] = (bool)$venue['is_active'];
            }

            return json_encode([
                "status" => "success",
                "data" => $venues
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }
    public function getVenueById($venueId) {
        try {
            // Get venue basic info
            $sql = "SELECT * FROM tbl_venue WHERE venue_id = :venue_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':venue_id' => $venueId]);
            $venue = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$venue) {
                return json_encode(["status" => "error", "message" => "Venue not found"]);
            }

            // Get venue inclusions
            $inclusionsSql = "SELECT * FROM tbl_venue_inclusions WHERE venue_id = :venue_id AND is_active = 1 ORDER BY inclusion_name";
            $inclusionsStmt = $this->conn->prepare($inclusionsSql);
            $inclusionsStmt->execute([':venue_id' => $venueId]);
            $inclusions = $inclusionsStmt->fetchAll(PDO::FETCH_ASSOC);

            // For each inclusion, get its components
            foreach ($inclusions as &$inclusion) {
                $componentsSql = "SELECT * FROM tbl_venue_components WHERE inclusion_id = :inclusion_id AND is_active = 1 ORDER BY component_name";
                $componentsStmt = $this->conn->prepare($componentsSql);
                $componentsStmt->execute([':inclusion_id' => $inclusion['inclusion_id']]);
                $inclusion['components'] = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $venue['inclusions'] = $inclusions;

            return json_encode([
                "status" => "success",
                "venue" => $venue
            ]);
        } catch (Exception $e) {
            error_log("getVenueById error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function updateVenue($data) {
        try {
            $this->conn->beginTransaction();

            // Update venue basic information
            $stmt = $this->conn->prepare("
                UPDATE tbl_venue SET
                    venue_title = ?,
                    venue_owner = ?,
                    venue_location = ?,
                    venue_contact = ?,
                    venue_details = ?,
                    venue_status = ?,
                    venue_capacity = ?,
                    venue_price = ?,
                    venue_type = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE venue_id = ?
            ");

            $stmt->execute([
                $data['venue_title'],
                $data['venue_owner'],
                $data['venue_location'],
                $data['venue_contact'],
                $data['venue_details'] ?? '',
                $data['venue_status'] ?? 'available',
                $data['venue_capacity'],
                $data['venue_price'],
                $data['venue_type'] ?? 'indoor',
                $data['venue_id']
            ]);

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Venue updated successfully"
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            return json_encode([
                "status" => "error",
                "message" => "Failed to update venue: " . $e->getMessage()
            ]);
        }
    }
    public function getVenuesForPackage() {
        try {
            $sql = "SELECT v.*,
                    GROUP_CONCAT(DISTINCT vc.component_name) as components,
                    GROUP_CONCAT(DISTINCT vi.inclusion_name) as inclusions,
                    COALESCE(v.venue_price, 0) as total_price
                    FROM tbl_venue v
                    LEFT JOIN tbl_venue_inclusions vi ON v.venue_id = vi.venue_id
                    LEFT JOIN tbl_venue_components vc ON vi.inclusion_id = vc.inclusion_id
                    WHERE v.is_active = 1
                    GROUP BY v.venue_id
                    ORDER BY v.created_at DESC";

            $stmt = $this->conn->query($sql);
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($venues as &$venue) {
                $venue['components'] = $venue['components'] ? explode(',', $venue['components']) : [];
                $venue['inclusions'] = $venue['inclusions'] ? explode(',', $venue['inclusions']) : [];
                $venue['total_price'] = floatval($venue['total_price']);
            }

            return json_encode([
                "status" => "success",
                "venues" => $venues
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }
    public function createPackageWithVenues($data) {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            $packageData = $data['package_data'];
            if (empty($packageData['package_title']) || empty($packageData['package_price']) ||
                empty($packageData['guest_capacity']) || empty($packageData['created_by'])) {
                return json_encode(["status" => "error", "message" => "Package title, price, guest capacity, and creator are required"]);
            }

            // Insert main package
            $sql = "INSERT INTO tbl_packages (package_title, package_description, package_price, guest_capacity, created_by, is_active)
                    VALUES (:title, :description, :price, :capacity, :created_by, 1)";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':title' => $packageData['package_title'],
                ':description' => $packageData['package_description'] ?? '',
                ':price' => $packageData['package_price'],
                ':capacity' => $packageData['guest_capacity'],
                ':created_by' => $packageData['created_by']
            ]);

            $packageId = $this->conn->lastInsertId();

            // Set original price and lock the package price after creation
            $lockPriceSql = "UPDATE tbl_packages SET
                                original_price = package_price,
                                is_price_locked = 1,
                                price_lock_date = CURRENT_TIMESTAMP
                            WHERE package_id = :package_id";
            $lockStmt = $this->conn->prepare($lockPriceSql);
            $lockStmt->execute([':package_id' => $packageId]);

            // Insert components if provided
            if (!empty($data['components']) && is_array($data['components'])) {
                foreach ($data['components'] as $index => $component) {
                    if (!empty($component['component_name'])) {
                        $componentSql = "INSERT INTO tbl_package_components (package_id, component_name, component_description, component_price, display_order)
                                        VALUES (:package_id, :name, :description, :price, :order)";
                        $componentStmt = $this->conn->prepare($componentSql);
                        $componentStmt->execute([
                            ':package_id' => $packageId,
                            ':name' => $component['component_name'],
                            ':description' => $component['component_description'] ?? '',
                            ':price' => $component['component_price'] ?? 0,
                            ':order' => $index
                        ]);
                    }
                }
            }

            // Insert freebies if provided
            if (!empty($data['freebies']) && is_array($data['freebies'])) {
                foreach ($data['freebies'] as $index => $freebie) {
                    if (!empty($freebie['freebie_name'])) {
                        $freebieSql = "INSERT INTO tbl_package_freebies (package_id, freebie_name, freebie_description, freebie_value, display_order)
                                      VALUES (:package_id, :name, :description, :value, :order)";
                        $freebieStmt = $this->conn->prepare($freebieSql);
                        $freebieStmt->execute([
                            ':package_id' => $packageId,
                            ':name' => $freebie['freebie_name'],
                            ':description' => $freebie['freebie_description'] ?? '',
                            ':value' => $freebie['freebie_value'] ?? 0,
                            ':order' => $index
                        ]);
                    }
                }
            }

            // Insert event types if provided
            if (!empty($data['event_types']) && is_array($data['event_types'])) {
                foreach ($data['event_types'] as $eventTypeId) {
                    $eventTypeSql = "INSERT INTO tbl_package_event_types (package_id, event_type_id) VALUES (:package_id, :event_type_id)";
                    $eventTypeStmt = $this->conn->prepare($eventTypeSql);
                    $eventTypeStmt->execute([
                        ':package_id' => $packageId,
                        ':event_type_id' => $eventTypeId
                    ]);
                }
            }

            // Insert venues if provided
            if (!empty($data['venue_ids']) && is_array($data['venue_ids'])) {
                foreach ($data['venue_ids'] as $venueId) {
                    $venueSql = "INSERT INTO tbl_package_venues (package_id, venue_id) VALUES (:package_id, :venue_id)";
                    $venueStmt = $this->conn->prepare($venueSql);
                    $venueStmt->execute([
                        ':package_id' => $packageId,
                        ':venue_id' => $venueId
                    ]);
                }
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Package created successfully",
                "package_id" => $packageId
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("createPackageWithVenues error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getDashboardMetrics($adminId) {
        try {
            $metrics = [];

            // Get total events for this admin
            $totalEventsSql = "SELECT COUNT(*) as total FROM tbl_events WHERE admin_id = ?";
            $stmt = $this->conn->prepare($totalEventsSql);
            $stmt->execute([$adminId]);
            $metrics['totalEvents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get total revenue (sum of all completed payments for admin's events)
            $revenueSql = "SELECT COALESCE(SUM(p.payment_amount), 0) as total_revenue
                          FROM tbl_payments p
                          JOIN tbl_events e ON p.event_id = e.event_id
                          WHERE e.admin_id = ? AND p.payment_status = 'completed'";
            $stmt = $this->conn->prepare($revenueSql);
            $stmt->execute([$adminId]);
            $metrics['totalRevenue'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'];

            // Get total clients (unique clients who have events with this admin)
            $clientsSql = "SELECT COUNT(DISTINCT e.user_id) as total
                          FROM tbl_events e
                          WHERE e.admin_id = ?";
            $stmt = $this->conn->prepare($clientsSql);
            $stmt->execute([$adminId]);
            $metrics['totalClients'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get completed events
            $completedSql = "SELECT COUNT(*) as total
                            FROM tbl_events
                            WHERE admin_id = ? AND event_status = 'completed'";
            $stmt = $this->conn->prepare($completedSql);
            $stmt->execute([$adminId]);
            $metrics['completedEvents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Calculate monthly growth (comparing this month vs last month)
            $currentMonth = date('Y-m');
            $lastMonth = date('Y-m', strtotime('-1 month'));

            // Events growth
            $currentMonthEventsSql = "SELECT COUNT(*) as total FROM tbl_events
                                     WHERE admin_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?";
            $stmt = $this->conn->prepare($currentMonthEventsSql);
            $stmt->execute([$adminId, $currentMonth]);
            $currentMonthEvents = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stmt->execute([$adminId, $lastMonth]);
            $lastMonthEvents = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $eventsGrowth = $lastMonthEvents > 0 ? (($currentMonthEvents - $lastMonthEvents) / $lastMonthEvents) * 100 : 0;

            // Revenue growth
            $currentMonthRevenueSql = "SELECT COALESCE(SUM(p.payment_amount), 0) as total
                                      FROM tbl_payments p
                                      JOIN tbl_events e ON p.event_id = e.event_id
                                      WHERE e.admin_id = ? AND p.payment_status = 'completed'
                                      AND DATE_FORMAT(p.created_at, '%Y-%m') = ?";
            $stmt = $this->conn->prepare($currentMonthRevenueSql);
            $stmt->execute([$adminId, $currentMonth]);
            $currentMonthRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stmt->execute([$adminId, $lastMonth]);
            $lastMonthRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $revenueGrowth = $lastMonthRevenue > 0 ? (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 : 0;

            // Clients growth
            $currentMonthClientsSql = "SELECT COUNT(DISTINCT e.user_id) as total
                                      FROM tbl_events e
                                      WHERE e.admin_id = ? AND DATE_FORMAT(e.created_at, '%Y-%m') = ?";
            $stmt = $this->conn->prepare($currentMonthClientsSql);
            $stmt->execute([$adminId, $currentMonth]);
            $currentMonthClients = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stmt->execute([$adminId, $lastMonth]);
            $lastMonthClients = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $clientsGrowth = $lastMonthClients > 0 ? (($currentMonthClients - $lastMonthClients) / $lastMonthClients) * 100 : 0;

            // Completed events growth
            $currentMonthCompletedSql = "SELECT COUNT(*) as total
                                        FROM tbl_events
                                        WHERE admin_id = ? AND event_status = 'completed'
                                        AND DATE_FORMAT(updated_at, '%Y-%m') = ?";
            $stmt = $this->conn->prepare($currentMonthCompletedSql);
            $stmt->execute([$adminId, $currentMonth]);
            $currentMonthCompleted = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stmt->execute([$adminId, $lastMonth]);
            $lastMonthCompleted = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $completedGrowth = $lastMonthCompleted > 0 ? (($currentMonthCompleted - $lastMonthCompleted) / $lastMonthCompleted) * 100 : 0;

            $metrics['monthlyGrowth'] = [
                'events' => round($eventsGrowth, 1),
                'revenue' => round($revenueGrowth, 1),
                'clients' => round($clientsGrowth, 1),
                'completed' => round($completedGrowth, 1)
            ];

            return json_encode([
                "status" => "success",
                "metrics" => $metrics
            ]);
        } catch (Exception $e) {
            error_log("getDashboardMetrics error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getUpcomingEvents($adminId, $limit = 5) {
        try {
            $sql = "SELECT
                        e.event_id as id,
                        e.event_title as title,
                        e.event_date as date,
                        e.event_status as status,
                        e.total_budget as budget,
                        COALESCE(v.venue_title, 'No venue selected') as venue,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as client
                    FROM tbl_events e
                    LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                    LEFT JOIN tbl_users u ON e.user_id = u.user_id
                    WHERE e.admin_id = ?
                    AND e.event_date >= CURDATE()
                    AND e.event_status IN ('draft', 'confirmed', 'in-progress')
                    ORDER BY e.event_date ASC
                    LIMIT ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$adminId, $limit]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "events" => $events
            ]);
        } catch (Exception $e) {
            error_log("getUpcomingEvents error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getRecentPayments($adminId, $limit = 5) {
        try {
            $sql = "SELECT
                        p.payment_id as id,
                        e.event_title as event,
                        p.payment_date as date,
                        p.payment_amount as amount,
                        p.payment_method as type,
                        p.payment_status as status
                    FROM tbl_payments p
                    JOIN tbl_events e ON p.event_id = e.event_id
                    WHERE e.admin_id = ?
                    ORDER BY p.created_at DESC
                    LIMIT ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$adminId, $limit]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "payments" => $payments
            ]);
        } catch (Exception $e) {
            error_log("getRecentPayments error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function createPayment($data) {
        try {
            $this->pdo->beginTransaction();

            // Handle payment attachments if provided
            $attachments = null;
            if (isset($data['payment_attachments']) && !empty($data['payment_attachments'])) {
                $attachments = json_encode($data['payment_attachments']);
            }

            // Insert payment record
            $stmt = $this->pdo->prepare("
                INSERT INTO tbl_payments (
                    event_id, schedule_id, client_id, payment_method,
                    payment_amount, payment_notes, payment_status,
                    payment_date, payment_reference, payment_attachments
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['event_id'],
                $data['schedule_id'] ?? null,
                $data['client_id'],
                $data['payment_method'],
                $data['payment_amount'],
                $data['payment_notes'] ?? '',
                $data['payment_status'] ?? 'completed',
                $data['payment_date'] ?? date('Y-m-d'),
                $data['payment_reference'] ?? '',
                $attachments
            ]);

            $paymentId = $this->pdo->lastInsertId();

            $this->pdo->commit();

            return json_encode([
                "status" => "success",
                "payment_id" => $paymentId,
                "message" => "Payment created successfully"
            ]);
        } catch (Exception $e) {
            $this->pdo->rollback();
            return json_encode([
                "status" => "error",
                "message" => "Failed to create payment: " . $e->getMessage()
            ]);
        }
    }
    public function getEventPayments($eventId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    p.*,
                    u.user_firstName,
                    u.user_lastName,
                    eps.installment_number,
                    eps.due_date,
                    eps.amount_due as schedule_amount_due
                FROM tbl_payments p
                LEFT JOIN tbl_users u ON p.client_id = u.user_id
                LEFT JOIN tbl_event_payment_schedules eps ON p.schedule_id = eps.schedule_id
                WHERE p.event_id = ?
                ORDER BY p.payment_date DESC
            ");
            $stmt->execute([$eventId]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "payments" => $payments
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch event payments: " . $e->getMessage()
            ]);
        }
    }
    public function getClientPayments($clientId) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function getAdminPayments($adminId) {
        try {
            $query = "
                SELECT
                    p.payment_id,
                    p.payment_amount,
                    p.payment_method,
                    p.payment_status,
                    p.payment_date,
                    p.payment_reference,
                    p.payment_notes,
                    e.event_title,
                    e.event_date,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name
                FROM tbl_payments p
                INNER JOIN tbl_events e ON p.event_id = e.event_id
                INNER JOIN tbl_users u ON p.client_id = u.user_id
                WHERE e.admin_id = :admin_id
                ORDER BY p.payment_date DESC, p.created_at DESC
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
            $stmt->execute();

            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "payments" => $payments
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch admin payments: " . $e->getMessage()
            ]);
        }
    }
    public function updatePaymentStatus($paymentId, $status, $notes = null) {
        try {
            $this->pdo->beginTransaction();

            // Get payment details first
            $query = "SELECT * FROM tbl_payments WHERE payment_id = :payment_id";
            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':payment_id', $paymentId, PDO::PARAM_INT);
            $stmt->execute();
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception("Payment not found");
            }

            // Update payment status
            $updateQuery = "UPDATE tbl_payments SET payment_status = :status, updated_at = CURRENT_TIMESTAMP WHERE payment_id = :payment_id";
            $updateStmt = $this->pdo->prepare($updateQuery);
            $updateStmt->bindParam(':status', $status);
            $updateStmt->bindParam(':payment_id', $paymentId, PDO::PARAM_INT);
            $updateStmt->execute();

            // Log the status change
            $logQuery = "
                INSERT INTO tbl_payment_logs
                (event_id, payment_id, client_id, action_type, amount, reference_number, notes)
                VALUES (:event_id, :payment_id, :client_id, 'payment_confirmed', :amount, :reference_number, :notes)
            ";
            $logStmt = $this->pdo->prepare($logQuery);
            $logStmt->bindParam(':event_id', $payment['event_id'], PDO::PARAM_INT);
            $logStmt->bindParam(':payment_id', $paymentId, PDO::PARAM_INT);
            $logStmt->bindParam(':client_id', $payment['client_id'], PDO::PARAM_INT);
            $logStmt->bindParam(':amount', $payment['payment_amount']);
            $logStmt->bindParam(':reference_number', $payment['payment_reference']);
            $logStmt->bindParam(':notes', $notes);
            $logStmt->execute();

            $this->pdo->commit();

            return json_encode([
                "status" => "success",
                "message" => "Payment status updated successfully"
            ]);

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return json_encode([
                "status" => "error",
                "message" => "Failed to update payment status: " . $e->getMessage()
            ]);
        }
    }
    public function getPaymentSchedule($eventId) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function getEventsWithPaymentStatus($adminId) {
        try {
            $query = "
                SELECT
                    e.event_id,
                    e.event_title,
                    e.event_date,
                    e.total_budget,
                    e.payment_status as event_payment_status,
                    COALESCE(SUM(p.payment_amount), 0) as total_paid,
                    (e.total_budget - COALESCE(SUM(p.payment_amount), 0)) as remaining_balance,
                    CASE
                        WHEN e.total_budget > 0 THEN ROUND((COALESCE(SUM(p.payment_amount), 0) / e.total_budget) * 100, 2)
                        ELSE 0
                    END as payment_percentage,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                    COUNT(p.payment_id) as payment_count
                FROM tbl_events e
                LEFT JOIN tbl_users u ON e.user_id = u.user_id
                LEFT JOIN tbl_payments p ON e.event_id = p.event_id AND p.payment_status = 'completed'
                WHERE e.admin_id = :admin_id
                GROUP BY e.event_id
                ORDER BY e.event_date DESC
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
            $stmt->execute();

            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "events" => $events
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch events with payment status: " . $e->getMessage()
            ]);
        }
    }
    public function getPaymentAnalytics($adminId, $startDate = null, $endDate = null) {
        try {
            // Base date filter
            $dateFilter = "";
            if ($startDate && $endDate) {
                $dateFilter = "AND p.payment_date BETWEEN :start_date AND :end_date";
            }

            $query = "
                SELECT
                    COUNT(DISTINCT e.event_id) as total_events,
                    COUNT(p.payment_id) as total_payments,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) as total_revenue,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'pending' THEN p.payment_amount ELSE 0 END), 0) as pending_payments,
                    CASE
                        WHEN COUNT(CASE WHEN p.payment_status = 'completed' THEN 1 END) > 0
                        THEN COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) / COUNT(CASE WHEN p.payment_status = 'completed' THEN 1 END)
                        ELSE 0
                    END as average_payment,
                    COUNT(CASE WHEN p.payment_method = 'gcash' AND p.payment_status = 'completed' THEN 1 END) as gcash_payments,
                    COUNT(CASE WHEN p.payment_method = 'bank-transfer' AND p.payment_status = 'completed' THEN 1 END) as bank_payments,
                    COUNT(CASE WHEN p.payment_method = 'cash' AND p.payment_status = 'completed' THEN 1 END) as cash_payments
                FROM tbl_events e
                LEFT JOIN tbl_payments p ON e.event_id = p.event_id
                WHERE e.admin_id = :admin_id $dateFilter
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':admin_id', $adminId, PDO::PARAM_INT);

            if ($startDate && $endDate) {
                $stmt->bindParam(':start_date', $startDate);
                $stmt->bindParam(':end_date', $endDate);
            }

            $stmt->execute();
            $analytics = $stmt->fetch(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "analytics" => $analytics
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch payment analytics: " . $e->getMessage()
            ]);
        }
    }
    public function createPaymentSchedule($data) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function getEventPaymentSchedule($eventId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    eps.*,
                    pst.schedule_name,
                    e.event_title,
                    e.total_budget
                FROM tbl_event_payment_schedules eps
                LEFT JOIN tbl_payment_schedule_types pst ON eps.schedule_type_id = pst.schedule_type_id
                LEFT JOIN tbl_events e ON eps.event_id = e.event_id
                WHERE eps.event_id = ?
                ORDER BY eps.installment_number
            ");
            $stmt->execute([$eventId]);
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "schedules" => $schedules
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch event payment schedule: " . $e->getMessage()
            ]);
        }
    }
    public function getPaymentScheduleTypes() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM tbl_payment_schedule_types
                WHERE is_active = 1
                ORDER BY schedule_type_id
            ");
            $stmt->execute();
            $scheduleTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "scheduleTypes" => $scheduleTypes
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch payment schedule types: " . $e->getMessage()
            ]);
        }
    }
    public function recordScheduledPayment($data) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function getPaymentLogs($eventId) {
        try {
            $query = "
                SELECT
                    pl.log_id,
                    pl.event_id,
                    pl.payment_id,
                    pl.action_type,
                    pl.amount,
                    pl.reference_number,
                    pl.notes,
                    pl.created_at,
                    CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                    CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                    p.payment_method,
                    p.payment_status,
                    e.event_title
                FROM tbl_payment_logs pl
                LEFT JOIN tbl_users c ON pl.client_id = c.user_id
                LEFT JOIN tbl_users a ON pl.admin_id = a.user_id
                LEFT JOIN tbl_payments p ON pl.payment_id = p.payment_id
                LEFT JOIN tbl_events e ON pl.event_id = e.event_id
                WHERE pl.event_id = :event_id
                ORDER BY pl.created_at DESC
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':event_id', $eventId, PDO::PARAM_INT);
            $stmt->execute();

            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "logs" => $logs
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch payment logs: " . $e->getMessage()
            ]);
        }
    }
    public function getAdminPaymentLogs($adminId, $limit = 50) {
        try {
            $query = "
                SELECT
                    pl.log_id,
                    pl.event_id,
                    pl.payment_id,
                    pl.action_type,
                    pl.amount,
                    pl.reference_number,
                    pl.notes,
                    pl.created_at,
                    CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                    CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                    p.payment_method,
                    p.payment_status,
                    e.event_title
                FROM tbl_payment_logs pl
                LEFT JOIN tbl_users c ON pl.client_id = c.user_id
                LEFT JOIN tbl_users a ON pl.admin_id = a.user_id
                LEFT JOIN tbl_payments p ON pl.payment_id = p.payment_id
                LEFT JOIN tbl_events e ON pl.event_id = e.event_id
                WHERE e.admin_id = :admin_id
                ORDER BY pl.created_at DESC
                LIMIT :limit
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "logs" => $logs
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch admin payment logs: " . $e->getMessage()
            ]);
        }
    }

    public function getEnhancedPaymentDashboard($adminId) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }

    // Settings Methods
    public function getUserProfile($userId) {
        try {
            $sql = "SELECT user_id, user_firstName, user_lastName, user_email, user_contact, user_pfp, created_at
                    FROM tbl_users WHERE user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($profile) {
                return json_encode([
                    "status" => "success",
                    "profile" => $profile
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "User not found"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error fetching profile: " . $e->getMessage()
            ]);
        }
    }

    public function updateUserProfile($data) {
        try {
            $sql = "UPDATE tbl_users SET
                        user_firstName = ?,
                        user_lastName = ?,
                        user_email = ?,
                        user_contact = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = ?";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                $data['firstName'],
                $data['lastName'],
                $data['email'],
                $data['contact'],
                $data['user_id']
            ]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Profile updated successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to update profile"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error updating profile: " . $e->getMessage()
            ]);
        }
    }

    public function changePassword($data) {
        try {
            // Verify current password
            $sql = "SELECT user_password FROM tbl_users WHERE user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$data['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($data['currentPassword'], $user['user_password'])) {
                return json_encode([
                    "status" => "error",
                    "message" => "Current password is incorrect"
                ]);
            }

            // Update password
            $hashedPassword = password_hash($data['newPassword'], PASSWORD_DEFAULT);
            $updateSql = "UPDATE tbl_users SET user_password = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?";
            $updateStmt = $this->conn->prepare($updateSql);
            $result = $updateStmt->execute([$hashedPassword, $data['user_id']]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Password changed successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to change password"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error changing password: " . $e->getMessage()
            ]);
        }
    }

    public function getWebsiteSettings() {
        try {
            // Check if settings table exists, if not create default settings
            $checkSql = "SELECT COUNT(*) as count FROM information_schema.tables
                        WHERE table_schema = DATABASE() AND table_name = 'tbl_website_settings'";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute();
            $tableExists = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

            if (!$tableExists) {
                // Create table if it doesn't exist
                $createTableSql = "CREATE TABLE tbl_website_settings (
                    setting_id INT PRIMARY KEY AUTO_INCREMENT,
                    company_name VARCHAR(255) DEFAULT 'Event Coordination System',
                    company_logo TEXT,
                    hero_image TEXT,
                    primary_color VARCHAR(7) DEFAULT '#16a34a',
                    secondary_color VARCHAR(7) DEFAULT '#059669',
                    contact_email VARCHAR(255),
                    contact_phone VARCHAR(50),
                    address TEXT,
                    about_text TEXT,
                    social_facebook VARCHAR(255),
                    social_instagram VARCHAR(255),
                    social_twitter VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )";
                $this->conn->exec($createTableSql);

                // Insert default settings
                $insertSql = "INSERT INTO tbl_website_settings (company_name) VALUES ('Event Coordination System')";
                $this->conn->exec($insertSql);
            }

            $sql = "SELECT * FROM tbl_website_settings ORDER BY setting_id DESC LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($settings) {
                return json_encode([
                    "status" => "success",
                    "settings" => $settings
                ]);
            } else {
                return json_encode([
                    "status" => "success",
                    "settings" => [
                        "company_name" => "Event Coordination System",
                        "primary_color" => "#16a34a",
                        "secondary_color" => "#059669"
                    ]
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error fetching website settings: " . $e->getMessage()
            ]);
        }
    }

    public function updateWebsiteSettings($settings) {
        try {
            $sql = "UPDATE tbl_website_settings SET
                        company_name = ?,
                        company_logo = ?,
                        hero_image = ?,
                        primary_color = ?,
                        secondary_color = ?,
                        contact_email = ?,
                        contact_phone = ?,
                        address = ?,
                        about_text = ?,
                        social_facebook = ?,
                        social_instagram = ?,
                        social_twitter = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE setting_id = (SELECT MAX(setting_id) FROM (SELECT setting_id FROM tbl_website_settings) as temp)";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                $settings['company_name'],
                $settings['company_logo'],
                $settings['hero_image'],
                $settings['primary_color'],
                $settings['secondary_color'],
                $settings['contact_email'],
                $settings['contact_phone'],
                $settings['address'],
                $settings['about_text'],
                $settings['social_facebook'],
                $settings['social_instagram'],
                $settings['social_twitter']
            ]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Website settings updated successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to update website settings"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error updating website settings: " . $e->getMessage()
            ]);
        }
    }

    public function getAllFeedbacks() {
        try {
            $sql = "SELECT
                        f.*,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as user_name,
                        u.user_firstName,
                        u.user_lastName,
                        u.user_email,
                        v.venue_title,
                        s.store_name
                    FROM tbl_feedback f
                    LEFT JOIN tbl_users u ON f.user_id = u.user_id
                    LEFT JOIN tbl_venue v ON f.venue_id = v.venue_id
                    LEFT JOIN tbl_store s ON f.store_id = s.store_id
                    ORDER BY f.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "feedbacks" => $feedbacks
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error fetching feedbacks: " . $e->getMessage()
            ]);
        }
    }

    public function deleteFeedback($feedbackId) {
        try {
            $sql = "DELETE FROM tbl_feedback WHERE feedback_id = ?";
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([$feedbackId]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Feedback deleted successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to delete feedback"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error deleting feedback: " . $e->getMessage()
            ]);
        }
    }

    public function uploadFile($file, $fileType) {
        try {
            $uploadDir = "uploads/";

            // Create directory based on file type
            switch ($fileType) {
                case 'profile':
                    $uploadDir .= "profile_pictures/";
                    break;
                case 'company_logo':
                    $uploadDir .= "website/logos/";
                    break;
                case 'hero_image':
                    $uploadDir .= "website/hero/";
                    break;
                case 'event_attachment':
                    $uploadDir .= "event_attachments/";
                    break;
                case 'payment_proof':
                    $uploadDir .= "payment_proofs/";
                    break;
                case 'venue_profile_pictures':
                    $uploadDir .= "venue_profile_pictures/";
                    break;
                case 'venue_cover_photos':
                    $uploadDir .= "venue_cover_photos/";
                    break;
                default:
                    $uploadDir .= "misc/";
            }

            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Generate unique filename
            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                return json_encode([
                    "status" => "success",
                    "filePath" => $filePath,
                    "message" => "File uploaded successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to upload file"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error uploading file: " . $e->getMessage()
            ]);
        }
    }

    public function uploadVenueFile($file, $fileType) {
        try {
            // Validate file type for venues (images only)
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowedTypes)) {
                return json_encode([
                    "status" => "error",
                    "message" => "Invalid file type. Only images are allowed for venue uploads."
                ]);
            }

            // Validate file size (max 5MB for venue images)
            if ($file['size'] > 5 * 1024 * 1024) {
                return json_encode([
                    "status" => "error",
                    "message" => "File too large. Maximum 5MB allowed for venue images."
                ]);
            }

            $uploadDir = "uploads/" . $fileType . "/";

            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Generate unique filename with timestamp
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                return json_encode([
                    "status" => "success",
                    "filePath" => $filePath,
                    "fileName" => $fileName,
                    "message" => "Venue file uploaded successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to upload venue file"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error uploading venue file: " . $e->getMessage()
            ]);
        }
    }

    public function uploadPaymentProof($eventId, $file, $description, $proofType) {
        try {
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                return json_encode(["status" => "error", "message" => "File upload error"]);
            }

            // Validate file size (max 10MB)
            if ($file['size'] > 10 * 1024 * 1024) {
                return json_encode(["status" => "error", "message" => "File too large. Maximum 10MB allowed."]);
            }

            // Validate file type (expanded to support more document types)
            $allowedTypes = [
                // Images
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
                // Documents
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                // Text files
                'text/plain', 'text/csv',
                // Other common formats
                'application/zip', 'application/x-zip-compressed'
            ];
            if (!in_array($file['type'], $allowedTypes)) {
                return json_encode(["status" => "error", "message" => "Invalid file type. Allowed: Images, PDF, Word, Excel, PowerPoint, Text, and ZIP files."]);
            }

            // Create payment proofs directory
            $uploadDir = "uploads/payment_proofs/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "payment_proof_{$eventId}_" . time() . '_' . uniqid() . '.' . $extension;
            $targetPath = $uploadDir . $filename;

            // Move the uploaded file
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Create new attachment object
                $newAttachment = [
                    'file_name' => $filename,
                    'original_name' => $file['name'],
                    'file_path' => $targetPath,
                    'file_size' => $file['size'],
                    'file_type' => $file['type'],
                    'description' => $description,
                    'proof_type' => $proofType, // receipt, screenshot, bank_slip, other
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ];

                return json_encode([
                    "status" => "success",
                    "message" => "Payment proof uploaded successfully",
                    "attachment" => $newAttachment
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to move uploaded file"]);
            }

        } catch (Exception $e) {
            error_log("uploadPaymentProof error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Upload error: " . $e->getMessage()]);
        }
    }

    public function getPaymentProofs($eventId) {
        try {
            // Get payment proofs from individual payments
            $sql = "SELECT
                        p.payment_id,
                        p.payment_amount,
                        p.payment_method,
                        p.payment_date,
                        p.payment_reference,
                        p.payment_attachments,
                        p.payment_status
                    FROM tbl_payments p
                    WHERE p.event_id = ? AND p.payment_attachments IS NOT NULL
                    ORDER BY p.payment_date DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$eventId]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $allProofs = [];
            foreach ($payments as $payment) {
                if ($payment['payment_attachments']) {
                    $attachments = json_decode($payment['payment_attachments'], true) ?: [];
                    foreach ($attachments as $attachment) {
                        $attachment['payment_id'] = $payment['payment_id'];
                        $attachment['payment_amount'] = $payment['payment_amount'];
                        $attachment['payment_method'] = $payment['payment_method'];
                        $attachment['payment_date'] = $payment['payment_date'];
                        $attachment['payment_reference'] = $payment['payment_reference'];
                        $attachment['payment_status'] = $payment['payment_status'];
                        $allProofs[] = $attachment;
                    }
                }
            }

            return json_encode([
                "status" => "success",
                "payment_proofs" => $allProofs
            ]);

        } catch (Exception $e) {
            error_log("getPaymentProofs error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Error retrieving payment proofs: " . $e->getMessage()]);
        }
    }

    public function deletePaymentProof($paymentId, $fileName) {
        try {
            // Get current payment attachments
            $sql = "SELECT payment_attachments FROM tbl_payments WHERE payment_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment || !$payment['payment_attachments']) {
                return json_encode(["status" => "error", "message" => "No attachments found"]);
            }

            $attachments = json_decode($payment['payment_attachments'], true) ?: [];

            // Find and remove the payment proof
            $fileFound = false;
            $filePath = "";
            $attachments = array_filter($attachments, function($attachment) use ($fileName, &$fileFound, &$filePath) {
                if ($attachment['file_name'] === $fileName) {
                    $fileFound = true;
                    $filePath = $attachment['file_path'];
                    return false; // Remove this attachment
                }
                return true; // Keep this attachment
            });

            if (!$fileFound) {
                return json_encode(["status" => "error", "message" => "Payment proof not found"]);
            }

            // Delete physical file
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Update payment with remaining attachments
            $updateSql = "UPDATE tbl_payments SET payment_attachments = ? WHERE payment_id = ?";
            $updateStmt = $this->conn->prepare($updateSql);
            $updateStmt->execute([json_encode(array_values($attachments)), $paymentId]);

            return json_encode([
                "status" => "success",
                "message" => "Payment proof deleted successfully"
            ]);

        } catch (Exception $e) {
            error_log("deletePaymentProof error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Error deleting payment proof: " . $e->getMessage()]);
        }
    }

    // Wedding Details Methods
    public function saveWeddingDetails($data) {
        try {
            $this->conn->beginTransaction();

            // Map form field names to database column names
            $mappedData = [
                'event_id' => $data['event_id'],
                'nuptial' => $data['nuptial'] ?? null,
                'motif' => $data['motif'] ?? null,

                // Bride & Groom (map form field names to DB column names)
                'bride_name' => $data['bride_name'] ?? null,
                'bride_size' => $data['bride_gown_size'] ?? $data['bride_size'] ?? null, // Map bride_gown_size to bride_size
                'groom_name' => $data['groom_name'] ?? null,
                'groom_size' => $data['groom_attire_size'] ?? $data['groom_size'] ?? null, // Map groom_attire_size to groom_size

                // Parents (map form field names to DB column names)
                'mother_bride_name' => $data['mothers_attire_name'] ?? $data['mother_bride_name'] ?? null,
                'mother_bride_size' => $data['mothers_attire_size'] ?? $data['mother_bride_size'] ?? null,
                'father_bride_name' => $data['fathers_attire_name'] ?? $data['father_bride_name'] ?? null,
                'father_bride_size' => $data['fathers_attire_size'] ?? $data['father_bride_size'] ?? null,
                'mother_groom_name' => $data['mother_groom_name'] ?? null,
                'mother_groom_size' => $data['mother_groom_size'] ?? null,
                'father_groom_name' => $data['father_groom_name'] ?? null,
                'father_groom_size' => $data['father_groom_size'] ?? null,

                // Principal Sponsors
                'maid_of_honor_name' => $data['maid_of_honor_name'] ?? null,
                'maid_of_honor_size' => $data['maid_of_honor_size'] ?? null,
                'best_man_name' => $data['best_man_name'] ?? null,
                'best_man_size' => $data['best_man_size'] ?? null,

                // Little Bride & Groom
                'little_bride_name' => $data['little_bride_name'] ?? null,
                'little_bride_size' => $data['little_bride_size'] ?? null,
                'little_groom_name' => $data['little_groom_name'] ?? null,
                'little_groom_size' => $data['little_groom_size'] ?? null,

                // Processing Info
                'prepared_by' => $data['prepared_by'] ?? null,
                'received_by' => $data['received_by'] ?? null,
                'pickup_date' => $data['pick_up_date'] ?? $data['pickup_date'] ?? null, // Map pick_up_date to pickup_date
                'return_date' => $data['return_date'] ?? null,
                'customer_signature' => $data['customer_signature'] ?? null
            ];

            // Process wedding party arrays and convert to quantities + JSON
            $weddingParties = ['bridesmaids', 'groomsmen', 'junior_groomsmen', 'flower_girls', 'bearers'];
            foreach ($weddingParties as $party) {
                if (isset($data[$party]) && is_array($data[$party])) {
                    // Extract quantities
                    $mappedData[$party . '_qty'] = count($data[$party]);

                    // Extract names as JSON
                    $names = array_map(function($member) {
                        return $member['name'] ?? '';
                    }, $data[$party]);
                    $mappedData[$party . '_names'] = json_encode($names);
                } else {
                    $mappedData[$party . '_qty'] = 0;
                    $mappedData[$party . '_names'] = json_encode([]);
                }
            }

            // Handle bearer-specific fields (since form uses 'bearers' but DB has specific types)
            $mappedData['ring_bearer_qty'] = 0;
            $mappedData['bible_bearer_qty'] = 0;
            $mappedData['coin_bearer_qty'] = 0;
            $mappedData['ring_bearer_names'] = json_encode([]);
            $mappedData['bible_bearer_names'] = json_encode([]);
            $mappedData['coin_bearer_names'] = json_encode([]);

            // Process wedding items quantities (map form field names to DB column names)
            $itemMappings = [
                'cushions_quantity' => 'cushions_qty',
                'headdress_for_bride_quantity' => 'headdress_qty',
                'shawls_quantity' => 'shawls_qty',
                'veil_cord_quantity' => 'veil_cord_qty',
                'basket_quantity' => 'basket_qty',
                'petticoat_quantity' => 'petticoat_qty',
                'neck_bowtie_quantity' => 'neck_bowtie_qty',
                'garter_leg_quantity' => 'garter_leg_qty',
                'fitting_form_quantity' => 'fitting_form_qty',
                'robe_quantity' => 'robe_qty'
            ];

            foreach ($itemMappings as $formField => $dbField) {
                $mappedData[$dbField] = $data[$formField] ?? $data[$dbField] ?? 0;
            }

            // Check if wedding details already exist for this event
            $checkSql = "SELECT id FROM tbl_wedding_details WHERE event_id = ?";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute([$mappedData['event_id']]);
            $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingRecord) {
                // Update existing record
                $sql = "UPDATE tbl_wedding_details SET
                            nuptial = ?, motif = ?, bride_name = ?, bride_size = ?,
                            groom_name = ?, groom_size = ?, mother_bride_name = ?, mother_bride_size = ?,
                            father_bride_name = ?, father_bride_size = ?, mother_groom_name = ?, mother_groom_size = ?,
                            father_groom_name = ?, father_groom_size = ?, maid_of_honor_name = ?, maid_of_honor_size = ?,
                            best_man_name = ?, best_man_size = ?, little_bride_name = ?, little_bride_size = ?,
                            little_groom_name = ?, little_groom_size = ?,
                            bridesmaids_qty = ?, groomsmen_qty = ?, junior_groomsmen_qty = ?, flower_girls_qty = ?,
                            ring_bearer_qty = ?, bible_bearer_qty = ?, coin_bearer_qty = ?,
                            bridesmaids_names = ?, groomsmen_names = ?, junior_groomsmen_names = ?, flower_girls_names = ?,
                            ring_bearer_names = ?, bible_bearer_names = ?, coin_bearer_names = ?,
                            cushions_qty = ?, headdress_qty = ?, shawls_qty = ?, veil_cord_qty = ?,
                            basket_qty = ?, petticoat_qty = ?, neck_bowtie_qty = ?,
                            garter_leg_qty = ?, fitting_form_qty = ?, robe_qty = ?,
                            prepared_by = ?, received_by = ?, pickup_date = ?, return_date = ?,
                            customer_signature = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE event_id = ?";

                $stmt = $this->conn->prepare($sql);
                $stmt->execute([
                    $mappedData['nuptial'], $mappedData['motif'],
                    $mappedData['bride_name'], $mappedData['bride_size'],
                    $mappedData['groom_name'], $mappedData['groom_size'],
                    $mappedData['mother_bride_name'], $mappedData['mother_bride_size'],
                    $mappedData['father_bride_name'], $mappedData['father_bride_size'],
                    $mappedData['mother_groom_name'], $mappedData['mother_groom_size'],
                    $mappedData['father_groom_name'], $mappedData['father_groom_size'],
                    $mappedData['maid_of_honor_name'], $mappedData['maid_of_honor_size'],
                    $mappedData['best_man_name'], $mappedData['best_man_size'],
                    $mappedData['little_bride_name'], $mappedData['little_bride_size'],
                    $mappedData['little_groom_name'], $mappedData['little_groom_size'],
                    $mappedData['bridesmaids_qty'], $mappedData['groomsmen_qty'],
                    $mappedData['junior_groomsmen_qty'], $mappedData['flower_girls_qty'],
                    $mappedData['ring_bearer_qty'], $mappedData['bible_bearer_qty'], $mappedData['coin_bearer_qty'],
                    $mappedData['bridesmaids_names'], $mappedData['groomsmen_names'],
                    $mappedData['junior_groomsmen_names'], $mappedData['flower_girls_names'],
                    $mappedData['ring_bearer_names'], $mappedData['bible_bearer_names'], $mappedData['coin_bearer_names'],
                    $mappedData['cushions_qty'], $mappedData['headdress_qty'], $mappedData['shawls_qty'],
                    $mappedData['veil_cord_qty'], $mappedData['basket_qty'], $mappedData['petticoat_qty'],
                    $mappedData['neck_bowtie_qty'], $mappedData['garter_leg_qty'],
                    $mappedData['fitting_form_qty'], $mappedData['robe_qty'],
                    $mappedData['prepared_by'], $mappedData['received_by'],
                    $mappedData['pickup_date'], $mappedData['return_date'],
                    $mappedData['customer_signature'], $mappedData['event_id']
                ]);
            } else {
                // Insert new record
                $sql = "INSERT INTO tbl_wedding_details (
                            event_id, nuptial, motif, bride_name, bride_size,
                            groom_name, groom_size, mother_bride_name, mother_bride_size,
                            father_bride_name, father_bride_size, mother_groom_name, mother_groom_size,
                            father_groom_name, father_groom_size, maid_of_honor_name, maid_of_honor_size,
                            best_man_name, best_man_size, little_bride_name, little_bride_size,
                            little_groom_name, little_groom_size,
                            bridesmaids_qty, groomsmen_qty, junior_groomsmen_qty, flower_girls_qty,
                            ring_bearer_qty, bible_bearer_qty, coin_bearer_qty,
                            bridesmaids_names, groomsmen_names, junior_groomsmen_names, flower_girls_names,
                            ring_bearer_names, bible_bearer_names, coin_bearer_names,
                            cushions_qty, headdress_qty, shawls_qty, veil_cord_qty,
                            basket_qty, petticoat_qty, neck_bowtie_qty,
                            garter_leg_qty, fitting_form_qty, robe_qty,
                            prepared_by, received_by, pickup_date, return_date, customer_signature
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                        )";

                $stmt = $this->conn->prepare($sql);
                $stmt->execute([
                    $mappedData['event_id'], $mappedData['nuptial'], $mappedData['motif'],
                    $mappedData['bride_name'], $mappedData['bride_size'],
                    $mappedData['groom_name'], $mappedData['groom_size'],
                    $mappedData['mother_bride_name'], $mappedData['mother_bride_size'],
                    $mappedData['father_bride_name'], $mappedData['father_bride_size'],
                    $mappedData['mother_groom_name'], $mappedData['mother_groom_size'],
                    $mappedData['father_groom_name'], $mappedData['father_groom_size'],
                    $mappedData['maid_of_honor_name'], $mappedData['maid_of_honor_size'],
                    $mappedData['best_man_name'], $mappedData['best_man_size'],
                    $mappedData['little_bride_name'], $mappedData['little_bride_size'],
                    $mappedData['little_groom_name'], $mappedData['little_groom_size'],
                    $mappedData['bridesmaids_qty'], $mappedData['groomsmen_qty'],
                    $mappedData['junior_groomsmen_qty'], $mappedData['flower_girls_qty'],
                    $mappedData['ring_bearer_qty'], $mappedData['bible_bearer_qty'], $mappedData['coin_bearer_qty'],
                    $mappedData['bridesmaids_names'], $mappedData['groomsmen_names'],
                    $mappedData['junior_groomsmen_names'], $mappedData['flower_girls_names'],
                    $mappedData['ring_bearer_names'], $mappedData['bible_bearer_names'], $mappedData['coin_bearer_names'],
                    $mappedData['cushions_qty'], $mappedData['headdress_qty'], $mappedData['shawls_qty'],
                    $mappedData['veil_cord_qty'], $mappedData['basket_qty'], $mappedData['petticoat_qty'],
                    $mappedData['neck_bowtie_qty'], $mappedData['garter_leg_qty'],
                    $mappedData['fitting_form_qty'], $mappedData['robe_qty'],
                    $mappedData['prepared_by'], $mappedData['received_by'],
                    $mappedData['pickup_date'], $mappedData['return_date'],
                    $mappedData['customer_signature']
                ]);
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Wedding details saved successfully",
                "debug" => [
                    "mapped_data" => $mappedData,
                    "original_data" => $data
                ]
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("saveWeddingDetails error: " . $e->getMessage());
            error_log("Wedding data received: " . json_encode($data));
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getWeddingDetails($eventId) {
        try {
            $sql = "SELECT * FROM tbl_wedding_details WHERE event_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$eventId]);
            $weddingDetails = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($weddingDetails) {
                // Map database column names back to form field names
                $formData = [
                    'nuptial' => $weddingDetails['nuptial'],
                    'motif' => $weddingDetails['motif'],

                    // Map DB field names back to form field names
                    'bride_name' => $weddingDetails['bride_name'],
                    'bride_gown_size' => $weddingDetails['bride_size'], // Map bride_size back to bride_gown_size
                    'groom_name' => $weddingDetails['groom_name'],
                    'groom_attire_size' => $weddingDetails['groom_size'], // Map groom_size back to groom_attire_size

                    // Parents
                    'mothers_attire_name' => $weddingDetails['mother_bride_name'],
                    'mothers_attire_size' => $weddingDetails['mother_bride_size'],
                    'fathers_attire_name' => $weddingDetails['father_bride_name'],
                    'fathers_attire_size' => $weddingDetails['father_bride_size'],
                    'mother_groom_name' => $weddingDetails['mother_groom_name'],
                    'mother_groom_size' => $weddingDetails['mother_groom_size'],
                    'father_groom_name' => $weddingDetails['father_groom_name'],
                    'father_groom_size' => $weddingDetails['father_groom_size'],

                    // Principal Sponsors
                    'maid_of_honor_name' => $weddingDetails['maid_of_honor_name'],
                    'maid_of_honor_size' => $weddingDetails['maid_of_honor_size'],
                    'best_man_name' => $weddingDetails['best_man_name'],
                    'best_man_size' => $weddingDetails['best_man_size'],

                    // Little Bride & Groom
                    'little_bride_name' => $weddingDetails['little_bride_name'],
                    'little_bride_size' => $weddingDetails['little_bride_size'],
                    'little_groom_name' => $weddingDetails['little_groom_name'],
                    'little_groom_size' => $weddingDetails['little_groom_size'],

                    // Processing Info
                    'prepared_by' => $weddingDetails['prepared_by'],
                    'received_by' => $weddingDetails['received_by'],
                    'pick_up_date' => $weddingDetails['pickup_date'], // Map pickup_date back to pick_up_date
                    'return_date' => $weddingDetails['return_date'],
                    'customer_signature' => $weddingDetails['customer_signature'],

                    // Wedding Items (map DB field names back to form field names)
                    'cushions_quantity' => $weddingDetails['cushions_qty'] ?? 0,
                    'headdress_for_bride_quantity' => $weddingDetails['headdress_qty'] ?? 0,
                    'shawls_quantity' => $weddingDetails['shawls_qty'] ?? 0,
                    'veil_cord_quantity' => $weddingDetails['veil_cord_qty'] ?? 0,
                    'basket_quantity' => $weddingDetails['basket_qty'] ?? 0,
                    'petticoat_quantity' => $weddingDetails['petticoat_qty'] ?? 0,
                    'neck_bowtie_quantity' => $weddingDetails['neck_bowtie_qty'] ?? 0,
                    'garter_leg_quantity' => $weddingDetails['garter_leg_qty'] ?? 0,
                    'fitting_form_quantity' => $weddingDetails['fitting_form_qty'] ?? 0,
                    'robe_quantity' => $weddingDetails['robe_qty'] ?? 0
                ];

                // Convert wedding party data back to arrays
                $weddingParties = ['bridesmaids', 'groomsmen', 'junior_groomsmen', 'flower_girls'];
                foreach ($weddingParties as $party) {
                    $names = json_decode($weddingDetails[$party . '_names'] ?? '[]', true);
                    $formData[$party] = [];

                    foreach ($names as $name) {
                        $formData[$party][] = [
                            'name' => $name,
                            'size' => '' // Size information is not stored separately for party members
                        ];
                    }
                }

                // Handle bearers separately
                $bearerNames = json_decode($weddingDetails['ring_bearer_names'] ?? '[]', true);
                $formData['bearers'] = [];
                foreach ($bearerNames as $name) {
                    $formData['bearers'][] = [
                        'name' => $name,
                        'size' => ''
                    ];
                }

                return json_encode([
                    "status" => "success",
                    "wedding_details" => $formData
                ]);
            } else {
                return json_encode([
                    "status" => "success",
                    "wedding_details" => null
                ]);
            }
        } catch (Exception $e) {
            error_log("getWeddingDetails error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    // Migration Methods
    public function runWeddingMigration() {
        try {
            $this->conn->beginTransaction();

            // Drop existing table if it exists to avoid conflicts
            $dropSql = "DROP TABLE IF EXISTS `tbl_wedding_details`";
            $this->conn->exec($dropSql);

            // Create the enhanced wedding details table
            $createSql = "CREATE TABLE `tbl_wedding_details` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `event_id` int(11) NOT NULL,

                -- Basic Information
                `nuptial` varchar(255) DEFAULT NULL,
                `motif` varchar(255) DEFAULT NULL,

                -- Bride & Groom Details
                `bride_name` varchar(255) DEFAULT NULL,
                `bride_size` varchar(50) DEFAULT NULL,
                `groom_name` varchar(255) DEFAULT NULL,
                `groom_size` varchar(50) DEFAULT NULL,

                -- Parents Details
                `mother_bride_name` varchar(255) DEFAULT NULL,
                `mother_bride_size` varchar(50) DEFAULT NULL,
                `father_bride_name` varchar(255) DEFAULT NULL,
                `father_bride_size` varchar(50) DEFAULT NULL,
                `mother_groom_name` varchar(255) DEFAULT NULL,
                `mother_groom_size` varchar(50) DEFAULT NULL,
                `father_groom_name` varchar(255) DEFAULT NULL,
                `father_groom_size` varchar(50) DEFAULT NULL,

                -- Principal Sponsors
                `maid_of_honor_name` varchar(255) DEFAULT NULL,
                `maid_of_honor_size` varchar(50) DEFAULT NULL,
                `best_man_name` varchar(255) DEFAULT NULL,
                `best_man_size` varchar(50) DEFAULT NULL,

                -- Little Bride & Groom
                `little_bride_name` varchar(255) DEFAULT NULL,
                `little_bride_size` varchar(50) DEFAULT NULL,
                `little_groom_name` varchar(255) DEFAULT NULL,
                `little_groom_size` varchar(50) DEFAULT NULL,

                -- Wedding Party Quantities
                `bridesmaids_qty` int(11) DEFAULT 0,
                `groomsmen_qty` int(11) DEFAULT 0,
                `junior_groomsmen_qty` int(11) DEFAULT 0,
                `flower_girls_qty` int(11) DEFAULT 0,
                `ring_bearer_qty` int(11) DEFAULT 0,
                `bible_bearer_qty` int(11) DEFAULT 0,
                `coin_bearer_qty` int(11) DEFAULT 0,

                -- Wedding Party Names (stored as JSON arrays)
                `bridesmaids_names` JSON DEFAULT NULL,
                `groomsmen_names` JSON DEFAULT NULL,
                `junior_groomsmen_names` JSON DEFAULT NULL,
                `flower_girls_names` JSON DEFAULT NULL,
                `ring_bearer_names` JSON DEFAULT NULL,
                `bible_bearer_names` JSON DEFAULT NULL,
                `coin_bearer_names` JSON DEFAULT NULL,

                -- Wedding Items Quantities
                `cushions_qty` int(11) DEFAULT 0,
                `headdress_qty` int(11) DEFAULT 0,
                `shawls_qty` int(11) DEFAULT 0,
                `veil_cord_qty` int(11) DEFAULT 0,
                `basket_qty` int(11) DEFAULT 0,
                `petticoat_qty` int(11) DEFAULT 0,
                `neck_bowtie_qty` int(11) DEFAULT 0,
                `garter_leg_qty` int(11) DEFAULT 0,
                `fitting_form_qty` int(11) DEFAULT 0,
                `robe_qty` int(11) DEFAULT 0,

                -- Processing Information
                `prepared_by` varchar(255) DEFAULT NULL,
                `received_by` varchar(255) DEFAULT NULL,
                `pickup_date` date DEFAULT NULL,
                `return_date` date DEFAULT NULL,
                `customer_signature` varchar(255) DEFAULT NULL,

                -- Metadata
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_event_wedding` (`event_id`),
                KEY `idx_wedding_event_id` (`event_id`),
                KEY `idx_wedding_bride_groom` (`bride_name`, `groom_name`),
                CONSTRAINT `fk_wedding_details_event` FOREIGN KEY (`event_id`) REFERENCES `tbl_events` (`event_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $this->conn->exec($createSql);
            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Wedding details table created successfully with enhanced structure"
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("runWeddingMigration error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Migration failed: " . $e->getMessage()
            ]);
        }
    }

    // Analytics and Reports Methods
    public function getAnalyticsData($adminId, $startDate = null, $endDate = null) {
        try {
            if (!$startDate) $startDate = date('Y-m-01'); // First day of current month
            if (!$endDate) $endDate = date('Y-m-t'); // Last day of current month

            $analytics = [];

            // Monthly revenue trend (last 12 months)
            $monthlyRevenueSql = "SELECT
                                    DATE_FORMAT(p.payment_date, '%Y-%m') as month,
                                    SUM(p.payment_amount) as revenue,
                                    COUNT(DISTINCT p.event_id) as events_with_payments
                                FROM tbl_payments p
                                JOIN tbl_events e ON p.event_id = e.event_id
                                WHERE e.admin_id = ?
                                AND p.payment_status = 'completed'
                                AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
                                ORDER BY month DESC";
            $stmt = $this->conn->prepare($monthlyRevenueSql);
            $stmt->execute([$adminId]);
            $analytics['monthlyRevenue'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Event types distribution
            $eventTypesSql = "SELECT
                                et.event_name,
                                COUNT(e.event_id) as count,
                                COALESCE(SUM(e.total_budget), 0) as total_budget
                            FROM tbl_events e
                            JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                            WHERE e.admin_id = ?
                            GROUP BY et.event_type_id, et.event_name
                            ORDER BY count DESC";
            $stmt = $this->conn->prepare($eventTypesSql);
            $stmt->execute([$adminId]);
            $analytics['eventTypes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Payment status breakdown
            $paymentStatusSql = "SELECT
                                   e.payment_status,
                                   COUNT(e.event_id) as count,
                                   COALESCE(SUM(e.total_budget), 0) as total_amount
                               FROM tbl_events e
                               WHERE e.admin_id = ?
                               GROUP BY e.payment_status
                               ORDER BY count DESC";
            $stmt = $this->conn->prepare($paymentStatusSql);
            $stmt->execute([$adminId]);
            $analytics['paymentStatus'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top venues by usage
            $topVenuesSql = "SELECT
                               v.venue_title,
                               COUNT(e.event_id) as events_count,
                               COALESCE(SUM(e.total_budget), 0) as total_revenue
                           FROM tbl_events e
                           JOIN tbl_venue v ON e.venue_id = v.venue_id
                           WHERE e.admin_id = ?
                           GROUP BY v.venue_id, v.venue_title
                           ORDER BY events_count DESC
                           LIMIT 10";
            $stmt = $this->conn->prepare($topVenuesSql);
            $stmt->execute([$adminId]);
            $analytics['topVenues'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top packages by usage
            $topPackagesSql = "SELECT
                                 p.package_title,
                                 COUNT(e.event_id) as events_count,
                                 COALESCE(SUM(e.total_budget), 0) as total_revenue
                             FROM tbl_events e
                             JOIN tbl_packages p ON e.package_id = p.package_id
                             WHERE e.admin_id = ?
                             GROUP BY p.package_id, p.package_title
                             ORDER BY events_count DESC
                             LIMIT 10";
            $stmt = $this->conn->prepare($topPackagesSql);
            $stmt->execute([$adminId]);
            $analytics['topPackages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Client statistics
            $clientStatsSql = "SELECT
                                 CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                                 COUNT(e.event_id) as events_count,
                                 COALESCE(SUM(e.total_budget), 0) as total_spent,
                                 MAX(e.created_at) as last_event_date
                             FROM tbl_events e
                             JOIN tbl_users u ON e.user_id = u.user_id
                             WHERE e.admin_id = ?
                             GROUP BY e.user_id, u.user_firstName, u.user_lastName
                             ORDER BY total_spent DESC
                             LIMIT 10";
            $stmt = $this->conn->prepare($clientStatsSql);
            $stmt->execute([$adminId]);
            $analytics['topClients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Payment method distribution
            $paymentMethodsSql = "SELECT
                                    p.payment_method,
                                    COUNT(p.payment_id) as count,
                                    COALESCE(SUM(p.payment_amount), 0) as total_amount
                                FROM tbl_payments p
                                JOIN tbl_events e ON p.event_id = e.event_id
                                WHERE e.admin_id = ? AND p.payment_status = 'completed'
                                GROUP BY p.payment_method
                                ORDER BY total_amount DESC";
            $stmt = $this->conn->prepare($paymentMethodsSql);
            $stmt->execute([$adminId]);
            $analytics['paymentMethods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "analytics" => $analytics
            ]);
        } catch (Exception $e) {
            error_log("getAnalyticsData error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getReportsData($adminId, $reportType = 'summary', $startDate = null, $endDate = null) {
        try {
            if (!$startDate) $startDate = date('Y-m-01');
            if (!$endDate) $endDate = date('Y-m-t');

            $reports = [];

            switch ($reportType) {
                case 'summary':
                    // Overall summary report
                    $summarySql = "SELECT
                                     COUNT(e.event_id) as total_events,
                                     COUNT(CASE WHEN e.event_status = 'completed' THEN 1 END) as completed_events,
                                     COUNT(CASE WHEN e.event_status = 'cancelled' THEN 1 END) as cancelled_events,
                                     COUNT(DISTINCT e.user_id) as unique_clients,
                                     COALESCE(SUM(e.total_budget), 0) as total_contract_value,
                                     COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) as total_revenue_collected,
                                     AVG(e.total_budget) as average_event_value
                                 FROM tbl_events e
                                 LEFT JOIN tbl_payments p ON e.event_id = p.event_id
                                 WHERE e.admin_id = ?
                                 AND e.created_at BETWEEN ? AND ?";
                    $stmt = $this->conn->prepare($summarySql);
                    $stmt->execute([$adminId, $startDate, $endDate]);
                    $reports['summary'] = $stmt->fetch(PDO::FETCH_ASSOC);
                    break;

                case 'financial':
                    // Financial detailed report
                    $financialSql = "SELECT
                                       e.event_id,
                                       e.event_title,
                                       e.event_date,
                                       CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                                       e.total_budget,
                                       e.down_payment,
                                       e.payment_status,
                                       COALESCE(SUM(p.payment_amount), 0) as total_paid,
                                       (e.total_budget - COALESCE(SUM(p.payment_amount), 0)) as remaining_balance
                                   FROM tbl_events e
                                   JOIN tbl_users u ON e.user_id = u.user_id
                                   LEFT JOIN tbl_payments p ON e.event_id = p.event_id AND p.payment_status = 'completed'
                                   WHERE e.admin_id = ?
                                   AND e.created_at BETWEEN ? AND ?
                                   GROUP BY e.event_id
                                   ORDER BY e.event_date DESC";
                    $stmt = $this->conn->prepare($financialSql);
                    $stmt->execute([$adminId, $startDate, $endDate]);
                    $reports['financial'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'events':
                    // Events detailed report
                    $eventsSql = "SELECT
                                    e.event_id,
                                    e.event_title,
                                    e.event_date,
                                    et.event_name as event_type,
                                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                                    v.venue_title,
                                    p.package_title,
                                    e.guest_count,
                                    e.total_budget,
                                    e.event_status,
                                    e.payment_status,
                                    e.created_at
                                FROM tbl_events e
                                JOIN tbl_users u ON e.user_id = u.user_id
                                JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                                LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                                WHERE e.admin_id = ?
                                AND e.created_at BETWEEN ? AND ?
                                ORDER BY e.event_date DESC";
                    $stmt = $this->conn->prepare($eventsSql);
                    $stmt->execute([$adminId, $startDate, $endDate]);
                    $reports['events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'clients':
                    // Clients detailed report
                    $clientsSql = "SELECT
                                     u.user_id,
                                     CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                                     u.user_email,
                                     u.user_contact,
                                     COUNT(e.event_id) as total_events,
                                     COALESCE(SUM(e.total_budget), 0) as total_contract_value,
                                     COALESCE(SUM(p.payment_amount), 0) as total_payments,
                                     MIN(e.created_at) as first_event_date,
                                     MAX(e.created_at) as last_event_date
                                 FROM tbl_users u
                                 JOIN tbl_events e ON u.user_id = e.user_id
                                 LEFT JOIN tbl_payments p ON e.event_id = p.event_id AND p.payment_status = 'completed'
                                 WHERE e.admin_id = ?
                                 AND e.created_at BETWEEN ? AND ?
                                 GROUP BY u.user_id
                                 ORDER BY total_contract_value DESC";
                    $stmt = $this->conn->prepare($clientsSql);
                    $stmt->execute([$adminId, $startDate, $endDate]);
                    $reports['clients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
            }

            return json_encode([
                "status" => "success",
                "reports" => $reports,
                "reportType" => $reportType,
                "dateRange" => [
                    "startDate" => $startDate,
                    "endDate" => $endDate
                ]
            ]);
        } catch (Exception $e) {
            error_log("getReportsData error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
                  }
      }

    // New method to associate payment proof with a specific payment
    public function attachPaymentProof($paymentId, $file, $description, $proofType) {
        try {
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                return json_encode(["status" => "error", "message" => "File upload error"]);
            }

            // Validate file size (max 10MB)
            if ($file['size'] > 10 * 1024 * 1024) {
                return json_encode(["status" => "error", "message" => "File too large. Maximum 10MB allowed."]);
            }

            // Validate file type (expanded to support more document types)
            $allowedTypes = [
                // Images
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
                // Documents
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                // Text files
                'text/plain', 'text/csv',
                // Other common formats
                'application/zip', 'application/x-zip-compressed'
            ];
            if (!in_array($file['type'], $allowedTypes)) {
                return json_encode(["status" => "error", "message" => "Invalid file type. Allowed: Images, PDF, Word, Excel, PowerPoint, Text, and ZIP files."]);
            }

            // Check if payment exists
            $checkSql = "SELECT payment_id, event_id, payment_attachments FROM tbl_payments WHERE payment_id = ?";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute([$paymentId]);
            $payment = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                return json_encode(["status" => "error", "message" => "Payment not found"]);
            }

            // Create payment proofs directory
            $uploadDir = "uploads/payment_proofs/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "payment_proof_p{$paymentId}_" . time() . '_' . uniqid() . '.' . $extension;
            $targetPath = $uploadDir . $filename;

            // Move the uploaded file
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Get current payment attachments
                $attachments = [];
                if ($payment['payment_attachments']) {
                    $attachments = json_decode($payment['payment_attachments'], true) ?: [];
                }

                // Add new attachment
                $newAttachment = [
                    'file_name' => $filename,
                    'original_name' => $file['name'],
                    'file_path' => $targetPath,
                    'file_size' => $file['size'],
                    'file_type' => $file['type'],
                    'description' => $description,
                    'proof_type' => $proofType,
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ];

                $attachments[] = $newAttachment;

                // Update payment with new attachments
                $updateSql = "UPDATE tbl_payments SET payment_attachments = ? WHERE payment_id = ?";
                $updateStmt = $this->conn->prepare($updateSql);
                $updateStmt->execute([json_encode($attachments), $paymentId]);

                return json_encode([
                    "status" => "success",
                    "message" => "Payment proof attached successfully",
                    "attachment" => $newAttachment
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to move uploaded file"]);
            }

        } catch (Exception $e) {
            error_log("attachPaymentProof error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Attachment error: " . $e->getMessage()]);
        }
    }

        public function getEventsForPayments($adminId, $searchTerm = '') {
        try {
            $sql = "
                SELECT
                    e.event_id,
                    e.event_title,
                    e.event_date,
                    e.user_id as client_id,
                    e.total_budget,
                    e.payment_status as event_payment_status,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                    u.user_email as client_email,
                    u.user_contact as client_contact,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) as total_paid,
                    (e.total_budget - COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0)) as remaining_balance,
                    CASE
                        WHEN e.total_budget > 0 THEN ROUND((COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) / e.total_budget) * 100, 2)
                        ELSE 0
                    END as payment_percentage,
                    COUNT(p.payment_id) as payment_count
                FROM tbl_events e
                LEFT JOIN tbl_users u ON e.user_id = u.user_id
                LEFT JOIN tbl_payments p ON e.event_id = p.event_id
                WHERE e.admin_id = ?
            ";

            $params = [$adminId];

            if (!empty($searchTerm)) {
                $sql .= " AND (
                    e.event_title LIKE ? OR
                    e.event_id LIKE ? OR
                    CONCAT(u.user_firstName, ' ', u.user_lastName) LIKE ? OR
                    u.user_email LIKE ?
                )";
                $searchParam = '%' . $searchTerm . '%';
                $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
            }

            $sql .= " GROUP BY e.event_id ORDER BY e.event_date DESC, e.event_title ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "events" => $events
            ]);

        } catch (Exception $e) {
            error_log("getEventsForPayments error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch events: " . $e->getMessage()
            ]);
        }
    }

    public function getEventPaymentDetails($eventId) {
        try {
            // Get event details with payment summary
            $eventQuery = "
                SELECT
                    e.event_id,
                    e.event_title,
                    e.event_date,
                    e.event_time,
                    e.user_id as client_id,
                    e.total_budget,
                    e.payment_status as event_payment_status,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                    u.user_email as client_email,
                    u.user_contact as client_contact,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) as total_paid,
                    (e.total_budget - COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0)) as remaining_balance,
                    CASE
                        WHEN e.total_budget > 0 THEN ROUND((COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) / e.total_budget) * 100, 2)
                        ELSE 0
                    END as payment_percentage,
                    COUNT(p.payment_id) as total_payments,
                    COUNT(CASE WHEN p.payment_status = 'completed' THEN 1 END) as completed_payments,
                    COUNT(CASE WHEN p.payment_status = 'pending' THEN 1 END) as pending_payments
                FROM tbl_events e
                LEFT JOIN tbl_users u ON e.user_id = u.user_id
                LEFT JOIN tbl_payments p ON e.event_id = p.event_id
                WHERE e.event_id = ?
                GROUP BY e.event_id
            ";

            $stmt = $this->pdo->prepare($eventQuery);
            $stmt->execute([$eventId]);
            $eventDetails = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$eventDetails) {
                return json_encode([
                    "status" => "error",
                    "message" => "Event not found"
                ]);
            }

                        // Get payment history for this event
            $paymentsQuery = "
                SELECT
                    p.payment_id,
                    p.payment_amount,
                    p.payment_method,
                    p.payment_status,
                    p.payment_date,
                    p.payment_reference,
                    p.payment_notes,
                    p.payment_attachments,
                    p.created_at,
                    p.updated_at,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                    DATE_FORMAT(p.created_at, '%Y-%m-%d %H:%i:%s') as formatted_created_at,
                    DATE_FORMAT(p.updated_at, '%Y-%m-%d %H:%i:%s') as formatted_updated_at
                FROM tbl_payments p
                LEFT JOIN tbl_users u ON p.client_id = u.user_id
                WHERE p.event_id = ?
                ORDER BY p.created_at DESC, p.payment_date DESC
            ";

            $stmt = $this->pdo->prepare($paymentsQuery);
            $stmt->execute([$eventId]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Parse payment attachments for each payment
            foreach ($payments as &$payment) {
                if (!empty($payment['payment_attachments'])) {
                    $payment['attachments'] = json_decode($payment['payment_attachments'], true);
                } else {
                    $payment['attachments'] = [];
                }
            }

            // Get payment summary by method
            $paymentSummaryQuery = "
                SELECT
                    p.payment_method,
                    COUNT(*) as payment_count,
                    SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END) as total_amount
                FROM tbl_payments p
                WHERE p.event_id = ?
                GROUP BY p.payment_method
                ORDER BY total_amount DESC
            ";

            $stmt = $this->pdo->prepare($paymentSummaryQuery);
            $stmt->execute([$eventId]);
            $paymentSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "event" => $eventDetails,
                "payments" => $payments,
                "payment_summary" => $paymentSummary
            ]);

        } catch (Exception $e) {
            error_log("getEventPaymentDetails error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch event payment details: " . $e->getMessage()
            ]);
        }
    }

    public function uploadPaymentAttachment($eventId, $paymentId, $file, $description = '') {
        try {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/events-api/uploads/payment_proof/';

            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];

            if (!in_array($fileExtension, $allowedExtensions)) {
                return json_encode([
                    "status" => "error",
                    "message" => "Invalid file type. Allowed: " . implode(', ', $allowedExtensions)
                ]);
            }

            $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Get current payment attachments
                $stmt = $this->pdo->prepare("SELECT payment_attachments FROM tbl_payments WHERE payment_id = ?");
                $stmt->execute([$paymentId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                $attachments = [];
                if (!empty($result['payment_attachments'])) {
                    $attachments = json_decode($result['payment_attachments'], true) ?: [];
                }

                // Add new attachment
                $attachments[] = [
                    'filename' => $fileName,
                    'original_name' => $file['name'],
                    'description' => $description,
                    'file_size' => $file['size'],
                    'file_type' => $fileExtension,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];

                // Update payment with new attachments
                $updateStmt = $this->pdo->prepare("
                    UPDATE tbl_payments
                    SET payment_attachments = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE payment_id = ?
                ");
                $updateStmt->execute([json_encode($attachments), $paymentId]);

                return json_encode([
                    "status" => "success",
                    "filename" => $fileName,
                    "message" => "Payment attachment uploaded successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to upload file"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to upload payment attachment: " . $e->getMessage()
            ]);
        }
    }

    public function updateVenueStatus($venueId, $isActive) {
        try {
            $sql = "UPDATE tbl_venue SET is_active = :is_active WHERE venue_id = :venue_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'is_active' => $isActive ? 1 : 0,
                'venue_id' => $venueId
            ]);

            if ($stmt->rowCount() > 0) {
                return json_encode([
                    "status" => "success",
                    "message" => "Venue status updated successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "No venue found with the given ID"
                ]);
            }
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }


    public function updateVenueWithPriceHistory() {
        try {
            $this->pdo->beginTransaction();

            // Get current venue data
            $stmt = $this->pdo->prepare("SELECT venue_price FROM tbl_venue WHERE venue_id = ?");
            $stmt->execute([$_POST['venue_id']]);
            $currentVenue = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentVenue) {
                throw new Exception("Venue not found");
            }

            // If price is changing, record it in price history
            if (floatval($currentVenue['venue_price']) != floatval($_POST['venue_price'])) {
                $sql = "INSERT INTO tbl_venue_price_history (
                    venue_id, old_price, new_price
                ) VALUES (
                    :venue_id, :old_price, :new_price
                )";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    ':venue_id' => $_POST['venue_id'],
                    ':old_price' => $currentVenue['venue_price'],
                    ':new_price' => $_POST['venue_price']
                ]);
            }

            // Handle file uploads
            $profilePicture = null;
            $coverPhoto = null;

            if (isset($_FILES['venue_profile_picture']) && $_FILES['venue_profile_picture']['error'] === 0) {
                $profilePicture = $this->uploadFile($_FILES['venue_profile_picture'], 'venue_profile_pictures');
            }

            if (isset($_FILES['venue_cover_photo']) && $_FILES['venue_cover_photo']['error'] === 0) {
                $coverPhoto = $this->uploadFile($_FILES['venue_cover_photo'], 'venue_cover_photos');
            }

            // Update venue basic info
            $sql = "UPDATE tbl_venue SET
                venue_title = :venue_title,
                venue_details = :venue_details,
                venue_location = :venue_location,
                venue_contact = :venue_contact,
                venue_capacity = :venue_capacity,
                venue_price = :venue_price";

            // Only include files in update if they were uploaded
            if ($profilePicture) {
                $sql .= ", venue_profile_picture = :venue_profile_picture";
            }
            if ($coverPhoto) {
                $sql .= ", venue_cover_photo = :venue_cover_photo";
            }

            $sql .= " WHERE venue_id = :venue_id";

            $stmt = $this->pdo->prepare($sql);

            $params = [
                ':venue_title' => $_POST['venue_title'],
                ':venue_details' => $_POST['venue_details'],
                ':venue_location' => $_POST['venue_location'],
                ':venue_contact' => $_POST['venue_contact'],
                ':venue_capacity' => $_POST['venue_capacity'],
                ':venue_price' => $_POST['venue_price'],
                ':venue_id' => $_POST['venue_id']
            ];

            if ($profilePicture) {
                $params[':venue_profile_picture'] = $profilePicture;
            }
            if ($coverPhoto) {
                $params[':venue_cover_photo'] = $coverPhoto;
            }

            $stmt->execute($params);

            $this->pdo->commit();

            return json_encode([
                "status" => "success",
                "message" => "Venue updated successfully"
            ]);

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return json_encode([
                "status" => "error",
                "message" => "Failed to update venue: " . $e->getMessage()
            ]);
        }
    }

    // Event Component Management Methods
    public function addEventComponent($data) {
        try {
            $required = ['event_id', 'component_name', 'component_price'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    return json_encode(["status" => "error", "message" => "$field is required"]);
                }
            }

            $sql = "INSERT INTO tbl_event_components (
                        event_id, component_name, component_description,
                        component_price, is_custom, is_included,
                        original_package_component_id, display_order
                    ) VALUES (
                        :event_id, :name, :description,
                        :price, :is_custom, :is_included,
                        :original_package_component_id, :display_order
                    )";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                ':event_id' => $data['event_id'],
                ':name' => $data['component_name'],
                ':description' => $data['component_description'] ?? null,
                ':price' => $data['component_price'],
                ':is_custom' => $data['is_custom'] ?? true,
                ':is_included' => $data['is_included'] ?? true,
                ':original_package_component_id' => $data['original_package_component_id'] ?? null,
                ':display_order' => $data['display_order'] ?? 0
            ]);

            if ($result) {
                $componentId = $this->conn->lastInsertId();
                return json_encode([
                    "status" => "success",
                    "message" => "Component added successfully",
                    "component_id" => $componentId
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to add component"]);
            }
        } catch (Exception $e) {
            error_log("addEventComponent error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function updateEventComponent($data) {
        try {
            $required = ['component_id', 'component_name', 'component_price'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    return json_encode(["status" => "error", "message" => "$field is required"]);
                }
            }

            // Fetch the current event component
            $sql = "SELECT * FROM tbl_event_components WHERE component_id = :component_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':component_id' => $data['component_id']]);
            $eventComponent = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$eventComponent) {
                return json_encode(["status" => "error", "message" => "Component not found"]);
            }

            // If not custom, enforce no downgrade
            if (!$eventComponent['is_custom'] && $eventComponent['original_package_component_id']) {
                $sql = "SELECT component_price FROM tbl_package_components WHERE component_id = :original_id";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([':original_id' => $eventComponent['original_package_component_id']]);
                $original = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($original && floatval($data['component_price']) < floatval($original['component_price'])) {
                    return json_encode(["status" => "error", "message" => "Cannot downgrade inclusion price below original package value (₱" . number_format($original['component_price'],2) . ")"]);
                }
            }

            $sql = "UPDATE tbl_event_components SET
                        component_name = :name,
                        component_description = :description,
                        component_price = :price,
                        is_included = :is_included
                    WHERE component_id = :component_id";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                ':component_id' => $data['component_id'],
                ':name' => $data['component_name'],
                ':description' => $data['component_description'] ?? null,
                ':price' => $data['component_price'],
                ':is_included' => $data['is_included'] ?? true
            ]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Component updated successfully"
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to update component"]);
            }
        } catch (Exception $e) {
            error_log("updateEventComponent error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function deleteEventComponent($componentId) {
        try {
            if (empty($componentId)) {
                return json_encode(["status" => "error", "message" => "Component ID is required"]);
            }

            $sql = "DELETE FROM tbl_event_components WHERE component_id = :component_id";
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([':component_id' => $componentId]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Component deleted successfully"
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to delete component"]);
            }
        } catch (Exception $e) {
            error_log("deleteEventComponent error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function updateEventBudget($eventId, $budgetChange) {
        try {
            // Get current budget
            $sql = "SELECT total_budget FROM tbl_events WHERE event_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$eventId]);
            $currentBudget = $stmt->fetch(PDO::FETCH_ASSOC)['total_budget'];

            // Calculate new budget
            $newBudget = $currentBudget + $budgetChange;

            // Update event budget
            $updateSql = "UPDATE tbl_events SET total_budget = ? WHERE event_id = ?";
            $updateStmt = $this->pdo->prepare($updateSql);
            $updateStmt->execute([$newBudget, $eventId]);

            return json_encode([
                "status" => "success",
                "message" => "Event budget updated successfully",
                "old_budget" => $currentBudget,
                "new_budget" => $newBudget,
                "change" => $budgetChange
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function getPackageVenues($packageId) {
        try {
            $sql = "SELECT v.*, pv.package_id
                    FROM tbl_venue v
                    INNER JOIN tbl_package_venues pv ON v.venue_id = pv.venue_id
                    WHERE pv.package_id = ? AND v.is_active = 1
                    ORDER BY v.venue_title ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$packageId]);
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "venues" => $venues
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function updateEventVenue($eventId, $venueId) {
        try {
            // First check if the venue is available for the event's package
            $checkSql = "SELECT e.package_id, pv.venue_id
                        FROM tbl_events e
                        INNER JOIN tbl_package_venues pv ON e.package_id = pv.package_id
                        WHERE e.event_id = ? AND pv.venue_id = ?";

            $checkStmt = $this->pdo->prepare($checkSql);
            $checkStmt->execute([$eventId, $venueId]);

            if (!$checkStmt->fetch()) {
                return json_encode([
                    "status" => "error",
                    "message" => "Selected venue is not available for this package"
                ]);
            }

            // Update the event venue
            $updateSql = "UPDATE tbl_events SET venue_id = ? WHERE event_id = ?";
            $updateStmt = $this->pdo->prepare($updateSql);
            $updateStmt->execute([$venueId, $eventId]);

            // Get venue details for response
            $venueSql = "SELECT venue_id, venue_title, venue_location, venue_price FROM tbl_venue WHERE venue_id = ?";
            $venueStmt = $this->pdo->prepare($venueSql);
            $venueStmt->execute([$venueId]);
            $venue = $venueStmt->fetch(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "message" => "Event venue updated successfully",
                "venue" => $venue
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function updateEventFinalization($eventId, $action) {
        try {
            // Get event details first
            $eventSql = "SELECT e.*, u.email as client_email, u.first_name, u.last_name
                        FROM tbl_events e
                        LEFT JOIN tbl_users u ON e.user_id = u.user_id
                        WHERE e.event_id = ?";
            $eventStmt = $this->pdo->prepare($eventSql);
            $eventStmt->execute([$eventId]);
            $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                return json_encode([
                    "status" => "error",
                    "message" => "Event not found"
                ]);
            }

            if ($action === "lock") {
                // Finalize and lock the event
                $updateSql = "UPDATE tbl_events SET
                             event_status = 'finalized',
                             finalized_at = NOW(),
                             updated_at = NOW()
                             WHERE event_id = ?";
                $updateStmt = $this->pdo->prepare($updateSql);
                $updateStmt->execute([$eventId]);

                // Send notification to organizer about upcoming payments
                $this->sendFinalizationNotification($event);

                return json_encode([
                    "status" => "success",
                    "message" => "Event has been finalized and locked for editing",
                    "finalized_at" => date('Y-m-d H:i:s')
                ]);
            } else {
                // Unlock the event
                $updateSql = "UPDATE tbl_events SET
                             event_status = 'confirmed',
                             finalized_at = NULL,
                             updated_at = NOW()
                             WHERE event_id = ?";
                $updateStmt = $this->pdo->prepare($updateSql);
                $updateStmt->execute([$eventId]);

                return json_encode([
                    "status" => "success",
                    "message" => "Event has been unlocked for editing"
                ]);
            }
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    private function sendFinalizationNotification($event) {
        try {
            // Create notification for the organizer
            $notificationSql = "INSERT INTO tbl_notifications (
                user_id,
                notification_type,
                title,
                message,
                related_id,
                created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())";

            $notificationStmt = $this->pdo->prepare($notificationSql);
            $notificationStmt->execute([
                $event['user_id'],
                'event_finalized',
                'Event Finalized',
                "Your event '{$event['event_title']}' has been finalized. Please check your payment schedule for upcoming payments.",
                $event['event_id']
            ]);

            // Log the finalization activity
            $logSql = "INSERT INTO tbl_payment_logs (
                event_id,
                client_id,
                action_type,
                notes,
                created_at
            ) VALUES (?, ?, ?, ?, NOW())";

            $logStmt = $this->pdo->prepare($logSql);
            $logStmt->execute([
                $event['event_id'],
                $event['user_id'],
                'event_finalized',
                'Event finalized by admin. Organizer notified about payment schedule.'
            ]);

        } catch (Exception $e) {
            error_log("Error sending finalization notification: " . $e->getMessage());
        }
    }

    /**
     * Calculate package budget breakdown including buffer/overage
     */
    public function getPackageBudgetBreakdown($packageId) {
        try {
            // Get package details
            $packageSql = "SELECT package_id, package_title, package_price, original_price, is_price_locked FROM tbl_packages WHERE package_id = :package_id";
            $packageStmt = $this->conn->prepare($packageSql);
            $packageStmt->execute([':package_id' => $packageId]);
            $package = $packageStmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Get components total
            $componentsSql = "SELECT COALESCE(SUM(component_price), 0) as total_cost FROM tbl_package_components WHERE package_id = :package_id";
            $componentsStmt = $this->conn->prepare($componentsSql);
            $componentsStmt->execute([':package_id' => $packageId]);
            $componentsTotal = floatval($componentsStmt->fetchColumn());

            $packagePrice = floatval($package['package_price']);
            $difference = $packagePrice - $componentsTotal;

            $budgetStatus = 'EXACT';
            if ($difference > 0) {
                $budgetStatus = 'BUFFER';
            } elseif ($difference < 0) {
                $budgetStatus = 'OVERAGE';
            }

            return json_encode([
                "status" => "success",
                "budget_breakdown" => [
                    "package_id" => $package['package_id'],
                    "package_title" => $package['package_title'],
                    "package_price" => $packagePrice,
                    "original_price" => floatval($package['original_price']),
                    "is_price_locked" => boolval($package['is_price_locked']),
                    "inclusions_total" => $componentsTotal,
                    "difference" => $difference,
                    "difference_absolute" => abs($difference),
                    "budget_status" => $budgetStatus,
                    "buffer_amount" => $difference > 0 ? $difference : 0,
                    "overage_amount" => $difference < 0 ? abs($difference) : 0,
                    "margin_percentage" => $packagePrice > 0 ? ($difference / $packagePrice) * 100 : 0
                ]
            ]);
        } catch (Exception $e) {
            error_log("getPackageBudgetBreakdown error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    /**
     * Validate package budget before saving
     */
    public function validatePackageBudget($packageId, $components) {
        try {
            // Get package price
            $packageSql = "SELECT package_price, is_price_locked FROM tbl_packages WHERE package_id = :package_id";
            $packageStmt = $this->conn->prepare($packageSql);
            $packageStmt->execute([':package_id' => $packageId]);
            $package = $packageStmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Calculate total component cost
            $totalComponentCost = 0;
            foreach ($components as $component) {
                $totalComponentCost += floatval($component['component_price'] ?? 0);
            }

            $packagePrice = floatval($package['package_price']);
            $difference = $packagePrice - $totalComponentCost;

            $validation = [
                "is_valid" => true,
                "package_price" => $packagePrice,
                "inclusions_total" => $totalComponentCost,
                "difference" => $difference,
                "warnings" => [],
                "errors" => []
            ];

            // Check for overage
            if ($difference < 0) {
                $validation["warnings"][] = [
                    "type" => "overage",
                    "message" => "Budget overage detected: Inclusions exceed package price by ₱" . number_format(abs($difference), 2),
                    "overage_amount" => abs($difference)
                ];
            }

            // Check if price is locked and cannot be reduced
            if (boolval($package['is_price_locked'])) {
                $validation["is_price_locked"] = true;
                $validation["price_lock_message"] = "Package price is locked and cannot be reduced";
            }

            return json_encode([
                "status" => "success",
                "validation" => $validation
            ]);
        } catch (Exception $e) {
            error_log("validatePackageBudget error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
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

// Debug logging
error_log("Admin.php - Raw input: " . $rawInput);
error_log("Admin.php - Decoded data: " . json_encode($data));
error_log("Admin.php - POST data: " . json_encode($_POST));
error_log("Admin.php - GET data: " . json_encode($_GET));

// Check if operation is provided via GET or POST
$operation = $_POST['operation'] ?? ($_GET['operation'] ?? ($data['operation'] ?? ''));

error_log("Admin.php - Operation: " . $operation);
error_log("Admin.php - All data: " . json_encode($data));
error_log("Admin.php - POST: " . json_encode($_POST));
error_log("Admin.php - GET: " . json_encode($_GET));

$admin = new Admin($pdo);

// Handle API actions
switch ($operation) {
    case "createEvent":
        error_log("Admin.php - Starting createEvent operation");
        echo $admin->createEvent($data);
        break;
    case "getAllVendors":
        echo $admin->getAllVendors();
        break;
    case "createPackage":
        echo $admin->createPackage($data);
        break;
    case "getAllPackages":
        echo $admin->getAllPackages();
        break;
    case "getPackageById":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageById($packageId);
        break;
    case "getPackageDetails":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageDetails($packageId);
        break;
    case "updatePackage":
        echo $admin->updatePackage($data);
        break;
    case "deletePackage":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->deletePackage($packageId);
        break;
    case "getEventTypes":
        echo $admin->getEventTypes();
        break;
    case "getPackagesByEventType":
        $eventTypeId = $_GET['event_type_id'] ?? ($data['event_type_id'] ?? 0);
        echo $admin->getPackagesByEventType($eventTypeId);
        break;
    case "getClients":
        echo $admin->getClients();
        break;
    case "getAllEvents":
        echo $admin->getAllEvents();
        break;
    case "getEvents":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        echo $admin->getEvents($adminId);
        break;
    case "getClientEvents":
        $userId = $_GET['user_id'] ?? ($data['user_id'] ?? 0);
        echo $admin->getClientEvents($userId);
        break;
    case "checkEventConflicts":
        $eventDate = $_GET['event_date'] ?? ($data['event_date'] ?? '');
        $startTime = $_GET['start_time'] ?? ($data['start_time'] ?? '');
        $endTime = $_GET['end_time'] ?? ($data['end_time'] ?? '');
        $excludeEventId = $_GET['exclude_event_id'] ?? ($data['exclude_event_id'] ?? null);
        echo $admin->checkEventConflicts($eventDate, $startTime, $endTime, $excludeEventId);
        break;
    case "getEventById":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getEventById($eventId);
        break;
    case "getEnhancedEventDetails":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getEnhancedEventDetails($eventId);
        break;
    case "getBookingByReference":
        $reference = $_GET['reference'] ?? ($data['reference'] ?? '');
        echo $admin->getBookingByReference($reference);
        break;
    case "updateBookingStatus":
        $bookingId = $_GET['booking_id'] ?? ($data['booking_id'] ?? 0);
        $status = $_GET['status'] ?? ($data['status'] ?? '');
        echo $admin->updateBookingStatus($bookingId, $status);
        break;
    case "confirmBooking":
        $bookingReference = $_GET['booking_reference'] ?? ($data['booking_reference'] ?? '');
        echo $admin->confirmBooking($bookingReference);
        break;
    case "getAllBookings":
        echo $admin->getAllBookings();
        break;
    case "getAvailableBookings":
        echo $admin->getAvailableBookings();
        break;
    case "getConfirmedBookings":
        echo $admin->getConfirmedBookings();
        break;
    case "getEventByBookingReference":
        $bookingReference = $_GET['booking_reference'] ?? ($data['booking_reference'] ?? '');
        echo $admin->getEventByBookingReference($bookingReference);
        break;
    case "testBookingsTable":
        echo $admin->testBookingsTable();
        break;
    case "createVenue":
        echo $admin->createVenue();
        break;
    case "getAllVenues":
        echo $admin->getAllVenues();
        break;
    case "getVenueById":
        $venueId = $_GET['venue_id'] ?? ($data['venue_id'] ?? 0);
        echo $admin->getVenueById($venueId);
        break;
    case "updateVenue":
        echo $admin->updateVenue($data);
        break;
    case "getVenuesForPackage":
        echo $admin->getVenuesForPackage();
        break;
    case "createPackageWithVenues":
        echo $admin->createPackageWithVenues($data);
        break;
    case "getPackageBudgetBreakdown":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageBudgetBreakdown($packageId);
        break;
    case "validatePackageBudget":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        $components = $_GET['components'] ?? ($data['components'] ?? []);
        echo $admin->validatePackageBudget($packageId, $components);
        break;
    case "getDashboardMetrics":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        echo $admin->getDashboardMetrics($adminId);
        break;
    case "getUpcomingEvents":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $limit = $_GET['limit'] ?? ($data['limit'] ?? 5);
        echo $admin->getUpcomingEvents($adminId, $limit);
        break;
    case "getRecentPayments":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $limit = $_GET['limit'] ?? ($data['limit'] ?? 5);
        echo $admin->getRecentPayments($adminId, $limit);
        break;
    case "createPayment":
        echo $admin->createPayment($data);
        break;
    case "getEventPayments":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getEventPayments($eventId);
        break;
    case "getClientPayments":
        $clientId = $_GET['client_id'] ?? ($data['client_id'] ?? 0);
        echo $admin->getClientPayments($clientId);
        break;
    case "getAdminPayments":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        echo $admin->getAdminPayments($adminId);
        break;
    case "updatePaymentStatus":
        $paymentId = $_GET['payment_id'] ?? ($data['payment_id'] ?? 0);
        $status = $_GET['status'] ?? ($data['status'] ?? '');
        $notes = $_GET['notes'] ?? ($data['notes'] ?? null);
        echo $admin->updatePaymentStatus($paymentId, $status, $notes);
        break;
    case "getPaymentSchedule":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getPaymentSchedule($eventId);
        break;
    case "getEventsWithPaymentStatus":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        echo $admin->getEventsWithPaymentStatus($adminId);
        break;
    case "getPaymentAnalytics":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? ($data['start_date'] ?? null);
        $endDate = $_GET['end_date'] ?? ($data['end_date'] ?? null);
        echo $admin->getPaymentAnalytics($adminId, $startDate, $endDate);
        break;
    case "createPaymentSchedule":
        echo $admin->createPaymentSchedule($data);
        break;
    case "getEventPaymentSchedule":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getEventPaymentSchedule($eventId);
        break;
    case "getPaymentScheduleTypes":
        echo $admin->getPaymentScheduleTypes();
        break;
    case "recordScheduledPayment":
        echo $admin->recordScheduledPayment($data);
        break;
    case "getPaymentLogs":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getPaymentLogs($eventId);
        break;
    case "getAdminPaymentLogs":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $limit = $_GET['limit'] ?? ($data['limit'] ?? 50);
        echo $admin->getAdminPaymentLogs($adminId, $limit);
        break;
    case "getEnhancedPaymentDashboard":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        echo $admin->getEnhancedPaymentDashboard($adminId);
        break;
    case "getAnalyticsData":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? ($data['start_date'] ?? null);
        $endDate = $_GET['end_date'] ?? ($data['end_date'] ?? null);
        echo $admin->getAnalyticsData($adminId, $startDate, $endDate);
        break;
    case "getReportsData":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $reportType = $_GET['report_type'] ?? ($data['report_type'] ?? 'summary');
        $startDate = $_GET['start_date'] ?? ($data['start_date'] ?? null);
        $endDate = $_GET['end_date'] ?? ($data['end_date'] ?? null);
        echo $admin->getReportsData($adminId, $reportType, $startDate, $endDate);
        break;
    case "getUserProfile":
        $userId = $_GET['user_id'] ?? ($data['user_id'] ?? 0);
        echo $admin->getUserProfile($userId);
        break;
    case "updateUserProfile":
        echo $admin->updateUserProfile($data);
        break;
    case "changePassword":
        echo $admin->changePassword($data);
        break;
    case "getWebsiteSettings":
        echo $admin->getWebsiteSettings();
        break;
    case "updateWebsiteSettings":
        echo $admin->updateWebsiteSettings($data['settings']);
        break;
    case "getAllFeedbacks":
        echo $admin->getAllFeedbacks();
        break;
    case "deleteFeedback":
        $feedbackId = $_GET['feedback_id'] ?? ($data['feedback_id'] ?? 0);
        echo $admin->deleteFeedback($feedbackId);
        break;
    case "uploadFile":
        if (isset($_FILES['file'])) {
            $fileType = $_POST['fileType'] ?? 'misc';
            echo $admin->uploadFile($_FILES['file'], $fileType);
        } else {
            echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        }
        break;
    case "saveWeddingDetails":
        echo $admin->saveWeddingDetails($data);
        break;
    case "getWeddingDetails":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getWeddingDetails($eventId);
        break;
    case "runWeddingMigration":
        echo $admin->runWeddingMigration();
        break;
    case "uploadPaymentProof":
        if (isset($_FILES['file'])) {
            $eventId = $_POST['event_id'] ?? ($data['event_id'] ?? 0);
            $description = $_POST['description'] ?? ($data['description'] ?? '');
            $proofType = $_POST['proof_type'] ?? ($data['proof_type'] ?? 'receipt');
            echo $admin->uploadPaymentProof($eventId, $_FILES['file'], $description, $proofType);
        } else {
            echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        }
        break;
    case "getPaymentProofs":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getPaymentProofs($eventId);
        break;
    case "attachPaymentProof":
        if (isset($_FILES['file'])) {
            $paymentId = $_POST['payment_id'] ?? ($data['payment_id'] ?? 0);
            $description = $_POST['description'] ?? ($data['description'] ?? '');
            $proofType = $_POST['proof_type'] ?? ($data['proof_type'] ?? 'receipt');
            echo $admin->attachPaymentProof($paymentId, $_FILES['file'], $description, $proofType);
        } else {
            echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        }
        break;
    case "deletePaymentProof":
        $paymentId = $_POST['payment_id'] ?? ($data['payment_id'] ?? 0);
        $fileName = $_POST['file_name'] ?? ($data['file_name'] ?? '');
        echo $admin->deletePaymentProof($paymentId, $fileName);
        break;
    case "getEventsForPayments":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $searchTerm = $_GET['search_term'] ?? ($data['search_term'] ?? '');
        echo $admin->getEventsForPayments($adminId, $searchTerm);
        break;
    case "getEventPaymentDetails":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getEventPaymentDetails($eventId);
        break;
    case "uploadPaymentAttachment":
        if (isset($_FILES['file'])) {
            $eventId = $_POST['event_id'] ?? ($data['event_id'] ?? 0);
            $paymentId = $_POST['payment_id'] ?? ($data['payment_id'] ?? 0);
            $description = $_POST['description'] ?? ($data['description'] ?? '');
            echo $admin->uploadPaymentAttachment($eventId, $paymentId, $_FILES['file'], $description);
        } else {
            echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        }
        break;
    case "addEventComponent":
        echo $admin->addEventComponent($data);
        break;
    case "updateEventComponent":
        echo $admin->updateEventComponent($data);
        break;
    case "deleteEventComponent":
        $componentId = $_GET['component_id'] ?? ($data['component_id'] ?? 0);
        echo $admin->deleteEventComponent($componentId);
        break;
    case "updateEventBudget":
        error_log("Admin.php - updateEventBudget case reached");
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        $budgetChange = $_GET['budget_change'] ?? ($data['budget_change'] ?? 0);
        error_log("Admin.php - updateEventBudget params: eventId=$eventId, budgetChange=$budgetChange");
        echo $admin->updateEventBudget($eventId, $budgetChange);
        break;
    case "getPackageVenues":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageVenues($packageId);
        break;
    case "updateEventVenue":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        $venueId = $_GET['venue_id'] ?? ($data['venue_id'] ?? 0);
        echo $admin->updateEventVenue($eventId, $venueId);
        break;
    case "updateEventFinalization":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        $action = $_GET['action'] ?? ($data['action'] ?? 'lock');
        echo $admin->updateEventFinalization($eventId, $action);
        break;
    case "createPackageWithVenues":
        echo $admin->createPackageWithVenues($data);
        break;
    case "createPackage":
        echo $admin->createPackage($data);
        break;
    case "getAllPackages":
        echo $admin->getAllPackages();
        break;
    case "getPackageById":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageById($packageId);
        break;
    case "getPackageDetails":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageDetails($packageId);
        break;
    case "updatePackage":
        echo $admin->updatePackage($data);
        break;
    case "deletePackage":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->deletePackage($packageId);
        break;
    case "getPackagesByEventType":
        $eventTypeId = $_GET['event_type_id'] ?? ($data['event_type_id'] ?? 0);
        echo $admin->getPackagesByEventType($eventTypeId);
        break;
    default:
        echo json_encode(["status" => "error", "message" => "Invalid action."]);
        break;
}


?>
