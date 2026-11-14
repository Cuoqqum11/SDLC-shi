<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(
        getenv("FOOD_DB_HOST") ?: "localhost",
        getenv("FOOD_DB_USER") ?: "root",
        getenv("FOOD_DB_PASSWORD") ?: "",
        getenv("FOOD_DB_NAME") ?: "food_products",
        (int) (getenv("FOOD_DB_PORT") ?: 3306),
    );
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log("MealDash database connection failed: " . $e->getMessage());
    http_response_code(503);
    exit(
        "MealDash is temporarily unavailable. Check that XAMPP MySQL is running and the food_products database is imported."
    );
}
require_once __DIR__ . "/app.php";
