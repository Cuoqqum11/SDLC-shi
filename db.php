<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "milk_products";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Thiết lập mã hóa tiếng Việt (nếu cần)
$conn->set_charset("utf8mb4");
?>
