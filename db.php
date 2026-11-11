<?php
// Local XAMPP defaults. Override with environment variables when deploying.
$conn = new mysqli(
    getenv('FOOD_DB_HOST') ?: 'localhost',
    getenv('FOOD_DB_USER') ?: 'root',
    getenv('FOOD_DB_PASSWORD') ?: '',
    getenv('FOOD_DB_NAME') ?: 'food_products'
);
if ($conn->connect_error) {
    http_response_code(500);
    exit('Database connection failed. Check the XAMPP setup instructions.');
}
$conn->set_charset('utf8mb4');
require_once __DIR__ . '/app.php';
