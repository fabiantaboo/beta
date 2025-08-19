<?php
// Simple image serving endpoint
// Use centralized session configuration
include_once '../includes/session_config.php';

include_once '../config/database.php';
include_once '../includes/functions.php';

// Check if user is authenticated
if (!isLoggedIn()) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Access denied';
    exit;
}

// Get the requested file path
$requestPath = $_SERVER['REQUEST_URI'];
$pathInfo = parse_url($requestPath, PHP_URL_PATH);

// Extract the file path relative to /uploads/
if (preg_match('#^/uploads/(.+)$#', $pathInfo, $matches)) {
    $filePath = $matches[1];
    $fullPath = __DIR__ . '/' . $filePath;
    
    // Security: Prevent directory traversal
    $realPath = realpath($fullPath);
    $uploadDir = realpath(__DIR__);
    
    if ($realPath === false || strpos($realPath, $uploadDir) !== 0) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'File not found';
        exit;
    }
    
    // Check if file exists
    if (!file_exists($fullPath) || !is_file($fullPath)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'File not found';
        exit;
    }
    
    // Get MIME type
    $mimeType = mime_content_type($fullPath);
    
    // Only serve images
    if (strpos($mimeType, 'image/') !== 0) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Access denied';
        exit;
    }
    
    // Set appropriate headers
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($fullPath)) . ' GMT');
    
    // Output the file
    readfile($fullPath);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain');
echo 'File not found';
?>