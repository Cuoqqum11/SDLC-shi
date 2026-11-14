<?php
require dirname(__DIR__) . "/includes/db.php";
require_role(["Customer", "Restaurant", "Driver", "Admin"]);
$me = user();
$id = max(0, (int) ($_GET["id"] ?? 0));
$role = $me["role"];
$where = match ($role) {
    "Customer" => "o.customer_id=?",
    "Restaurant" => "r.owner_user_id=?",
    "Driver" => "o.driver_id=?",
    default => "1=1",
};
$sql = "SELECT o.*,r.name restaurant,r.address restaurant_address,r.phone restaurant_phone,u.FullName driver_name,u.PhoneNumber driver_phone FROM orders o JOIN restaurants r ON r.id=o.restaurant_id LEFT JOIN users u ON u.UserID=o.driver_id WHERE o.id=? AND $where";
$s = $conn->prepare($sql);
if ($role === "Admin") {
    $s->bind_param("i", $id);
} else {
    $s->bind_param("ii", $id, $me["id"]);
}
$s->execute();
$order = $s->get_result()->fetch_assoc();
if (!$order) {

    http_response_code(404);
    page_start("Order not found");
    ?><div class="empty-state"><strong>We couldn't find that order</strong>It may not exist or may belong to another account.<p><a class="button" href="orders.php">Back to orders</a></p></div><?php
page_end();
exit();

}
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cancel_order"])) {
    check_csrf();
    require_role(["Customer"]);
    $reason = trim($_POST["reason"] ?? "");
    if (strlen($reason) < 3) {
        flash("Please tell us why you need to cancel.", "warning");
    } elseif (
        transition_order(
            $conn,
            $id,
            $me["id"],
            "Customer",
            "Cancelled",
            $reason,
        )
    ) {
        flash("Your order was cancelled and its stock was returned.");
    } else {
        flash("This order can no longer be cancelled.", "error");
    }
    header("Location: order_detail.php?id=" . $id);
    exit();
}
$s = $conn->prepare(
    "SELECT oi.*,m.image_url FROM order_items oi LEFT JOIN menu_items m ON m.id=oi.menu_item_id WHERE oi.order_id=? ORDER BY oi.id",
);
$s->bind_param("i", $id);
$s->execute();
$items = $s->get_result();
$s = $conn->prepare(
    "SELECT status,created_at FROM order_status_history WHERE order_id=? ORDER BY id",
);
$s->bind_param("i", $id);
$s->execute();
$history = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s = $conn->prepare(
    "SELECT latitude,longitude,recorded_at FROM delivery_locations WHERE order_id=? AND driver_id=? ORDER BY recorded_at DESC LIMIT 1",
);
$s->bind_param("ii", $id, $order["driver_id"]);
$s->execute();
$locationResult = $s->get_result();
$location = $order["driver_id"] ? $locationResult->fetch_assoc() : null;
$current = $order["status"];
$steps = [
    "Placed" => "Waiting restaurant",
    "Accepted" => "Confirmed",
    "Preparing" => "Preparing",
    "Ready for pickup" => "Ready for pickup",
    "Picked up" => "Delivering",
    "On the way" => "Delivering",
    "Delivered" => "Delivered",
];
$stepOrder = [
    "Waiting restaurant",
    "Confirmed",
    "Preparing",
    "Ready for pickup",
    "Delivering",
    "Delivered",
];
$currentStep = $steps[$current] ?? "Waiting restaurant";
$position = array_search($currentStep, $stepOrder, true);
page_start("Order #" . $id);
?>
<div class="section-heading"><div><p class="eyebrow">ORDER DETAILS</p><h2>Order #<?= $id ?></h2></div><span class="badge <?= $current ===
"Delivered"
    ? "badge-good"
    : ($current === "Cancelled"
        ? "badge-bad"
        : "badge-warn") ?>"><?= h($current) ?></span></div>
