<?php
require dirname(__DIR__) . "/includes/db.php";
require_role(["Customer"]);
$me = user();
$error = "";
$fields = [
    "name" => "",
    "cuisine" => "",
    "description" => "",
    "address" => "",
    "phone" => "",
];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    foreach ($fields as $key => $value) {
        $fields[$key] = trim($_POST[$key] ?? "");
    }
    if (
        strlen($fields["name"]) < 2 ||
        strlen($fields["cuisine"]) < 2 ||
        strlen($fields["address"]) < 8 ||
        strlen($fields["phone"]) < 7
    ) {
        $error =
            "Complete the restaurant name, cuisine, pickup address and contact phone.";
    } else {
        $s = $conn->prepare(
            "SELECT id FROM restaurant_applications WHERE user_id=? AND status='Pending'",
        );
        $s->bind_param("i", $me["id"]);
        $s->execute();
        if ($s->get_result()->num_rows) {
            $error = "You already have an application waiting for review.";
        } else {
            $s = $conn->prepare(
                "INSERT INTO restaurant_applications(user_id,name,cuisine,description,address,phone,status) VALUES(?,?,?,?,?,?,'Pending')",
            );
            $s->bind_param(
                "isssss",
                $me["id"],
                $fields["name"],
                $fields["cuisine"],
                $fields["description"],
                $fields["address"],
                $fields["phone"],
            );
            $s->execute();
            flash("Application submitted. The MealDash team will review it.");
            header("Location: account.php");
            exit();
        }
    }
}
$s = $conn->prepare(
    "SELECT status,created_at FROM restaurant_applications WHERE user_id=? ORDER BY created_at DESC LIMIT 1",
);
$s->bind_param("i", $me["id"]);
$s->execute();
$application = $s->get_result()->fetch_assoc();
page_start("Restaurant partner application");
?><section class="panel form-panel"><p class="eyebrow">PARTNER WITH MEALDASH</p><h2>Tell us about your restaurant</h2><p class="muted">An administrator reviews every restaurant before it appears in customer search.</p><?php
if ($application): ?><div class="flash <?= $application["status"] === "Rejected"
    ? "flash-warning"
    : "flash-success" ?>" role="status">Latest application: <?= h(
    $application["status"],
) ?> · <?= h($application["created_at"]) ?></div><?php endif;
if ($error): ?><div class="flash flash-error" role="alert"><?= h(
    $error,
) ?></div><?php endif;
if (
    $application &&
    $application["status"] === "Pending"
): ?><p>We'll notify you when the review is complete.</p><?php else: ?><form method="post"><?php csrf_field(); ?><div class="field-wrap"><label for="name">Restaurant name</label><input class="input" id="name" name="name" value="<?= h(
    $fields["name"],
) ?>" required maxlength="140"></div><div class="field-wrap"><label for="cuisine">Cuisine type</label><input class="input" id="cuisine" name="cuisine" value="<?= h(
    $fields["cuisine"],
) ?>" required maxlength="100"></div><div class="field-wrap"><label for="description">About your restaurant</label><textarea class="textarea" id="description" name="description" maxlength="2000"><?= h(
    $fields["description"],
) ?></textarea></div><div class="field-wrap"><label for="address">Pickup address</label><textarea class="textarea" id="address" name="address" required minlength="8" maxlength="255"><?= h(
    $fields["address"],
) ?></textarea></div><div class="field-wrap"><label for="phone">Restaurant contact number</label><input class="input" id="phone" name="phone" type="tel" required maxlength="30" value="<?= h(
    $fields["phone"],
) ?>"></div><button class="button">Send for review</button></form><?php endif;
?></section><?php page_end();
