<?php
include 'db.php';
session_start();

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = md5(trim($_POST['password']));

    // Truy vấn kết hợp lấy thông tin user và tên phân quyền (RoleName)
    $sql = "SELECT u.*, r.RoleName FROM users u 
            JOIN roles r ON u.RoleID = r.RoleID 
            WHERE u.UserName='$username' AND u.Password='$password'";
    $result = $conn->query($sql);

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $_SESSION['user_id'] = $user['UserID'];
        $_SESSION['username'] = $user['UserName'];
        $_SESSION['role'] = $user['RoleName']; // Ví dụ: Admin hoặc Customer
        $success = "Đăng nhập thành công!";
    } else {
        $error = "Sai tên đăng nhập hoặc mật khẩu!";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Đăng nhập</title>
<link rel="stylesheet" href="style.css">
<style>
.toast {
  visibility: hidden; background: #4CAF50; color: white; padding: 12px 20px;
  border-radius: 8px; position: fixed; bottom: 30px; left: 50%;
  transform: translateX(-50%); opacity: 0; transition: opacity .5s; font-weight: 600; z-index: 1000;
}
.toast.show { visibility: visible; opacity: 1; }
.toast.error { background: #f44336; }
</style>
</head>
<body>
<div class="form-container">
  <h2>Login Account</h2>
  <form method="POST">
    <label>UserName:</label>
    <input type="text" name="username" required>

    <label>Password:</label>
    <input type="password" name="password" required>

    <button type="submit" name="login">Login</button>
  </form>
  <p>Chưa có tài khoản? <a href="register.php">Đăng ký ngay</a></p>
</div>

<div id="toast" class="toast"></div>

<script>
function showToast(msg, err=false){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = "toast show" + (err ? " error" : "");
  setTimeout(()=>t.className="toast", 3000);
}
<?php if(isset($success)){ ?>
showToast("<?= $success ?>");
setTimeout(()=>{window.location='index.php';}, 1500);
<?php } elseif(isset($error)){ ?>
showToast("<?= $error ?>", true);
<?php } ?>
</script>
</body>
</html>
