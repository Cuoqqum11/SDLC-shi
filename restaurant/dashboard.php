<?php
require dirname(__DIR__) . "/includes/db.php";
require_role(["Restaurant"]);
$me = user();
$s = $conn->prepare(
    "SELECT * FROM restaurants WHERE owner_user_id=? ORDER BY id LIMIT 1",
);
$s->bind_param("i", $me["id"]);
$s->execute();
$restaurant = $s->get_result()->fetch_assoc();
if (!$restaurant) {

    http_response_code(403);
    page_start("Restaurant profile missing", "restaurant");
    ?><div class="empty-state"><strong>No restaurant is linked to this account</strong>Ask an administrator to review your onboarding application.</div><?php
page_end();
exit();

}
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $action = $_POST["action"] ?? "";
    try {
        if ($action === "add_item") {
            $name = trim($_POST["name"] ?? "");
            $desc = trim($_POST["description"] ?? "");
            $price = (float) ($_POST["price"] ?? 0);
            $stock = (int) ($_POST["stock"] ?? 0);
            $cat = (int) ($_POST["category_id"] ?? 0);
            if (strlen($name) < 2 || $price <= 0 || $stock < 0) {
                throw new RuntimeException(
                    "Enter a dish name, positive price and non-negative stock.",
                );
            }
            $image = store_food_image($_FILES["image"] ?? []);
            $s = $conn->prepare(
                "INSERT INTO menu_items(restaurant_id,category_id,name,description,price,stock,image_url,is_available) VALUES(?,?,?,?,?,?,?,1)",
            );
            $s->bind_param(
                "iissdis",
                $restaurant["id"],
                $cat,
                $name,
                $desc,
                $price,
                $stock,
                $image,
            );
            $s->execute();
            flash("Menu item added.");
            header("Location: restaurant.php#menu");
            exit();
        }
        if ($action === "edit_item") {
            $id = (int) ($_POST["item_id"] ?? 0);
            $name = trim($_POST["name"] ?? "");
            $desc = trim($_POST["description"] ?? "");
            $price = (float) ($_POST["price"] ?? 0);
            $stock = (int) ($_POST["stock"] ?? 0);
            $category = (int) ($_POST["category_id"] ?? 0);
            $available = !empty($_POST["is_available"]) ? 1 : 0;
            if (strlen($name) < 2 || $price <= 0 || $stock < 0) {
                throw new RuntimeException(
                    "Enter a dish name, positive price and non-negative stock.",
                );
            }
            $image = store_food_image($_FILES["image"] ?? []);
            if ($image) {
                $s = $conn->prepare(
                    "UPDATE menu_items m JOIN restaurants r ON r.id=m.restaurant_id SET m.category_id=?,m.name=?,m.description=?,m.price=?,m.stock=?,m.image_url=?,m.is_available=? WHERE m.id=? AND r.owner_user_id=?",
                );
                $s->bind_param(
                    "issdisiii",
                    $category,
                    $name,
                    $desc,
                    $price,
                    $stock,
                    $image,
                    $available,
                    $id,
                    $me["id"],
                );
            } else {
                $s = $conn->prepare(
                    "UPDATE menu_items m JOIN restaurants r ON r.id=m.restaurant_id SET m.category_id=?,m.name=?,m.description=?,m.price=?,m.stock=?,m.is_available=? WHERE m.id=? AND r.owner_user_id=?",
                );
                $s->bind_param(
                    "issdiiii",
                    $category,
                    $name,
                    $desc,
                    $price,
                    $stock,
                    $available,
                    $id,
                    $me["id"],
                );
            }
            $s->execute();
            if (!$s->affected_rows) {
                throw new RuntimeException(
                    "Dish not found or no changes were made.",
                );
            }
            flash("Menu item updated.");
            header("Location: restaurant.php#menu");
            exit();
        }
        if ($action === "settings") {
            $name = trim($_POST["name"] ?? "");
            $description = trim($_POST["description"] ?? "");
            $cuisine = trim($_POST["cuisine"] ?? "");
            $address = trim($_POST["address"] ?? "");
            $phone = trim($_POST["phone"] ?? "");
            $opening = $_POST["opening_time"] ?: null;
            $closing = $_POST["closing_time"] ?: null;
            $open = !empty($_POST["is_open"]) ? 1 : 0;
            if (strlen($name) < 2 || strlen($address) < 5) {
                throw new RuntimeException(
                    "Add a restaurant name and full pickup address.",
                );
            }
            $image = store_food_image($_FILES["cover"] ?? []);
            if ($image) {
                $s = $conn->prepare(
                    "UPDATE restaurants SET name=?,description=?,cuisine=?,address=?,phone=?,opening_time=?,closing_time=?,is_open=?,image_url=? WHERE id=? AND owner_user_id=?",
                );
                $s->bind_param(
                    "sssssssisii",
                    $name,
                    $description,
                    $cuisine,
                    $address,
                    $phone,
                    $opening,
                    $closing,
                    $open,
                    $image,
                    $restaurant["id"],
                    $me["id"],
                );
            } else {
                $s = $conn->prepare(
                    "UPDATE restaurants SET name=?,description=?,cuisine=?,address=?,phone=?,opening_time=?,closing_time=?,is_open=? WHERE id=? AND owner_user_id=?",
                );
                $s->bind_param(
                    "sssssssiii",
                    $name,
                    $description,
                    $cuisine,
                    $address,
                    $phone,
                    $opening,
                    $closing,
                    $open,
                    $restaurant["id"],
                    $me["id"],
                );
            }
            $s->execute();
            flash("Restaurant settings updated.");
            header("Location: restaurant.php#settings");
            exit();
        }
        if ($action === "order_status") {
            $orderId = (int) ($_POST["order_id"] ?? 0);
            $next = $_POST["next_status"] ?? "";
            $reason = trim($_POST["reason"] ?? "");
            if (
                transition_order(
                    $conn,
                    $orderId,
                    $me["id"],
                    "Restaurant",
                    $next,
                    $reason,
                )
            ) {
                flash("Order #" . $orderId . " updated to " . $next . ".");
            } else {
                throw new RuntimeException(
                    "That order status change is not allowed.",
                );
            }
            header("Location: restaurant.php#orders");
            exit();
        }
    } catch (Throwable $e) {
        $error =
            $e instanceof RuntimeException
                ? $e->getMessage()
                : "The request could not be completed.";
        if (!($e instanceof RuntimeException)) {
            error_log("Restaurant operation failed: " . $e->getMessage());
        }
    }
}
$rid = (int) $restaurant["id"];
$s = $conn->prepare(
    "SELECT COUNT(*) total,SUM(status='Placed') pending,SUM(status IN ('Accepted','Preparing')) preparing,SUM(status='Delivered') completed,COALESCE(SUM(CASE WHEN status='Delivered' THEN total ELSE 0 END),0) revenue FROM orders WHERE restaurant_id=?",
);
$s->bind_param("i", $rid);
$s->execute();
$metrics = $s->get_result()->fetch_assoc();
$s = $conn->prepare(
    "SELECT COUNT(*) low_stock FROM menu_items WHERE restaurant_id=? AND is_available=1 AND stock<=5",
);
$s->bind_param("i", $rid);
$s->execute();
$lowStock = (int) $s->get_result()->fetch_assoc()["low_stock"];
$s = $conn->prepare(
    'SELECT m.id,m.name,m.price,SUM(oi.quantity) sold FROM order_items oi JOIN menu_items m ON m.id=oi.menu_item_id JOIN orders o ON o.id=oi.order_id WHERE m.restaurant_id=? AND o.status<>\'Cancelled\' GROUP BY m.id ORDER BY sold DESC LIMIT 5',
);
$s->bind_param("i", $rid);
$s->execute();
$best = $s->get_result();
$s = $conn->prepare(
    "SELECT m.*,c.name category FROM menu_items m LEFT JOIN categories c ON c.id=m.category_id WHERE m.restaurant_id=? ORDER BY m.id DESC",
);
$s->bind_param("i", $rid);
$s->execute();
$items = $s->get_result();
$categories = $conn->query("SELECT id,name FROM categories ORDER BY name");
$s = $conn->prepare(
    "SELECT o.*,(SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id) line_count FROM orders o WHERE o.restaurant_id=? ORDER BY FIELD(o.status,'Placed','Accepted','Preparing','Ready for pickup','Picked up','On the way','Delivered','Cancelled'),o.created_at DESC LIMIT 40",
);
$s->bind_param("i", $rid);
$s->execute();
$orders = $s->get_result();
$open = restaurant_open_now($restaurant);
$msg = take_flash();
page_start("Restaurant overview", "restaurant");
?>
<section class="dashboard-hero"><div class="row"><div><p class="eyebrow">YOUR RESTAURANT</p><h2><?= h(
    $restaurant["name"],
) ?></h2><p><?= h($restaurant["cuisine"]) ?> · <?= h(
     $restaurant["address"],
 ) ?></p><p class="muted"><?= $open
    ? "Accepting orders now"
    : "Currently closed or outside opening hours" ?></p></div><div><span class="badge <?= $open
    ? "badge-good"
    : "badge-warn" ?>"><?= $open
    ? "Open"
    : "Closed" ?></span><p><a class="button button-sm button-quiet" href="restaurant_detail.php?id=<?= $rid ?>" target="_blank">View storefront</a></p></div></div></section><?php
