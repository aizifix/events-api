<?php
// Add CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

require_once 'db_connect.php';

function fetchGeneralData() {
    global $pdo;

    try {
        // Fetch stores
        $storeQuery = "SELECT s.*, u.first_name, u.last_name
                      FROM tbl_stores s
                      LEFT JOIN tbl_users u ON s.user_id = u.user_id
                      WHERE s.status = 'active'
                      ORDER BY s.created_at DESC";

        // Fetch venues
        $venueQuery = "SELECT v.*, u.first_name, u.last_name
                      FROM tbl_venues v
                      LEFT JOIN tbl_users u ON v.user_id = u.user_id
                      WHERE v.status = 'active'
                      ORDER BY v.created_at DESC";

        $storeStmt = $pdo->prepare($storeQuery);
        $venueStmt = $pdo->prepare($venueQuery);

        $storeStmt->execute();
        $venueStmt->execute();

        $stores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);
        $venues = $venueStmt->fetchAll(PDO::FETCH_ASSOC);

        // Process cover photos
        array_walk($stores, function(&$store) {
            $store['cover_photo'] = !empty($store['cover_photo']) ?
                'uploads/store_media/' . $store['cover_photo'] : null;
        });

        array_walk($venues, function(&$venue) {
            $venue['cover_photo'] = !empty($venue['cover_photo']) ?
                'uploads/venue_cover_photos/' . $venue['cover_photo'] : null;
        });

        return [
            'status' => 'success',
            'stores' => $stores,
            'venues' => $venues
        ];

    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'message' => 'Error fetching data: ' . $e->getMessage()
        ];
    }
}

// Update the getRandomFeatured function to ensure it always returns data
function getRandomFeatured($limit = 3) {
    global $pdo;

    try {
        $storeQuery = "SELECT
            s.store_id,
            s.store_name,
            s.store_type,
            s.store_coverphoto,
            s.store_profile_picture,
            u.user_firstName as first_name,
            u.user_lastName as last_name,
            sc.store_category_type as store_category
        FROM tbl_store s
        LEFT JOIN tbl_users u ON s.user_id = u.user_id
        LEFT JOIN tbl_store_category sc ON s.store_category_id = sc.store_category_id
        WHERE s.store_status = 'active'
        ORDER BY RAND()
        LIMIT ?";

        $storeStmt = $pdo->prepare($storeQuery);
        $storeStmt->execute([$limit]);
        $stores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);

        // Venue query with specific field selection
        $venueQuery = "SELECT
            v.venue_id,
            v.venue_title as venue_name,
            v.venue_location as location,
            v.venue_type,
            v.venue_status as availability_status,
            v.venue_profile_picture,
            v.venue_cover_photo,
            u.user_firstName as first_name,
            u.user_lastName as last_name
        FROM tbl_venue v
        LEFT JOIN tbl_users u ON v.user_id = u.user_id
        WHERE v.venue_status = 'available'
        ORDER BY RAND()
        LIMIT ?";

        // Execute venue query
        $venueStmt = $pdo->prepare($venueQuery);
        $venueStmt->execute([$limit]);
        $venues = $venueStmt->fetchAll(PDO::FETCH_ASSOC);

        // Process and format response
        $response = [
            'status' => 'success',
            'featured_stores' => array_map(function($store) {
                $coverPhoto = !empty($store['store_coverphoto'])
                    ? 'uploads/cover_photos/' . basename($store['store_coverphoto'])
                    : 'placeholder.jpg';

                $profilePicture = !empty($store['store_profile_picture'])
                    ? 'uploads/profile_pictures/' . basename($store['store_profile_picture'])
                    : 'placeholder.jpg';

                return [
                    'store_id' => $store['store_id'],
                    'store_name' => $store['store_name'],
                    'store_type' => $store['store_type'],
                    'store_category' => $store['store_category'],
                    'cover_photo' => $coverPhoto,
                    'profile_picture' => $profilePicture,
                    'first_name' => $store['first_name'],
                    'last_name' => $store['last_name']
                ];
            }, $stores),
            'featured_venues' => array_map(function($venue) {
                $venueCoverPhoto = $venue['venue_cover_photo']
                    ? 'uploads/venue_cover_photos/' . basename($venue['venue_cover_photo'])
                    : 'placeholder.jpg';

                $venueProfilePicture = $venue['venue_profile_picture']
                    ? 'uploads/venue_profile_pictures/' . basename($venue['venue_profile_picture'])
                    : 'placeholder.jpg';

                return [
                    'venue_id' => $venue['venue_id'],
                    'venue_name' => $venue['venue_name'],
                    'location' => $venue['location'],
                    'venue_type' => $venue['venue_type'],
                    'availability_status' => $venue['availability_status'],
                    'cover_photo' => $venueCoverPhoto,
                    'profile_picture' => $venueProfilePicture
                ];
            }, $venues)
        ];

        return $response;

    } catch (PDOException $e) {
        error_log("Database Error in getRandomFeatured: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Database error occurred'
        ];
    }
}

// Example API endpoint usage:
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['featured'])) {
        echo json_encode(getRandomFeatured());
    } else {
        echo json_encode(fetchGeneralData());
    }
}
