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

class SupplierService {
    private $conn;
    private $pdo;
    private $uploadPath = "uploads/supplier_portal/";

    public function __construct($pdo) {
        $this->conn = $pdo;
        $this->pdo = $pdo;

        // Ensure upload directories exist
        $this->createUploadDirectories();
    }

    private function createUploadDirectories() {
        $directories = [
            "uploads/supplier_portal/",
            "uploads/supplier_portal/offers/",
            "uploads/supplier_portal/documents/",
            "uploads/supplier_portal/portfolios/"
        ];

        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    // ==================== AUTHENTICATION METHODS ====================

    // Verify supplier authentication and get supplier info
    private function authenticateSupplier($userId) {
        try {
            $sql = "SELECT s.*, u.user_firstName, u.user_lastName, u.user_email
                    FROM tbl_suppliers s
                    JOIN tbl_users u ON s.user_id = u.user_id
                    WHERE s.user_id = ? AND s.supplier_type = 'internal' AND s.is_active = 1";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId]);
            $supplier = $stmt->fetch();

            if (!$supplier) {
                throw new Exception("Supplier not found or access denied");
            }

            return $supplier;
        } catch (Exception $e) {
            throw new Exception("Authentication failed: " . $e->getMessage());
        }
    }

    // Helper method to log supplier activity
    private function logSupplierActivity($supplierId, $activityType, $description, $relatedId = null, $metadata = null) {
        try {
            $sql = "INSERT INTO tbl_supplier_activity (
                        supplier_id, activity_type, activity_description,
                        related_id, metadata, created_at
                    ) VALUES (?, ?, ?, ?, ?, NOW())";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $supplierId,
                $activityType,
                $description,
                $relatedId,
                $metadata ? json_encode($metadata) : null
            ]);
        } catch (Exception $e) {
            // Don't throw exception for logging errors
            error_log("Failed to log supplier activity: " . $e->getMessage());
        }
    }

    // ==================== PUBLIC SUPPLIER METHODS ====================

    // Get all active offers for package builder (Public access)
    public function getAllActiveOffers($filters = []) {
        try {
            $whereClauses = ["so.is_active = 1", "s.is_active = 1", "s.is_verified = 1"];
            $params = [];

            if (!empty($filters['service_category'])) {
                $whereClauses[] = "so.service_category = ?";
                $params[] = $filters['service_category'];
            }

            if (!empty($filters['supplier_id'])) {
                $whereClauses[] = "so.supplier_id = ?";
                $params[] = $filters['supplier_id'];
            }

            if (!empty($filters['package_size'])) {
                $whereClauses[] = "so.package_size = ?";
                $params[] = $filters['package_size'];
            }

            if (!empty($filters['tier_level'])) {
                $whereClauses[] = "so.tier_level = ?";
                $params[] = $filters['tier_level'];
            }

            if (!empty($filters['max_guests'])) {
                $whereClauses[] = "(so.max_guests >= ? OR so.max_guests IS NULL)";
                $params[] = $filters['max_guests'];
            }

            if (!empty($filters['price_range'])) {
                if ($filters['price_range'] === 'budget') {
                    $whereClauses[] = "so.price_min <= 50000";
                } elseif ($filters['price_range'] === 'mid') {
                    $whereClauses[] = "so.price_min BETWEEN 50000 AND 150000";
                } elseif ($filters['price_range'] === 'premium') {
                    $whereClauses[] = "so.price_min > 150000";
                }
            }

            $whereSQL = "WHERE " . implode(" AND ", $whereClauses);

            $sql = "SELECT so.*, s.business_name, s.supplier_type, s.specialty_category,
                           s.rating_average, s.total_ratings, s.contact_email, s.contact_number,
                           COUNT(DISTINCT ec.event_component_id) as times_booked
                    FROM tbl_supplier_offers so
                    JOIN tbl_suppliers s ON so.supplier_id = s.supplier_id
                    LEFT JOIN tbl_event_components ec ON so.offer_id = ec.offer_id
                    $whereSQL
                    GROUP BY so.offer_id
                    ORDER BY s.supplier_type ASC, so.is_featured DESC, s.rating_average DESC, so.tier_level ASC, so.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $offers = [];
            while ($row = $stmt->fetch()) {
                $row['offer_attachments'] = $row['offer_attachments'] ? json_decode($row['offer_attachments'], true) : [];

                // Get subcomponents for each offer
                $subSql = "SELECT * FROM tbl_offer_subcomponents WHERE offer_id = ? AND is_active = 1 ORDER BY display_order ASC";
                $subStmt = $this->conn->prepare($subSql);
                $subStmt->execute([$row['offer_id']]);

                $subcomponents = [];
                while ($sub = $subStmt->fetch()) {
                    $subcomponents[] = $sub;
                }
                $row['subcomponents'] = $subcomponents;

                $offers[] = $row;
            }

            return json_encode([
                "status" => "success",
                "offers" => $offers
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get offers by specific supplier (Public access)
    public function getOffersBySupplier($supplierId) {
        try {
            $sql = "SELECT so.*, s.business_name, s.supplier_type, s.specialty_category,
                           s.rating_average, s.total_ratings,
                           COUNT(DISTINCT ec.event_component_id) as times_booked
                    FROM tbl_supplier_offers so
                    JOIN tbl_suppliers s ON so.supplier_id = s.supplier_id
                    LEFT JOIN tbl_event_components ec ON so.offer_id = ec.offer_id
                    WHERE so.supplier_id = ? AND so.is_active = 1 AND s.is_active = 1
                    GROUP BY so.offer_id
                    ORDER BY so.tier_level ASC, so.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$supplierId]);

            $offers = [];
            while ($row = $stmt->fetch()) {
                $row['offer_attachments'] = $row['offer_attachments'] ? json_decode($row['offer_attachments'], true) : [];

                // Get subcomponents
                $subSql = "SELECT * FROM tbl_offer_subcomponents WHERE offer_id = ? AND is_active = 1 ORDER BY display_order ASC";
                $subStmt = $this->conn->prepare($subSql);
                $subStmt->execute([$row['offer_id']]);

                $subcomponents = [];
                while ($sub = $subStmt->fetch()) {
                    $subcomponents[] = $sub;
                }
                $row['subcomponents'] = $subcomponents;

                $offers[] = $row;
            }

            return json_encode([
                "status" => "success",
                "offers" => $offers
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get specific offer details with subcomponents (Public access)
    public function getOfferDetails($offerId) {
        try {
            $sql = "SELECT so.*, s.business_name, s.supplier_type, s.specialty_category,
                           s.rating_average, s.total_ratings, s.contact_email, s.contact_number,
                           s.business_address, s.business_description,
                           COUNT(DISTINCT ec.event_component_id) as times_booked,
                           AVG(sr.rating) as offer_rating,
                           COUNT(DISTINCT sr.rating_id) as offer_rating_count
                    FROM tbl_supplier_offers so
                    JOIN tbl_suppliers s ON so.supplier_id = s.supplier_id
                    LEFT JOIN tbl_event_components ec ON so.offer_id = ec.offer_id
                    LEFT JOIN tbl_supplier_ratings sr ON ec.event_component_id = sr.event_component_id AND sr.is_public = 1
                    WHERE so.offer_id = ? AND so.is_active = 1 AND s.is_active = 1
                    GROUP BY so.offer_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$offerId]);
            $offer = $stmt->fetch();

            if (!$offer) {
                return json_encode(["status" => "error", "message" => "Offer not found"]);
            }

            $offer['offer_attachments'] = $offer['offer_attachments'] ? json_decode($offer['offer_attachments'], true) : [];

            // Get subcomponents
            $subSql = "SELECT * FROM tbl_offer_subcomponents WHERE offer_id = ? AND is_active = 1 ORDER BY display_order ASC";
            $subStmt = $this->conn->prepare($subSql);
            $subStmt->execute([$offerId]);

            $subcomponents = [];
            while ($sub = $subStmt->fetch()) {
                $subcomponents[] = $sub;
            }
            $offer['subcomponents'] = $subcomponents;

            // Get recent ratings for this offer
            $ratingsSql = "SELECT sr.*, u.user_firstName, u.user_lastName, e.event_title
                          FROM tbl_supplier_ratings sr
                          JOIN tbl_event_components ec ON sr.event_component_id = ec.event_component_id
                          JOIN tbl_events e ON sr.event_id = e.event_id
                          LEFT JOIN tbl_users u ON sr.client_id = u.user_id
                          WHERE ec.offer_id = ? AND sr.is_public = 1
                          ORDER BY sr.created_at DESC
                          LIMIT 5";

            $ratingsStmt = $this->conn->prepare($ratingsSql);
            $ratingsStmt->execute([$offerId]);

            $ratings = [];
            while ($rating = $ratingsStmt->fetch()) {
                $rating['feedback_attachments'] = $rating['feedback_attachments'] ? json_decode($rating['feedback_attachments'], true) : [];
                $ratings[] = $rating;
            }
            $offer['recent_ratings'] = $ratings;

            return json_encode([
                "status" => "success",
                "offer" => $offer
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get supplier categories for filtering (Public access)
    public function getSupplierCategories() {
        try {
            $sql = "SELECT DISTINCT s.specialty_category, COUNT(*) as supplier_count
                    FROM tbl_suppliers s
                    JOIN tbl_supplier_offers so ON s.supplier_id = so.supplier_id
                    WHERE s.specialty_category IS NOT NULL AND s.is_active = 1
                          AND s.is_verified = 1 AND so.is_active = 1
                    GROUP BY s.specialty_category
                    ORDER BY supplier_count DESC, s.specialty_category ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $categories = [];
            while ($row = $stmt->fetch()) {
                $categories[] = $row;
            }

            return json_encode([
                "status" => "success",
                "categories" => $categories
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get featured offers for homepage/promotions (Public access)
    public function getFeaturedOffers($limit = 6) {
        try {
            $sql = "SELECT so.*, s.business_name, s.supplier_type, s.specialty_category,
                           s.rating_average, s.total_ratings
                    FROM tbl_supplier_offers so
                    JOIN tbl_suppliers s ON so.supplier_id = s.supplier_id
                    WHERE so.is_active = 1 AND s.is_active = 1 AND s.is_verified = 1
                          AND so.is_featured = 1
                    ORDER BY s.rating_average DESC, so.created_at DESC
                    LIMIT ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$limit]);

            $offers = [];
            while ($row = $stmt->fetch()) {
                $row['offer_attachments'] = $row['offer_attachments'] ? json_decode($row['offer_attachments'], true) : [];

                // Get first few subcomponents as preview
                $subSql = "SELECT * FROM tbl_offer_subcomponents WHERE offer_id = ? AND is_active = 1 ORDER BY display_order ASC LIMIT 3";
                $subStmt = $this->conn->prepare($subSql);
                $subStmt->execute([$row['offer_id']]);

                $subcomponents = [];
                while ($sub = $subStmt->fetch()) {
                    $subcomponents[] = $sub;
                }
                $row['preview_subcomponents'] = $subcomponents;

                $offers[] = $row;
            }

            return json_encode([
                "status" => "success",
                "featured_offers" => $offers
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Create supplier rating (Public - post-event)
    public function createRating($data) {
        try {
            // Validate required fields
            if (empty($data['supplier_id']) || empty($data['rating']) || $data['rating'] < 1 || $data['rating'] > 5) {
                throw new Exception("Supplier ID and valid rating (1-5) are required");
            }

            $sql = "INSERT INTO tbl_supplier_ratings (
                        supplier_id, event_id, client_id, admin_id, rating, feedback,
                        service_quality, punctuality, communication, value_for_money,
                        component_satisfaction, would_recommend, feedback_attachments,
                        event_component_id, is_public, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->conn->prepare($sql);

            $feedbackAttachments = isset($data['feedback_attachments']) ? json_encode($data['feedback_attachments']) : null;
            $isPublic = isset($data['is_public']) ? (int)$data['is_public'] : 1;

            $stmt->execute([
                $data['supplier_id'],
                $data['event_id'] ?? null,
                $data['client_id'] ?? null,
                $data['admin_id'] ?? null,
                $data['rating'],
                $data['feedback'] ?? null,
                $data['service_quality'] ?? null,
                $data['punctuality'] ?? null,
                $data['communication'] ?? null,
                $data['value_for_money'] ?? null,
                $data['component_satisfaction'] ?? null,
                $data['would_recommend'] ?? null,
                $feedbackAttachments,
                $data['event_component_id'] ?? null,
                $isPublic
            ]);

            $ratingId = $this->conn->lastInsertId();

            // Mark event component as rated if provided
            if (!empty($data['event_component_id'])) {
                $updateStmt = $this->conn->prepare("UPDATE tbl_event_components SET is_rated = 1 WHERE event_component_id = ?");
                $updateStmt->execute([$data['event_component_id']]);
            }

            return json_encode([
                "status" => "success",
                "message" => "Rating submitted successfully",
                "rating_id" => $ratingId
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Search suppliers and offers (Public access)
    public function searchSuppliersAndOffers($searchTerm, $filters = []) {
        try {
            $whereClauses = [
                "so.is_active = 1",
                "s.is_active = 1",
                "s.is_verified = 1",
                "(s.business_name LIKE ? OR so.offer_title LIKE ? OR so.offer_description LIKE ? OR s.specialty_category LIKE ?)"
            ];

            $searchPattern = "%" . $searchTerm . "%";
            $params = [$searchPattern, $searchPattern, $searchPattern, $searchPattern];

            // Apply additional filters
            if (!empty($filters['service_category'])) {
                $whereClauses[] = "so.service_category = ?";
                $params[] = $filters['service_category'];
            }

            if (!empty($filters['price_min'])) {
                $whereClauses[] = "so.price_min >= ?";
                $params[] = $filters['price_min'];
            }

            if (!empty($filters['price_max'])) {
                $whereClauses[] = "so.price_max <= ?";
                $params[] = $filters['price_max'];
            }

            $whereSQL = "WHERE " . implode(" AND ", $whereClauses);

            $sql = "SELECT so.*, s.business_name, s.supplier_type, s.specialty_category,
                           s.rating_average, s.total_ratings
                    FROM tbl_supplier_offers so
                    JOIN tbl_suppliers s ON so.supplier_id = s.supplier_id
                    $whereSQL
                    ORDER BY s.rating_average DESC, so.is_featured DESC, so.created_at DESC
                    LIMIT 50";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $results = [];
            while ($row = $stmt->fetch()) {
                $row['offer_attachments'] = $row['offer_attachments'] ? json_decode($row['offer_attachments'], true) : [];
                $results[] = $row;
            }

            return json_encode([
                "status" => "success",
                "search_results" => $results,
                "search_term" => $searchTerm,
                "result_count" => count($results)
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get supplier public profile (Public access)
    public function getSupplierProfile($supplierId) {
        try {
            $sql = "SELECT s.*,
                           COUNT(DISTINCT so.offer_id) as total_offers,
                           COUNT(DISTINCT ec.event_component_id) as total_bookings,
                           AVG(sr.rating) as avg_rating,
                           COUNT(DISTINCT sr.rating_id) as total_ratings
                    FROM tbl_suppliers s
                    LEFT JOIN tbl_supplier_offers so ON s.supplier_id = so.supplier_id AND so.is_active = 1
                    LEFT JOIN tbl_event_components ec ON s.supplier_id = ec.supplier_id
                    LEFT JOIN tbl_supplier_ratings sr ON s.supplier_id = sr.supplier_id AND sr.is_public = 1
                    WHERE s.supplier_id = ? AND s.is_active = 1 AND s.is_verified = 1
                    GROUP BY s.supplier_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$supplierId]);
            $supplier = $stmt->fetch();

            if (!$supplier) {
                return json_encode(["status" => "error", "message" => "Supplier not found"]);
            }

            // Remove sensitive information for public view
            unset($supplier['registration_docs']);
            unset($supplier['contact_email']);
            unset($supplier['contact_number']);
            unset($supplier['user_id']);

            // Get offers
            $offersSql = "SELECT * FROM tbl_supplier_offers WHERE supplier_id = ? AND is_active = 1 ORDER BY tier_level ASC, created_at DESC";
            $offersStmt = $this->conn->prepare($offersSql);
            $offersStmt->execute([$supplierId]);

            $offers = [];
            while ($offer = $offersStmt->fetch()) {
                $offer['offer_attachments'] = $offer['offer_attachments'] ? json_decode($offer['offer_attachments'], true) : [];
                $offers[] = $offer;
            }
            $supplier['offers'] = $offers;

            // Get public ratings
            $ratingsSql = "SELECT sr.rating, sr.feedback, sr.service_quality, sr.punctuality,
                                 sr.communication, sr.value_for_money, sr.would_recommend, sr.created_at,
                                 u.user_firstName, e.event_title
                          FROM tbl_supplier_ratings sr
                          LEFT JOIN tbl_users u ON sr.client_id = u.user_id
                          LEFT JOIN tbl_events e ON sr.event_id = e.event_id
                          WHERE sr.supplier_id = ? AND sr.is_public = 1
                          ORDER BY sr.created_at DESC
                          LIMIT 10";

            $ratingsStmt = $this->conn->prepare($ratingsSql);
            $ratingsStmt->execute([$supplierId]);

            $ratings = [];
            while ($rating = $ratingsStmt->fetch()) {
                $ratings[] = $rating;
            }
            $supplier['public_ratings'] = $ratings;

            return json_encode([
                "status" => "success",
                "supplier" => $supplier
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // ==================== SUPPLIER PORTAL METHODS (AUTHENTICATED) ====================

    // Get supplier dashboard metrics
    public function getSupplierDashboard($userId) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            // Get basic metrics
            $metricsSql = "SELECT
                              COUNT(DISTINCT so.offer_id) as total_offers,
                              COUNT(DISTINCT ec.event_component_id) as total_bookings,
                              AVG(sr.rating) as avg_rating,
                              COUNT(DISTINCT sr.rating_id) as total_ratings,
                              SUM(CASE WHEN ec.supplier_status = 'pending' THEN 1 ELSE 0 END) as pending_bookings,
                              SUM(CASE WHEN ec.supplier_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
                              SUM(CASE WHEN ec.supplier_status = 'delivered' THEN 1 ELSE 0 END) as completed_bookings
                           FROM tbl_suppliers s
                           LEFT JOIN tbl_supplier_offers so ON s.supplier_id = so.supplier_id AND so.is_active = 1
                           LEFT JOIN tbl_event_components ec ON s.supplier_id = ec.supplier_id
                           LEFT JOIN tbl_supplier_ratings sr ON s.supplier_id = sr.supplier_id AND sr.is_public = 1
                           WHERE s.supplier_id = ?";

            $stmt = $this->conn->prepare($metricsSql);
            $stmt->execute([$supplier['supplier_id']]);
            $metrics = $stmt->fetch();

            // Get recent bookings
            $bookingsSql = "SELECT ec.*, e.event_title, e.event_date, e.event_startTime,
                                   u.user_firstName, u.user_lastName
                            FROM tbl_event_components ec
                            JOIN tbl_events e ON ec.event_id = e.event_id
                            LEFT JOIN tbl_users u ON e.user_id = u.user_id
                            WHERE ec.supplier_id = ?
                            ORDER BY e.event_date DESC
                            LIMIT 10";

            $bookingsStmt = $this->conn->prepare($bookingsSql);
            $bookingsStmt->execute([$supplier['supplier_id']]);

            $recentBookings = [];
            while ($booking = $bookingsStmt->fetch()) {
                $recentBookings[] = $booking;
            }

            // Get recent ratings
            $ratingsSql = "SELECT sr.*, u.user_firstName, u.user_lastName, e.event_title
                          FROM tbl_supplier_ratings sr
                          LEFT JOIN tbl_users u ON sr.client_id = u.user_id
                          LEFT JOIN tbl_events e ON sr.event_id = e.event_id
                          WHERE sr.supplier_id = ? AND sr.is_public = 1
                          ORDER BY sr.created_at DESC
                          LIMIT 5";

            $ratingsStmt = $this->conn->prepare($ratingsSql);
            $ratingsStmt->execute([$supplier['supplier_id']]);

            $recentRatings = [];
            while ($rating = $ratingsStmt->fetch()) {
                $rating['feedback_attachments'] = $rating['feedback_attachments'] ? json_decode($rating['feedback_attachments'], true) : [];
                $recentRatings[] = $rating;
            }

            // Get monthly performance data for chart
            $performanceSql = "SELECT
                                  DATE_FORMAT(e.event_date, '%Y-%m') as month,
                                  COUNT(*) as bookings_count,
                                  AVG(sr.rating) as avg_rating
                               FROM tbl_event_components ec
                               JOIN tbl_events e ON ec.event_id = e.event_id
                               LEFT JOIN tbl_supplier_ratings sr ON ec.event_component_id = sr.event_component_id
                               WHERE ec.supplier_id = ? AND e.event_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                               GROUP BY DATE_FORMAT(e.event_date, '%Y-%m')
                               ORDER BY month ASC";

            $performanceStmt = $this->conn->prepare($performanceSql);
            $performanceStmt->execute([$supplier['supplier_id']]);

            $monthlyData = [];
            while ($month = $performanceStmt->fetch()) {
                $monthlyData[] = $month;
            }

            return json_encode([
                "status" => "success",
                "dashboard" => [
                    "supplier_info" => $supplier,
                    "metrics" => $metrics,
                    "recent_bookings" => $recentBookings,
                    "recent_ratings" => $recentRatings,
                    "monthly_performance" => $monthlyData
                ]
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get supplier's own offers
    public function getSupplierOffers($userId) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            $sql = "SELECT so.*,
                           COUNT(DISTINCT ec.event_component_id) as times_booked,
                           AVG(sr.rating) as avg_rating,
                           COUNT(DISTINCT sr.rating_id) as rating_count
                    FROM tbl_supplier_offers so
                    LEFT JOIN tbl_event_components ec ON so.offer_id = ec.offer_id
                    LEFT JOIN tbl_supplier_ratings sr ON ec.event_component_id = sr.event_component_id AND sr.is_public = 1
                    WHERE so.supplier_id = ? AND so.is_active = 1
                    GROUP BY so.offer_id
                    ORDER BY so.tier_level ASC, so.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$supplier['supplier_id']]);

            $offers = [];
            while ($offer = $stmt->fetch()) {
                $offer['offer_attachments'] = $offer['offer_attachments'] ? json_decode($offer['offer_attachments'], true) : [];

                // Get subcomponents for this offer
                $subSql = "SELECT * FROM tbl_offer_subcomponents WHERE offer_id = ? AND is_active = 1 ORDER BY display_order ASC";
                $subStmt = $this->conn->prepare($subSql);
                $subStmt->execute([$offer['offer_id']]);

                $subcomponents = [];
                while ($sub = $subStmt->fetch()) {
                    $subcomponents[] = $sub;
                }
                $offer['subcomponents'] = $subcomponents;

                $offers[] = $offer;
            }

            return json_encode([
                "status" => "success",
                "offers" => $offers
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Create a new offer (Supplier only)
    public function createOffer($userId, $data) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            $this->conn->beginTransaction();

            // Validate required fields
            if (empty($data['offer_title']) || empty($data['price_min'])) {
                throw new Exception("Offer title and minimum price are required");
            }

            $sql = "INSERT INTO tbl_supplier_offers (
                        supplier_id, offer_title, tier_name, tier_level, offer_description,
                        price_min, price_max, service_category, package_size, max_guests,
                        setup_fee, delivery_timeframe, terms_conditions, cancellation_policy,
                        offer_attachments, is_featured, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

            $stmt = $this->conn->prepare($sql);

            $priceMax = $data['price_max'] ?? $data['price_min'];
            $offerAttachments = isset($data['offer_attachments']) ? json_encode($data['offer_attachments']) : null;
            $isFeatured = isset($data['is_featured']) ? (int)$data['is_featured'] : 0;

            $stmt->execute([
                $supplier['supplier_id'],
                $data['offer_title'],
                $data['tier_name'] ?? null,
                $data['tier_level'] ?? 1,
                $data['offer_description'] ?? null,
                $data['price_min'],
                $priceMax,
                $data['service_category'] ?? null,
                $data['package_size'] ?? null,
                $data['max_guests'] ?? null,
                $data['setup_fee'] ?? 0,
                $data['delivery_timeframe'] ?? null,
                $data['terms_conditions'] ?? null,
                $data['cancellation_policy'] ?? null,
                $offerAttachments,
                $isFeatured
            ]);

            $offerId = $this->conn->lastInsertId();

            // Add subcomponents if provided
            if (isset($data['subcomponents']) && is_array($data['subcomponents'])) {
                foreach ($data['subcomponents'] as $index => $subcomponent) {
                    $subSql = "INSERT INTO tbl_offer_subcomponents (
                                  offer_id, component_title, component_description,
                                  is_customizable, display_order, is_active, created_at
                               ) VALUES (?, ?, ?, ?, ?, 1, NOW())";

                    $subStmt = $this->conn->prepare($subSql);
                    $subStmt->execute([
                        $offerId,
                        $subcomponent['component_title'],
                        $subcomponent['component_description'] ?? null,
                        isset($subcomponent['is_customizable']) ? (int)$subcomponent['is_customizable'] : 0,
                        $index + 1
                    ]);
                }
            }

            // Log activity
            $this->logSupplierActivity($supplier['supplier_id'], 'offer_created',
                "Created new offer: " . $data['offer_title'], $offerId);

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Offer created successfully",
                "offer_id" => $offerId
            ]);

        } catch (Exception $e) {
            $this->conn->rollback();
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Update offer (Supplier only)
    public function updateOffer($userId, $offerId, $data) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            // Verify offer belongs to supplier
            $checkStmt = $this->conn->prepare("SELECT * FROM tbl_supplier_offers WHERE offer_id = ? AND supplier_id = ?");
            $checkStmt->execute([$offerId, $supplier['supplier_id']]);
            $offer = $checkStmt->fetch();

            if (!$offer) {
                throw new Exception("Offer not found or access denied");
            }

            $this->conn->beginTransaction();

            // Update offer
            $updateFields = [];
            $params = [];

            $allowedFields = [
                'offer_title', 'tier_name', 'tier_level', 'offer_description',
                'price_min', 'price_max', 'service_category', 'package_size',
                'max_guests', 'setup_fee', 'delivery_timeframe', 'terms_conditions',
                'cancellation_policy', 'is_featured'
            ];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    if ($field === 'offer_attachments') {
                        $params[] = json_encode($data[$field]);
                    } else {
                        $params[] = $data[$field];
                    }
                }
            }

            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = NOW()";
                $params[] = $offerId;

                $sql = "UPDATE tbl_supplier_offers SET " . implode(", ", $updateFields) . " WHERE offer_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
            }

            // Update subcomponents if provided
            if (isset($data['subcomponents']) && is_array($data['subcomponents'])) {
                // Remove existing subcomponents
                $deleteSubStmt = $this->conn->prepare("UPDATE tbl_offer_subcomponents SET is_active = 0 WHERE offer_id = ?");
                $deleteSubStmt->execute([$offerId]);

                // Add new subcomponents
                foreach ($data['subcomponents'] as $index => $subcomponent) {
                    $subSql = "INSERT INTO tbl_offer_subcomponents (
                                  offer_id, component_title, component_description,
                                  is_customizable, display_order, is_active, created_at
                               ) VALUES (?, ?, ?, ?, ?, 1, NOW())";

                    $subStmt = $this->conn->prepare($subSql);
                    $subStmt->execute([
                        $offerId,
                        $subcomponent['component_title'],
                        $subcomponent['component_description'] ?? null,
                        isset($subcomponent['is_customizable']) ? (int)$subcomponent['is_customizable'] : 0,
                        $index + 1
                    ]);
                }
            }

            // Log activity
            $this->logSupplierActivity($supplier['supplier_id'], 'offer_updated',
                "Updated offer: " . ($data['offer_title'] ?? $offer['offer_title']), $offerId);

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Offer updated successfully"
            ]);

        } catch (Exception $e) {
            $this->conn->rollback();
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Delete offer (Supplier only)
    public function deleteOffer($userId, $offerId) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            // Verify offer belongs to supplier
            $checkStmt = $this->conn->prepare("SELECT * FROM tbl_supplier_offers WHERE offer_id = ? AND supplier_id = ?");
            $checkStmt->execute([$offerId, $supplier['supplier_id']]);
            $offer = $checkStmt->fetch();

            if (!$offer) {
                throw new Exception("Offer not found or access denied");
            }

            // Soft delete
            $sql = "UPDATE tbl_supplier_offers SET is_active = 0, updated_at = NOW() WHERE offer_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$offerId]);

            // Also soft delete subcomponents
            $subSql = "UPDATE tbl_offer_subcomponents SET is_active = 0 WHERE offer_id = ?";
            $subStmt = $this->conn->prepare($subSql);
            $subStmt->execute([$offerId]);

            // Log activity
            $this->logSupplierActivity($supplier['supplier_id'], 'offer_deleted',
                "Deleted offer: " . $offer['offer_title'], $offerId);

            return json_encode([
                "status" => "success",
                "message" => "Offer deleted successfully"
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get supplier bookings
    public function getSupplierBookings($userId, $status = null) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            $whereClauses = ["ec.supplier_id = ?"];
            $params = [$supplier['supplier_id']];

            if ($status && in_array($status, ['pending', 'confirmed', 'delivered', 'cancelled'])) {
                $whereClauses[] = "ec.supplier_status = ?";
                $params[] = $status;
            }

            $whereSQL = "WHERE " . implode(" AND ", $whereClauses);

            $sql = "SELECT ec.*, e.event_title, e.event_date, e.event_startTime, e.event_endTime,
                           u.user_firstName, u.user_lastName, u.user_email, u.user_contact,
                           so.offer_title, so.tier_name
                    FROM tbl_event_components ec
                    JOIN tbl_events e ON ec.event_id = e.event_id
                    LEFT JOIN tbl_users u ON e.user_id = u.user_id
                    LEFT JOIN tbl_supplier_offers so ON ec.offer_id = so.offer_id
                    $whereSQL
                    ORDER BY e.event_date ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $bookings = [];
            while ($booking = $stmt->fetch()) {
                $bookings[] = $booking;
            }

            return json_encode([
                "status" => "success",
                "bookings" => $bookings
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Update booking status
    public function updateBookingStatus($userId, $eventComponentId, $status, $notes = null) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            // Verify component belongs to supplier
            $checkStmt = $this->conn->prepare("SELECT * FROM tbl_event_components WHERE event_component_id = ? AND supplier_id = ?");
            $checkStmt->execute([$eventComponentId, $supplier['supplier_id']]);
            $component = $checkStmt->fetch();

            if (!$component) {
                throw new Exception("Component not found or access denied");
            }

            $validStatuses = ['pending', 'confirmed', 'delivered', 'cancelled'];
            if (!in_array($status, $validStatuses)) {
                throw new Exception("Invalid status");
            }

            $sql = "UPDATE tbl_event_components SET
                      supplier_status = ?, supplier_notes = ?, updated_at = NOW()
                    WHERE event_component_id = ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$status, $notes, $eventComponentId]);

            // Log activity
            $this->logSupplierActivity($supplier['supplier_id'], 'component_delivered',
                "Updated booking status to: $status", $eventComponentId);

            return json_encode([
                "status" => "success",
                "message" => "Booking status updated successfully"
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Update supplier profile
    public function updateProfile($userId, $data) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            $this->conn->beginTransaction();

            // Update supplier table
            $updateFields = [];
            $params = [];

            $allowedFields = [
                'business_name', 'contact_number', 'contact_email', 'contact_person',
                'business_address', 'business_description', 'specialty_category'
            ];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = NOW()";
                $params[] = $supplier['supplier_id'];

                $sql = "UPDATE tbl_suppliers SET " . implode(", ", $updateFields) . " WHERE supplier_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
            }

            // Update user table if email is changed
            if (isset($data['contact_email'])) {
                $userUpdateStmt = $this->conn->prepare("UPDATE tbl_users SET user_email = ? WHERE user_id = ?");
                $userUpdateStmt->execute([$data['contact_email'], $supplier['user_id']]);
            }

            // Log activity
            $this->logSupplierActivity($supplier['supplier_id'], 'profile_updated',
                "Updated profile information", null);

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Profile updated successfully"
            ]);

        } catch (Exception $e) {
            $this->conn->rollback();
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Upload document
    public function uploadDocument($userId, $file, $documentType, $title) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            $allowedTypes = ['dti', 'business_permit', 'contract', 'portfolio', 'certification', 'other'];
            if (!in_array($documentType, $allowedTypes)) {
                throw new Exception("Invalid document type");
            }

            // Handle file upload
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($fileExtension, $allowedExtensions)) {
                throw new Exception("Invalid file type. Allowed: " . implode(', ', $allowedExtensions));
            }

            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
            $filePath = $this->uploadPath . 'documents/' . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception("Failed to upload file");
            }

            // Save to database
            $sql = "INSERT INTO tbl_supplier_documents (
                        supplier_id, document_type, document_title, file_path, file_name,
                        file_size, file_type, uploaded_by, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $supplier['supplier_id'],
                $documentType,
                $title,
                $filePath,
                $fileName,
                $file['size'],
                $file['type'],
                $supplier['user_id']
            ]);

            $documentId = $this->conn->lastInsertId();

            // Log activity
            $this->logSupplierActivity($supplier['supplier_id'], 'document_uploaded',
                "Uploaded document: $title", $documentId);

            return json_encode([
                "status" => "success",
                "message" => "Document uploaded successfully",
                "document_id" => $documentId
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get supplier documents
    public function getSupplierDocuments($userId) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            $sql = "SELECT * FROM tbl_supplier_documents
                    WHERE supplier_id = ? AND is_active = 1
                    ORDER BY created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$supplier['supplier_id']]);

            $documents = [];
            while ($doc = $stmt->fetch()) {
                $documents[] = $doc;
            }

            return json_encode([
                "status" => "success",
                "documents" => $documents
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get supplier activity log
    public function getSupplierActivity($userId, $limit = 50) {
        try {
            $supplier = $this->authenticateSupplier($userId);

            $sql = "SELECT * FROM tbl_supplier_activity
                    WHERE supplier_id = ?
                    ORDER BY created_at DESC
                    LIMIT ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$supplier['supplier_id'], $limit]);

            $activities = [];
            while ($activity = $stmt->fetch()) {
                $activity['metadata'] = $activity['metadata'] ? json_decode($activity['metadata'], true) : [];
                $activities[] = $activity;
            }

            return json_encode([
                "status" => "success",
                "activities" => $activities
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get supplier analytics (for analytics dashboard)
    public function getSupplierAnalytics($userId, $timeRange = '6_months') {
        try {
            $supplier = $this->authenticateSupplier($userId);

            // Calculate date range
            $dateRange = match($timeRange) {
                '1_month' => 'INTERVAL 1 MONTH',
                '3_months' => 'INTERVAL 3 MONTH',
                '6_months' => 'INTERVAL 6 MONTH',
                '1_year' => 'INTERVAL 1 YEAR',
                default => 'INTERVAL 6 MONTH'
            };

            // Get overview metrics
            $overviewSql = "SELECT
                              SUM(ec.component_price) as total_revenue,
                              COUNT(DISTINCT ec.event_component_id) as total_bookings,
                              AVG(sr.rating) as avg_rating,
                              (COUNT(CASE WHEN ec.supplier_status = 'delivered' THEN 1 END) / COUNT(*) * 100) as completion_rate
                           FROM tbl_event_components ec
                           JOIN tbl_events e ON ec.event_id = e.event_id
                           LEFT JOIN tbl_supplier_ratings sr ON ec.event_component_id = sr.event_component_id
                           WHERE ec.supplier_id = ? AND e.event_date >= DATE_SUB(NOW(), $dateRange)";

            $overviewStmt = $this->conn->prepare($overviewSql);
            $overviewStmt->execute([$supplier['supplier_id']]);
            $overview = $overviewStmt->fetch();

            // Get monthly data
            $monthlySql = "SELECT
                              DATE_FORMAT(e.event_date, '%b %Y') as month,
                              SUM(ec.component_price) as revenue,
                              COUNT(ec.event_component_id) as bookings,
                              AVG(sr.rating) as avg_rating
                           FROM tbl_event_components ec
                           JOIN tbl_events e ON ec.event_id = e.event_id
                           LEFT JOIN tbl_supplier_ratings sr ON ec.event_component_id = sr.event_component_id
                           WHERE ec.supplier_id = ? AND e.event_date >= DATE_SUB(NOW(), $dateRange)
                           GROUP BY DATE_FORMAT(e.event_date, '%Y-%m')
                           ORDER BY e.event_date ASC";

            $monthlyStmt = $this->conn->prepare($monthlySql);
            $monthlyStmt->execute([$supplier['supplier_id']]);

            $monthlyData = [];
            while ($month = $monthlyStmt->fetch()) {
                $monthlyData[] = $month;
            }

            // Get offer performance
            $offerPerformanceSql = "SELECT so.offer_title, so.tier_name,
                                          COUNT(ec.event_component_id) as bookings_count,
                                          SUM(ec.component_price) as total_revenue,
                                          AVG(sr.rating) as avg_rating
                                   FROM tbl_supplier_offers so
                                   LEFT JOIN tbl_event_components ec ON so.offer_id = ec.offer_id
                                   LEFT JOIN tbl_events e ON ec.event_id = e.event_id
                                   LEFT JOIN tbl_supplier_ratings sr ON ec.event_component_id = sr.event_component_id
                                   WHERE so.supplier_id = ? AND so.is_active = 1
                                   AND (e.event_date IS NULL OR e.event_date >= DATE_SUB(NOW(), $dateRange))
                                   GROUP BY so.offer_id
                                   ORDER BY bookings_count DESC";

            $offerStmt = $this->conn->prepare($offerPerformanceSql);
            $offerStmt->execute([$supplier['supplier_id']]);

            $offerPerformance = [];
            while ($offer = $offerStmt->fetch()) {
                $offerPerformance[] = $offer;
            }

            // Get status breakdown
            $statusSql = "SELECT supplier_status, COUNT(*) as count
                         FROM tbl_event_components ec
                         JOIN tbl_events e ON ec.event_id = e.event_id
                         WHERE ec.supplier_id = ? AND e.event_date >= DATE_SUB(NOW(), $dateRange)
                         GROUP BY supplier_status";

            $statusStmt = $this->conn->prepare($statusSql);
            $statusStmt->execute([$supplier['supplier_id']]);

            $statusBreakdown = [];
            while ($status = $statusStmt->fetch()) {
                $statusBreakdown[$status['supplier_status']] = $status['count'];
            }

            // Get recent feedback
            $feedbackSql = "SELECT sr.rating, sr.feedback, sr.created_at,
                                  u.user_firstName, u.user_lastName, e.event_title
                           FROM tbl_supplier_ratings sr
                           JOIN tbl_events e ON sr.event_id = e.event_id
                           LEFT JOIN tbl_users u ON sr.client_id = u.user_id
                           WHERE sr.supplier_id = ? AND sr.is_public = 1
                           ORDER BY sr.created_at DESC
                           LIMIT 10";

            $feedbackStmt = $this->conn->prepare($feedbackSql);
            $feedbackStmt->execute([$supplier['supplier_id']]);

            $recentFeedback = [];
            while ($feedback = $feedbackStmt->fetch()) {
                $recentFeedback[] = $feedback;
            }

            return json_encode([
                "status" => "success",
                "analytics" => [
                    "overview" => $overview,
                    "monthly_data" => $monthlyData,
                    "offer_performance" => $offerPerformance,
                    "status_breakdown" => $statusBreakdown,
                    "recent_feedback" => $recentFeedback
                ]
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
}

// Handle the request
try {
    $supplierService = new SupplierService($pdo);
    $method = $_SERVER['REQUEST_METHOD'];
    $operation = $_GET['operation'] ?? '';

    if ($method === 'GET') {
        switch ($operation) {
            case 'getAllActiveOffers':
                $filters = [
                    'service_category' => $_GET['service_category'] ?? '',
                    'supplier_id' => $_GET['supplier_id'] ?? '',
                    'package_size' => $_GET['package_size'] ?? '',
                    'tier_level' => $_GET['tier_level'] ?? '',
                    'max_guests' => $_GET['max_guests'] ?? '',
                    'price_range' => $_GET['price_range'] ?? ''
                ];
                echo $supplierService->getAllActiveOffers($filters);
                break;

            case 'getOffersBySupplier':
                $supplierId = (int)($_GET['supplier_id'] ?? 0);
                if ($supplierId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid supplier ID required"]);
                } else {
                    echo $supplierService->getOffersBySupplier($supplierId);
                }
                break;

            case 'getOfferDetails':
                $offerId = (int)($_GET['offer_id'] ?? 0);
                if ($offerId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid offer ID required"]);
                } else {
                    echo $supplierService->getOfferDetails($offerId);
                }
                break;

            case 'getSupplierCategories':
                echo $supplierService->getSupplierCategories();
                break;

            case 'getFeaturedOffers':
                $limit = (int)($_GET['limit'] ?? 6);
                echo $supplierService->getFeaturedOffers($limit);
                break;

            case 'searchSuppliersAndOffers':
                $searchTerm = $_GET['search'] ?? '';
                if (empty($searchTerm)) {
                    echo json_encode(["status" => "error", "message" => "Search term required"]);
                } else {
                    $filters = [
                        'service_category' => $_GET['service_category'] ?? '',
                        'price_min' => $_GET['price_min'] ?? '',
                        'price_max' => $_GET['price_max'] ?? ''
                    ];
                    echo $supplierService->searchSuppliersAndOffers($searchTerm, $filters);
                }
                break;

            case 'getSupplierProfile':
                $supplierId = (int)($_GET['supplier_id'] ?? 0);
                if ($supplierId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid supplier ID required"]);
                } else {
                    echo $supplierService->getSupplierProfile($supplierId);
                }
                break;

            // ==================== SUPPLIER PORTAL OPERATIONS ====================
            case 'getDashboard':
                $userId = (int)($_GET['user_id'] ?? 0);
                if ($userId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID required"]);
                } else {
                    echo $supplierService->getSupplierDashboard($userId);
                }
                break;

            case 'getOffers':
                $userId = (int)($_GET['user_id'] ?? 0);
                if ($userId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID required"]);
                } else {
                    echo $supplierService->getSupplierOffers($userId);
                }
                break;

            case 'getBookings':
                $userId = (int)($_GET['user_id'] ?? 0);
                $status = $_GET['status'] ?? null;
                if ($userId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID required"]);
                } else {
                    echo $supplierService->getSupplierBookings($userId, $status);
                }
                break;

            case 'getDocuments':
                $userId = (int)($_GET['user_id'] ?? 0);
                if ($userId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID required"]);
                } else {
                    echo $supplierService->getSupplierDocuments($userId);
                }
                break;

            case 'getActivity':
                $userId = (int)($_GET['user_id'] ?? 0);
                $limit = (int)($_GET['limit'] ?? 50);
                if ($userId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID required"]);
                } else {
                    echo $supplierService->getSupplierActivity($userId, $limit);
                }
                break;

            case 'getAnalytics':
                $userId = (int)($_GET['user_id'] ?? 0);
                $timeRange = $_GET['time_range'] ?? '6_months';
                if ($userId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID required"]);
                } else {
                    echo $supplierService->getSupplierAnalytics($userId, $timeRange);
                }
                break;

            default:
                echo json_encode(["status" => "error", "message" => "Invalid operation"]);
                break;
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        switch ($operation) {
            case 'createRating':
                echo $supplierService->createRating($input);
                break;

            // ==================== SUPPLIER PORTAL POST OPERATIONS ====================
            case 'createOffer':
                $userId = (int)($_GET['user_id'] ?? 0);
                if ($userId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID required"]);
                } else {
                    echo $supplierService->createOffer($userId, $input);
                }
                break;

            case 'updateProfile':
                $userId = (int)($_GET['user_id'] ?? 0);
                if ($userId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID required"]);
                } else {
                    echo $supplierService->updateProfile($userId, $input);
                }
                break;

            case 'uploadDocument':
                $userId = (int)($_GET['user_id'] ?? 0);
                if ($userId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID required"]);
                } elseif (isset($_FILES['document'])) {
                    $documentType = $_POST['document_type'] ?? 'other';
                    $title = $_POST['title'] ?? 'Untitled Document';
                    echo $supplierService->uploadDocument($userId, $_FILES['document'], $documentType, $title);
                } else {
                    echo json_encode(["status" => "error", "message" => "No file uploaded"]);
                }
                break;

            default:
                echo json_encode(["status" => "error", "message" => "Invalid operation"]);
                break;
        }
    } elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);

        switch ($operation) {
            case 'updateOffer':
                $userId = (int)($_GET['user_id'] ?? 0);
                $offerId = (int)($_GET['offer_id'] ?? 0);
                if ($userId <= 0 || $offerId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID and offer ID required"]);
                } else {
                    echo $supplierService->updateOffer($userId, $offerId, $input);
                }
                break;

            case 'updateBookingStatus':
                $userId = (int)($_GET['user_id'] ?? 0);
                $eventComponentId = (int)($_GET['event_component_id'] ?? 0);
                $status = $input['status'] ?? '';
                $notes = $input['notes'] ?? null;

                if ($userId <= 0 || $eventComponentId <= 0 || empty($status)) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID, component ID and status required"]);
                } else {
                    echo $supplierService->updateBookingStatus($userId, $eventComponentId, $status, $notes);
                }
                break;

            default:
                echo json_encode(["status" => "error", "message" => "Invalid operation"]);
                break;
        }
    } elseif ($method === 'DELETE') {
        switch ($operation) {
            case 'deleteOffer':
                $userId = (int)($_GET['user_id'] ?? 0);
                $offerId = (int)($_GET['offer_id'] ?? 0);
                if ($userId <= 0 || $offerId <= 0) {
                    echo json_encode(["status" => "error", "message" => "Valid user ID and offer ID required"]);
                } else {
                    echo $supplierService->deleteOffer($userId, $offerId);
                }
                break;

            default:
                echo json_encode(["status" => "error", "message" => "Invalid operation"]);
                break;
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
