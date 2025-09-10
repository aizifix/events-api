<?php
require_once 'db_connect.php';

if (!$pdo) {
    echo "Database connection failed\n";
    exit();
}

try {
    echo "=== Manual Organizer Assignment ===\n\n";

    // First, ensure the assignment table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tbl_event_organizer_assignments` (
            `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
            `event_id` int(11) NOT NULL,
            `organizer_id` int(11) NOT NULL,
            `assigned_by` int(11) NOT NULL,
            `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `status` enum('assigned','accepted','rejected','removed') DEFAULT 'assigned',
            `notes` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`assignment_id`),
            KEY `idx_event_id` (`event_id`),
            KEY `idx_organizer_id` (`organizer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "✅ Assignment table created/verified\n";

    // Find the event on October 30, 2025
    $stmt = $pdo->prepare("SELECT event_id, event_title FROM tbl_events WHERE event_date = '2025-10-30'");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($events)) {
        echo "❌ No events found on October 30, 2025\n";
        exit();
    }

    $event = $events[0];
    echo "📅 Found event: " . $event['event_title'] . " (ID: " . $event['event_id'] . ")\n";

    // Find organizer ID 7 (Mayette Lagdamin)
    $stmt = $pdo->prepare("SELECT organizer_id, user_id FROM tbl_organizer WHERE organizer_id = 7");
    $stmt->execute();
    $organizer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$organizer) {
        echo "❌ Organizer ID 7 not found\n";
        exit();
    }

    echo "👤 Found organizer ID: " . $organizer['organizer_id'] . " (User ID: " . $organizer['user_id'] . ")\n";

    // Check if assignment already exists
    $stmt = $pdo->prepare("SELECT assignment_id FROM tbl_event_organizer_assignments WHERE event_id = ? AND organizer_id = ?");
    $stmt->execute([$event['event_id'], $organizer['organizer_id']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo "⚠️ Assignment already exists (ID: " . $existing['assignment_id'] . ")\n";
    } else {
        // Create the assignment
        $stmt = $pdo->prepare("
            INSERT INTO tbl_event_organizer_assignments (
                event_id, organizer_id, assigned_by, status, notes
            ) VALUES (?, ?, ?, 'assigned', ?)
        ");
        $stmt->execute([
            $event['event_id'],
            $organizer['organizer_id'],
            7, // admin_id (assuming admin ID 7)
            'Manually assigned for testing'
        ]);

        $assignmentId = $pdo->lastInsertId();
        echo "✅ Created assignment ID: " . $assignmentId . "\n";
    }

    // Test the GetOrganizerEvents procedure
    echo "\n=== Testing GetOrganizerEvents Procedure ===\n";

    // Create the procedure if it doesn't exist
    $pdo->exec("DROP PROCEDURE IF EXISTS GetOrganizerEvents");
    $procedureSql = "
    CREATE PROCEDURE `GetOrganizerEvents`(IN p_organizer_id INT)
    BEGIN
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
            eoa.status as assignment_status
        FROM tbl_event_organizer_assignments eoa
        JOIN tbl_events e ON eoa.event_id = e.event_id
        LEFT JOIN tbl_users c ON e.user_id = c.user_id
        LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
        LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
        WHERE eoa.organizer_id = p_organizer_id
        AND eoa.status IN ('assigned', 'accepted')
        ORDER BY e.event_date ASC, e.start_time ASC;
    END";

    $pdo->exec($procedureSql);
    echo "✅ GetOrganizerEvents procedure created\n";

    // Test the procedure
    $stmt = $pdo->prepare("CALL GetOrganizerEvents(?)");
    $stmt->execute([7]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "📊 Procedure returned " . count($results) . " events for organizer ID 7\n";
    if (count($results) > 0) {
        foreach ($results as $result) {
            echo "Event: " . $result['event_title'] . " (ID: " . $result['event_id'] . ")\n";
            echo "Date: " . $result['event_date'] . "\n";
            echo "Client: " . $result['client_name'] . "\n";
            echo "Status: " . $result['assignment_status'] . "\n";
            echo "---\n";
        }
    }

    echo "\n✅ Manual assignment completed successfully!\n";
    echo "The organizer should now see this event in their calendar.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
