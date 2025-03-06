<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connect.php';

class Admin {
    private $conn;

    public function __construct($pdo) {
        $this->conn = $pdo;
    }

    public function createEvent($data) {
        try {
            $json = $data;

            // Required Fields
            $required = ['title', 'date', 'timeIn', 'timeOut', 'type', 'budget', 'venue', 'created_by'];

            foreach ($required as $field) {
                if (empty($json[$field])) {
                    return json_encode(["status" => "error", "message" => "$field is required"]);
                }
            }

            // Insert Event
            $sql = "INSERT INTO tbl_event (
                        event_timeIn, event_timeOut, event_date,
                        event_type, event_budget, event_venue, created_by
                    ) VALUES (
                        :timeIn, :timeOut, :date,
                        :type, :budget, :venue, :created_by
                    )";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':timeIn' => $json['timeIn'],
                ':timeOut' => $json['timeOut'],
                ':date' => $json['date'],
                ':type' => $json['type'],
                ':budget' => $json['budget'],
                ':venue' => $json['venue'],
                ':created_by' => $json['created_by'],
            ]);

            $eventId = $this->conn->lastInsertId();

            // Insert notification for the event creation
            $notifSql = "INSERT INTO tbl_notifications (user_id, event_id, message, status)
                         VALUES (:user_id, :event_id, :message, 'Unread')";
            $notifStmt = $this->conn->prepare($notifSql);
            $notifStmt->execute([
                ':user_id' => $json['created_by'],
                ':event_id' => $eventId,
                ':message' => "You have created an event: " . $json['title'],
            ]);

            return json_encode(["status" => "success", "message" => "Event created successfully!"]);

        } catch (PDOException $e) {
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

 public function getAllVendors() {
    try {
        $sql = "SELECT vendor_id AS id, vendor_name AS vendorName,
                       vendor_storeName AS storeName, vendor_type AS storeType,
                       vendor_cover_photo AS coverPhoto, vendor_profile_picture AS profilePicture
                FROM tbl_vendors";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($stores as &$store) {
            // Prevent duplicate paths
            $store['coverPhoto'] = !empty($store['coverPhoto'])
                ? "uploads/cover_photos/" . basename($store['coverPhoto'])
                : null;

            $store['profilePicture'] = !empty($store['profilePicture'])
                ? "uploads/profile_pictures/" . basename($store['profilePicture'])
                : null;
        }

        echo json_encode(["status" => "success", "stores" => $stores]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
}




}

// Read JSON input from frontend (for POST requests)
$data = json_decode(file_get_contents("php://input"), true);

// Check if operation is provided via GET or POST
$operation = $_POST['operation'] ?? ($_GET['operation'] ?? ($data['operation'] ?? ''));

$admin = new Admin($pdo);

// Handle API actions
switch ($operation) {
    case "createEvent":
        echo $admin->createEvent($data);
        break;
    case "getAllVendors":
        echo $admin->getAllVendors();
        break;
    default:
        echo json_encode(["status" => "error", "message" => "Invalid action."]);
        break;
}


?>
