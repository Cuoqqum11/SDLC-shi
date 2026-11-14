<?php
require dirname(__DIR__) . "/includes/db.php";
$id = max(0, (int) ($_GET["id"] ?? 0));
$s = $conn->prepare(
    'SELECT r.*,COALESCE(AVG(rv.rating),0) score,COUNT(DISTINCT rv.id) review_count FROM restaurants r LEFT JOIN reviews rv ON rv.restaurant_id=r.id WHERE r.id=? AND r.approval_status=\'Approved\' GROUP BY r.id',
);
$s->bind_param("i", $id);
$s->execute();
$restaurant = $s->get_result()->fetch_assoc();
if (!$restaurant) {

    http_response_code(404);
    page_start("Restaurant not found");
    ?><div class="empty-state"><strong>We couldn't find that restaurant</strong><a class="button" href="index.php">Back to restaurants</a></div><?php
page_end();
exit();

}
$me = user();
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $action = $_POST["action"] ?? "";
    if ($action === "favorite" && $me && $me["role"] === "Customer") {
        $s = $conn->prepare(
            "SELECT 1 FROM favorites WHERE user_id=? AND restaurant_id=?",
        );
        $s->bind_param("ii", $me["id"], $id);
        $s->execute();
        if ($s->get_result()->num_rows) {
            $s = $conn->prepare(
                "DELETE FROM favorites WHERE user_id=? AND restaurant_id=?",
            );
            $s->bind_param("ii", $me["id"], $id);
            $s->execute();
            flash("Removed from saved restaurants.");
        } else {
            $s = $conn->prepare(
                "INSERT IGNORE INTO favorites(user_id,restaurant_id) VALUES(?,?)",
            );
            $s->bind_param("ii", $me["id"], $id);
            $s->execute();
            flash("Restaurant saved to your favorites.");
        }
    } elseif ($action === "review" && $me && $me["role"] === "Customer") {
        $orderId = (int) ($_POST["order_id"] ?? 0);
        $rating = (int) ($_POST["rating"] ?? 0);
        $comment = trim($_POST["comment"] ?? "");
        if ($rating < 1 || $rating > 5) {
            flash("Choose a rating from 1 to 5.", "warning");
        } else {
            $s = $conn->prepare(
                "SELECT id FROM orders WHERE id=? AND customer_id=? AND restaurant_id=? AND status='Delivered'",
            );
            $s->bind_param("iii", $orderId, $me["id"], $id);
            $s->execute();
            if ($s->get_result()->num_rows) {
                $s = $conn->prepare(
                    "INSERT INTO reviews(order_id,customer_id,restaurant_id,rating,comment) VALUES(?,?,?,?,?)",
                );
                $s->bind_param(
                    "iiiis",
                    $orderId,
                    $me["id"],
                    $id,
                    $rating,
                    $comment,
                );
                if ($s->execute()) {
                    flash("Thanks for sharing your review.");
                } else {
                    flash(
                        "A review has already been submitted for this order.",
                        "warning",
                    );
                }
            } else {
                flash(
                    "Reviews are available after your order is delivered.",
                    "warning",
                );
            }
        }
    }
    header("Location: restaurant_detail.php?id=" . $id);
    exit();
}
$isFavorite = false;
if ($me && $me["role"] === "Customer") {
    $s = $conn->prepare(
        "SELECT 1 FROM favorites WHERE user_id=? AND restaurant_id=?",
    );
    $s->bind_param("ii", $me["id"], $id);
    $s->execute();
    $isFavorite = (bool) $s->get_result()->num_rows;
}
$category = (int) ($_GET["category"] ?? 0);
$sort = $_GET["sort"] ?? "popular";
$order = match ($sort) {
    "price_low" => "m.price ASC,m.id",
    "price_high" => "m.price DESC,m.id",
    "name" => "m.name ASC",
    default => "sold DESC,m.id DESC",
};
$menuSql =
    "SELECT m.*,c.name category,COALESCE(x.sold,0) sold FROM menu_items m LEFT JOIN categories c ON c.id=m.category_id LEFT JOIN (SELECT oi.menu_item_id,SUM(oi.quantity) sold FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status<>'Cancelled' GROUP BY oi.menu_item_id) x ON x.menu_item_id=m.id WHERE m.restaurant_id=?";
