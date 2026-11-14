<?php
require dirname(__DIR__) . "/includes/db.php";
require_role(["Customer", "Restaurant", "Driver", "Admin"]);
$me = user();
$error = "";
$info = [];
$s = $conn->prepare(
    "SELECT FullName,UserName,Email,PhoneNumber FROM users WHERE UserID=?",
);
$s->bind_param("i", $me["id"]);
$s->execute();
$profile = $s->get_result()->fetch_assoc();
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $action = $_POST["action"] ?? "";
    if ($action === "profile") {
        $name = trim($_POST["full_name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        if (strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Enter your name and a valid email.";
        } else {
            try {
                $s = $conn->prepare(
                    "UPDATE users SET FullName=?,Email=?,PhoneNumber=? WHERE UserID=?",
                );
                $s->bind_param("sssi", $name, $email, $phone, $me["id"]);
                $s->execute();
                $_SESSION["full_name"] = $name;
                flash("Your profile was updated.");
                header("Location: account.php");
                exit();
            } catch (mysqli_sql_exception $e) {
                $error =
                    $e->getCode() === 1062
                        ? "That email address is already in use."
                        : "Your profile could not be saved.";
            }
        }
    } elseif ($action === "password") {
        $old = (string) ($_POST["current_password"] ?? "");
        $new = (string) ($_POST["new_password"] ?? "");
        $s = $conn->prepare("SELECT Password FROM users WHERE UserID=?");
        $s->bind_param("i", $me["id"]);
        $s->execute();
        $hash = $s->get_result()->fetch_assoc()["Password"];
        $oldValid =
            (password_get_info($hash)["algo"] !== null &&
                password_verify($old, $hash)) ||
            hash_equals($hash, md5($old));
        if (!$oldValid) {
            $error = "Your current password is incorrect.";
        } elseif (strlen($new) < 10) {
            $error = "Choose a password with at least 10 characters.";
        } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $s = $conn->prepare("UPDATE users SET Password=? WHERE UserID=?");
            $s->bind_param("si", $newHash, $me["id"]);
            $s->execute();
            session_regenerate_id(true);
            flash("Your password was changed.", "success");
            header("Location: account.php");
            exit();
        }
    } elseif ($action === "address_save") {
        $id = (int) ($_POST["address_id"] ?? 0);
        $recipient = trim($_POST["recipient_name"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $line = trim($_POST["address_line"] ?? "");
        $label = trim($_POST["label"] ?? "Home");
        $default = !empty($_POST["is_default"]) ? 1 : 0;
        if (strlen($recipient) < 2 || strlen($phone) < 7 || strlen($line) < 8) {
            $error = "Enter a recipient, contact phone and complete address.";
        } else {
            $conn->begin_transaction();
            try {
                if ($default) {
                    $s = $conn->prepare(
                        "UPDATE user_addresses SET is_default=0 WHERE user_id=?",
                    );
                    $s->bind_param("i", $me["id"]);
                    $s->execute();
                }
                if ($id) {
                    $s = $conn->prepare(
                        "UPDATE user_addresses SET recipient_name=?,phone=?,address_line=?,label=?,is_default=? WHERE id=? AND user_id=?",
                    );
                    $s->bind_param(
                        "ssssiii",
                        $recipient,
                        $phone,
                        $line,
                        $label,
                        $default,
                        $id,
                        $me["id"],
                    );
                    $s->execute();
                    if (!$s->affected_rows) {
                        throw new RuntimeException(
                            "Address could not be found.",
                        );
                    }
                } else {
                    $s = $conn->prepare(
                        "INSERT INTO user_addresses(user_id,recipient_name,phone,address_line,label,is_default) VALUES(?,?,?,?,?,?)",
                    );
                    $s->bind_param(
                        "issssi",
                        $me["id"],
                        $recipient,
                        $phone,
                        $line,
                        $label,
                        $default,
                    );
                    $s->execute();
                }
                $conn->commit();
                flash("Delivery address saved.");
                header("Location: account.php#addresses");
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $error =
                    $e instanceof RuntimeException
                        ? $e->getMessage()
                        : "The address could not be saved.";
            }
        }
    } elseif ($action === "address_delete") {
        $id = (int) ($_POST["address_id"] ?? 0);
        $s = $conn->prepare(
            "DELETE FROM user_addresses WHERE id=? AND user_id=?",
        );
        $s->bind_param("ii", $id, $me["id"]);
        $s->execute();
        flash("Address removed.");
        header("Location: account.php#addresses");
        exit();
    } elseif ($action === "favorite_remove" && $me["role"] === "Customer") {
        $id = (int) ($_POST["restaurant_id"] ?? 0);
        $s = $conn->prepare(
            "DELETE FROM favorites WHERE user_id=? AND restaurant_id=?",
        );
        $s->bind_param("ii", $me["id"], $id);
        $s->execute();
        flash("Restaurant removed from favorites.");
        header("Location: account.php#favorites");
        exit();
    }
}
$s = $conn->prepare(
    "SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC,id DESC",
);
$s->bind_param("i", $me["id"]);
$s->execute();
$addresses = $s->get_result();
$favorites = null;
$reviews = null;
if ($me["role"] === "Customer") {
    $s = $conn->prepare(
        "SELECT r.*,COALESCE(v.score,0) score FROM favorites f JOIN restaurants r ON r.id=f.restaurant_id LEFT JOIN (SELECT restaurant_id,AVG(rating) score FROM reviews GROUP BY restaurant_id) v ON v.restaurant_id=r.id WHERE f.user_id=? ORDER BY f.created_at DESC",
    );
    $s->bind_param("i", $me["id"]);
    $s->execute();
    $favorites = $s->get_result();
    $s = $conn->prepare(
        "SELECT rv.*,r.name restaurant FROM reviews rv JOIN restaurants r ON r.id=rv.restaurant_id WHERE rv.customer_id=? ORDER BY rv.created_at DESC",
    );
    $s->bind_param("i", $me["id"]);
    $s->execute();
    $reviews = $s->get_result();
}
$layout = match ($me["role"]) {
    "Restaurant" => "restaurant",
    "Driver" => "courier",
    "Admin" => "admin",
    default => "storefront",
};
page_start("Your account", $layout);
?><div class="section-heading"><div><p class="eyebrow">PROFILE & PREFERENCES</p><h2>Your account</h2></div></div><?php if (
    $error
): ?><div class="flash flash-error" role="alert"><?= h(
    $error,
) ?></div><?php endif; ?>
<div class="two-col"><section class="panel"><h2>Personal information</h2><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="profile"><div class="field-wrap"><label for="name">Full name</label><input class="input" id="name" name="full_name" maxlength="120" value="<?= h(
    $profile["FullName"],
) ?>" required></div><div class="field-wrap"><label for="username">Username</label><input class="input" id="username" value="<?= h(
    $profile["UserName"],
) ?>" disabled></div><div class="field-wrap"><label for="email">Email</label><input class="input" type="email" id="email" name="email" value="<?= h(
    $profile["Email"],
) ?>" required></div><div class="field-wrap"><label for="phone">Phone</label><input class="input" type="tel" id="phone" name="phone" value="<?= h(
    $profile["PhoneNumber"],
) ?>"></div><button class="button">Save profile</button></form></section><section class="panel"><h2>Change password</h2><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="password"><div class="field-wrap"><label for="current_password">Current password</label><input class="input" type="password" id="current_password" name="current_password" required autocomplete="current-password"></div><div class="field-wrap"><label for="new_password">New password</label><input class="input" type="password" id="new_password" name="new_password" minlength="10" required autocomplete="new-password"><p class="help-text">At least 10 characters.</p></div><button class="button button-dark">Change password</button></form><hr class="hr"><p class="muted small">Account role: <?= h(
    $me["role"],
) ?> · Role changes are managed by a MealDash administrator.</p></section></div>
<section class="section" id="addresses"><div class="section-heading"><div><p class="eyebrow">DELIVERY DESTINATIONS</p><h2>Saved addresses</h2></div></div><div class="two-col"><div class="stack"><?php if (
    !$addresses->num_rows
): ?><div class="empty-state"><strong>No saved addresses</strong>Add one for faster checkout.</div><?php else:while (
        $a = $addresses->fetch_assoc()
    ): ?><article class="panel"><div class="row"><strong><?= h(
    $a["label"],
) ?> <?= $a["is_default"]
     ? '<span class="badge badge-good">Default</span>'
     : "" ?></strong><form method="post"><?php csrf_field(); ?><input type="hidden" name="address_id" value="<?= $a[
    "id"
] ?>"><button class="button button-sm button-quiet" name="action" value="address_delete" data-confirm="Remove this saved address?">Remove</button></form></div><p><?= h(
    $a["recipient_name"],
) ?> · <?= h($a["phone"]) ?><br><?= h(
    $a["address_line"],
) ?></p><details><summary>Edit address</summary><form method="post" style="margin-top:12px"><?php csrf_field(); ?><input type="hidden" name="action" value="address_save"><input type="hidden" name="address_id" value="<?= $a[
    "id"
] ?>"><div class="field-wrap"><label for="recipient-<?= $a[
    "id"
] ?>">Recipient</label><input class="input" id="recipient-<?= $a[
    "id"
] ?>" name="recipient_name" value="<?= h(
    $a["recipient_name"],
) ?>" required></div><div class="field-wrap"><label for="phone-<?= $a[
    "id"
] ?>">Phone</label><input class="input" type="tel" id="phone-<?= $a[
    "id"
] ?>" name="phone" value="<?= h(
    $a["phone"],
) ?>" required></div><div class="field-wrap"><label for="label-<?= $a[
    "id"
] ?>">Label</label><input class="input" id="label-<?= $a[
    "id"
] ?>" name="label" value="<?= h(
    $a["label"],
) ?>" required></div><div class="field-wrap"><label for="address-<?= $a[
    "id"
] ?>">Full address</label><textarea class="textarea" id="address-<?= $a[
    "id"
] ?>" name="address_line" required><?= h(
    $a["address_line"],
) ?></textarea></div><label><input type="checkbox" name="is_default" value="1" <?= $a[
    "is_default"
]
    ? "checked"
    : "" ?>> Make default</label><p><button class="button button-sm">Save address</button></p></form></details></article><?php endwhile;endif; ?></div><section class="panel"><h2>Add a delivery address</h2><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="address_save"><div class="field-wrap"><label for="recipient">Recipient</label><input class="input" name="recipient_name" id="recipient" required></div><div class="field-wrap"><label for="address_phone">Phone</label><input class="input" type="tel" name="phone" id="address_phone" required></div><div class="field-wrap"><label for="label">Label</label><select class="select" id="label" name="label"><option>Home</option><option>Work</option><option>Other</option></select></div><div class="field-wrap"><label for="address_line">Full address</label><textarea class="textarea" name="address_line" id="address_line" minlength="8" required></textarea></div><label><input type="checkbox" name="is_default" value="1"> Make default</label><p><button class="button">Save address</button></p></form></section></div></section>
