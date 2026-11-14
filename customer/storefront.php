<?php
require dirname(__DIR__) . "/includes/db.php";
$me = user();
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_item"])) {
    check_csrf();
    $id = (int) ($_POST["item_id"] ?? 0);
    if (cart_add($conn, $id, max(1, (int) ($_POST["quantity"] ?? 1)))) {
        flash("Added to your cart.");
    } else {
        flash(
            "That dish is unavailable or your cart already has food from another restaurant.",
            "warning",
        );
    }
    header("Location: " . ($_SERVER["HTTP_REFERER"] ?? "index.php"));
    exit();
}
$q = trim($_GET["q"] ?? "");
$category = (int) ($_GET["category"] ?? 0);
$sort = $_GET["sort"] ?? "popular";
$order = match ($sort) {
    "rating" => "review_score DESC, r.id DESC",
    "name" => "r.name ASC",
    default => "order_count DESC, review_score DESC, r.id DESC",
};
$sql =
    "SELECT r.*,COALESCE(rv.score,0) review_score,COALESCE(rv.review_count,0) review_count,COALESCE(od.order_count,0) order_count FROM restaurants r LEFT JOIN (SELECT restaurant_id,AVG(rating) score,COUNT(*) review_count FROM reviews GROUP BY restaurant_id) rv ON rv.restaurant_id=r.id LEFT JOIN (SELECT restaurant_id,COUNT(*) order_count FROM orders WHERE status NOT IN ('Cancelled') GROUP BY restaurant_id) od ON od.restaurant_id=r.id WHERE r.approval_status='Approved'";
$params = [];
$types = "";
if ($q !== "") {
    $sql .=
        " AND (r.name LIKE ? OR r.cuisine LIKE ? OR r.description LIKE ? OR EXISTS(SELECT 1 FROM menu_items sm WHERE sm.restaurant_id=r.id AND sm.name LIKE ?))";
    $like = "%" . $q . "%";
    $params = [$like, $like, $like, $like];
    $types = "ssss";
}
if ($category) {
    $sql .=
        " AND EXISTS(SELECT 1 FROM menu_items mi WHERE mi.restaurant_id=r.id AND mi.category_id=? AND mi.is_available=1 AND mi.stock>0)";
    $params[] = $category;
    $types .= "i";
}
$sql .= " ORDER BY " . $order . ",r.id DESC";
$stmt = $conn->prepare($sql);
execute_params($stmt, $params);
$restaurants = $stmt->get_result();
$cats = $conn->query("SELECT id,name,slug FROM categories ORDER BY name");
$address = "Set your delivery address";
if ($me && $me["role"] === "Customer") {
    $s = $conn->prepare(
        "SELECT label,address_line FROM user_addresses WHERE user_id=? ORDER BY is_default DESC,id DESC LIMIT 1",
    );
    $s->bind_param("i", $me["id"]);
    $s->execute();
    if ($a = $s->get_result()->fetch_assoc()) {
        $address = $a["label"] . ": " . $a["address_line"];
    }
}
$foods = $conn->query(
    "SELECT m.*,r.name restaurant,r.id restaurant_id,COALESCE(s.sold,0) sold,COALESCE(v.score,0) score FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id LEFT JOIN (SELECT oi.menu_item_id,SUM(oi.quantity) sold FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status NOT IN ('Cancelled') AND oi.menu_item_id IS NOT NULL GROUP BY oi.menu_item_id) s ON s.menu_item_id=m.id LEFT JOIN (SELECT restaurant_id,AVG(rating) score FROM reviews GROUP BY restaurant_id) v ON v.restaurant_id=r.id WHERE m.is_available=1 AND m.stock>0 AND r.is_open=1 AND r.approval_status='Approved' ORDER BY sold DESC,m.id DESC LIMIT 6",
);
page_start("Discover local food");
?><section class="hero-store"><div class="hero-copy"><p class="eyebrow">GOOD FOOD, CLOSE TO HOME</p><h1>What are you craving today?</h1><p>Explore local kitchens, find a new favorite and follow every order from the first bite to your door.</p><p class="help-text">⌖ <?= h(
    $address,
) ?> · <a href="account.php">Manage address</a></p><form class="search-form" method="get" role="search"><label class="sr-only" for="search">Search restaurants or cuisine</label><input class="search-input" id="search" name="q" value="<?= h(
     $q,
 ) ?>" placeholder="Search restaurants or cuisine"><button class="button" type="submit">Find food</button></form></div><img class="hero-art" src="https://images.unsplash.com/photo-1547592180-85f173990554?w=850&auto=format&fit=crop&q=85" alt="A colorful table of freshly prepared food"></section>