if ($category) {
    $menuSql .= " AND m.category_id=?";
}
$menuSql .= " ORDER BY " . $order;
$s = $conn->prepare($menuSql);
if ($category) {
    $s->bind_param("ii", $id, $category);
} else {
    $s->bind_param("i", $id);
}
$s->execute();
$menu = $s->get_result();
$menuCount = $menu->num_rows;
$cats = $conn->query("SELECT id,name FROM categories ORDER BY name");
$s = $conn->prepare(
    "SELECT rv.rating,rv.comment,rv.created_at,u.FullName FROM reviews rv JOIN users u ON u.UserID=rv.customer_id WHERE rv.restaurant_id=? ORDER BY rv.created_at DESC LIMIT 12",
);
$s->bind_param("i", $id);
$s->execute();
$reviews = $s->get_result();
$reviewOrders = null;
if ($me && $me["role"] === "Customer") {
    $s = $conn->prepare(
        "SELECT o.id FROM orders o LEFT JOIN reviews rv ON rv.order_id=o.id WHERE o.customer_id=? AND o.restaurant_id=? AND o.status='Delivered' AND rv.id IS NULL ORDER BY o.created_at DESC",
    );
    $s->bind_param("ii", $me["id"], $id);
    $s->execute();
    $reviewOrders = $s->get_result();
}
$open = restaurant_open_now($restaurant);
page_start($restaurant["name"]);
?>
<div class="section-heading"><div><a class="muted" href="index.php">← Browse restaurants</a></div><?php if (
    $me &&
    $me["role"] === "Customer"
): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="favorite"><button class="button button-quiet" type="submit" aria-pressed="<?= $isFavorite
    ? "true"
    : "false" ?>"><?= $isFavorite
    ? "♥ Saved"
    : "♡ Save restaurant" ?></button></form><?php endif; ?></div>
<img class="restaurant-detail-cover" src="<?= h(
    $restaurant["image_url"] ?:
    "https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=1200&auto=format&fit=crop&q=80",
) ?>" alt="<?= h($restaurant["name"]) ?> restaurant cover">
<section class="panel" style="margin-top:-28px;position:relative;width:min(720px,94%);margin-left:auto;margin-right:auto"><div class="row"><div><p class="eyebrow"><?= h(
    $restaurant["cuisine"],
) ?></p><h1 style="font:800 31px Manrope;margin:0"><?= h(
    $restaurant["name"],
) ?></h1></div><span class="badge <?= $open
    ? "badge-good"
    : "badge-warn" ?>"><?= $open
    ? "Open now"
    : "Closed now" ?></span></div><p class="muted"><?= h(
    $restaurant["description"] ?? "",
) ?></p><div class="meta-row"><strong><?= (int) $restaurant["review_count"]
    ? "★ " . number_format((float) $restaurant["score"], 1)
    : "New" ?></strong><span>·</span><span><?= h(
    $restaurant["address"],
) ?></span><span>·</span><span>Delivery <?= money(
    $restaurant["delivery_fee"],
) ?></span><?php if ($restaurant["phone"]): ?><span>·</span><a href="tel:<?= h(
    $restaurant["phone"],
) ?>">Call restaurant</a><?php endif; ?></div><p class="small muted">Hours: <?= h(
    $restaurant["opening_time"] ?: "Not set",
) ?> – <?= h($restaurant["closing_time"] ?: "Not set") ?></p></section>
<section class="section"><div class="section-heading"><div><p class="eyebrow">MADE FRESH HERE</p><h2>Menu</h2><p><?= $menuCount ?> dishes listed</p></div><form method="get" class="filter-bar"><input type="hidden" name="id" value="<?= $id ?>"><select name="category" aria-label="Filter by category"><option value="0">All categories</option><?php while (
    $c = $cats->fetch_assoc()
): ?><option value="<?= $c["id"] ?>" <?= $category === (int) $c["id"]
    ? "selected"
    : "" ?>><?= h(
    $c["name"],
) ?></option><?php endwhile; ?></select><select name="sort" aria-label="Sort menu"><option value="popular" <?= $sort ===
"popular"
    ? "selected"
    : "" ?>>Popular</option><option value="price_low" <?= $sort === "price_low"
    ? "selected"
    : "" ?>>Price: low to high</option><option value="price_high" <?= $sort ===
