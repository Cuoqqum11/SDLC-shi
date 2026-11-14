<?php
require "../db.php";
require_role(["Admin"]);
$me = user();
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["override"])) {
    check_csrf();
    $id = (int) ($_POST["order_id"] ?? 0);
    $status = $_POST["status"] ?? "";
    $reason = trim($_POST["reason"] ?? "");
    if (strlen($reason) < 5) {
        $error =
            "Record a reason of at least 5 characters for every admin override.";
    } elseif (
        transition_order($conn, $id, $me["id"], "Admin", $status, $reason)
    ) {
        flash("Admin override recorded for order #" . $id . ".");
    } else {
        $error = "This order is terminal or the requested status is not valid.";
    }
}
$q = trim($_GET["q"] ?? "");
$status = $_GET["status"] ?? "";
$from = $_GET["from"] ?? "";
$to = $_GET["to"] ?? "";
$where = [];
$params = [];
if ($q !== "") {
    if (ctype_digit($q)) {
        $where[] = "o.id=?";
        $params[] = (int) $q;
    } else {
        $where[] = "(u.UserName LIKE ? OR r.name LIKE ?)";
        $like = "%" . $q . "%";
        array_push($params, $like, $like);
    }
}
$statuses = [
    "Placed",
    "Accepted",
    "Preparing",
    "Ready for pickup",
    "Picked up",
    "On the way",
    "Delivered",
    "Cancelled",
];
if (in_array($status, $statuses, true)) {
    $where[] = "o.status=?";
    $params[] = $status;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = "DATE(o.created_at)>=?";
    $params[] = $from;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = "DATE(o.created_at)<=?";
    $params[] = $to;
}
$clause = $where ? "WHERE " . implode(" AND ", $where) : "";
$count = $conn->prepare(
    "SELECT COUNT(*) n FROM orders o JOIN users u ON u.UserID=o.customer_id JOIN restaurants r ON r.id=o.restaurant_id $clause",
);
execute_params($count, $params);
$total = (int) $count->get_result()->fetch_assoc()["n"];
$page = max(1, (int) ($_GET["page"] ?? 1));
$limit = 25;
$params[] = $limit;
$params[] = ($page - 1) * $limit;
$s = $conn->prepare(
    "SELECT o.*,u.UserName customer,r.name restaurant,dr.FullName driver FROM orders o JOIN users u ON u.UserID=o.customer_id JOIN restaurants r ON r.id=o.restaurant_id LEFT JOIN users dr ON dr.UserID=o.driver_id $clause ORDER BY o.created_at DESC LIMIT ? OFFSET ?",
);
execute_params($s, $params);
$orders = $s->get_result();
$msg = take_flash();
page_start("Order monitoring", "admin");
?>
<section class="section-heading"><div><p class="eyebrow">PLATFORM OPERATIONS</p><h2>All orders</h2><p>Search by order number, customer or restaurant. Overrides require a reason and are recorded.</p></div><a class="button button-quiet" href="export.php?from=<?= urlencode(
    $from,
) ?>&to=<?= urlencode($to) ?>">Export CSV</a></section><?php
if ($msg): ?><div class="flash flash-success" role="status"><?= h(
    $msg["message"],
) ?></div><?php endif;
if ($error): ?><div class="flash flash-error" role="alert"><?= h(
    $error,
) ?></div><?php endif;
?><form method="get" class="panel filter-bar"><label for="q">Search</label><input class="input" name="q" id="q" value="<?= h(
    $q,
) ?>" placeholder="Order #, customer or restaurant"><label for="status">Status</label><select class="select" id="status" name="status"><option value="">All statuses</option><?php foreach (
    $statuses
    as $v
): ?><option <?= $status === $v ? "selected" : "" ?>><?= h(
    $v,
) ?></option><?php endforeach; ?></select><label for="from">From</label><input class="input" type="date" name="from" id="from" value="<?= h(
    $from,
) ?>"><label for="to">To</label><input class="input" type="date" name="to" id="to" value="<?= h(
    $to,
) ?>"><button class="button button-sm">Filter</button></form><p class="muted">Showing <?= $orders->num_rows ?> of <?= $total ?> orders.</p>
<?php
if (
    !$orders->num_rows
): ?><div class="empty-state"><strong>No orders match your filters</strong>Try a different order number or date range.</div><?php else: ?><div class="stack"><?php while (
    $o = $orders->fetch_assoc()
): ?><article class="panel"><div class="row"><div><p class="eyebrow">ORDER #<?= $o[
    "id"
] ?> · <?= h($o["created_at"]) ?></p><h3><?= h($o["customer"]) ?> → <?= h(
     $o["restaurant"],
 ) ?></h3><p class="muted"><?= h(
    $o["delivery_address"],
) ?></p><p class="small">Courier: <?= h(
    $o["driver"] ?: "Not assigned",
) ?> · <?= h($o["payment_method"]) ?> / <?= h(
     $o["payment_status"],
 ) ?></p></div><div><span class="badge <?= $o["status"] === "Delivered"
    ? "badge-good"
    : ($o["status"] === "Cancelled"
        ? "badge-bad"
        : "badge-warn") ?>"><?= h($o["status"]) ?></span><p><strong><?= money(
    $o["total"],
) ?></strong></p></div></div><div class="row"><a class="button button-sm button-quiet" href="../order_detail.php?id=<?= $o[
    "id"
] ?>">Order details</a><?php if (
    !in_array($o["status"], ["Delivered", "Cancelled"], true)
): ?><form method="post" class="row"><?php csrf_field(); ?><input type="hidden" name="order_id" value="<?= $o[
    "id"
] ?>"><select class="select" name="status" aria-label="Override status for order <?= $o[
    "id"
] ?>"><?php foreach (
    array_diff($statuses, ["Cancelled"])
    as $v
): ?><option value="<?= h($v) ?>" <?= $v === $o["status"]
    ? "selected"
    : "" ?>><?= h(
    $v,
) ?></option><?php endforeach; ?><option value="Cancelled">Cancelled</option></select><input class="input" name="reason" placeholder="Override reason" minlength="5" required><button class="button button-sm button-dark" name="override" data-confirm="Record this admin order override?">Apply</button></form><?php endif; ?></div></article><?php endwhile; ?></div><?php endif;
if (
    $total > $limit
): ?><nav class="pagination" aria-label="Order pages"><?php if (
    $page > 1
): ?><a href="?q=<?= urlencode($q) ?>&status=<?= urlencode(
    $status,
) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&page=<?= $page -
    1 ?>">Previous</a><?php endif; ?><span class="current">Page <?= $page ?></span><?php if (
    $page * $limit <
    $total
): ?><a href="?q=<?= urlencode($q) ?>&status=<?= urlencode(
    $status,
) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&page=<?= $page +
    1 ?>">Next</a><?php endif; ?></nav><?php endif;
page_end();

