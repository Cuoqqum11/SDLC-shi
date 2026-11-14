<?php
require dirname(__DIR__) . "/includes/db.php";
$error = "";
$old = ["full_name" => "", "username" => "", "email" => "", "phone" => ""];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    foreach ($old as $key => $value) {
        $old[$key] = trim($_POST[$key] ?? "");
    }
    $password = (string) ($_POST["password"] ?? "");
    if (strlen($old["full_name"]) < 2 || strlen($old["username"]) < 3) {
        $error = "Enter your name and a username with at least 3 characters.";
    } elseif (!filter_var($old["email"], FILTER_VALIDATE_EMAIL)) {
        $error = "Enter a valid email address.";
    } elseif (strlen($password) < 10) {
        $error = "Use a password with at least 10 characters.";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $s = $conn->prepare(
                'INSERT INTO users(FullName,UserName,Email,PhoneNumber,Password,RoleID,IsActive) VALUES(?,?,?,?,?,(SELECT RoleID FROM roles WHERE RoleName=\'Customer\'),1)',
            );
            $s->bind_param(
                "sssss",
                $old["full_name"],
                $old["username"],
                $old["email"],
                $old["phone"],
                $hash,
            );
            $s->execute();
            flash("Account created. You can sign in now.");
            header("Location: login.php");
            exit();
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                $error = "That username or email is already in use.";
            } else {
                error_log("MealDash registration failed: " . $e->getMessage());
                $error = "We could not create the account. Please try again.";
            }
        }
    }
}
page_start("Create account");
?><section class="panel form-panel"><p class="eyebrow">JOIN THE TABLE</p><h2>Create your account</h2><p class="muted">Good food from local restaurants, all in one place.</p><?php if (
    $error
): ?><div class="flash flash-error" role="alert"><?= h(
    $error,
) ?></div><?php endif; ?><form method="post" autocomplete="on"><?php csrf_field(); ?><div class="field-wrap"><label for="full_name">Full name</label><input class="input" id="full_name" name="full_name" required maxlength="120" autocomplete="name" value="<?= h(
    $old["full_name"],
) ?>"></div><div class="field-wrap"><label for="username">Username</label><input class="input" id="username" name="username" required minlength="3" maxlength="60" autocomplete="username" value="<?= h(
    $old["username"],
) ?>"></div><div class="field-wrap"><label for="email">Email</label><input class="input" id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?= h(
    $old["email"],
) ?>"></div><div class="field-wrap"><label for="phone">Phone</label><input class="input" id="phone" type="tel" name="phone" maxlength="30" autocomplete="tel" value="<?= h(
    $old["phone"],
) ?>"></div><div class="field-wrap"><label for="password">Password</label><input class="input" id="password" type="password" name="password" minlength="10" required autocomplete="new-password"><p class="help-text">At least 10 characters.</p></div><button class="button" type="submit">Create account</button></form><p class="muted">Already registered? <a href="login.php">Sign in</a></p></section><?php page_end();
