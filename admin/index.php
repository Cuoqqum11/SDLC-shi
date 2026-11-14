<?php
require "../db.php";
require_role(["Admin"]);
$users = (int) $conn->query("SELECT COUNT(*) n FROM users")->fetch_assoc()["n"];
$customers = (int) $conn
    ->query(
        "SELECT COUNT(*) n FROM users u JOIN roles r ON r.RoleID=u.RoleID WHERE r.RoleName='Customer' AND u.IsActive=1",
    )
    ->fetch_assoc()["n"];
$drivers = (int) $conn
    ->query(
        "SELECT COUNT(*) n FROM users u JOIN roles r ON r.RoleID=u.RoleID WHERE r.RoleName='Driver' AND u.IsActive=1",
    )
    ->fetch_assoc()["n"];
$restaurants = (int) $conn
    ->query(
        "SELECT COUNT(*) n FROM restaurants WHERE approval_status='Approved'",
    )
    ->fetch_assoc()["n"];
$orders = (int) $conn->query("SELECT COUNT(*) n FROM orders")->fetch_assoc()[
    "n"
];
$pending = (int) $conn
    ->query("SELECT COUNT(*) n FROM orders WHERE status='Placed'")
    ->fetch_assoc()["n"];
$cancelled = (int) $conn
    ->query("SELECT COUNT(*) n FROM orders WHERE status='Cancelled'")
    ->fetch_assoc()["n"];
$completed = (int) $conn
    ->query("SELECT COUNT(*) n FROM orders WHERE status='Delivered'")
    ->fetch_assoc()["n"];
$revenue = (float) $conn
    ->query(
        "SELECT COALESCE(SUM(total),0) n FROM orders WHERE status='Delivered'",
    )
    ->fetch_assoc()["n"];
$applicants = (int) $conn
    ->query(
        "SELECT COUNT(*) n FROM restaurant_applications WHERE status='Pending'",
    )
    ->fetch_assoc()["n"];
