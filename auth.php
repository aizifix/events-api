<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Set timezone
date_default_timezone_set('Asia/Manila');

require_once 'db_connect.php';
require 'vendor/autoload.php';

// PHP Mailer OTP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Auth {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function sendOTP($email, $user_id) {
        $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date("Y-m-d H:i:s", strtotime("+5 minutes"));

        $stmt = $this->conn->prepare("INSERT INTO tbl_2fa (user_id, email, otp_code, expires_at)
                                    VALUES (:user_id, :email, :otp, :expires_at)
                                    ON DUPLICATE KEY UPDATE otp_code = VALUES(otp_code), expires_at = VALUES(expires_at)");
        $stmt->execute([
            ':user_id' => $user_id,
            ':email' => $email,
            ':otp' => $otp,
            ':expires_at' => $expiresAt
        ]);

        return $this->sendEmailOTP($email, $otp);
    }

    // Send Email OTP
    private function sendEmailOTP($email, $otp) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'aizelartunlock@gmail.com';
            $mail->Password = 'nhueuwnriexqdbpt';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('your-email@gmail.com', 'Event Planning System');
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'Your OTP Code';

            // HTML Email Template
            $mail->Body = '
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        margin: 0;
                        padding: 0;
                        background-color: #ffffff;
                    }
                    .container {
                        max-width: 600px;
                        margin: 0 auto;
                        padding: 20px;
                        text-align: center;
                    }
                    .logo {
                        margin-bottom: 20px;
                    }
                    .logo img {
                        max-width: 150px;
                        height: auto;
                    }
                    .otp-title {
                        color: #333333;
                        font-size: 24px;
                        margin-bottom: 20px;
                    }
                    .otp-code {
                        font-size: 36px;
                        font-weight: bold;
                        letter-spacing: 5px;
                        color: #000000;
                        padding: 20px;
                        background-color: #f5f5f5;
                        border-radius: 10px;
                        margin: 20px 0;
                    }
                    .expiry-text {
                        color: #666666;
                        font-size: 14px;
                        margin-top: 20px;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="logo">
                        <img src="https://i.ibb.co/67tRfS2r/logo.png" alt="Event Planning System Logo">
                    </div>
                    <div class="otp-title">
                        Your OTP Code is:
                    </div>
                    <div class="otp-code">
                        ' . $otp . '
                    </div>
                    <div class="expiry-text">
                        This code expires in 5 minutes.
                    </div>
                </div>
            </body>
            </html>';

            $mail->AltBody = "Your OTP code is: $otp. It expires in 5 minutes.";

            $mail->send();
            return json_encode([
                "status" => "otp_sent",
                "message" => "OTP sent to your email"
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Mailer Error: " . $mail->ErrorInfo
            ]);
        }
    }

    public function register($data) {
        try {
            $this->conn->beginTransaction();

            // Required fields for registration
            $required = [
                'firstName', 'lastName', 'birthdate', 'email', 'user_contact',
                'username', 'password', 'vendorAddress', 'vendorContactNumber'
            ];

            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return json_encode(["status" => "error", "message" => "$field is required"]);
                }
            }

            // Check if username or email already exists
            $stmt = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_username = ? OR user_email = ?");
            $stmt->execute([$data['username'], $data['email']]);
            if ($stmt->rowCount() > 0) {
                return json_encode(["status" => "error", "message" => "Username or email already exists"]);
            }

            // Insert into tbl_users
            $sql = "INSERT INTO tbl_users (
                user_firstName, user_lastName, user_suffix, user_birthdate,
                user_email, user_contact, user_username, user_pwd,
                user_role
            ) VALUES (
                :firstName, :lastName, :suffix, :birthdate,
                :email, :user_contact, :username, :password,
                :role
            )";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':firstName' => $data['firstName'],
                ':lastName' => $data['lastName'],
                ':suffix' => $data['suffix'] ?? null,
                ':birthdate' => $data['birthdate'],
                ':email' => $data['email'],
                ':user_contact' => $data['user_contact'],
                ':username' => $data['username'],
                ':password' => password_hash($data['password'], PASSWORD_DEFAULT),
                ':role' => in_array($data['role'], ['client', 'organizer']) ? $data['role'] : 'client'
            ]);

            $userId = $this->conn->lastInsertId();

            // Insert into tbl_vendor only if role is vendor (legacy support)
            if (isset($data['role']) && $data['role'] === 'vendor') {
                $vendorSql = "INSERT INTO tbl_vendor (
                    user_id, vendor_address, vendor_contactNumber, vendor_registrationDate,
                    vendor_documents, vendor_notes
                ) VALUES (
                    :userId, :vendorAddress, :vendorContactNumber, CURDATE(),
                    :vendorDocuments, :vendorNotes
                )";

                $vendorStmt = $this->conn->prepare($vendorSql);
                $vendorStmt->execute([
                    ':userId' => $userId,
                    ':vendorAddress' => $data['vendorAddress'],
                    ':vendorContactNumber' => $data['vendorContactNumber'],
                    ':vendorDocuments' => $data['vendorDocuments'] ?? null,
                    ':vendorNotes' => $data['vendorNotes'] ?? null
                ]);
            }

            // Placeholder: Insert into tbl_organizer if role is organizer (if you have such a table)
            // if (isset($data['role']) && $data['role'] === 'organizer') {
            //     // Insert organizer-specific data here
            // }

            $this->conn->commit();
            return json_encode(["status" => "success", "message" => "Registration successful!"]);
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function login($username, $password) {
        if (empty($username) || empty($password)) {
            return json_encode(["status" => "error", "message" => "Username and password are required."]);
        }

        // Allow login by username or email
        $sql = "SELECT * FROM tbl_users WHERE user_username = :username OR user_email = :username";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':username' => $username]);

        if ($stmt->rowCount() === 0) {
            return json_encode(["status" => "error", "message" => "User not found."]);
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!password_verify($password, $user['user_pwd'])) {
            return json_encode(["status" => "error", "message" => "Invalid password."]);
        }

        // Check account status
        if (isset($user['account_status']) && $user['account_status'] !== 'active') {
            return json_encode(["status" => "error", "message" => "Account is " . $user['account_status'] . ". Please contact support."]);
        }

        // Generate and send OTP
        $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date("Y-m-d H:i:s", strtotime("+5 minutes"));

        // Delete any existing OTPs for this user
        $this->conn->prepare("DELETE FROM tbl_2fa WHERE user_id = :user_id")
            ->execute([':user_id' => $user['user_id']]);

        // Insert new OTP
        $stmt = $this->conn->prepare("INSERT INTO tbl_2fa (user_id, email, otp_code, expires_at)
                                    VALUES (:user_id, :email, :otp, :expires_at)");
        $stmt->execute([
            ':user_id' => $user['user_id'],
            ':email' => $user['user_email'],
            ':otp' => $otp,
            ':expires_at' => $expiresAt
        ]);

        error_log("New OTP generated - User ID: " . $user['user_id'] . ", OTP: " . $otp . ", Expires at: " . $expiresAt);

        // Send OTP email
        $emailResult = $this->sendEmailOTP($user['user_email'], $otp);
        $emailData = json_decode($emailResult, true);

        if ($emailData['status'] === 'otp_sent') {
            return json_encode([
                "status" => "otp_sent",
                "message" => "OTP sent to your email",
                "user_id" => $user['user_id'],
                "email" => $user['user_email']
            ]);
        }

        return $emailResult;
    }

    public function verifyOTP($user_id, $otp) {
        try {
            error_log("Verifying OTP - User ID: " . $user_id . ", OTP: " . $otp);
            error_log("Current server time: " . date('Y-m-d H:i:s'));

            // Get the latest OTP for the user
            $stmt = $this->conn->prepare("
                SELECT *
                FROM tbl_2fa
                WHERE user_id = :user_id
                AND otp_code = :otp
                AND expires_at > NOW()
                ORDER BY expires_at DESC
                LIMIT 1
            ");

            $stmt->execute([
                ':user_id' => $user_id,
                ':otp' => $otp
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            error_log("OTP verification query result: " . print_r($result, true));

            if (!$result) {
                return json_encode([
                    "status" => "error",
                    "message" => "Invalid OTP. Please try again."
                ]);
            }

            // Get user data for successful login response
            $userStmt = $this->conn->prepare("SELECT * FROM tbl_users WHERE user_id = :user_id");
            $userStmt->execute([':user_id' => $user_id]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            // Check if password change is required
            $forcePasswordChange = isset($user['force_password_change']) && $user['force_password_change'] == 1;

            // Update last login timestamp
            $this->conn->prepare("UPDATE tbl_users SET last_login = NOW() WHERE user_id = :user_id")
                ->execute([':user_id' => $user_id]);

            // Log supplier activity if user is a supplier
            if ($user['user_role'] === 'supplier') {
                $this->logSupplierLogin($user_id);
            }

            // Normalize user role
            $user['user_role'] = ucfirst(strtolower($user['user_role']));
            unset($user['user_pwd']); // Remove password from response

            // Delete all OTPs for this user
            $this->conn->prepare("DELETE FROM tbl_2fa WHERE user_id = :user_id")
                ->execute([':user_id' => $user_id]);

            $response = [
                "status" => "success",
                "message" => $forcePasswordChange ? "Login successful! Password change required." : "Login successful!",
                "user" => $user,
                "force_password_change" => $forcePasswordChange
            ];

            // If supplier role, get additional supplier info
            if ($user['user_role'] === 'supplier') {
                $supplierStmt = $this->conn->prepare("SELECT supplier_id, business_name, onboarding_status FROM tbl_suppliers WHERE user_id = :user_id AND is_active = 1");
                $supplierStmt->execute([':user_id' => $user_id]);
                $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);

                if ($supplier) {
                    $response['supplier_info'] = $supplier;
                }
            }

            return json_encode($response);
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    // Change password method (especially for forced password changes)
    public function changePassword($userId, $currentPassword, $newPassword, $confirmPassword) {
        try {
            // Validate inputs
            if (empty($newPassword) || empty($confirmPassword)) {
                return json_encode(["status" => "error", "message" => "New password and confirmation are required"]);
            }

            if ($newPassword !== $confirmPassword) {
                return json_encode(["status" => "error", "message" => "Password confirmation does not match"]);
            }

            if (strlen($newPassword) < 8) {
                return json_encode(["status" => "error", "message" => "Password must be at least 8 characters long"]);
            }

            // Get current user
            $userStmt = $this->conn->prepare("SELECT * FROM tbl_users WHERE user_id = :user_id");
            $userStmt->execute([':user_id' => $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return json_encode(["status" => "error", "message" => "User not found"]);
            }

            // For non-forced password changes, verify current password
            if (!$user['force_password_change'] && !empty($currentPassword)) {
                if (!password_verify($currentPassword, $user['user_pwd'])) {
                    return json_encode(["status" => "error", "message" => "Current password is incorrect"]);
                }
            }

            // Hash new password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            // Update password and clear force_password_change flag
            $updateStmt = $this->conn->prepare("
                UPDATE tbl_users
                SET user_pwd = :password, force_password_change = 0, updated_at = NOW()
                WHERE user_id = :user_id
            ");

            $updateStmt->execute([
                ':password' => $hashedPassword,
                ':user_id' => $userId
            ]);

            // Log supplier activity if user is a supplier
            if ($user['user_role'] === 'supplier') {
                $this->logSupplierPasswordChange($userId);
            }

            return json_encode([
                "status" => "success",
                "message" => "Password changed successfully"
            ]);

        } catch (PDOException $e) {
            error_log("Password change error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Failed to change password"
            ]);
        }
    }

    // Log supplier login activity
    private function logSupplierLogin($userId) {
        try {
            // Get supplier ID
            $supplierStmt = $this->conn->prepare("SELECT supplier_id FROM tbl_suppliers WHERE user_id = :user_id AND is_active = 1");
            $supplierStmt->execute([':user_id' => $userId]);
            $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);

            if ($supplier) {
                $activityStmt = $this->conn->prepare("
                    INSERT INTO tbl_supplier_activity (
                        supplier_id, activity_type, activity_description, ip_address, user_agent, created_at
                    ) VALUES (?, 'login', 'Supplier logged in', ?, ?, NOW())
                ");

                $activityStmt->execute([
                    $supplier['supplier_id'],
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            }
        } catch (Exception $e) {
            error_log("Failed to log supplier login: " . $e->getMessage());
        }
    }

    // Log supplier password change activity
    private function logSupplierPasswordChange($userId) {
        try {
            // Get supplier ID
            $supplierStmt = $this->conn->prepare("SELECT supplier_id FROM tbl_suppliers WHERE user_id = :user_id AND is_active = 1");
            $supplierStmt->execute([':user_id' => $userId]);
            $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);

            if ($supplier) {
                $activityStmt = $this->conn->prepare("
                    INSERT INTO tbl_supplier_activity (
                        supplier_id, activity_type, activity_description, ip_address, user_agent, created_at
                    ) VALUES (?, 'password_changed', 'Password changed successfully', ?, ?, NOW())
                ");

                $activityStmt->execute([
                    $supplier['supplier_id'],
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);

                // Update supplier password_set_at timestamp
                $this->conn->prepare("UPDATE tbl_suppliers SET password_set_at = NOW() WHERE supplier_id = ?")
                    ->execute([$supplier['supplier_id']]);
            }
        } catch (Exception $e) {
            error_log("Failed to log supplier password change: " . $e->getMessage());
        }
    }
}

// Debugging: Log received data
error_log("Received POST data: " . print_r($_POST, true));
error_log("Received RAW JSON data: " . file_get_contents("php://input"));

$auth = new Auth($pdo);

// Handle API actions
$operation = $_POST['operation'] ?? '';

if (empty($operation)) {
    $jsonData = json_decode(file_get_contents("php://input"), true);
    if (isset($jsonData['operation'])) {
        $operation = $jsonData['operation'];
    }
}

error_log("Determined operation: " . $operation);

switch ($operation) {
    case "login":
        echo $auth->login($_POST['username'] ?? $jsonData['username'], $_POST['password'] ?? $jsonData['password']);
        break;
    case "register":
        echo $auth->register($_POST);
        break;
    case "verify_otp":
        echo $auth->verifyOTP($_POST['user_id'] ?? $jsonData['user_id'], $_POST['otp'] ?? $jsonData['otp']);
        break;
    case "change_password":
        $userId = $_POST['user_id'] ?? $jsonData['user_id'];
        $currentPassword = $_POST['current_password'] ?? $jsonData['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? $jsonData['new_password'];
        $confirmPassword = $_POST['confirm_password'] ?? $jsonData['confirm_password'];
        echo $auth->changePassword($userId, $currentPassword, $newPassword, $confirmPassword);
        break;
    case "check_username":
        $jsonData = json_decode(file_get_contents("php://input"), true);
        $username = $jsonData['username'] ?? $_POST['username'] ?? '';

        error_log("Checking username: " . $username);

        if (empty($username)) {
            echo json_encode(["status" => "error", "message" => "Username required"]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT user_id FROM tbl_users WHERE user_username = ?");
        $stmt->execute([$username]);
        $userExists = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userExists) {
            echo json_encode(["status" => "error", "exists" => true, "message" => "Username already taken"]);
        } else {
            echo json_encode(["status" => "success", "exists" => false, "message" => "Username available"]);
        }
        exit;

    default:
        error_log("Invalid action received: " . $operation);
        echo json_encode(["status" => "error", "message" => "Invalid action."]);
        break;
}
?>