<section class="section"><div class="section-heading"><div><p class="eyebrow">A LITTLE BIT OF EVERYTHING</p><h2>Explore by category</h2></div></div><div class="category-row"><a class="category-chip <?= $category ===
0
    ? "active"
    : "" ?>" href="index.php">🍽️ All food</a><?php while (
    $c = $cats->fetch_assoc()
): ?><a class="category-chip <?= $category === (int) $c["id"]
    ? "active"
    : "" ?>" href="<?= "?category=" .
    (int) $c["id"] .
    ($q !== "" ? "&q=" . urlencode($q) : "") ?>"><?= match ($c["slug"]) {
    "burgers" => "🍔",
    "pizza" => "🍕",
    "asian" => "🥢",
    "healthy" => "🥗",
    "desserts" => "🍰",
    default => "✨",
} ?> <?= h($c["name"]) ?></a><?php endwhile; ?></div></section>
<section class="section"><div class="section-heading"><div><p class="eyebrow">PLACES WORTH A VISIT</p><h2><?= $q !==
""
    ? "Restaurants matching “" . h($q) . "”"
    : "Popular restaurants" ?></h2><p>Local spots ready for your next meal.</p></div><form method="get" class="filter-bar"><input type="hidden" name="q" value="<?= h(
    $q,
) ?>"><input type="hidden" name="category" value="<?= $category ?>"><label for="sort" class="small">Sort:</label><select id="sort" name="sort" onchange="this.form.submit()"><option value="popular" <?= $sort ===
"popular"
    ? "selected"
    : "" ?>>Popular</option><option value="rating" <?= $sort === "rating"
    ? "selected"
    : "" ?>>Top rated</option><option value="name" <?= $sort === "name"
    ? "selected"
    : "" ?>>Name</option></select></form></div><?php if (
    !$restaurants->num_rows
): ?><div class="empty-state"><strong>No restaurants found</strong>Try another search, category, or clear your filters.</div><?php else: ?><div class="food-grid"><?php while (
    $r = $restaurants->fetch_assoc()
): ?><a class="restaurant-card" href="restaurant_detail.php?id=<?= $r[
    "id"
] ?>"><img class="restaurant-cover" src="<?= h(
    $r["image_url"] ?:
    "https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=900&auto=format&fit=crop&q=80",
) ?>" alt="<?= h(
    $r["name"],
) ?> restaurant" loading="lazy"><div class="restaurant-content"><div class="row"><h3><?= h(
     $r["name"],
 ) ?></h3><span class="badge <?= (int) $r["is_open"]
    ? "badge-good"
    : "badge-warn" ?>"><?= (int) $r["is_open"]
    ? "Open"
    : "Closed" ?></span></div><p><?= h($r["cuisine"]) ?> · <?= h(
     $r["address"],
 ) ?></p><div class="meta-row"><strong><?= (int) $r["review_count"]
    ? "★ " . number_format((float) $r["review_score"], 1)
    : "New" ?></strong><span>·</span><span><?= number_format(
    (float) $r["delivery_fee"],
    0,
) ?> ₫ delivery</span><span>·</span><span><?= (int) $r[
     "order_count"
 ] ?> orders</span></div></div></a><?php endwhile; ?></div><?php endif; ?></section>
<section class="section"><div class="section-heading"><div><p class="eyebrow">GOOD PICKS</p><h2>Dishes to try</h2><p>Availability and popularity are based on current menu and order data.</p></div></div><?php if (
    !$foods->num_rows
): ?><div class="empty-state"><strong>Fresh menus are on the way</strong>Check back when a restaurant opens its kitchen.</div><?php else: ?><div class="food-grid"><?php while (
    $f = $foods->fetch_assoc()
): ?><article class="food-card"><a href="restaurant_detail.php?id=<?= $f[
    "restaurant_id"
] ?>"><img src="<?= h(
    $f["image_url"] ?:
    "https://images.unsplash.com/photo-1547592180-85f173990554?w=700&auto=format&fit=crop&q=80",
) ?>" alt="<?= h(
    $f["name"],
) ?>" loading="lazy"></a><div class="food-info"><div class="row"><h3><?= h(
    $f["name"],
) ?></h3><span class="price"><?= money($f["price"]) ?></span></div><p><?= h(
    $f["restaurant"],
) ?> · <?= h(
     $f["description"] ?? "",
 ) ?></p><div class="row"><span class="muted small"><?= $f[
    "sold"
] ?> ordered<?= $f["sold"] == 1
     ? ""
     : "s" ?></span><form method="post"><?php csrf_field(); ?><input type="hidden" name="item_id" value="<?= $f[
    "id"
] ?>"><button class="button button-sm" name="add_item" type="submit">Add</button></form></div></div></article><?php endwhile; ?></div><?php endif; ?></section><?php page_end();