if ($msg): ?><div class="flash flash-success" role="status"><?= h(
    $msg["message"],
) ?></div><?php endif;
if ($error): ?><div class="flash flash-error" role="alert"><?= h(
    $error,
) ?></div><?php endif;
?>
<div class="stats-grid"><div class="stat-card"><span class="stat-icon">▤</span><div class="stat-label">All orders</div><div class="stat-value"><?= (int) $metrics[
    "total"
] ?></div></div><div class="stat-card"><span class="stat-icon">◷</span><div class="stat-label">Waiting response</div><div class="stat-value"><?= (int) $metrics[
    "pending"
] ?></div></div><div class="stat-card"><span class="stat-icon">♨</span><div class="stat-label">In preparation</div><div class="stat-value"><?= (int) $metrics[
    "preparing"
] ?></div></div><div class="stat-card"><span class="stat-icon">₫</span><div class="stat-label">Delivered revenue</div><div class="stat-value"><?= money(
    $metrics["revenue"],
) ?></div></div></div><div class="row"><p class="badge <?= $lowStock
    ? "badge-warn"
    : "badge-good" ?>"><?= $lowStock ?> low-stock menu items (5 or fewer)</p><a href="account.php">Restaurant owner profile →</a></div>
<section class="section" id="orders"><div class="section-heading"><div><p class="eyebrow">INCOMING & RECENT</p><h2>Order management</h2><p>Advance orders one step at a time. Only assigned couriers can complete delivery.</p></div></div><?php if (
    !$orders->num_rows
): ?><div class="empty-state"><strong>Your first order is on its way</strong>New customer orders will appear here.</div><?php else: ?><div class="stack"><?php while (
    $o = $orders->fetch_assoc()
): ?><article class="panel"><div class="row"><div><p class="eyebrow">ORDER #<?= $o[
    "id"
] ?> · <?= h($o["created_at"]) ?></p><h3><?= h(
    $o["recipient_name"] ?: "Customer",
) ?> · <?= money($o["total"]) ?></h3><p class="muted"><?= h(
    $o["delivery_address"],
) ?> · <?= h($o["recipient_phone"] ?? "") ?></p></div><span class="badge <?= $o[
    "status"
] === "Delivered"
    ? "badge-good"
    : ($o["status"] === "Cancelled"
        ? "badge-bad"
        : "badge-warn") ?>"><?= h($o["status"]) ?></span></div><p><?= (int) $o[
    "line_count"
] ?> line items · <?= h($o["payment_method"]) ?> / <?= h(
     $o["payment_status"],
 ) ?></p><?php
