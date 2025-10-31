<?php
// Render.com specific optimizations
error_reporting(E_ALL);
ini_set('display_errors', 1); // Show errors for debugging
ini_set('log_errors', 1);
ini_set('error_log', 'php://stderr');

// Set appropriate timezone
date_default_timezone_set('UTC');

// Handle CORS if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Health check response
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === '/') {
    echo "Telegram Bot is running successfully!";
    error_log("Health check accessed");
    exit;
}

// Simple test response
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "<h1>Telegram Bot Test</h1>";
    echo "<p>Server is working!</p>";
    echo "<p>PHP Version: " . PHP_VERSION . "</p>";
    echo "<p>Time: " . date('Y-m-d H:i:s') . "</p>";
    exit;
}

// For POST requests (Telegram webhook)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    error_log("Received POST data: " . $input);
    
    // Simple response
    echo "OK";
    exit;
}

echo "Unexpected request";
?>
