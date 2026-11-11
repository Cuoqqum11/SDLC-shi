<?php
include 'db.php';
session_start();

if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $role_id = intval($_POST['role_id']); // Nhận RoleID từ form (1: Admin, 2: Customer)

    // Kiểm tra định dạng mật khẩu mạnh
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{6,}$/', $password)) {
        $error = "Mật khẩu phải ≥6 ký tự, gồm chữ hoa, thường, số và ký tự đặc biệt!";
    } else {
        $password_hash = md5($password);
        
        // Kiểm tra xem UserName hoặc Email đã tồn tại chưa
        $check = $conn->query("SELECT * FROM users WHERE UserName='$username' OR Email='$email'");
        if ($check->num_rows > 0) {
            $error = "Tên đăng nhập hoặc Email đã tồn tại!";
        } else {
            // Thêm user kèm theo RoleID đã chọn
            $sql = "INSERT INTO users (UserName, Email, Password, RoleID) VALUES ('$username', '$email', '$password_hash', $role_id)";
            if ($conn->query($sql)) {
                $success = "Đăng ký tài khoản thành công! Hãy đăng nhập.";
            } else {
                $error = "Lỗi khi đăng ký: " . $conn->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Register Account</title>
<link rel="stylesheet" href="style.css">
<style>
.toast {
  visibility: hidden; background-color: #4CAF50; color: white;
  padding: 12px 20px; border-radius: 8px; position: fixed;
  bottom: 30px; left: 50%; transform: translateX(-50%);
  opacity: 0; transition: opacity 0.5s; font-weight: 600; z-index: 1000;
}
.toast.show { visibility: visible; opacity: 1; }
.toast.error { background-color: #f44336; }

/* Thêm style cho thẻ select (Role) */
select {
  width: 100%;
  padding: 12px 14px;
  margin-bottom: 18px;
  border: 1px solid #dcdcdc;
  border-radius: 8px;
  font-size: 15px;
  background-color: #fdfdfd;
  box-sizing: border-box;
  outline: none;
  transition: all 0.3s ease;
}
select:focus {
  border-color: #3498db;
  box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15);
}
</style>
</head>
<body>
<div class="form-container">
  <h2>Register Account</h2>
  <form method="POST" onsubmit="return validatePassword();">
    <label>UserName:</label>
    <input type="text" name="username" required>

    <label>Email:</label>
    <input type="email" name="email" required>

    <label>Password:</label>
    <input type="password" id="password" name="password" required>
    <small style="color: #666; display: block; margin-top: -10px; margin-bottom: 15px; font-size: 11px;">≥6 ký tự, có chữ hoa, thường, số & ký tự đặc biệt</small>

    <label>Role (Vai trò):</label>
    <select name="role_id" required>
      <option value="2">Customer (Khách hàng)</option>
      <option value="1">Admin (Quản trị viên)</option>
    </select>

    <button type="submit" name="register">Register</button>
  </form>
  <p>Đã có tài khoản? <a href="login.php">Đăng nhập ngay</a></p>
</div>

<div id="toast" class="toast"></div>

<script>
function showToast(msg, error=false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = "toast show" + (error ? " error" : "");
  setTimeout(()=>t.className="toast", 3000);
}
function validatePassword(){
  const pw = document.getElementById('password').value;
  const re = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{6,}$/;
  if(!re.test(pw)){ showToast("Mật khẩu chưa đủ mạnh!", true); return false; }
  return true;
}
<?php if(isset($success)){ ?>
showToast("<?= $success ?>");
setTimeout(()=>{window.location='login.php';}, 2000);
<?php } elseif(isset($error)){ ?>
showToast("<?= $error ?>", true);
<?php } ?>
</script>
</body>
</html>