if ($o["customer_note"]): ?><p><strong>Note:</strong> <?= h(
    $o["customer_note"],
) ?></p><?php endif;
$next = match ($o["status"]) {
    "Placed" => [["Accepted", "Accept order"], ["Cancelled", "Reject order"]],
    "Accepted" => [
        ["Preparing", "Start preparing"],
        ["Cancelled", "Reject order"],
    ],
    "Preparing" => [["Ready for pickup", "Mark ready"]],
    default => [],
};
if (
    $next
): ?><div class="row"><form method="post" class="row"><?php csrf_field(); ?><input type="hidden" name="order_id" value="<?= $o[
    "id"
] ?>"><input type="hidden" name="action" value="order_status"><?php if (
    $o["status"] === "Placed" ||
    $o["status"] === "Accepted"
): ?><label class="sr-only" for="reason-<?= $o[
    "id"
] ?>">Decline reason</label><input class="input" id="reason-<?= $o[
    "id"
] ?>" name="reason" placeholder="Reason required to decline" maxlength="255" style="max-width:220px"><?php endif; ?><select class="select" name="next_status" aria-label="Next status for order <?= $o[
    "id"
] ?>"><?php foreach ($next as [$status, $label]): ?><option value="<?= h(
    $status,
) ?>"><?= h(
    $label,
) ?></option><?php endforeach; ?></select><button class="button button-sm">Update order</button></form><a class="button button-sm button-quiet" href="order_detail.php?id=<?= $o[
    "id"
] ?>">Details</a></div><?php endif;
?></article><?php endwhile; ?></div><?php endif; ?></section>
<section class="section" id="menu"><div class="section-heading"><div><p class="eyebrow">YOUR CATALOG</p><h2>Menu & stock</h2></div></div><div class="two-col"><section class="panel"><h2>Add a dish</h2><form method="post" enctype="multipart/form-data"><?php csrf_field(); ?><input type="hidden" name="action" value="add_item"><div class="field-wrap"><label for="dish_name">Dish name</label><input class="input" id="dish_name" name="name" maxlength="140" required></div><div class="field-wrap"><label for="dish_description">Description</label><textarea class="textarea" id="dish_description" name="description" maxlength="1000"></textarea></div><div class="field-wrap"><label for="category_id">Category</label><select class="select" id="category_id" name="category_id" required><?php while (
    $c = $categories->fetch_assoc()
): ?><option value="<?= $c["id"] ?>"><?= h(
    $c["name"],
) ?></option><?php endwhile; ?></select></div><div class="two-col"><div class="field-wrap"><label for="price">Price (VND)</label><input class="input" type="number" id="price" name="price" min="1" step="1000" required></div><div class="field-wrap"><label for="stock">Stock</label><input class="input" type="number" id="stock" name="stock" min="0" value="10" required></div></div><div class="field-wrap"><label for="image">Food photo</label><input class="input" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"><p class="help-text">JPEG, PNG or WebP · maximum 3 MB.</p></div><button class="button">Add dish</button></form></section><section class="panel"><h2>Top ordered dishes</h2><?php if (
    !$best->num_rows
): ?><p class="muted">Sales ranking will appear after the first order.</p><?php else:while (
        $dish = $best->fetch_assoc()
    ): ?><div class="row" style="padding:10px 0;border-bottom:1px solid var(--line)"><span><?= h(
    $dish["name"],
) ?></span><strong><?= $dish[
    "sold"
] ?> sold</strong></div><?php endwhile;endif; ?></section></div>
<div class="stack" style="margin-top:16px"><?php if (
    !$items->num_rows
): ?><div class="empty-state"><strong>Your menu is ready for its first dish</strong>Add an item to make it available to customers.</div><?php else:while (
        $item = $items->fetch_assoc()
    ): ?><form class="panel" method="post" enctype="multipart/form-data"><?php csrf_field(); ?><input type="hidden" name="action" value="edit_item"><input type="hidden" name="item_id" value="<?= $item[
    "id"
] ?>"><div class="row"><h3><?= h(
    $item["name"],
) ?></h3><span class="badge <?= $item["stock"] <= 5
    ? "badge-warn"
    : ($item["is_available"]
        ? "badge-good"
        : "") ?>"><?= $item["is_available"]
    ? "Available"
    : "Unavailable" ?> · <?= $item[
     "stock"
 ] ?> in stock</span></div><div class="two-col"><div><div class="field-wrap"><label for="name-<?= $item[
     "id"
 ] ?>">Dish name</label><input class="input" id="name-<?= $item[
    "id"
] ?>" name="name" value="<?= h(
    $item["name"],
) ?>" required></div><div class="field-wrap"><label for="desc-<?= $item[
    "id"
] ?>">Description</label><textarea class="textarea" id="desc-<?= $item[
    "id"
] ?>" name="description"><?= h(
    $item["description"],
) ?></textarea></div><div class="two-col"><div class="field-wrap"><label for="price-<?= $item[
    "id"
] ?>">Price</label><input class="input" type="number" min="1" step="1000" id="price-<?= $item[
    "id"
] ?>" name="price" value="<?= $item[
    "price"
] ?>" required></div><div class="field-wrap"><label for="stock-<?= $item[
    "id"
] ?>">Stock</label><input class="input" type="number" min="0" id="stock-<?= $item[
    "id"
] ?>" name="stock" value="<?= $item[
    "stock"
] ?>" required></div></div></div><div><?php if (
    $item["image_url"]
): ?><img src="<?= h($item["image_url"]) ?>" alt="<?= h(
    $item["name"],
) ?>" width="180" height="120" style="object-fit:cover;border-radius:12px"><?php endif; ?><div class="field-wrap"><label for="category-<?= $item[
    "id"
] ?>">Category</label><select class="select" id="category-<?= $item[
    "id"
] ?>" name="category_id"><?php foreach (
    $conn->query("SELECT id,name FROM categories ORDER BY name")
    as $cat
): ?><option value="<?= $cat["id"] ?>" <?= $cat["id"] == $item["category_id"]
    ? "selected"
    : "" ?>><?= h(
    $cat["name"],
) ?></option><?php endforeach; ?></select></div><div class="field-wrap"><label for="image-<?= $item[
    "id"
] ?>">Replace image</label><input class="input" type="file" id="image-<?= $item[
    "id"
] ?>" name="image" accept="image/jpeg,image/png,image/webp"></div><label><input type="checkbox" name="is_available" value="1" <?= $item[
    "is_available"
]
    ? "checked"
    : "" ?>> Available to customers</label></div></div><div class="row"><span class="help-text">Item #<?= $item[
    "id"
] ?></span><button class="button button-sm">Save changes</button></div></form><?php endwhile;endif; ?></div></section>
<section class="section" id="settings"><div class="section-heading"><div><p class="eyebrow">KEEP YOUR DETAILS CURRENT</p><h2>Restaurant settings</h2></div></div><section class="panel"><form method="post" enctype="multipart/form-data"><?php csrf_field(); ?><input type="hidden" name="action" value="settings"><div class="two-col"><div><div class="field-wrap"><label for="restaurant_name">Restaurant name</label><input class="input" id="restaurant_name" name="name" maxlength="140" value="<?= h(
    $restaurant["name"],
) ?>" required></div><div class="field-wrap"><label for="cuisine">Cuisine</label><input class="input" id="cuisine" name="cuisine" value="<?= h(
    $restaurant["cuisine"],
) ?>"></div><div class="field-wrap"><label for="description">Description</label><textarea class="textarea" id="description" name="description"><?= h(
    $restaurant["description"],
) ?></textarea></div><div class="field-wrap"><label for="restaurant_address">Pickup address</label><textarea class="textarea" id="restaurant_address" name="address" required><?= h(
    $restaurant["address"],
) ?></textarea></div><div class="field-wrap"><label for="restaurant_phone">Contact phone</label><input class="input" id="restaurant_phone" name="phone" type="tel" value="<?= h(
    $restaurant["phone"],
) ?>"></div></div><div><div class="two-col"><div class="field-wrap"><label for="opening">Opens</label><input class="input" type="time" id="opening" name="opening_time" value="<?= h(
    $restaurant["opening_time"],
) ?>"></div><div class="field-wrap"><label for="closing">Closes</label><input class="input" type="time" id="closing" name="closing_time" value="<?= h(
    $restaurant["closing_time"],
) ?>"></div></div><?php if ($restaurant["image_url"]): ?><img src="<?= h(
    $restaurant["image_url"],
) ?>" alt="Current restaurant cover" width="250" height="150" style="object-fit:cover;border-radius:14px"><?php endif; ?><div class="field-wrap"><label for="cover">Restaurant cover image</label><input class="input" type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp"><p class="help-text">JPEG, PNG or WebP · maximum 3 MB.</p></div><label><input type="checkbox" name="is_open" value="1" <?= $restaurant[
    "is_open"
]
    ? "checked"
    : "" ?>> Restaurant is accepting orders</label><p class="help-text">Storefront availability also checks opening and closing times.</p><button class="button">Save restaurant details</button></div></div></form></section></section><?php page_end();
