/* Thiết lập chung */
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

body {
  background-color: #f4f6f9;
  display: flex;
  min-height: 100vh;
}

/* SIDEBAR BÊN TRÁI */
.sidebar {
  width: 260px;
  background-color: #ffffff;
  border-right: 1px solid #e0e0e0;
  display: flex;
  flex-direction: column;
  position: fixed;
  height: 100%;
  left: 0;
  top: 0;
}

.logo-area {
  padding: 25px 20px;
  border-bottom: 1px solid #f0f0f0;
}

.logo-area h2 {
  color: #ff6b00; /* Màu cam chủ đạo phong cách thương hiệu */
  font-size: 22px;
  font-weight: 800;
  letter-spacing: 0.5px;
}

.logo-area span {
  font-size: 12px;
  color: #888;
}

.menu-list {
  list-style: none;
  padding: 20px 0;
  overflow-y: auto;
}

.menu-category {
  font-size: 11px;
  text-transform: uppercase;
  color: #aaa;
  padding: 10px 20px 5px 20px;
  font-weight: 700;
  letter-spacing: 0.5px;
}

.menu-list li a {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 20px;
  color: #444;
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  transition: all 0.2s ease;
}

.menu-list li a i {
  font-size: 16px;
  width: 20px;
  text-align: center;
  color: #666;
}

.menu-list li a:hover, 
.menu-list li a.active {
  background-color: #fff4ee;
  color: #ff6b00;
  border-left: 4px solid #ff6b00;
}

.menu-list li a:hover i, 
.menu-list li a.active i {
  color: #ff6b00;
}

.logout-btn {
  color: #e74c3c !important;
}
.logout-btn i {
  color: #e74c3c !important;
}
.logout-btn:hover {
  background-color: #fdf2f2 !important;
  border-left: 4px solid #e74c3c !important;
}

/* KHUNG NỘI DUNG CHÍNH BÊN PHẢI */
.main-content {
  margin-left: 260px;
  flex: 1;
  display: flex;
  flex-direction: column;
}

/* Topbar */
.topbar {
  height: 60px;
  background-color: #ffffff;
  border-bottom: 1px solid #e0e0e0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 30px;
  font-size: 14px;
  color: #555;
  position: sticky;
  top: 0;
  z-index: 10;
}

.user-info {
  display: flex;
  align-items: center;
  gap: 20px;
}

.avatar-badge {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #f8f9fa;
  padding: 6px 14px;
  border-radius: 20px;
  border: 1px solid #eaeaea;
  font-weight: 600;
  color: #333;
}

/* Dashboard Container */
.dashboard-container {
  padding: 35px;
  display: flex;
  gap: 30px;
  flex: 1;
  background-color: #fafbfc;
}

.welcome-box {
  flex: 1.3;
  background: #fff;
  padding: 35px;
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0,0,0,0.03);
  border: 1px solid #eaeaea;
}

.welcome-box h1 {
  font-size: 28px;
  color: #222;
  margin-bottom: 25px;
  line-height: 1.3;
}

.highlight-name {
  color: #ff6b00;
  font-size: 32px;
}

/* Phần thông báo */
.notifications-section h3 {
  font-size: 16px;
  color: #444;
  margin-bottom: 15px;
  border-bottom: 2px solid #f0f0f0;
  padding-bottom: 10px;
}

.notif-item {
  padding: 12px 0;
  border-bottom: 1px solid #f5f5f5;
}

.notif-item:last-child {
  border-bottom: none;
}

.notif-date {
  font-size: 12px;
  color: #888;
  display: block;
  margin-bottom: 4px;
}

.notif-item p {
  font-size: 14px;
  color: #333;
  line-height: 1.5;
}

/* Khung minh họa bên phải */
.illustration-box {
  flex: 0.9;
  display: flex;
  justify-content: center;
  align-items: center;
}

.store-banner-card {
  background: linear-gradient(135deg, #ff8008, #ffc837);
  width: 100%;
  height: 100%;
  min-height: 380px;
  border-radius: 16px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  text-align: center;
  padding: 40px;
  color: white;
  box-shadow: 0 10px 25px rgba(255, 128, 8, 0.2);
}

.store-banner-card i {
  font-size: 64px;
  margin-bottom: 20px;
  background: rgba(255, 255, 255, 0.2);
  padding: 20px;
  border-radius: 50%;
}

.store-banner-card h2 {
  font-size: 24px;
  margin-bottom: 10px;
  font-weight: 700;
}

.store-banner-card p {
  font-size: 14px;
  opacity: 0.9;
  line-height: 1.6;
}

/* Responsive cho màn hình nhỏ */
@media (max-width: 900px) {
  .sidebar {
    width: 70px;
  }
  .sidebar .logo-area h2, 
  .sidebar .logo-area span,
  .sidebar .menu-category,
  .sidebar .menu-list li a span {
    display: none;
  }
  .main-content {
    margin-left: 70px;
  }
  .dashboard-container {
    flex-direction: column;
  }
}