$chart = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date("Y-m-d", strtotime("-" . $i . " days"));
    $chart[$day] = ["orders" => 0, "revenue" => 0];
}
$res = $conn->query(
    "SELECT DATE(created_at) day,COUNT(*) orders,COALESCE(SUM(CASE WHEN status='Delivered' THEN total ELSE 0 END),0) revenue FROM orders WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY DATE(created_at)",
);
while ($row = $res->fetch_assoc()) {
    if (isset($chart[$row["day"]])) {
        $chart[$row["day"]] = [
            "orders" => (int) $row["orders"],
            "revenue" => (float) $row["revenue"],
        ];
    }
}
$maxOrders = max(1, max(array_column($chart, "orders")));
$maxRevenue = max(1, max(array_column($chart, "revenue")));
$statusRows = $conn->query(
    "SELECT status,COUNT(*) total FROM orders GROUP BY status",
);
$popular = $conn->query(
    "SELECT oi.item_name,SUM(oi.quantity) quantity,SUM(oi.quantity*oi.unit_price) gross FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status<>'Cancelled' GROUP BY oi.item_name ORDER BY quantity DESC LIMIT 6",
);
$recent = $conn->query(
    "SELECT o.id,o.status,o.total,o.created_at,r.name restaurant,u.UserName customer FROM orders o JOIN restaurants r ON r.id=o.restaurant_id JOIN users u ON u.UserID=o.customer_id ORDER BY o.created_at DESC LIMIT 8",
);
$activity = $conn->query(
    "SELECT l.action,l.entity_type,l.entity_id,l.created_at,u.UserName FROM admin_activity_log l JOIN users u ON u.UserID=l.admin_id ORDER BY l.created_at DESC LIMIT 5",
);
$msg = take_flash();
page_start("Platform overview", "admin");
?>
<section class="dashboard-hero"><div class="row"><div><p class="eyebrow">MEALDASH AT A GLANCE</p><h2>Welcome to platform management.</h2><p>Live totals from your current orders, accounts and restaurant applications.</p></div><a class="button" href="restaurants.php"><?= $applicants ?> restaurant applications</a></div></section><?php if (
     $msg
 ): ?><div class="flash flash-success" role="status"><?= h(
    $msg["message"],
) ?></div><?php endif; ?>
<div class="stats-grid"><div class="stat-card"><span class="stat-icon">◎</span><div class="stat-label">Active customers</div><div class="stat-value"><?= $customers ?></div><span class="small muted"><?= $users ?> accounts total</span></div><div class="stat-card"><span class="stat-icon">⌂</span><div class="stat-label">Approved restaurants</div><div class="stat-value"><?= $restaurants ?></div></div><div class="stat-card"><span class="stat-icon">↗</span><div class="stat-label">Active couriers</div><div class="stat-value"><?= $drivers ?></div></div><div class="stat-card"><span class="stat-icon">▤</span><div class="stat-label">Total orders</div><div class="stat-value"><?= $orders ?></div></div><div class="stat-card"><span class="stat-icon">◷</span><div class="stat-label">Waiting restaurant</div><div class="stat-value"><?= $pending ?></div></div><div class="stat-card"><span class="stat-icon">✓</span><div class="stat-label">Delivered</div><div class="stat-value"><?= $completed ?></div></div><div class="stat-card"><span class="stat-icon">×</span><div class="stat-label">Cancelled</div><div class="stat-value"><?= $cancelled ?></div></div><div class="stat-card"><span class="stat-icon">₫</span><div class="stat-label">Delivered revenue</div><div class="stat-value"><?= money(
    $revenue,
) ?></div><span class="small muted">COD/platform order totals</span></div></div>
<section class="section two-col"><article class="panel"><div class="section-heading"><div><p class="eyebrow">LAST 7 DAYS</p><h2>Orders by day</h2></div></div><?php foreach (
    $chart
    as $day => $row
): ?><div class="chart-row"><span class="small"><?= date(
    "D, M j",
    strtotime($day),
) ?></span><div class="chart-track"><div class="chart-bar" style="width:<?= max(
    $row["orders"] ? 4 : 0,
    round(($row["orders"] / $maxOrders) * 100),
) ?>%"></div></div><strong><?= $row[
    "orders"
] ?></strong></div><?php endforeach; ?></article><article class="panel"><div class="section-heading"><div><p class="eyebrow">LAST 7 DAYS</p><h2>Delivered revenue</h2></div></div><?php foreach (
    $chart
    as $day => $row
): ?><div class="chart-row"><span class="small"><?= date(
    "D, M j",
    strtotime($day),
) ?></span><div class="chart-track"><div class="chart-bar chart-bar-green" style="width:<?= max(
    $row["revenue"] ? 4 : 0,
    round(($row["revenue"] / $maxRevenue) * 100),
) ?>%"></div></div><strong class="small"><?= number_format(
    $row["revenue"] / 1000,
    0,
) ?>k</strong></div><?php endforeach; ?></article></section>
<section class="section two-col"><article class="panel"><div class="section-heading"><div><p class="eyebrow">ORDER MIX</p><h2>Status distribution</h2></div></div><?php if (
    !$statusRows->num_rows
): ?><p class="muted">No order data yet.</p><?php else: ?><div class="stack"><?php while (
    $st = $statusRows->fetch_assoc()
): ?><div class="row"><span><?= h(
    $st["status"],
) ?></span><span class="badge"><?= $st[
    "total"
] ?></span></div><?php endwhile; ?></div><?php endif; ?></article><article class="panel"><div class="section-heading"><div><p class="eyebrow">REAL ORDER QUANTITIES</p><h2>Popular dishes</h2></div></div><?php if (
    !$popular->num_rows
): ?><p class="muted">Dish ranking appears after orders are placed.</p><?php else:while (
        $dish = $popular->fetch_assoc()
    ): ?><div class="row" style="padding:9px 0;border-bottom:1px solid var(--line)"><strong><?= h(
    $dish["item_name"],
) ?></strong><span class="badge"><?= $dish[
    "quantity"
] ?> sold</span></div><?php endwhile;endif; ?></article></section>
<section class="section panel"><div class="section-heading"><div><p class="eyebrow">RECENT PLATFORM ACTIVITY</p><h2>Latest orders</h2></div><a class="button button-sm" href="orders.php">Manage orders</a></div><?php if (
    !$recent->num_rows
): ?><div class="empty-state"><strong>No orders yet</strong>Recent customer activity will appear here.</div><?php else: ?><div class="table-scroll"><table><thead><tr><th>Order</th><th>Customer</th><th>Restaurant</th><th>Status</th><th>Total</th><th>Placed</th></tr></thead><tbody><?php while (
    $o = $recent->fetch_assoc()
): ?><tr><td><a href="../order_detail.php?id=<?= $o["id"] ?>">#<?= $o[
    "id"
] ?></a></td><td><?= h($o["customer"]) ?></td><td><?= h(
    $o["restaurant"],
) ?></td><td><span class="badge"><?= h(
    $o["status"],
) ?></span></td><td><?= money($o["total"]) ?></td><td><?= h(
    $o["created_at"],
) ?></td></tr><?php endwhile; ?></tbody></table></div><?php endif; ?></section>
<section class="panel"><div class="section-heading"><div><p class="eyebrow">ADMIN ACTIVITY LOG</p><h2>Recent changes</h2></div></div><?php if (
    !$activity->num_rows
): ?><p class="muted">Administrative actions will be recorded here.</p><?php else:while (
        $log = $activity->fetch_assoc()
    ): ?><p class="notification-item"><strong><?= h(
    $log["action"],
) ?></strong> · <?= h($log["entity_type"]) ?> #<?= h(
     $log["entity_id"],
 ) ?> · <?= h($log["UserName"]) ?> · <?= h(
     $log["created_at"],
 ) ?></p><?php endwhile;endif; ?></section><?php page_end();
