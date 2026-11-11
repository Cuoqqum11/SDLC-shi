<?php
session_start();
include '../db.php';

// Kiểm tra đăng nhập và quyền Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    echo "<script>alert('Bạn không có quyền truy cập trang quản trị!'); window.location='../index.php';</script>";
    exit;
}

$username = $_SESSION['username'] ?? 'Admin';

// Lấy thống kê tổng quan từ cơ sở dữ liệu
$total_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$total_roles = $conn->query("SELECT COUNT(*) as count FROM roles")->fetch_assoc()['count'];
$total_products = $conn->query("SELECT COUNT(*) as count FROM milk_products")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang Quản trị (Admin) - Milk Store</title>
    <!-- Đường dẫn CSS lùi ra 1 cấp thư mục (../style(index).css) -->
    <link rel="stylesheet" href="../style(index).css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style(admin)index.php">
</head>
<body>

    <!-- SIDEBAR BÊN TRÁI CHO ADMIN -->
    <div class="sidebar">
        <div class="logo-area">
            <h2>MILK ADMIN</h2>
            <span>Quản trị hệ thống</span>
        </div>

        <ul class="menu-list">
            <li class="menu-category">Chức năng chính</li>
            <li><a href="index.php" class="active"><i class="fa-solid fa-gauge"></i> Tổng quan Admin</a></li>
            <li><a href="../index.php"><i class="fa-solid fa-house"></i> Về trang chủ</a></li>
            
            <li class="menu-category">Quản lý dữ liệu</li>
            <li><a href="#"><i class="fa-solid fa-users"></i> Quản lý tài khoản</a></li>
            <li><a href="#"><i class="fa-solid fa-box-open"></i> Quản lý sản phẩm sữa</a></li>
            <li><a href="#"><i class="fa-solid fa-shield-halved"></i> Quản lý phân quyền</a></li>

            <li class="menu-category">Hệ thống</li>
            <li><a href="../logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Đăng xuất</a></li>
        </ul>
    </div>

    <!-- NỘI DUNG CHÍNH BÊN PHẢI -->
    <div class="main-content">
        <!-- Thanh top bar -->
        <div class="topbar">
            <span>Trang Quản trị Hệ thống</span>
            <div class="user-info">
                <i class="fa-regular fa-bell" style="cursor: pointer; font-size: 18px;"></i>
                <div class="avatar-badge">
                    <i class="fa-solid fa-user-shield" style="color: #ff6b00;"></i>
                    <span><?= htmlspecialchars($username) ?> (Admin)</span>
                </div>
            </div>
        </div>

        <!-- Nội dung Dashboard Admin -->
        <div class="dashboard-container" style="flex-direction: column;">
            
            <div class="admin-welcome">
                <h2>Chào mừng Quản trị viên, <?= htmlspecialchars($username) ?>!</h2>
                <p>Đây là khu vực quản lý hệ thống. Bạn có thể theo dõi số liệu tổng quan, quản lý người dùng và cập nhật danh mục sản phẩm sữa tại đây.</p>
            </div>

            <!-- Các ô thống kê nhanh -->
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fa-solid fa-users"></i>
                    <div class="stat-info">
                        <h4>Tổng số người dùng</h4>
                        <span><?= $total_users ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <i class="fa-solid fa-box"></i>
                    <div class="stat-info">
                        <h4>Tổng sản phẩm sữa</h4>
                        <span><?= $total_products ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <i class="fa-solid fa-user-tag"></i>
                    <div class="stat-info">
                        <h4>Nhóm quyền (Roles)</h4>
                        <span><?= $total_roles ?></span>
                    </div>
                </div>
            </div>

        </div>
    </div>

</body>
</html>