<?php if (
    $current === "Cancelled"
): ?><div class="flash flash-error" role="status">This order was cancelled. <?= h(
    $order["cancellation_reason"] ?? "",
) ?></div><?php else: ?><section class="panel"><h2>Order progress</h2><div class="status-stepper" aria-label="Order progress"><?php foreach (
    $stepOrder
    as $index => $label
): ?><div class="status-step <?= $position !== false && $index < $position
    ? "done"
    : ($index === $position
        ? "current"
        : "") ?>"><span><?= $position !== false && $index < $position
    ? "✓ "
    : "" ?></span><?= h(
    $label,
) ?></div><?php endforeach; ?></div><div class="table-scroll"><table><thead><tr><th>Status</th><th>Updated</th></tr></thead><tbody><?php foreach (
    $history
    as $step
): ?><tr><td><?= h($steps[$step["status"]] ?? $step["status"]) ?></td><td><?= h(
    $step["created_at"],
) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<div class="two-col section"><section class="panel"><h2>Food in this order</h2><?php while (
    $item = $items->fetch_assoc()
): ?><div class="row" style="padding:12px 0;border-bottom:1px solid var(--line)"><div style="display:flex;gap:12px;align-items:center"><?php if (
    $item["image_url"]
): ?><img src="<?= h($item["image_url"]) ?>" alt="<?= h(
    $item["item_name"],
) ?>" width="64" height="58" style="object-fit:cover;border-radius:10px"><?php endif; ?><div><strong><?= h(
    $item["item_name"],
) ?></strong><div class="muted small">Qty <?= $item["quantity"] ?> · <?= money(
     $item["unit_price"],
 ) ?> each</div></div></div><strong><?= money(
     (float) $item["unit_price"] * $item["quantity"],
 ) ?></strong></div><?php endwhile; ?><div class="row" style="margin-top:14px"><span>Subtotal</span><strong><?= money(
    $order["subtotal"],
) ?></strong></div><div class="row"><span>Delivery</span><strong><?= money(
    $order["delivery_fee"],
) ?></strong></div><div class="row"><strong>Total</strong><strong><?= money(
    $order["total"],
) ?></strong></div><hr class="hr"><p><strong>Payment:</strong> <?= h(
    $order["payment_method"],
) ?> · <?= h($order["payment_status"]) ?></p></section>
<section class="panel"><h2>Delivery information</h2><p><strong><?= h(
    $order["recipient_name"] ?: "Recipient",
) ?></strong><br><?= h($order["recipient_phone"] ?? "") ?></p><p><?= h(
    $order["delivery_address"],
) ?></p><?php if ($order["customer_note"]): ?><p class="muted">Note: <?= h(
    $order["customer_note"],
) ?></p><?php endif; ?><hr class="hr"><h3><?= h(
    $order["restaurant"],
) ?></h3><p class="muted"><?= h($order["restaurant_address"]) ?></p><?php if (
    $order["driver_id"]
): ?><hr class="hr"><h3>Your courier</h3><p><?= h(
    $order["driver_name"] ?: "MealDash courier",
) .
    ($order["driver_phone"]
        ? " · " . h($order["driver_phone"])
        : "") ?></p><?php if (
    $location
): ?><p class="small muted">Last location update: <?= h(
    $location["recorded_at"],
) ?></p><a class="button button-sm" target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= urlencode(
    $location["latitude"] . "," . $location["longitude"],
) ?>">View latest courier location</a><?php else: ?><p class="muted">No courier location has been shared yet.</p><?php endif;else: ?><p class="muted">A courier has not been assigned yet. Location tracking will appear when available.</p><?php endif; ?></section></div>
<?php
if (
    $role === "Customer" &&
    $current === "Placed"
): ?><section class="panel"><h2>Need to cancel?</h2><p class="muted">You can cancel before the restaurant accepts your order.</p><form method="post"><?php csrf_field(); ?><div class="field-wrap"><label for="reason">Reason</label><input class="input" id="reason" name="reason" minlength="3" maxlength="255" required></div><button class="button button-danger" data-confirm="Cancel this order? Stock will be returned to the restaurant." name="cancel_order">Cancel order</button></form></section><?php endif;
if (
    $current === "Delivered" &&
    $role === "Customer"
): ?><p><a class="button" href="restaurant_detail.php?id=<?= $order[
    "restaurant_id"
] ?>#reviews">Leave a review</a></p><?php endif;
page_end();

