<?php
require dirname(__DIR__) . "/includes/db.php";
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $identity = trim($_POST["identity"] ?? "");
    $password = (string) ($_POST["password"] ?? "");
    $s = $conn->prepare(
        "SELECT u.UserID,u.UserName,u.FullName,u.Password,u.IsActive,r.RoleName FROM users u JOIN roles r ON r.RoleID=u.RoleID WHERE u.UserName=? OR u.Email=? LIMIT 1",
    );
    $s->bind_param("ss", $identity, $identity);
    $s->execute();
    $account = $s->get_result()->fetch_assoc();
    $valid =
        $account &&
        ((password_get_info($account["Password"])["algo"] !== null &&
            password_verify($password, $account["Password"])) ||
            hash_equals($account["Password"], md5($password)));
    if ($valid && (int) $account["IsActive"] === 1) {
        if (password_get_info($account["Password"])["algo"] === null) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE users SET Password=? WHERE UserID=?");
            $up->bind_param("si", $hash, $account["UserID"]);
            $up->execute();
        }
        session_regenerate_id(true);
        unset($_SESSION["csrf"]);
        $_SESSION["user_id"] = (int) $account["UserID"];
        $_SESSION["username"] = $account["UserName"];
        $_SESSION["full_name"] = $account["FullName"] ?: $account["UserName"];
        $_SESSION["role"] = $account["RoleName"];
        merge_guest_cart($conn, (int) $account["UserID"]);
        $target = match ($account["RoleName"]) {
            "Admin" => "admin/index.php",
            "Restaurant" => "restaurant.php",
            "Driver" => "courier.php",
            default => "index.php",
        };
        header("Location: " . $target);
        exit();
    }
    $error = $valid
        ? "This account is currently deactivated. Contact support for help."
        : "The email or password is incorrect.";
}
page_start("Sign in");
?><section class="panel form-panel"><p class="eyebrow">GOOD TO SEE YOU</p><h2>Sign in to MealDash</h2><p class="muted">Pick up where your next meal begins.</p><?php if (
    $error
): ?><div class="flash flash-error" role="alert"><?= h(
    $error,
) ?></div><?php endif; ?><form method="post" autocomplete="on"><?php csrf_field(); ?><div class="field-wrap"><label for="identity">Username or email</label><input class="input" id="identity" name="identity" required autocomplete="username" value="<?= h(
    $_POST["identity"] ?? "",
) ?>"></div><div class="field-wrap"><label for="password">Password</label><input class="input" id="password" type="password" name="password" required autocomplete="current-password"></div><button class="button" type="submit">Sign in</button></form><p class="muted">New to MealDash? <a href="register.php">Create an account</a></p></section><?php page_end();
