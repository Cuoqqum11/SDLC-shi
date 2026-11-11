<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'Customer';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang chủ - Quản lý Cửa hàng Sữa</title>
    <link rel="stylesheet" href="style(index).css">
    <!-- FontAwesome để lấy icon -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <!-- SIDEBAR BÊN TRÁI -->
    <div class="sidebar">
        <div class="logo-area">
            <h2>MILK STORE</h2>
            <span>Hệ thống Quản lý Sữa</span>
        </div>

        <ul class="menu-list">
            <li class="menu-category">Menu Chính</li>
            <li><a href="index.php" class="active"><i class="fa-solid fa-house"></i> Trang chủ</a></li>
            
            <li class="menu-category">Quản lý Sản phẩm</li>
            <li><a href="#"><i class="fa-solid fa-box"></i> Danh sách sản phẩm</a></li>
            <li><a href="#"><i class="fa-solid fa-tags"></i> Loại sữa / Danh mục</a></li>
            
            <?php if ($role === 'Admin'): ?>
            <li class="menu-category">Quản trị hệ thống</li>
            <li><a href="#"><i class="fa-solid fa-users-gear"></i> Quản lý tài khoản</a></li>
            <li><a href="#"><i class="fa-solid fa-chart-line"></i> Thống kê doanh thu</a></li>
            <?php endif; ?>

            <li class="menu-category">Hệ thống</li>
            <li><a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Đăng xuất</a></li>
        </ul>
    </div>

    <!-- NỘI DUNG CHÍNH BÊN PHẢI -->
    <div class="main-content">
        <!-- Thanh top bar -->
        <div class="topbar">
            <span>Trang chủ</span>
            <div class="user-info">
                <i class="fa-regular fa-bell" style="cursor: pointer; font-size: 18px;"></i>
                <div class="avatar-badge">
                    <i class="fa-solid fa-user"></i>
                    <span><?= htmlspecialchars($username) ?> (<?= htmlspecialchars($role) ?>)</span>
                </div>
            </div>
        </div>

        <!-- Khung chào mừng & thông báo -->
        <div class="dashboard-container">
            <div class="welcome-box">
                <h1>Xin chào, <br><span class="highlight-name"><?= htmlspecialchars($username) ?></span></h1>
                
                <div class="notifications-section">
                    <h3><i class="fa-solid fa-bullhorn"></i> Thông báo mới nhất từ cửa hàng</h3>
                    
                    <div class="notif-item">
                        <span class="notif-date">11/11/2026 08:30</span>
                        <p><strong>[CHƯƠNG TRÌNH KHUYẾN MÃI]</strong> Giảm giá 20% cho tất cả các dòng sữa tươi nguyên chất trong tuần lễ tri ân khách hàng.</p>
                    </div>

                    <div class="notif-item">
                        <span class="notif-date">05/11/2026 14:15</span>
                        <p><strong>[THÔNG BÁO NHẬP HÀNG]</strong> Đã cập nhật lô hàng sữa bột dinh dưỡng mới về kho, khách hàng có thể đặt mua ngay.</p>
                    </div>

                    <div class="notif-item">
                        <span class="notif-date">01/11/2026 09:00</span>
                        <p><strong>[HỆ THỐNG]</strong> Cập nhật giao diện quản lý kho và danh mục sản phẩm nhằm tối ưu trải nghiệm người dùng.</p>
                    </div>
                </div>
            </div>

            <!-- Khung ảnh minh họa bên phải -->
            <div class="illustration-box">
                <div class="store-banner-card">
                    <i class="fa-solid fa-glass-water"></i>
                    <h2>Milk Products Management</h2>
                    <p>Quản lý sản phẩm, tồn kho và phân quyền người dùng nhanh chóng, tiện lợi.</p>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
