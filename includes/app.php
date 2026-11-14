<?php
date_default_timezone_set("Asia/Ho_Chi_Minh");
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set("session.use_strict_mode", "1");
    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_samesite", "Lax");
    if (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
        ini_set("session.cookie_secure", "1");
    }
    session_start();
    if (
        isset($_SESSION["last_activity"]) &&
        time() - (int) $_SESSION["last_activity"] > 7200
    ) {
        foreach (
            ["user_id", "username", "full_name", "role", "last_activity"]
            as $key
        ) {
            unset($_SESSION[$key]);
        }
        $_SESSION["flash"] = [
            "type" => "warning",
            "message" => "Your session expired. Sign in again to continue.",
        ];
    }
    $_SESSION["last_activity"] = time();
}

function h($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        "UTF-8",
    );
}
function money($value): string
{
    return number_format((float) $value, 0, ".", ",") . " ₫";
}
function execute_params(mysqli_stmt $stmt, array $params): void
{
    if (!$params) {
        $stmt->execute();
        return;
    }
    $types = "";
    $refs = [];
    foreach ($params as $i => &$value) {
        $types .= is_int($value) ? "i" : (is_float($value) ? "d" : "s");
        $refs[$i] = &$value;
    }
    $stmt->bind_param($types, ...$refs);
    $stmt->execute();
}
function restaurant_open_now(array $restaurant): bool
{
    if (
        !(int) ($restaurant["is_open"] ?? 0) ||
        ($restaurant["approval_status"] ?? "Approved") !== "Approved"
    ) {
        return false;
    }
    if (
        empty($restaurant["opening_time"]) ||
        empty($restaurant["closing_time"])
    ) {
        return true;
    }
    $now = date("H:i:s");
    $open = $restaurant["opening_time"];
    $close = $restaurant["closing_time"];
    return $close >= $open
        ? $now >= $open && $now <= $close
        : $now >= $open || $now <= $close;
}
function user(): ?array
{
    return isset($_SESSION["user_id"])
        ? [
            "id" => (int) $_SESSION["user_id"],
            "name" => $_SESSION["full_name"] ?? $_SESSION["username"],
            "username" => $_SESSION["username"],
            "role" => $_SESSION["role"] ?? "Customer",
        ]
        : null;
}
function require_role(array $roles): void
{
    global $conn;
    $u = user();
    if (!$u) {
        header(
            "Location: " .
                (str_contains($_SERVER["PHP_SELF"] ?? "", "/admin/")
                    ? "../"
                    : "") .
                "login.php",
        );
        exit();
    }
    $s = $conn->prepare(
        "SELECT u.IsActive,u.UserName,u.FullName,r.RoleName FROM users u JOIN roles r ON r.RoleID=u.RoleID WHERE u.UserID=?",
    );
    $s->bind_param("i", $u["id"]);
    $s->execute();
    $current = $s->get_result()->fetch_assoc();
    if (
        !$current ||
        !(int) $current["IsActive"] ||
        !in_array($current["RoleName"], $roles, true)
    ) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION["flash"] = [
            "type" => "error",
            "message" => "Your account does not have access to that page.",
        ];
        header(
            "Location: " .
                (str_contains($_SERVER["PHP_SELF"] ?? "", "/admin/")
                    ? "../"
                    : "") .
                "login.php",
        );
        exit();
    }
    $_SESSION["role"] = $current["RoleName"];
    $_SESSION["username"] = $current["UserName"];
    $_SESSION["full_name"] = $current["FullName"] ?: $current["UserName"];
}
function flash(string $message, string $type = "success"): void
{
    $_SESSION["flash"] = ["type" => $type, "message" => $message];
}
function take_flash(): ?array
{
    $message = $_SESSION["flash"] ?? null;
    unset($_SESSION["flash"]);
    return $message;
}
function csrf_token(): string
{
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf"];
}
function csrf_field(): void
{
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}
function check_csrf(): void
{
    if (!hash_equals($_SESSION["csrf"] ?? "", $_POST["csrf"] ?? "")) {
        http_response_code(400);
        exit("Your form expired. Reload the page and try again.");
    }
}
function notify_user(
    mysqli $conn,
    int $userId,
    string $title,
    string $message,
    string $link = "",
): void {
    if ($userId < 1) {
        return;
    }
    $s = $conn->prepare(
        "INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)",
    );
    $s->bind_param("isss", $userId, $title, $message, $link);
    $s->execute();
}
function page_start(string $title, string $layout = "storefront"): void
{
    global $conn;
    $u = user();
    $base = str_contains($_SERVER["PHP_SELF"] ?? "", "/admin/") ? "../" : "";
    $flash = take_flash();
    $dashboard = in_array($layout, ["restaurant", "courier", "admin"], true);
    $unread = 0;
    $cartCount = 0;
    if ($u) {
        $s = $conn->prepare(
            "SELECT COUNT(*) n FROM notifications WHERE user_id=? AND is_read=0",
        );
        $s->bind_param("i", $u["id"]);
        $s->execute();
        $unread = (int) $s->get_result()->fetch_assoc()["n"];
        if ($u["role"] === "Customer") {
            $s = $conn->prepare(
                "SELECT COALESCE(SUM(quantity),0) n FROM cart_items WHERE user_id=?",
            );
            $s->bind_param("i", $u["id"]);
            $s->execute();
            $cartCount = (int) $s->get_result()->fetch_assoc()["n"];
        } else {
            $cartCount = array_sum(
                array_column($_SESSION["cart"] ?? [], "quantity"),
            );
        }
    }
    $links = match ($u["role"] ?? "Guest") {
        "Restaurant" => [
            ["restaurant.php", "Overview"],
            ["restaurant.php#orders", "Orders"],
            ["account.php", "Account"],
        ],
        "Driver" => [["courier.php", "Deliveries"], ["account.php", "Profile"]],
        "Admin" => [
            ["admin/index.php", "Overview"],
            ["admin/users.php", "Users"],
            ["admin/restaurants.php", "Restaurants"],
            ["admin/orders.php", "Orders"],
            ["admin/reports.php", "Reports"],
            ["account.php", "Account"],
        ],
        "Customer" => [
            ["index.php", "Discover"],
            ["orders.php", "My orders"],
            ["account.php", "Account"],
        ],
        default => [["index.php", "Discover"], ["login.php", "Sign in"]],
    };
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f05a35"><title><?= h($title) ?> · MealDash</title><link rel="stylesheet" href="<?= $base ?>assets/app.css"><script defer src="<?= $base ?>assets/app.js"></script></head><body class="role-<?= h(strtolower($u["role"] ?? "guest")) ?> layout-<?= h($layout) ?>">
    <?php
    if (
        $dashboard
    ): ?><aside class="sidebar"><a class="brand" href="<?= $base ?>index.php"><span class="brand-mark">M</span><span>MealDash<small><?= h(
    $layout === "restaurant"
        ? "Restaurant Studio"
        : ($layout === "courier"
            ? "Courier"
            : "Platform admin"),
) ?></small></span></a><nav><?php foreach (
    $links
    as [$href, $label]
): ?><a href="<?= h($base . $href) ?>" class="<?= basename(
    $_SERVER["PHP_SELF"] ?? "",
) === basename(explode("#", $href)[0])
    ? "active"
    : "" ?>"><?= h(
    $label,
) ?></a><?php endforeach; ?></nav><div class="sidebar-bottom"><span class="user-chip"><?= h(
    $u["name"] ?? "Guest",
) ?><small><?= h($u["role"] ?? "Guest") ?></small></span><?php if (
    $u
): ?><a href="<?= $base ?>logout.php">Sign out</a><?php endif; ?></div></aside><div class="dashboard-main"><header class="topbar"><div><p class="eyebrow"><?= h(
    $layout === "restaurant"
        ? "MERCHANT WORKSPACE"
        : ($layout === "courier"
            ? "DELIVERY WORKSPACE"
            : "PLATFORM MANAGEMENT"),
) ?></p><h1><?= h(
    $title,
) ?></h1></div><div class="topbar-actions"><a class="icon-link" href="<?= $base ?>notifications.php" aria-label="Notifications">♧<?php if (
    $unread
): ?><span class="notification-count"><?= $unread ?></span><?php endif; ?></a><span class="avatar-initial"><?= h(
    strtoupper(substr($u["name"] ?? "M", 0, 1)),
) ?></span></div></header><main class="page-content">
    <?php else: ?><header class="store-header"><a class="brand" href="<?= $base ?>index.php"><span class="brand-mark">M</span><span>MealDash</span></a><nav><?php foreach (
    $links
    as [$href, $label]
): ?><a href="<?= h($base . $href) ?>"><?= h(
    $label,
) ?></a><?php endforeach; ?></nav><div class="header-actions"><?php
if (
    $u
): ?><a class="icon-link" href="<?= $base ?>notifications.php" aria-label="Notifications">♧<?php if (
    $unread
): ?><span class="notification-count"><?= $unread ?></span><?php endif; ?></a><?php endif;
if (
    $u &&
    $u["role"] === "Customer"
): ?><a class="cart-link" href="<?= $base ?>orders.php#cart">Cart <span data-cart-count><?= $cartCount ?:
    "" ?></span></a><?php endif;
