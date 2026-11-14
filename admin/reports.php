<?php
require "../db.php";
require_role(["Admin"]);
page_start("Reports & exports", "admin");
?><section class="section-heading"><div><p class="eyebrow">DATA FOR DECISIONS</p><h2>Reports & exports</h2><p>Download current platform records as spreadsheet-safe CSV files.</p></div></section><div class="food-grid"><?php
$types = [
    "orders" => [
        "Orders by date",
        "Order status, customer, restaurant, payment and totals.",
    ],
    "revenue" => [
        "Revenue by date",
        "Daily delivered order count and revenue.",
    ],
    "restaurants" => [
        "Restaurant performance",
        "Order volume, delivered orders and delivered revenue.",
    ],
    "popular_items" => [
        "Popular dishes",
        "Ordered quantities and gross order value.",
    ],
    "users" => [
        "User statistics",
        "Account roles, active status and registration dates.",
    ],
];
foreach (
    $types
    as $key => [$label, $description]
): ?><section class="panel"><p class="eyebrow">CSV REPORT</p><h2><?= h(
    $label,
) ?></h2><p class="muted"><?= h(
    $description,
) ?></p><form method="get" action="export.php"><input type="hidden" name="type" value="<?= h(
    $key,
) ?>"><div class="field-wrap"><label for="from-<?= $key ?>">From (optional)</label><input class="input" type="date" name="from" id="from-<?= $key ?>"></div><div class="field-wrap"><label for="to-<?= $key ?>">To (optional)</label><input class="input" type="date" name="to" id="to-<?= $key ?>"></div><button class="button button-sm">Download CSV</button></form></section><?php endforeach;
?></div><?php page_end();
