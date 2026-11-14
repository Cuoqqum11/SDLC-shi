<?php
$dbName = getenv("FOOD_DB_NAME") ?: "";
if (!str_contains($dbName, "phase2_")) {
    fwrite(
        STDERR,
        "Set FOOD_DB_NAME to a disposable database whose name contains phase2_.\n",
    );
    exit(2);
}
require __DIR__ . "/../db.php";
$checks = 0;
$exitCode = 0;
$createdOrders = [];
$createdUsers = [];
$createdRestaurants = [];
$createdMenus = [];
$token = bin2hex(random_bytes(4));
function check(bool $ok, string $label): void
{
    global $checks;
    $checks++;
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    echo "PASS: " . $label, PHP_EOL;
}
function make_user(
    mysqli $db,
    string $prefix,
    string $role,
    int $roleId,
    string $token,
    array &$ids,
): int {
    $name = $prefix . "_" . $token;
    $email = $name . "@example.test";
    $hash = password_hash("TestPassword!123", PASSWORD_DEFAULT);
    $s = $db->prepare(
        "INSERT INTO users(FullName,UserName,Email,Password,RoleID,IsActive) VALUES(?,?,?,?,?,1)",
    );
    $s->bind_param("ssssi", $name, $name, $email, $hash, $roleId);
    $s->execute();
    $id = $db->insert_id;
    $ids[] = $id;
    return $id;
}
function make_order(
    mysqli $db,
    int $customer,
    int $restaurant,
    int $menuId,
): int {
    $s = $db->prepare(
        "INSERT INTO orders(customer_id,restaurant_id,status,subtotal,delivery_fee,total,payment_method,payment_status,delivery_address) VALUES(?,?,'Placed',59000,0,59000,'Cash','Cash due','123 Testing Avenue')",
    );
    $s->bind_param("ii", $customer, $restaurant);
    $s->execute();
    $id = $db->insert_id;
    $name = "Smoke test dish";
    $price = 59000;
    $qty = 1;
    $s = $db->prepare(
        "INSERT INTO order_items(order_id,menu_item_id,item_name,unit_price,quantity) VALUES(?,?,?,?,?)",
    );
    $s->bind_param("iisdi", $id, $menuId, $name, $price, $qty);
    $s->execute();
    $s = $db->prepare(
        "UPDATE menu_items SET stock=stock-1 WHERE id=? AND stock>0",
    );
    $s->bind_param("i", $menuId);
    $s->execute();
    return $id;
}
try {
    $roles = [];
    foreach ($conn->query("SELECT RoleName,RoleID FROM roles") as $r) {
        $roles[$r["RoleName"]] = (int) $r["RoleID"];
    }
    $customer = make_user(
        $conn,
        "phase2_customer",
        "Customer",
        $roles["Customer"],
        $token,
        $createdUsers,
    );
    $customer2 = make_user(
        $conn,
        "phase2_customer2",
        "Customer",
        $roles["Customer"],
        $token,
        $createdUsers,
    );
    $owner = make_user(
        $conn,
        "phase2_owner",
        "Restaurant",
        $roles["Restaurant"],
        $token,
        $createdUsers,
    );
    $otherOwner = make_user(
        $conn,
        "phase2_other_owner",
        "Restaurant",
        $roles["Restaurant"],
        $token,
        $createdUsers,
    );
    $driver = make_user(
        $conn,
        "phase2_driver",
        "Driver",
        $roles["Driver"],
        $token,
        $createdUsers,
    );
    $driver2 = make_user(
        $conn,
        "phase2_driver2",
        "Driver",
        $roles["Driver"],
        $token,
        $createdUsers,
    );
    $admin = make_user(
        $conn,
        "phase2_admin",
        "Admin",
        $roles["Admin"],
        $token,
        $createdUsers,
    );
    $s = $conn->prepare(
        "INSERT INTO restaurants(owner_user_id,name,cuisine,address,delivery_fee,is_open,approval_status) VALUES(?,?,'Test kitchen','Testing address',0,1,'Approved')",
    );
    $rname = "Smoke kitchen " . $token;
    $s->bind_param("is", $owner, $rname);
    $s->execute();
    $restaurant = (int) $conn->insert_id;
    $createdRestaurants[] = $restaurant;
    $rname2 = "Other smoke kitchen " . $token;
    $s->bind_param("is", $otherOwner, $rname2);
    $s->execute();
    $otherRestaurant = (int) $conn->insert_id;
    $createdRestaurants[] = $otherRestaurant;
    $category = (int) $conn
        ->query("SELECT id FROM categories WHERE slug='popular' LIMIT 1")
        ->fetch_assoc()["id"];
    $dish = "Smoke food " . $token;
    $price = 59000;
    $stock = 10;
    $available = 1;
    $s = $conn->prepare(
        "INSERT INTO menu_items(restaurant_id,category_id,name,price,stock,is_available) VALUES(?,?,?,?,?,?)",
    );
    $s->bind_param(
        "iisdii",
        $restaurant,
        $category,
        $dish,
        $price,
        $stock,
        $available,
    );
    $s->execute();
    $menu = (int) $conn->insert_id;
    $createdMenus[] = $menu;
    $secondDish = "Smoke other food " . $token;
    $s->bind_param(
        "iisdii",
        $otherRestaurant,
        $category,
        $secondDish,
        $price,
        $stock,
        $available,
    );
    $s->execute();
    $secondMenu = (int) $conn->insert_id;
    $createdMenus[] = $secondMenu;
    $_SESSION["user_id"] = $customer;
    $_SESSION["username"] = "test";
    $_SESSION["full_name"] = "Smoke Customer";
    $_SESSION["role"] = "Customer";
    check(
        cart_add($conn, $menu, 1),
        "Customer can add available food to persistent cart",
    );
    check(cart_set($conn, $menu, 3), "Customer can update cart quantity");
    check(
        !cart_add($conn, $secondMenu, 1),
        "Cart rejects items from another restaurant",
    );
    check(cart_set($conn, $menu, 0), "Customer can remove an item from cart");
    check(
        count(current_cart($conn)) === 0,
        "Removing last item leaves an empty cart",
    );
    $stockBefore = (int) $conn
        ->query("SELECT stock FROM menu_items WHERE id=$menu")
        ->fetch_assoc()["stock"];
    $cancelOrder = make_order($conn, $customer, $restaurant, $menu);
    $createdOrders[] = $cancelOrder;
    check(
        !transition_order(
            $conn,
            $cancelOrder,
            $customer2,
            "Customer",
            "Cancelled",
            "wrong account",
        ),
        'Customer cannot cancel another customer\'s order',
    );
    check(
        transition_order(
            $conn,
            $cancelOrder,
            $customer,
            "Customer",
            "Cancelled",
            "Testing cancellation",
        ),
        "Customer can cancel before restaurant acceptance",
    );
    $stockAfter = (int) $conn
        ->query("SELECT stock FROM menu_items WHERE id=$menu")
        ->fetch_assoc()["stock"];
    check(
        $stockAfter === $stockBefore,
        "Cancellation restores the reserved stock exactly once",
    );
    check(
        !transition_order(
            $conn,
            $cancelOrder,
            $customer,
            "Customer",
            "Cancelled",
            "second cancellation",
        ),
        "Terminal order cannot be cancelled twice",
    );
    $workflowOrder = make_order($conn, $customer, $restaurant, $menu);
    $createdOrders[] = $workflowOrder;
    check(
        !transition_order(
            $conn,
            $workflowOrder,
            $owner,
            "Restaurant",
            "Ready for pickup",
        ),
        "Restaurant cannot skip required order states",
    );
    check(
        !transition_order(
            $conn,
            $workflowOrder,
            $otherOwner,
            "Restaurant",
            "Accepted",
        ),
        'Restaurant cannot update another restaurant\'s order',
    );
    check(
        transition_order(
            $conn,
            $workflowOrder,
            $owner,
            "Restaurant",
            "Accepted",
        ),
        "Restaurant accepts its own order",
    );
    check(
        transition_order(
            $conn,
            $workflowOrder,
            $owner,
            "Restaurant",
            "Preparing",
        ),
        "Restaurant starts preparation",
    );
    check(
        transition_order(
            $conn,
            $workflowOrder,
            $owner,
            "Restaurant",
            "Ready for pickup",
        ),
        "Restaurant marks order ready",
    );
    check(
        claim_delivery($conn, $workflowOrder, $driver),
        "Courier can atomically claim a ready order",
    );
    check(
        !claim_delivery($conn, $workflowOrder, $driver2),
        "A second courier cannot claim the same order",
    );
    check(
        !transition_order(
            $conn,
            $workflowOrder,
            $driver2,
            "Driver",
            "Picked up",
        ),
        'Courier cannot update another courier\'s delivery',
    );
    check(
        transition_order($conn, $workflowOrder, $driver, "Driver", "Picked up"),
        "Assigned courier confirms pickup",
    );
    check(
        transition_order(
            $conn,
            $workflowOrder,
            $driver,
            "Driver",
            "On the way",
        ),
        "Courier advances delivery to on the way",
    );
    check(
        record_driver_location(
            $conn,
            $workflowOrder,
            $driver,
            10.7769,
            106.7009,
        ),
        "Courier can save valid GPS while delivery is active",
    );
    check(
        !record_driver_location($conn, $workflowOrder, $driver, 91, 181),
        "GPS input rejects out-of-range coordinates",
    );
    check(
        transition_order($conn, $workflowOrder, $driver, "Driver", "Delivered"),
        "Assigned courier completes delivery",
    );
    check(
        !record_driver_location($conn, $workflowOrder, $driver, 10.77, 106.7),
        "GPS updates stop after delivery is complete",
    );
    $review = 5;
    $comment = "Smoke-test review";
    $s = $conn->prepare(
        "INSERT INTO reviews(order_id,customer_id,restaurant_id,rating,comment) VALUES(?,?,?,?,?)",
    );
    $s->bind_param(
        "iiiis",
        $workflowOrder,
        $customer,
        $restaurant,
        $review,
        $comment,
    );
    $s->execute();
    check(
        (int) $conn
            ->query(
                "SELECT COUNT(*) n FROM reviews WHERE order_id=$workflowOrder",
            )
            ->fetch_assoc()["n"] === 1,
        "Completed order review is stored",
    );
    try {
        $s->execute();
        $duplicateReview = false;
    } catch (mysqli_sql_exception $e) {
        $duplicateReview = $e->getCode() === 1062;
    }
    check($duplicateReview, "One review per completed order is enforced");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($createdOrders) {
        $ids = implode(",", array_map("intval", $createdOrders));
        $conn->query("DELETE FROM orders WHERE id IN ($ids)");
    }
    foreach ($createdMenus as $id) {
        $id = (int) $id;
        $conn->query("DELETE FROM menu_items WHERE id=$id");
    }
    foreach ($createdRestaurants as $id) {
        $id = (int) $id;
        $conn->query("DELETE FROM restaurants WHERE id=$id");
    }
    foreach ($createdUsers as $id) {
        $id = (int) $id;
        $conn->query("DELETE FROM users WHERE UserID=$id");
    }
}
if (!empty($exitCode)) {
    exit(1);
}
echo "All $checks database/service checks passed.\n";
