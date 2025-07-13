<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Get the image path from query parameter
$path = isset($_GET['path']) ? $_GET['path'] : '';

// Security: Remove any directory traversal attempts
$path = str_replace('../', '', $path);
$path = str_replace('..\\', '', $path);

// Define the base directory for uploads
$baseDir = dirname(__FILE__) . '/';

// Construct the full file path
$fullPath = $baseDir . $path;

// Check if file exists and is within the allowed directory
if (!file_exists($fullPath) || !is_file($fullPath)) {
    // Return default profile picture if file not found
    $defaultImage = dirname(__FILE__) . '/../../public/default_pfp.png';
    if (file_exists($defaultImage)) {
        $fullPath = $defaultImage;
    } else {
        header('HTTP/1.0 404 Not Found');
        exit('File not found');
    }
}

// Verify the file is an image
$imageInfo = @getimagesize($fullPath);
if ($imageInfo === false) {
    header('HTTP/1.0 403 Forbidden');
    exit('Invalid image file');
}

// Get the MIME type
$mimeType = $imageInfo['mime'];

// Set appropriate headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year

// Output the image
readfile($fullPath);
exit();
?>