"price_high"
    ? "selected"
    : "" ?>>Price: high to low</option><option value="name" <?= $sort === "name"
    ? "selected"
    : "" ?>>Name</option></select><button class="button button-sm">Apply</button></form></div>
<?php if (
    !$menu->num_rows
): ?><div class="empty-state"><strong>No dishes in this menu yet</strong>Try another category or check back soon.</div><?php else: ?><div class="food-grid"><?php while (
    $item = $menu->fetch_assoc()
):
    $available =
        (int) $item["is_available"] &&
        (int) $item["stock"] > 0 &&
        $open; ?><article class="food-card"><img src="<?= h(
    $item["image_url"] ?:
    "https://images.unsplash.com/photo-1547592180-85f173990554?w=700&auto=format&fit=crop&q=80",
) ?>" alt="<?= h(
    $item["name"],
) ?>" loading="lazy"><div class="food-info"><div class="row"><h3><?= h(
    $item["name"],
) ?></h3><span class="price"><?= money($item["price"]) ?></span></div><p><?= h(
    $item["description"] ?? "",
) ?></p><div class="row"><span class="badge <?= $available
    ? "badge-good"
    : "badge-warn" ?>"><?= $available
    ? "Available"
    : ((int) $item["stock"] < 1
        ? "Sold out"
        : ($open
            ? "Unavailable"
            : "Closed")) ?></span><?php if (
    $available
): ?><form method="post" action="index.php"><?php csrf_field(); ?><input type="hidden" name="item_id" value="<?= $item[
    "id"
] ?>"><button class="button button-sm" name="add_item">Add to cart</button></form><?php endif; ?></div></div></article><?php
endwhile; ?></div><?php endif; ?></section>
<section class="section"><div class="section-heading"><div><p class="eyebrow">FROM OUR DINERS</p><h2>Ratings & reviews</h2></div></div><?php if (
    $reviews->num_rows
): ?><div class="stack"><?php while (
    $review = $reviews->fetch_assoc()
): ?><article class="panel"><div class="row"><strong><?= h(
    $review["FullName"],
) ?></strong><span class="badge">★ <?= $review[
    "rating"
] ?> / 5</span></div><p><?= h(
     $review["comment"] ?: "No written comment.",
 ) ?></p><span class="small muted"><?= h(
    $review["created_at"],
) ?></span></article><?php endwhile; ?></div><?php else: ?><div class="empty-state"><strong>No reviews yet</strong>Be among the first to share feedback after an order.</div><?php endif; ?>
<?php if (
    $reviewOrders &&
    $reviewOrders->num_rows
): ?><article class="panel" style="margin-top:16px"><h2>Review a completed order</h2><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="review"><div class="field-wrap"><label for="order_id">Completed order</label><select class="select" name="order_id" id="order_id"><?php while (
    $o = $reviewOrders->fetch_assoc()
): ?><option value="<?= $o["id"] ?>">Order #<?= $o[
    "id"
] ?></option><?php endwhile; ?></select></div><div class="field-wrap"><label for="rating">Rating</label><select class="select" id="rating" name="rating"><option value="5">5 — Loved it</option><option value="4">4 — Really good</option><option value="3">3 — Good</option><option value="2">2 — Could be better</option><option value="1">1 — Disappointed</option></select></div><div class="field-wrap"><label for="comment">Comment (optional)</label><textarea class="textarea" id="comment" name="comment" maxlength="1000"></textarea></div><button class="button">Submit review</button></form></article><?php endif; ?></section><?php page_end();
