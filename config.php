<?php
// Database configuration
define('DB_HOST', 'sql112.infinityfree.com');
define('DB_USER', 'if0_37529255'); // Change to your database username
define('DB_PASS', 'ZnTbnLUmjwHVy'); // Change to your database password
define('DB_NAME', 'if0_37529255_toilet1');

// Start session
session_start();

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Get current user
$currentUser = isset($_SESSION['user']) ? $_SESSION['user'] : null;
?>