<?php
if (
    $me["role"] === "Customer"
): ?><section class="panel"><div class="row"><div><p class="eyebrow">RESTAURANT OWNERS</p><h2>Partner with MealDash</h2><p class="muted">Submit your restaurant details for administrator review.</p></div><a class="button" href="restaurant_apply.php">Apply to join</a></div></section><section class="section" id="favorites"><div class="section-heading"><div><p class="eyebrow">SAVED FOR LATER</p><h2>Favorite restaurants</h2></div></div><?php if (
    !$favorites->num_rows
): ?><div class="empty-state"><strong>No favorites yet</strong>Save a restaurant from its menu to find it here.</div><?php else: ?><div class="food-grid"><?php while (
    $r = $favorites->fetch_assoc()
): ?><article class="restaurant-card"><a href="restaurant_detail.php?id=<?= $r[
    "id"
] ?>"><img class="restaurant-cover" src="<?= h(
    $r["image_url"] ?:
    "https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=700&auto=format&fit=crop&q=80",
) ?>" alt="<?= h(
    $r["name"],
) ?>" loading="lazy"></a><div class="restaurant-content"><div class="row"><a href="restaurant_detail.php?id=<?= $r[
    "id"
] ?>"><h3><?= h(
    $r["name"],
) ?></h3></a><form method="post"><?php csrf_field(); ?><input type="hidden" name="restaurant_id" value="<?= $r[
    "id"
] ?>"><button class="button button-sm button-quiet" name="action" value="favorite_remove">Remove</button></form></div><p><?= h(
    $r["cuisine"],
) ?> · <?= (float) $r["score"]
     ? "★ " . number_format((float) $r["score"], 1)
     : "No reviews yet" ?></p></div></article><?php endwhile; ?></div><?php endif; ?></section><section class="section"><div class="section-heading"><div><p class="eyebrow">YOUR FEEDBACK</p><h2>Reviews you have written</h2></div></div><?php if (
    !$reviews->num_rows
): ?><div class="empty-state"><strong>No reviews yet</strong>After an order is delivered, you can rate your meal and restaurant.</div><?php else: ?><div class="stack"><?php while (
    $review = $reviews->fetch_assoc()
): ?><article class="panel"><div class="row"><strong><?= h(
    $review["restaurant"],
) ?></strong><span class="badge">★ <?= $review[
    "rating"
] ?> / 5</span></div><p><?= h(
     $review["comment"] ?: "No written comment.",
 ) ?></p></article><?php endwhile; ?></div><?php endif; ?></section><?php endif;
page_end();

