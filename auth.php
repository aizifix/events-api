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
require_once 'ActivityLogger.php';

// PHP Mailer OTP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Auth {
    private $conn;
    private $logger;

    public function __construct($db) {
        $this->conn = $db;
        $this->logger = new ActivityLogger($db);
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

            $mail->setFrom('aizelartunlock@gmail.com', 'Event Planning System');
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
                'password', 'vendorAddress', 'vendorContactNumber'
            ];

            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return json_encode(["status" => "error", "message" => "$field is required"]);
                }
            }

            // Check if email already exists
            $stmt = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->rowCount() > 0) {
                return json_encode(["status" => "error", "message" => "Email already exists"]);
            }

            // Generate a username from email (optional, for backward compatibility)
            $username = explode('@', $data['email'])[0];
            $baseUsername = $username;
            $counter = 1;

            // Keep checking until we find a unique username
            while (true) {
                $stmt = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_username = ?");
                $stmt->execute([$username]);
                if ($stmt->rowCount() == 0) break;
                $username = $baseUsername . $counter++;
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
                ':username' => $username, // Auto-generated username
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

            $this->conn->commit();
            return json_encode(["status" => "success", "message" => "Registration successful!"]);
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function login($email, $password) {
        if (empty($email) || empty($password)) {
            return json_encode(["status" => "error", "message" => "Email and password are required."]);
        }

        // Find user by email
        $sql = "SELECT * FROM tbl_users WHERE user_email = :email";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':email' => $email]);

        if ($stmt->rowCount() === 0) {
            // Log failed login attempt - user not found
            if ($this->logger) {
                $this->logger->logAuth(0, 'login_attempt', "Failed login attempt for non-existent user: $email", 'unknown', false, 'User not found');
            }
            return json_encode(["status" => "error", "message" => "User not found."]);
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!password_verify($password, $user['user_pwd'])) {
            // Log failed login attempt - invalid password
            if ($this->logger) {
                $this->logger->logAuth($user['user_id'], 'login_attempt', "Failed login attempt for {$user['user_firstName']} {$user['user_lastName']}", $user['user_role'], false, 'Invalid password');
            }
            return json_encode(["status" => "error", "message" => "Invalid password."]);
        }

        // Check if email is verified (for new signup flow)
        if (isset($user['is_verified']) && $user['is_verified'] == 0) {
            return json_encode([
                "status" => "error",
                "message" => "Please verify your email address before logging in. Check your email for the verification code.",
                "requires_verification" => true,
                "email" => $user['user_email']
            ]);
        }

        // Check account status
        if (isset($user['account_status']) && $user['account_status'] !== 'active') {
            return json_encode(["status" => "error", "message" => "Account is " . $user['account_status'] . ". Please contact support."]);
        }

        // Check website setting for OTP requirement
        try {
            $settingsStmt = $this->conn->prepare("SELECT require_otp_on_login FROM tbl_website_settings ORDER BY setting_id DESC LIMIT 1");
            $settingsStmt->execute();
            $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
            $requireOtpOnLogin = isset($settings['require_otp_on_login']) ? (int)$settings['require_otp_on_login'] === 1 : true; // default to true
        } catch (Exception $e) {
            $requireOtpOnLogin = true;
        }

        if (!$requireOtpOnLogin) {
            // Proceed with direct login (no OTP)
            // Update last login timestamp
            $this->conn->prepare("UPDATE tbl_users SET last_login = NOW() WHERE user_id = :user_id")
                ->execute([':user_id' => $user['user_id']]);

            // Log successful login using ActivityLogger
            if ($this->logger) {
                $this->logger->logAuth(
                    $user['user_id'],
                    'login',
                    "User {$user['user_firstName']} {$user['user_lastName']} logged in successfully (OTP disabled)",
                    $user['user_role'],
                    true
                );
            }

            // Log supplier activity if user is a supplier
            if ($user['user_role'] === 'supplier') {
                $this->logSupplierLogin($user['user_id']);
            }

            // Prepare user data
            $user['user_role'] = ucfirst(strtolower($user['user_role']));
            unset($user['user_pwd']);

            $response = [
                "status" => "success",
                "message" => "Login successful!",
                "user" => $user,
                "force_password_change" => isset($user['force_password_change']) && $user['force_password_change'] == 1
            ];

            // If supplier role, get additional supplier info
            if ($user['user_role'] === 'Supplier') {
                $supplierStmt = $this->conn->prepare("SELECT supplier_id, business_name, onboarding_status FROM tbl_suppliers WHERE user_id = :user_id AND is_active = 1");
                $supplierStmt->execute([':user_id' => $user['user_id']]);
                $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
                if ($supplier) {
                    $response['supplier_info'] = $supplier;
                }
            }

            return json_encode($response);
        }

        // Generate and send OTP (when required)
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

            // Log successful login using ActivityLogger
            if ($this->logger) {
                $this->logger->logAuth(
                    $user_id,
                    'login',
                    "User {$user['user_firstName']} {$user['user_lastName']} logged in successfully",
                    $user['user_role'],
                    true
                );
            }

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

    // New OTP-based registration method
    public function registerWithOTP($data) {
        try {
            // Validate required fields
            if (empty($data['fullName']) || empty($data['email']) || empty($data['password'])) {
                return json_encode(["status" => "error", "message" => "All fields are required."]);
            }

            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return json_encode(["status" => "error", "message" => "Invalid email format."]);
            }

            // Validate CAPTCHA
            if (empty($data['captcha'])) {
                return json_encode(["status" => "error", "message" => "CAPTCHA validation required."]);
            }

            // Verify CAPTCHA with Google
            $captchaResponse = $data['captcha'];
            $secretKey = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe'; // Test secret key that matches site key
            $verifyURL = 'https://www.google.com/recaptcha/api/siteverify';
            $postData = http_build_query([
                'secret' => $secretKey,
                'response' => $captchaResponse,
                'remoteip' => $_SERVER['REMOTE_ADDR']
            ]);

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => $postData
                ]
            ]);

            $result = file_get_contents($verifyURL, false, $context);
            $captchaResult = json_decode($result);

            if (!$captchaResult->success) {
                return json_encode(["status" => "error", "message" => "CAPTCHA verification failed."]);
            }

            // Check if email already exists
            $stmt = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                return json_encode(["status" => "error", "message" => "Email already exists. Please use a different email."]);
            }

            // Parse full name
            $nameParts = explode(' ', trim($data['fullName']));
            $firstName = $nameParts[0];
            $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

            // Insert user as unverified
            $stmt = $this->conn->prepare(
                "INSERT INTO tbl_users (user_firstName, user_lastName, user_email, user_contact, user_username, user_pwd, user_role, is_verified, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'organizer', 0, NOW())"
            );

            // Generate username from email
            $username = explode('@', $data['email'])[0];

            if ($stmt->execute([$firstName, $lastName, $data['email'], '', $username, $hashedPassword])) {
                $userId = $this->conn->lastInsertId();

                // Generate and send OTP
                $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expiresAt = date("Y-m-d H:i:s", strtotime("+10 minutes"));

                // Store OTP for signup verification
                $stmt = $this->conn->prepare(
                    "INSERT INTO tbl_signup_otp (user_id, email, otp_code, expires_at)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE otp_code = VALUES(otp_code), expires_at = VALUES(expires_at)"
                );
                $stmt->execute([$userId, $data['email'], $otp, $expiresAt]);

                // Send OTP email
                if ($this->sendSignupOTPEmail($data['email'], $otp, $firstName)) {
                    return json_encode([
                        "status" => "success",
                        "message" => "Registration successful! Please check your email for the verification code.",
                        "user_id" => $userId,
                        "email" => $data['email']
                    ]);
                } else {
                    // If email fails, clean up the user record
                    $stmt = $this->conn->prepare("DELETE FROM tbl_users WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    return json_encode(["status" => "error", "message" => "Failed to send verification email. Please try again."]);
                }
            } else {
                return json_encode(["status" => "error", "message" => "Registration failed. Please try again."]);
            }
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Registration failed. Please try again."]);
        }
    }

    // Verify signup OTP
    public function verifySignupOTP($userId, $email, $otp) {
        try {
            if (empty($userId) || empty($email) || empty($otp)) {
                return json_encode(["status" => "error", "message" => "Missing required fields."]);
            }

            // Check OTP
            $stmt = $this->conn->prepare(
                "SELECT * FROM tbl_signup_otp WHERE user_id = ? AND email = ? AND otp_code = ? AND expires_at > NOW()"
            );
            $stmt->execute([$userId, $email, $otp]);
            $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$otpRecord) {
                return json_encode(["status" => "error", "message" => "Invalid or expired OTP code."]);
            }

            // Verify user exists and is unverified
            $stmt = $this->conn->prepare("SELECT * FROM tbl_users WHERE user_id = ? AND user_email = ? AND is_verified = 0");
            $stmt->execute([$userId, $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return json_encode(["status" => "error", "message" => "User not found or already verified."]);
            }

            // Activate user account
            $stmt = $this->conn->prepare("UPDATE tbl_users SET is_verified = 1, email_verified_at = NOW() WHERE user_id = ?");
            if ($stmt->execute([$userId])) {
                // Delete used OTP
                $stmt = $this->conn->prepare("DELETE FROM tbl_signup_otp WHERE user_id = ?");
                $stmt->execute([$userId]);

                return json_encode([
                    "status" => "success",
                    "message" => "Email verified successfully! You can now log in."
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to verify account. Please try again."]);
            }
        } catch (Exception $e) {
            error_log("OTP verification error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Verification failed. Please try again."]);
        }
    }

    // Resend signup OTP
    public function resendSignupOTP($userId, $email) {
        try {
            if (empty($userId) || empty($email)) {
                return json_encode(["status" => "error", "message" => "Missing required fields."]);
            }

            // Check if user exists and is unverified
            $stmt = $this->conn->prepare("SELECT user_first_name FROM tbl_users WHERE user_id = ? AND user_email = ? AND is_verified = 0");
            $stmt->execute([$userId, $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return json_encode(["status" => "error", "message" => "User not found or already verified."]);
            }

            // Generate new OTP
            $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date("Y-m-d H:i:s", strtotime("+10 minutes"));

            // Update OTP
            $stmt = $this->conn->prepare(
                "INSERT INTO tbl_signup_otp (user_id, email, otp_code, expires_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE otp_code = VALUES(otp_code), expires_at = VALUES(expires_at)"
            );
            $stmt->execute([$userId, $email, $otp, $expiresAt]);

            // Send new OTP email
            if ($this->sendSignupOTPEmail($email, $otp, $user['user_first_name'])) {
                return json_encode([
                    "status" => "success",
                    "message" => "New verification code sent to your email!"
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to send verification email. Please try again."]);
            }
        } catch (Exception $e) {
            error_log("Resend OTP error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Failed to resend verification code. Please try again."]);
        }
    }

    // Send signup OTP email
    private function sendSignupOTPEmail($email, $otp, $firstName) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'aizelartunlock@gmail.com';
            $mail->Password = 'nhueuwnriexqdbpt';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('aizelartunlock@gmail.com', 'Noreen Event Planning');
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'Verify Your Email - Noreen Event Planning';

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
                        background-color: #f8f9fa;
                    }
                    .container {
                        max-width: 600px;
                        margin: 0 auto;
                        padding: 20px;
                        background-color: white;
                        border-radius: 10px;
                        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    }
                    .header {
                        text-align: center;
                        padding: 20px 0;
                        border-bottom: 2px solid #334746;
                    }
                    .logo {
                        color: #334746;
                        font-size: 28px;
                        font-weight: bold;
                        margin-bottom: 10px;
                    }
                    .welcome-text {
                        color: #333333;
                        font-size: 24px;
                        margin: 30px 0 20px 0;
                        text-align: center;
                    }
                    .content {
                        padding: 20px;
                        text-align: center;
                    }
                    .otp-code {
                        font-size: 36px;
                        font-weight: bold;
                        letter-spacing: 5px;
                        color: #334746;
                        padding: 20px;
                        background-color: #f8f9fa;
                        border-radius: 10px;
                        margin: 20px 0;
                        border: 2px dashed #334746;
                    }
                    .expiry-text {
                        color: #666666;
                        font-size: 14px;
                        margin: 20px 0;
                    }
                    .footer {
                        text-align: center;
                        padding: 20px;
                        color: #666666;
                        font-size: 12px;
                        border-top: 1px solid #eee;
                        margin-top: 30px;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <div class="logo">Noreen Event Planning</div>
                        <p>Your trusted partner for unforgettable events</p>
                    </div>

                    <div class="content">
                        <h2 class="welcome-text">Welcome, ' . htmlspecialchars($firstName) . '!</h2>
                        <p>Thank you for joining Noreen Event Planning. To complete your registration, please verify your email address using the code below:</p>

                        <div class="otp-code">' . $otp . '</div>

                        <p class="expiry-text">This code will expire in 10 minutes.</p>

                        <p>If you didn\'t create an account with us, please ignore this email.</p>
                    </div>

                    <div class="footer">
                        <p>© 2024 Noreen Event Planning. All rights reserved.</p>
                        <p>This is an automated message, please do not reply to this email.</p>
                    </div>
                </div>
            </body>
            </html>';

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
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

    // Ensure a generic user activity logs table exists
    private function ensureUserActivityTable() {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS tbl_user_activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                action_type ENUM('login','logout','signup','event_created','booking_created','payment_received','admin_action','booking_accepted','booking_rejected') NOT NULL,
                description TEXT NULL,
                user_role ENUM('admin','organizer','client','supplier','staff') NOT NULL,
                ip_address VARCHAR(64) NULL,
                user_agent TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_activity_user (user_id),
                INDEX idx_user_activity_action (action_type),
                INDEX idx_user_activity_date (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            $this->conn->exec($sql);
        } catch (Exception $e) {
            error_log("Failed ensuring tbl_user_activity_logs: " . $e->getMessage());
        }
    }

    // Log a generic user logout
    public function logout($userId) {
        try {
            if (empty($userId)) {
                return json_encode(["status" => "error", "message" => "Missing user_id"]);
            }

            // Fetch user
            $stmt = $this->conn->prepare("SELECT user_id, user_firstName, user_lastName, user_role FROM tbl_users WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return json_encode(["status" => "error", "message" => "User not found"]);
            }

            // Supplier-specific activity log
            if (strtolower($user['user_role']) === 'supplier') {
                try {
                    $supplierStmt = $this->conn->prepare("SELECT supplier_id FROM tbl_suppliers WHERE user_id = :user_id AND is_active = 1");
                    $supplierStmt->execute([':user_id' => $userId]);
                    $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
                    if ($supplier) {
                        $activityStmt = $this->conn->prepare("
                            INSERT INTO tbl_supplier_activity (
                                supplier_id, activity_type, activity_description, ip_address, user_agent, created_at
                            ) VALUES (?, 'logout', 'Supplier logged out', ?, ?, NOW())
                        ");
                        $activityStmt->execute([
                            $supplier['supplier_id'],
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    }
                } catch (Exception $e) {
                    error_log("Failed to log supplier logout: " . $e->getMessage());
                }
            }

            // Log logout using ActivityLogger
            if ($this->logger) {
                $this->logger->logAuth(
                    $userId,
                    'logout',
                    "User {$user['user_firstName']} {$user['user_lastName']} logged out",
                    $user['user_role'],
                    true
                );
            }

            return json_encode(["status" => "success", "message" => "Logout recorded"]);
        } catch (Exception $e) {
            error_log("Logout error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Failed to record logout"]);
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

    // New method specifically for client registration
    public function registerClient($data) {
        try {
            $this->conn->beginTransaction();

            // Log received data for debugging
            error_log("Client Registration Data: " . print_r($data, true));
            error_log("Raw POST data: " . print_r($_POST, true));

            // Required fields for client registration
            $required = ['firstName', 'lastName', 'username', 'email', 'contactNumber', 'password'];

            foreach ($required as $field) {
                if (!isset($data[$field]) || empty(trim($data[$field]))) {
                    error_log("Missing or empty required field: $field. Value: " . ($data[$field] ?? 'NOT SET'));
                    return json_encode(["status" => "error", "message" => "Missing required field: $field"]);
                }
            }

            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return json_encode(["status" => "error", "message" => "Invalid email format"]);
            }

            // Simplified CAPTCHA validation (for development/testing)
            if (!empty($data['captcha'])) {
                error_log("CAPTCHA received: " . $data['captcha']);
                // For development, accept any non-empty CAPTCHA response
                // In production, implement proper Google reCAPTCHA verification
                error_log("CAPTCHA validation passed (development mode)");
            } else {
                error_log("CAPTCHA response is empty or missing");
                return json_encode(["status" => "error", "message" => "CAPTCHA verification required"]);
            }

            // Check if email already exists
            $stmt = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->rowCount() > 0) {
                return json_encode(["status" => "error", "message" => "Email already exists"]);
            }

            // Check if username already exists
            $stmt = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_username = ?");
            $stmt->execute([$data['username']]);
            if ($stmt->rowCount() > 0) {
                return json_encode(["status" => "error", "message" => "Username already exists"]);
            }

            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

            // Prepare contact number (add + prefix if not present)
            $contactNumber = $data['contactNumber'];
            if (isset($data['countryCode']) && !empty($data['countryCode'])) {
                $contactNumber = $data['countryCode'] . $data['contactNumber'];
            }

            // Insert user into database - CRITICAL: Check if user_id column has AUTO_INCREMENT
            $sql = "INSERT INTO tbl_users (
                user_firstName, user_lastName, user_suffix, user_birthdate,
                user_email, user_contact, user_username, user_pwd,
                user_role, is_verified, created_at, account_status
            ) VALUES (
                :firstName, :lastName, :suffix, :birthdate,
                :email, :contactNumber, :username, :password,
                :userRole, 0, NOW(), 'active'
            )";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                ':firstName' => trim($data['firstName']),
                ':lastName' => trim($data['lastName']),
                ':suffix' => !empty($data['suffix']) ? trim($data['suffix']) : null,
                ':birthdate' => !empty($data['birthdate']) ? $data['birthdate'] : null,
                ':email' => strtolower(trim($data['email'])),
                ':contactNumber' => $contactNumber,
                ':username' => trim($data['username']),
                ':password' => $hashedPassword,
                ':userRole' => $data['userRole'] ?? 'client'
            ]);

            error_log("SQL Insert result: " . ($result ? 'SUCCESS' : 'FAILED'));
            if (!$result) {
                error_log("SQL Error: " . print_r($stmt->errorInfo(), true));
                return json_encode(["status" => "error", "message" => "Failed to create account"]);
            }

            $userId = $this->conn->lastInsertId();
            error_log("User ID after insert: " . $userId);

            // CRITICAL: Check if userId is valid
            if (!$userId || $userId == 0) {
                error_log("CRITICAL: lastInsertId() returned 0 - likely missing AUTO_INCREMENT");

                // Backup solution: Find the user by email to get the actual ID
                $stmt = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_email = ? ORDER BY user_id DESC LIMIT 1");
                $stmt->execute([$data['email']]);
                $userRecord = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($userRecord && $userRecord['user_id']) {
                    $userId = $userRecord['user_id'];
                    error_log("Found user ID via email lookup: " . $userId);
                } else {
                    error_log("Could not find user even after email lookup");
                    $this->conn->rollback();
                    return json_encode([
                        "status" => "error",
                        "message" => "Database error: user_id column missing AUTO_INCREMENT. Please run: ALTER TABLE tbl_users MODIFY COLUMN user_id INT(11) NOT NULL AUTO_INCREMENT;"
                    ]);
                }
            }

            // Generate OTP for email verification
            $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date("Y-m-d H:i:s", strtotime("+10 minutes"));

            // CRITICAL: Use the correct table: tbl_signup_otp (NOT tbl_2fa)
            $stmt = $this->conn->prepare("INSERT INTO tbl_signup_otp (user_id, email, otp_code, expires_at)
                                        VALUES (:user_id, :email, :otp, :expires_at)
                                        ON DUPLICATE KEY UPDATE otp_code = VALUES(otp_code), expires_at = VALUES(expires_at)");
            $otpResult = $stmt->execute([
                ':user_id' => $userId,
                ':email' => $data['email'],
                ':otp' => $otp,
                ':expires_at' => $expiresAt
            ]);

            error_log("OTP Insert result: " . ($otpResult ? 'SUCCESS' : 'FAILED'));
            error_log("OTP stored - User ID: $userId, Email: " . $data['email'] . ", OTP: $otp, Expires: $expiresAt");

            if (!$otpResult) {
                error_log("OTP insertion failed: " . print_r($stmt->errorInfo(), true));
                $this->conn->rollback();
                return json_encode(["status" => "error", "message" => "Failed to generate verification code"]);
            }

            // Send OTP email
            $emailSent = $this->sendSignupOTPEmail($data['email'], $otp, $data['firstName']);

            if (!$emailSent) {
                error_log("Failed to send OTP email");
                $this->conn->rollback();
                return json_encode(["status" => "error", "message" => "Failed to send verification email"]);
            }

            $this->conn->commit();
            error_log("Client registration completed successfully for user ID: $userId");

            return json_encode([
                "status" => "success",
                "message" => "Account created successfully. Please check your email for verification code.",
                "user" => [
                    "user_id" => $userId,
                    "email" => $data['email'],
                    "firstName" => $data['firstName'],
                    "lastName" => $data['lastName']
                ]
            ]);

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Registration error: " . $e->getMessage());
            error_log("Registration error trace: " . $e->getTraceAsString());

            // Return more specific error message for debugging
            $errorMessage = "Registration failed: " . $e->getMessage();
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $errorMessage = "Email or username already exists";
            } elseif (strpos($e->getMessage(), 'tbl_signup_otp') !== false) {
                $errorMessage = "Database table 'tbl_signup_otp' not found. Please run the migration.";
            } elseif (strpos($e->getMessage(), 'foreign key') !== false) {
                $errorMessage = "Database foreign key constraint failed";
            }

            return json_encode(["status" => "error", "message" => $errorMessage, "debug" => $e->getMessage()]);
        }
    }
}

// Debugging: Log received data
error_log("Received POST data: " . print_r($_POST, true));
error_log("Received RAW JSON data: " . file_get_contents("php://input"));

// Initialize Auth class with error handling
try {
    $auth = new Auth($pdo);
    error_log("Auth class initialized successfully");
} catch (Exception $e) {
    error_log("Failed to initialize Auth class: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "System initialization failed"]);
    exit;
}

// Handle API actions - Initialize $jsonData properly
$operation = $_POST['operation'] ?? '';
$jsonData = null;

if (empty($operation)) {
    $jsonData = json_decode(file_get_contents("php://input"), true);
    if (isset($jsonData['operation'])) {
        $operation = $jsonData['operation'];
    }
} else {
    // Even if operation is found in $_POST, try to parse JSON data
    $jsonData = json_decode(file_get_contents("php://input"), true);
}

error_log("Determined operation: " . $operation);

try {
switch ($operation) {
    case "login":
        echo $auth->login($_POST['email'] ?? ($jsonData['email'] ?? ''), $_POST['password'] ?? ($jsonData['password'] ?? ''));
        break;
    case "logout":
        $uid = $_POST['user_id'] ?? ($jsonData['user_id'] ?? '');
        echo $auth->logout($uid);
        break;
        case "register":
        // Use JSON data if available, otherwise use $_POST
        $data = !empty($jsonData) ? $jsonData : $_POST;

        error_log("Register operation - Data received: " . print_r($data, true));
        error_log("Register operation - POST data: " . print_r($_POST, true));
        error_log("Register operation - JSON data: " . print_r($jsonData, true));

        // CRITICAL: Always use registerClient for client registration
        // Check if this is client registration (improved detection)
        $isClientRegistration = (
            (isset($data['userRole']) && $data['userRole'] === 'client') ||
            (isset($data['firstName']) && isset($data['lastName']) && isset($data['username']) && !isset($data['vendorAddress'])) ||
            (isset($data['contactNumber']) && !isset($data['user_contact'])) || // New signup form uses contactNumber
            (isset($data['firstName']) && isset($data['email']) && !isset($data['vendorAddress']))
        );

        if ($isClientRegistration) {
            error_log("USING registerClient method - Client registration detected");
            $result = $auth->registerClient($data);
            error_log("registerClient result: " . $result);
            echo $result;
        } else {
            error_log("USING original register method - Vendor registration detected");
            $result = $auth->register($data);
            error_log("register result: " . $result);
            echo $result;
        }
        break;
    case "verify_otp":
        echo $auth->verifyOTP($_POST['user_id'] ?? ($jsonData['user_id'] ?? ''), $_POST['otp'] ?? ($jsonData['otp'] ?? ''));
        break;
    case "change_password":
        $userId = $_POST['user_id'] ?? ($jsonData['user_id'] ?? '');
        $currentPassword = $_POST['current_password'] ?? ($jsonData['current_password'] ?? '');
        $newPassword = $_POST['new_password'] ?? ($jsonData['new_password'] ?? '');
        $confirmPassword = $_POST['confirm_password'] ?? ($jsonData['confirm_password'] ?? '');
        echo $auth->changePassword($userId, $currentPassword, $newPassword, $confirmPassword);
        break;
    case "register_with_otp":
        echo $auth->registerWithOTP($_POST);
        break;
    case "verify_signup_otp":
        echo $auth->verifySignupOTP($_POST['user_id'] ?? ($jsonData['user_id'] ?? ''), $_POST['email'] ?? ($jsonData['email'] ?? ''), $_POST['otp'] ?? ($jsonData['otp'] ?? ''));
        break;
    case "resend_signup_otp":
        echo $auth->resendSignupOTP($_POST['user_id'] ?? ($jsonData['user_id'] ?? ''), $_POST['email'] ?? ($jsonData['email'] ?? ''));
        break;
    case "request_otp":
        // Allow clients to explicitly request an OTP (e.g., per-user 2FA preference)
        $uid = $_POST['user_id'] ?? ($jsonData['user_id'] ?? '');
        $email = $_POST['email'] ?? ($jsonData['email'] ?? '');
        echo $auth->sendOTP($email, $uid);
        break;
    case "check_email":
        $email = $_POST['email'] ?? ($jsonData['email'] ?? '');

        error_log("Checking email: " . $email);

        if (empty($email)) {
            echo json_encode(["status" => "error", "message" => "Email required"]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT user_id FROM tbl_users WHERE user_email = ?");
        $stmt->execute([$email]);
        $userExists = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userExists) {
            echo json_encode(["status" => "error", "exists" => true, "message" => "Email already taken"]);
        } else {
            echo json_encode(["status" => "success", "exists" => false, "message" => "Email available"]);
        }
        exit;

    default:
        error_log("Invalid action received: " . $operation);
        echo json_encode(["status" => "error", "message" => "Invalid action."]);
        break;
}
} catch (Exception $e) {
    error_log("Fatal error in auth.php: " . $e->getMessage());
    error_log("Error trace: " . $e->getTraceAsString());
    echo json_encode(["status" => "error", "message" => "Server error occurred", "debug" => $e->getMessage()]);
}
?>
