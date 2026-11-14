<?php
require dirname(__DIR__) . "/includes/db.php";
$me = user();
if ($me && in_array($me["role"], ["Restaurant", "Driver", "Admin"], true)) {
    header(
        "Location: " .
            match ($me["role"]) {
                "Restaurant" => "restaurant.php#orders",
                "Driver" => "courier.php",
                "Admin" => "admin/orders.php",
            },
    );
    exit();
}
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cart_action"])) {
    check_csrf();
    $itemId = (int) ($_POST["item_id"] ?? 0);
    $qty = (int) ($_POST["quantity"] ?? 0);
    $ok = cart_set($conn, $itemId, $qty);
    flash(
        $ok
            ? ($qty > 0
                ? "Cart updated."
                : "Item removed from your cart.")
            : "That item is no longer available in the requested quantity.",
        "warning",
    );
    header("Location: orders.php#cart");
    exit();
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["checkout"])) {
    require_role(["Customer"]);
    check_csrf();
    $cart = current_cart($conn);
    $addressId = (int) ($_POST["address_id"] ?? 0);
    $recipient = trim($_POST["recipient_name"] ?? "");
    $phone = trim($_POST["recipient_phone"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $note = trim($_POST["note"] ?? "");
    $saveAddress = !empty($_POST["save_address"]);
    if (!$cart) {
        $error = "Your cart is empty.";
    } elseif (
        $addressId === 0 &&
        (strlen($address) < 8 || strlen($recipient) < 2 || strlen($phone) < 7)
    ) {
        $error =
            "Enter a recipient name, phone number and full delivery address.";
    } else {
        $conn->begin_transaction();
        try {
            if ($addressId > 0) {
                $s = $conn->prepare(
                    "SELECT recipient_name,phone,address_line FROM user_addresses WHERE id=? AND user_id=? FOR UPDATE",
                );
                $s->bind_param("ii", $addressId, $me["id"]);
                $s->execute();
                $saved = $s->get_result()->fetch_assoc();
                if (!$saved) {
                    throw new RuntimeException(
                        "Choose one of your saved delivery addresses.",
                    );
                }
                $recipient = $saved["recipient_name"];
                $phone = $saved["phone"];
                $address = $saved["address_line"];
            }
            $ids = array_map("intval", array_keys($cart));
            $marks = implode(",", array_fill(0, count($ids), "?"));
            $types = str_repeat("i", count($ids));
            $s = $conn->prepare(
                "SELECT m.id,m.name,m.price,m.stock,m.is_available,m.restaurant_id,r.delivery_fee,r.is_open,r.approval_status,r.opening_time,r.closing_time FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id WHERE m.id IN ($marks) FOR UPDATE",
            );
            $s->bind_param($types, ...$ids);
            $s->execute();
            $result = $s->get_result();
            $items = [];
            $subtotal = 0;
            $restaurantId = 0;
            $fee = 0;
            while ($item = $result->fetch_assoc()) {
                $quantity = (int) $cart[$item["id"]]["quantity"];
                if (
                    !(int) $item["is_available"] ||
                    $item["stock"] < $quantity ||
                    !restaurant_open_now($item)
                ) {
                    throw new RuntimeException(
                        "A cart item is unavailable, out of stock, or from a closed restaurant.",
                    );
                }
                if (
                    $restaurantId &&
                    $restaurantId !== (int) $item["restaurant_id"]
                ) {
                    throw new RuntimeException(
                        "A cart can only contain dishes from one restaurant.",
                    );
                }
                $restaurantId = (int) $item["restaurant_id"];
                $fee = (float) $item["delivery_fee"];
                $subtotal += (float) $item["price"] * $quantity;
                $items[] = [$item, $quantity];
            }
            if (count($items) !== count($cart)) {
                throw new RuntimeException(
                    "A cart item could not be found. Remove it and try again.",
                );
            }
            if ($addressId === 0 && $saveAddress) {
                $s = $conn->prepare(
                    'INSERT INTO user_addresses(user_id,recipient_name,phone,address_line,label,is_default) VALUES(?,?,?,?,\'Home\',0)',
                );
                $s->bind_param("isss", $me["id"], $recipient, $phone, $address);
                $s->execute();
                $addressId = $conn->insert_id;
            }
            $total = $subtotal + $fee;
            $payment = "Cash";
            $payStatus = "Cash due";
            $s = $conn->prepare(
                "INSERT INTO orders(customer_id,restaurant_id,status,subtotal,delivery_fee,total,payment_method,payment_status,delivery_address,recipient_name,recipient_phone,address_id,customer_note) VALUES(?,?,'Placed',?,? ,?,'Cash','Cash due',?,?,?,?,?)",
            );
            $s->bind_param(
                "iidddsssis",
                $me["id"],
                $restaurantId,
                $subtotal,
                $fee,
                $total,
                $address,
                $recipient,
                $phone,
                $addressId,
                $note,
            );
            $s->execute();
            $orderId = $conn->insert_id;
            $oi = $conn->prepare(
                "INSERT INTO order_items(order_id,menu_item_id,item_name,unit_price,quantity) VALUES(?,?,?,?,?)",
            );
            $stock = $conn->prepare(
                "UPDATE menu_items SET stock=stock-? WHERE id=? AND stock>=?",
            );
            foreach ($items as [$item, $quantity]) {
                $menuId = (int) $item["id"];
                $unitPrice = (float) $item["price"];
                $oi->bind_param(
                    "iisdi",
                    $orderId,
                    $menuId,
                    $item["name"],
                    $unitPrice,
                    $quantity,
                );
                $oi->execute();
                $stock->bind_param("iii", $quantity, $menuId, $quantity);
                $stock->execute();
                if ($stock->affected_rows !== 1) {
                    throw new RuntimeException(
                        "Stock changed while placing this order. Please retry.",
                    );
                }
            }
            record_status($conn, $orderId, $me["id"], "Placed");
            cart_clear($conn);
            $conn->commit();
            flash("Order #" . $orderId . " placed. Pay cash on delivery.");
            header("Location: order_detail.php?id=" . $orderId);
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                error_log("Checkout failed: " . $e->getMessage());
                $error = "We could not place your order. Please try again.";
            }
        }
    }
}
$cart = current_cart($conn);
$cartItems = [];
$subtotal = 0;
$fee = 0;
$restaurantName = "";
if ($cart) {
    $ids = array_map("intval", array_keys($cart));
    $marks = implode(",", array_fill(0, count($ids), "?"));
    $types = str_repeat("i", count($ids));
    $s = $conn->prepare(
        "SELECT m.*,r.name restaurant,r.delivery_fee,r.id restaurant_id FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id WHERE m.id IN ($marks)",
    );
    $s->bind_param($types, ...$ids);
    $s->execute();
    $rows = $s->get_result();
    while ($item = $rows->fetch_assoc()) {
        $qty = (int) $cart[$item["id"]]["quantity"];
        $item["cart_quantity"] = $qty;
        $subtotal += (float) $item["price"] * $qty;
        $fee = (float) $item["delivery_fee"];
        $restaurantName = $item["restaurant"];
        $cartItems[] = $item;
    }
}
$addresses = [];
if ($me && $me["role"] === "Customer") {
    $s = $conn->prepare(
        "SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC,id DESC",
    );
    $s->bind_param("i", $me["id"]);
    $s->execute();
    $addresses = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s = $conn->prepare(
        "SELECT o.id,o.restaurant_id,o.status,o.total,o.payment_method,o.payment_status,o.created_at,r.name restaurant FROM orders o JOIN restaurants r ON r.id=o.restaurant_id WHERE o.customer_id=? ORDER BY o.created_at DESC LIMIT 30",
    );
    $s->bind_param("i", $me["id"]);
    $s->execute();
    $orders = $s->get_result();
}
page_start("Your cart and orders");
?><section class="section" id="cart"><div class="section-heading"><div><p class="eyebrow">YOUR NEXT MEAL</p><h2>Your cart</h2><p><?= h(
    $restaurantName ?: "A good meal is only a few steps away.",
) ?></p></div><a href="index.php">Continue browsing →</a></div><?php if (
    $error
): ?><div class="flash flash-error" role="alert"><?= h(
    $error,
) ?></div><?php endif; ?>
<?php if (
    !$cartItems
): ?><div class="empty-state"><strong>Your cart is waiting for something delicious</strong>Browse restaurants and add a dish to get started.<p><a class="button" href="index.php">Explore restaurants</a></p></div><?php else: ?><div class="two-col"><div class="stack"><?php foreach (
    $cartItems
    as $item
): ?><article class="panel"><div class="row"><div style="display:flex;gap:14px;align-items:center"><img src="<?= h(
    $item["image_url"] ?:
    "https://images.unsplash.com/photo-1547592180-85f173990554?w=250&auto=format&fit=crop&q=80",
) ?>" alt="<?= h(
    $item["name"],
) ?>" style="width:90px;height:80px;object-fit:cover;border-radius:12px"><div><strong><?= h(
    $item["name"],
) ?></strong><p class="muted small"><?= money(
    $item["price"],
) ?> each</p><span class="small muted">Available stock: <?= $item[
     "stock"
 ] ?></span></div></div><strong><?= money(
    (float) $item["price"] * $item["cart_quantity"],
) ?></strong></div><div class="row" style="margin-top:12px"><form method="post" class="qty-control"><?php csrf_field(); ?><input type="hidden" name="item_id" value="<?= $item[
    "id"
] ?>"><label class="sr-only" for="qty-<?= $item["id"] ?>">Quantity for <?= h(
    $item["name"],
) ?></label><input id="qty-<?= $item[
    "id"
] ?>" type="number" min="0" max="<?= $item[
    "stock"
] ?>" name="quantity" value="<?= $item[
    "cart_quantity"
] ?>"><button class="button button-sm button-dark" name="cart_action" value="update">Update</button></form><form method="post"><?php csrf_field(); ?><input type="hidden" name="item_id" value="<?= $item[
    "id"
] ?>"><input type="hidden" name="quantity" value="0"><button class="button button-sm button-quiet" name="cart_action" value="remove">Remove</button></form></div></article><?php endforeach; ?></div><aside class="panel"><h2>Order summary</h2><div class="row"><span>Subtotal</span><strong><?= money(
    $subtotal,
) ?></strong></div><div class="row"><span>Delivery</span><strong><?= money(
    $fee,
) ?></strong></div><hr class="hr"><div class="row"><strong>Total</strong><strong><?= money(
    $subtotal + $fee,
) ?></strong></div><form method="post" style="margin-top:20px"><?php
csrf_field();
if (
    !$me
): ?><p class="muted">Sign in to complete checkout and save your cart.</p><a class="button" href="login.php">Sign in to checkout</a><?php else: ?><h3>Delivery details</h3><?php if (
    $addresses
): ?><div class="field-wrap"><label for="address_id">Saved address</label><select class="select" id="address_id" name="address_id"><option value="0">Enter a new address</option><?php foreach (
    $addresses
    as $a
): ?><option value="<?= $a["id"] ?>" <?= $a["is_default"]
    ? "selected"
    : "" ?>><?= h($a["label"]) ?> — <?= h(
     $a["address_line"],
 ) ?></option><?php endforeach; ?></select></div><?php else: ?><input type="hidden" name="address_id" value="0"><?php endif; ?><div data-new-address><div class="field-wrap"><label for="recipient_name">Recipient name</label><input class="input" name="recipient_name" id="recipient_name" autocomplete="name"></div><div class="field-wrap"><label for="recipient_phone">Contact phone</label><input class="input" name="recipient_phone" id="recipient_phone" type="tel" autocomplete="tel"></div><div class="field-wrap"><label for="address">Delivery address</label><input class="input" name="address" id="address" minlength="8" autocomplete="street-address"></div><label class="small"><input type="checkbox" name="save_address" value="1"> Save this address for next time</label></div><div class="field-wrap"><label for="note">Delivery notes (optional)</label><textarea class="textarea" id="note" name="note" maxlength="1000"></textarea></div><p class="help-text">Payment: Cash on Delivery. Online payment is not enabled.</p><button class="button" type="submit" name="checkout" value="1">Place order · <?= money(
    $subtotal + $fee,
) ?></button><?php endif;
?></form></aside></div><?php endif; ?></section>
<?php
if (
    $me &&
    $me["role"] === "Customer"
): ?><section class="section"><div class="section-heading"><div><p class="eyebrow">MEALDASH ORDERS</p><h2>Your recent orders</h2></div></div><?php if (
    !$orders->num_rows
): ?><div class="empty-state"><strong>No orders yet</strong>After checkout, your order updates will appear here.</div><?php else: ?><div class="stack"><?php while (
    $o = $orders->fetch_assoc()
): ?><article class="order-card panel"><div class="row"><div><p class="eyebrow">ORDER #<?= $o[
    "id"
] ?></p><h3><?= h($o["restaurant"]) ?></h3><p class="muted small"><?= h(
    $o["created_at"],
) ?> · <?= h($o["payment_method"]) ?> (<?= h(
     $o["payment_status"],
 ) ?>)</p></div><span class="badge <?= $o["status"] === "Delivered"
    ? "badge-good"
    : ($o["status"] === "Cancelled"
        ? "badge-bad"
        : "badge-warn") ?>"><?= h(
    $o["status"],
) ?></span></div><div class="row"><strong><?= money(
    $o["total"],
) ?></strong><a class="button button-sm" href="order_detail.php?id=<?= $o[
    "id"
] ?>">Track order</a></div></article><?php endwhile; ?></div><?php endif; ?></section><?php endif;
page_end();

