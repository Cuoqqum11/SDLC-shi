<?php
require "../db.php";
require_role(["Admin"]);
$type = $_GET["type"] ?? "orders";
if (
    !in_array(
        $type,
        ["orders", "revenue", "restaurants", "popular_items", "users"],
        true,
    )
) {
    $type = "orders";
}
$from = $_GET["from"] ?? "";
$to = $_GET["to"] ?? "";
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : "";
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : "";
$dateClause = "";
$params = [];
if ($from) {
    $dateClause .= " AND DATE(o.created_at)>=?";
    $params[] = $from;
}
if ($to) {
    $dateClause .= " AND DATE(o.created_at)<=?";
    $params[] = $to;
}
$queries = [
    "orders" => [
        "Order ID",
        "Customer",
        "Restaurant",
        "Status",
        "Subtotal",
        "Delivery fee",
        "Total",
        "Payment method",
        "Payment status",
        "Address",
        "Placed",
    ],
    "revenue" => ["Date", "Delivered orders", "Delivered revenue"],
    "restaurants" => [
        "Restaurant",
        "Owner",
        "Orders",
        "Delivered orders",
        "Delivered revenue",
    ],
    "popular_items" => [
        "Dish",
        "Restaurant",
        "Quantity ordered",
        "Gross order value",
    ],
    "users" => ["Username", "Full name", "Email", "Role", "Active", "Joined"],
];
$filename = "mealdash-" . $type . ".csv";
header("Content-Type: text/csv; charset=utf-8");
header('Content-Disposition: attachment; filename="' . $filename . '"');
header("X-Content-Type-Options: nosniff");
$out = fopen("php://output", "w");
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $queries[$type]);
if ($type === "orders") {
    $sql =
        "SELECT o.id,u.UserName,r.name,o.status,o.subtotal,o.delivery_fee,o.total,o.payment_method,o.payment_status,o.delivery_address,o.created_at FROM orders o JOIN users u ON u.UserID=o.customer_id JOIN restaurants r ON r.id=o.restaurant_id WHERE 1=1" .
        $dateClause .
        " ORDER BY o.created_at DESC";
    $s = $conn->prepare($sql);
    execute_params($s, $params);
    $rows = $s->get_result();
    while ($row = $rows->fetch_row()) {
        fputcsv($out, array_map("csv_safe", $row));
    }
} elseif ($type === "revenue") {
    $sql =
        "SELECT DATE(o.created_at),COUNT(*),COALESCE(SUM(o.total),0) FROM orders o WHERE o.status='Delivered'" .
        $dateClause .
        " GROUP BY DATE(o.created_at) ORDER BY DATE(o.created_at)";
    $s = $conn->prepare($sql);
    execute_params($s, $params);
    $rows = $s->get_result();
    while ($row = $rows->fetch_row()) {
        fputcsv($out, array_map("csv_safe", $row));
    }
} elseif ($type === "restaurants") {
    $sql =
        "SELECT r.name,COALESCE(u.UserName,''),COUNT(o.id),SUM(o.status='Delivered'),COALESCE(SUM(CASE WHEN o.status='Delivered' THEN o.total ELSE 0 END),0) FROM restaurants r LEFT JOIN users u ON u.UserID=r.owner_user_id LEFT JOIN orders o ON o.restaurant_id=r.id" .
        ($dateClause ? " AND " . substr($dateClause, 5) : "") .
        " GROUP BY r.id ORDER BY r.name";
    $s = $conn->prepare($sql);
    execute_params($s, $params);
    $rows = $s->get_result();
    while ($row = $rows->fetch_row()) {
        fputcsv($out, array_map("csv_safe", $row));
    }
} elseif ($type === "popular_items") {
    $sql =
        "SELECT oi.item_name,r.name,SUM(oi.quantity),SUM(oi.quantity*oi.unit_price) FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN restaurants r ON r.id=o.restaurant_id WHERE o.status<>'Cancelled'" .
        $dateClause .
        " GROUP BY oi.item_name,r.name ORDER BY SUM(oi.quantity) DESC";
    $s = $conn->prepare($sql);
    execute_params($s, $params);
    $rows = $s->get_result();
    while ($row = $rows->fetch_row()) {
        fputcsv($out, array_map("csv_safe", $row));
    }
} else {
    $rows = $conn->query(
        "SELECT u.UserName,u.FullName,u.Email,r.RoleName,u.IsActive,u.CreatedAt FROM users u JOIN roles r ON r.RoleID=u.RoleID ORDER BY u.UserID",
    );
    while ($row = $rows->fetch_row()) {
        fputcsv($out, array_map("csv_safe", $row));
    }
}
fclose($out);
function csv_safe(mixed $value): mixed
{
    if (is_string($value) && preg_match('/^[\x00-\x20]*[=+@\-]/u', $value)) {
        return "'" . $value;
    }
    return $value;
}
