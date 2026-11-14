<?php
require dirname(__DIR__) . "/includes/db.php";
require_role(["Customer", "Restaurant", "Driver", "Admin"]);
$me = user();
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    if (isset($_POST["mark_all"])) {
        $s = $conn->prepare(
            "UPDATE notifications SET is_read=1 WHERE user_id=?",
        );
        $s->bind_param("i", $me["id"]);
        $s->execute();
    } elseif (isset($_POST["mark_read"])) {
        $id = (int) $_POST["notification_id"];
        $s = $conn->prepare(
            "UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?",
        );
        $s->bind_param("ii", $id, $me["id"]);
        $s->execute();
    }
    header("Location: notifications.php");
    exit();
}
$s = $conn->prepare(
    "SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 60",
);
$s->bind_param("i", $me["id"]);
$s->execute();
$notifications = $s->get_result();
page_start(
    "Notifications",
    match ($me["role"]) {
        "Restaurant" => "restaurant",
        "Driver" => "courier",
        "Admin" => "admin",
        default => "storefront",
    },
);
?><div class="section-heading"><div><p class="eyebrow">YOUR UPDATES</p><h2>Notifications</h2></div><form method="post"><?php csrf_field(); ?><button class="button button-quiet" name="mark_all" value="1">Mark all as read</button></form></div><?php
if (
    !$notifications->num_rows
): ?><div class="empty-state"><strong>You're all caught up</strong>Important order and account updates will show up here.</div><?php else: ?><div class="stack"><?php while (
    $n = $notifications->fetch_assoc()
): ?><article class="panel notification-item" style="opacity:<?= $n["is_read"]
    ? ".72"
    : "1" ?>"><div class="row"><div><strong><?= h(
    $n["title"],
) ?></strong><p><?= h($n["message"]) ?></p><span class="muted small"><?= h(
    $n["created_at"],
) ?></span></div><?php if (
    !$n["is_read"]
): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="notification_id" value="<?= $n[
    "id"
] ?>"><button class="button button-sm button-quiet" name="mark_read">Mark read</button></form><?php endif; ?></div><?php if (
    $n["link"]
): ?><a href="<?= h(
    $n["link"],
) ?>">Open update →</a><?php endif; ?></article><?php endwhile; ?></div><?php endif;
page_end();