if ($u): ?><a class="user-link" href="<?= $base ?>account.php"><?= h(
    $u["name"],
) ?></a><a class="button button-quiet" href="<?= $base ?>logout.php">Sign out</a><?php else: ?><a class="button button-quiet" href="<?= $base ?>login.php">Sign in</a><a class="button" href="<?= $base ?>register.php">Create account</a><?php endif;
?></div></header><main class="store-main">
    <?php endif;
    if ($flash): ?><div class="flash flash-<?= h(
    $flash["type"] ?? "success",
) ?>" role="status" data-flash><?= h(
    $flash["message"] ?? "",
) ?><button type="button" aria-label="Dismiss message" data-dismiss>×</button></div><?php endif;?>
<?php
}
function page_end(): void
{
    $dashboard = in_array(
        $_SESSION["role"] ?? "",
        ["Restaurant", "Driver", "Admin"],
        true,
    );
    $base = str_contains($_SERVER["PHP_SELF"] ?? "", "/admin/") ? "../" : "";
    ?></main><?php
if (
    $dashboard
): ?></div><?php else: ?><footer class="site-footer"><a class="brand" href="<?= $base ?>index.php"><span class="brand-mark">M</span><span>MealDash</span></a><p>Great local food, delivered with care.</p><span>© <?= date(
    "Y",
) ?> MealDash</span></footer><?php endif;
if (
    ($role = $_SESSION["role"] ?? "") === "Customer"
): ?><nav class="mobile-bottom-nav" aria-label="Quick navigation"><a href="<?= $base ?>index.php">Discover</a><a href="<?= $base ?>orders.php#cart">Cart</a><a href="<?= $base ?>orders.php">Orders</a><a href="<?= $base ?>account.php">Profile</a></nav><?php elseif (
    $role === "Driver"
): ?><nav class="mobile-bottom-nav" aria-label="Courier navigation"><a href="<?= $base ?>courier.php">Deliveries</a><a href="<?= $base ?>notifications.php">Updates</a><a href="<?= $base ?>account.php">Profile</a></nav><?php endif;?></body></html><?php
}
function current_cart(mysqli $conn): array
{
    $u = user();
    if ($u && $u["role"] === "Customer") {
        $s = $conn->prepare(
            "SELECT ci.menu_item_id,ci.quantity,m.restaurant_id FROM cart_items ci JOIN menu_items m ON m.id=ci.menu_item_id WHERE ci.user_id=?",
        );
        $s->bind_param("i", $u["id"]);
        $s->execute();
        $result = $s->get_result();
        $cart = [];
        while ($row = $result->fetch_assoc()) {
            $cart[(int) $row["menu_item_id"]] = [
                "quantity" => (int) $row["quantity"],
                "restaurant_id" => (int) $row["restaurant_id"],
            ];
        }
        return $cart;
    }
    return $_SESSION["cart"] ?? [];
}
function cart_add(mysqli $conn, int $itemId, int $quantity): bool
{
    $u = user();
    $s = $conn->prepare(
        "SELECT m.restaurant_id,m.stock,m.is_available,r.is_open,r.approval_status,r.opening_time,r.closing_time FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id WHERE m.id=?",
    );
    $s->bind_param("i", $itemId);
    $s->execute();
    $item = $s->get_result()->fetch_assoc();
    if (
        !$item ||
        !(int) $item["is_available"] ||
        !restaurant_open_now($item) ||
        (int) $item["stock"] < 1
    ) {
        return false;
    }
    $cart = current_cart($conn);
    foreach ($cart as $existingId => $line) {
        if (
            (int) $line["restaurant_id"] !== (int) $item["restaurant_id"] &&
            $existingId !== $itemId
        ) {
            return false;
        }
    }
    $newQty = min(
        (int) $item["stock"],
        max(1, (int) ($cart[$itemId]["quantity"] ?? 0) + $quantity),
    );
    if ($u && $u["role"] === "Customer") {
        $s = $conn->prepare(
            "INSERT INTO cart_items(user_id,menu_item_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)",
        );
        $s->bind_param("iii", $u["id"], $itemId, $newQty);
        return $s->execute();
    }
    $_SESSION["cart"][$itemId] = [
        "quantity" => $newQty,
        "restaurant_id" => (int) $item["restaurant_id"],
    ];
    return true;
}
function cart_set(mysqli $conn, int $itemId, int $quantity): bool
{
    $u = user();
    $cart = current_cart($conn);
    if (!isset($cart[$itemId])) {
        return false;
    }
    if ($quantity <= 0) {
        if ($u && $u["role"] === "Customer") {
            $s = $conn->prepare(
                "DELETE FROM cart_items WHERE user_id=? AND menu_item_id=?",
            );
            $s->bind_param("ii", $u["id"], $itemId);
            return $s->execute();
        }
        unset($_SESSION["cart"][$itemId]);
        return true;
    }
    $s = $conn->prepare("SELECT stock,is_available FROM menu_items WHERE id=?");
    $s->bind_param("i", $itemId);
    $s->execute();
    $item = $s->get_result()->fetch_assoc();
    if (
        !$item ||
        !(int) $item["is_available"] ||
        (int) $item["stock"] < $quantity
    ) {
        return false;
    }
    $quantity = min(99, $quantity);
    if ($u && $u["role"] === "Customer") {
        $s = $conn->prepare(
            "UPDATE cart_items SET quantity=? WHERE user_id=? AND menu_item_id=?",
        );
        $s->bind_param("iii", $quantity, $u["id"], $itemId);
        return $s->execute();
    }
    $_SESSION["cart"][$itemId]["quantity"] = $quantity;
    return true;
}
function cart_clear(mysqli $conn): void
{
    $u = user();
    if ($u && $u["role"] === "Customer") {
        $s = $conn->prepare("DELETE FROM cart_items WHERE user_id=?");
        $s->bind_param("i", $u["id"]);
        $s->execute();
    } else {
        unset($_SESSION["cart"]);
    }
}
function merge_guest_cart(mysqli $conn, int $userId): void
{
    $guest = $_SESSION["cart"] ?? [];
    foreach ($guest as $itemId => $line) {
        $s = $conn->prepare(
            "SELECT stock,restaurant_id FROM menu_items WHERE id=? AND is_available=1",
        );
        $s->bind_param("i", $itemId);
        $s->execute();
        $item = $s->get_result()->fetch_assoc();
        if (!$item || $item["restaurant_id"] !== (int) $line["restaurant_id"]) {
            continue;
        }
        $existing = current_cart($conn);
        $compatible = true;
        foreach ($existing as $current) {
            if (
                (int) $current["restaurant_id"] !== (int) $item["restaurant_id"]
            ) {
                $compatible = false;
                break;
            }
        }
        if (!$compatible) {
            continue;
        }
        $qty = min((int) $item["stock"], (int) $line["quantity"]);
        if ($qty > 0) {
            $i = (int) $itemId;
            $s = $conn->prepare(
                "INSERT INTO cart_items(user_id,menu_item_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=LEAST(quantity+VALUES(quantity),99)",
            );
            $s->bind_param("iii", $userId, $i, $qty);
            $s->execute();
        }
    }
    unset($_SESSION["cart"]);
}
function store_food_image(array $file): ?string
{
    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (
        ($file["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK ||
        ($file["size"] ?? 0) > 3 * 1024 * 1024
    ) {
        throw new RuntimeException("Choose a valid image up to 3 MB.");
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file["tmp_name"] ?? "");
    $types = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
    ];
    if (!isset($types[$mime]) || !@getimagesize($file["tmp_name"])) {
        throw new RuntimeException(
            "Only JPEG, PNG and WebP images are accepted.",
        );
    }
    $info = getimagesize($file["tmp_name"]);
    if (!$info || $info[0] > 6000 || $info[1] > 6000) {
        throw new RuntimeException(
            "Image dimensions must be 6000 × 6000 pixels or smaller.",
        );
    }
    $directory = __DIR__ . "/uploads/menu";
    if (
        !is_dir($directory) &&
        !mkdir($directory, 0755, true) &&
        !is_dir($directory)
    ) {
        throw new RuntimeException("Image storage is unavailable.");
    }
    $filename = bin2hex(random_bytes(18)) . "." . $types[$mime];
    if (!move_uploaded_file($file["tmp_name"], $directory . "/" . $filename)) {
        throw new RuntimeException("The image could not be saved.");
    }
    return "uploads/menu/" . $filename;
}
function claim_delivery(mysqli $conn, int $orderId, int $driverId): bool
{
    $conn->begin_transaction();
    try {
        $s = $conn->prepare(
            "SELECT id,restaurant_id FROM orders WHERE id=? AND status='Ready for pickup' AND driver_id IS NULL FOR UPDATE",
        );
        $s->bind_param("i", $orderId);
        $s->execute();
        $order = $s->get_result()->fetch_assoc();
        if (!$order) {
            $conn->rollback();
            return false;
        }
        $s = $conn->prepare(
            "UPDATE orders SET driver_id=? WHERE id=? AND driver_id IS NULL AND status='Ready for pickup'",
        );
        $s->bind_param("ii", $driverId, $orderId);
        $s->execute();
        if ($s->affected_rows !== 1) {
            $conn->rollback();
            return false;
        }
        $action = "Claimed";
        $s = $conn->prepare(
            "INSERT INTO delivery_assignments(order_id,driver_id,action) VALUES(?,?,?)",
        );
        $s->bind_param("iis", $orderId, $driverId, $action);
        $s->execute();
        $s = $conn->prepare("SELECT owner_user_id FROM restaurants WHERE id=?");
        $s->bind_param("i", $order["restaurant_id"]);
        $s->execute();
        $owner = $s->get_result()->fetch_assoc();
        if ($owner && $owner["owner_user_id"]) {
            notify_user(
                $conn,
                (int) $owner["owner_user_id"],
                "Courier assigned",
                "A courier has accepted order #" . $orderId . ".",
                "restaurant.php#orders",
            );
        }
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Delivery assignment failed: " . $e->getMessage());
        return false;
    }
}
function release_delivery(mysqli $conn, int $orderId, int $driverId): bool
{
    $conn->begin_transaction();
    try {
        $s = $conn->prepare(
            "SELECT id FROM orders WHERE id=? AND driver_id=? AND status='Ready for pickup' FOR UPDATE",
        );
        $s->bind_param("ii", $orderId, $driverId);
        $s->execute();
        if (!$s->get_result()->num_rows) {
            $conn->rollback();
            return false;
        }
        $s = $conn->prepare(
            "UPDATE orders SET driver_id=NULL WHERE id=? AND driver_id=? AND status='Ready for pickup'",
        );
        $s->bind_param("ii", $orderId, $driverId);
        $s->execute();
        if ($s->affected_rows !== 1) {
            $conn->rollback();
            return false;
        }
        $action = "Released";
        $s = $conn->prepare(
            "INSERT INTO delivery_assignments(order_id,driver_id,action) VALUES(?,?,?)",
        );
        $s->bind_param("iis", $orderId, $driverId, $action);
        $s->execute();
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Delivery release failed: " . $e->getMessage());
        return false;
    }
}
function record_driver_location(
    mysqli $conn,
    int $orderId,
    int $driverId,
    float $latitude,
    float $longitude,
): bool {
    if (
        $latitude < -90 ||
        $latitude > 90 ||
        $longitude < -180 ||
        $longitude > 180
    ) {
        return false;
    }
    $s = $conn->prepare(
        "INSERT INTO delivery_locations(order_id,driver_id,latitude,longitude) SELECT id,?,?,? FROM orders WHERE id=? AND driver_id=? AND status IN ('Picked up','On the way')",
    );
    $s->bind_param(
        "iddii",
        $driverId,
        $latitude,
        $longitude,
        $orderId,
        $driverId,
    );
    $s->execute();
    return $s->affected_rows === 1;
}
function record_status(
    mysqli $conn,
    int $orderId,
    int $actorId,
    string $status,
    string $reason = "",
): void {
    $s = $conn->prepare(
        "INSERT INTO order_status_history(order_id,status,changed_by) VALUES(?,?,?)",
    );
    $s->bind_param("isi", $orderId, $status, $actorId);
    $s->execute();
    $s = $conn->prepare(
        "SELECT customer_id,restaurant_id,driver_id FROM orders WHERE id=?",
    );
    $s->bind_param("i", $orderId);
    $s->execute();
    $o = $s->get_result()->fetch_assoc();
    if (!$o) {
        return;
    }
    $message =
        $reason !== ""
            ? "Order #" . $orderId . " is " . $status . ": " . $reason
            : "Order #" . $orderId . " is now " . $status . ".";
    notify_user(
        $conn,
        (int) $o["customer_id"],
        "Order update",
        $message,
        "orders.php",
    );
    $s = $conn->prepare("SELECT owner_user_id FROM restaurants WHERE id=?");
    $s->bind_param("i", $o["restaurant_id"]);
    $s->execute();
    $owner = $s->get_result()->fetch_assoc();
    if ($owner && $owner["owner_user_id"]) {
        notify_user(
            $conn,
            (int) $owner["owner_user_id"],
            "Order update",
            $message,
            "restaurant.php",
        );
    }
    if ($o["driver_id"]) {
        notify_user(
            $conn,
            (int) $o["driver_id"],
            "Delivery update",
            $message,
            "courier.php",
        );
    }
}
function transition_order(
    mysqli $conn,
    int $orderId,
    int $actorId,
    string $actorRole,
    string $next,
    string $reason = "",
): bool {
    $map = [
        "Restaurant" => [
            "Placed" => ["Accepted", "Cancelled"],
            "Accepted" => ["Preparing", "Cancelled"],
            "Preparing" => ["Ready for pickup"],
        ],
        "Driver" => [
            "Ready for pickup" => ["Picked up"],
            "Picked up" => ["On the way"],
            "On the way" => ["Delivered"],
        ],
        "Customer" => ["Placed" => ["Cancelled"]],
    ];
    $conn->begin_transaction();
    try {
        $s = $conn->prepare("SELECT * FROM orders WHERE id=? FOR UPDATE");
        $s->bind_param("i", $orderId);
        $s->execute();
        $order = $s->get_result()->fetch_assoc();
        if (!$order) {
            $conn->rollback();
            return false;
        }
        if ($actorRole === "Restaurant") {
            $s = $conn->prepare(
                "SELECT id FROM restaurants WHERE id=? AND owner_user_id=?",
            );
            $s->bind_param("ii", $order["restaurant_id"], $actorId);
            $s->execute();
            if (!$s->get_result()->num_rows) {
                $conn->rollback();
                return false;
            }
        }
        if (
            $actorRole === "Customer" &&
            (int) $order["customer_id"] !== $actorId
        ) {
            $conn->rollback();
            return false;
        }
        if ($actorRole === "Driver" && (int) $order["driver_id"] !== $actorId) {
            $conn->rollback();
            return false;
        }
        if ($next === $order["status"]) {
            $conn->rollback();
            return false;
        }
        if ($actorRole === "Admin" && strlen($reason) < 5) {
            $conn->rollback();
            return false;
        }
        if ($actorRole === "Admin") {
            $allowed = [
                "Placed",
                "Accepted",
                "Preparing",
                "Ready for pickup",
                "Picked up",
                "On the way",
                "Delivered",
                "Cancelled",
            ];
            if (
                in_array($order["status"], ["Delivered", "Cancelled"], true) ||
                !in_array($next, $allowed, true)
            ) {
                $conn->rollback();
                return false;
            }
        } elseif (
            !in_array($next, $map[$actorRole][$order["status"]] ?? [], true)
        ) {
            $conn->rollback();
            return false;
        }
        if ($next === "Cancelled" && trim($reason) === "") {
            $conn->rollback();
            return false;
        }
        if ($next === "Cancelled") {
            $restore = $conn->prepare(
                "SELECT menu_item_id,quantity FROM order_items WHERE order_id=? FOR UPDATE",
            );
            $restore->bind_param("i", $orderId);
            $restore->execute();
            $lines = $restore->get_result();
            $add = $conn->prepare(
                "UPDATE menu_items SET stock=stock+? WHERE id=?",
            );
            while ($line = $lines->fetch_assoc()) {
                if ($line["menu_item_id"]) {
                    $qty = (int) $line["quantity"];
                    $menu = (int) $line["menu_item_id"];
                    $add->bind_param("ii", $qty, $menu);
                    $add->execute();
                }
            }
        }
        $s = $conn->prepare(
            "UPDATE orders SET status=?,cancellation_reason=? WHERE id=?",
        );
        $s->bind_param("ssi", $next, $reason, $orderId);
        $s->execute();
        record_status($conn, $orderId, $actorId, $next, $reason);
        if ($actorRole === "Admin") {
            $type = "order";
            $details = "Status changed to " . $next . "; reason: " . $reason;
            $s = $conn->prepare(
                "INSERT INTO admin_activity_log(admin_id,action,entity_type,entity_id,details) VALUES(?,?,?,?,?)",
            );
            $s->bind_param("issss", $actorId, $next, $type, $orderId, $details);
            $s->execute();
        }
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Order transition failed: " . $e->getMessage());
        return false;
    }
}
