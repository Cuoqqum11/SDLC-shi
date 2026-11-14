<?php
$dbName = getenv("FOOD_DB_NAME") ?: "";
if (!str_contains($dbName, "phase2_")) {
    fwrite(
        STDERR,
        "Refusing to seed a database not named with the phase2_ test marker.\n",
    );
    exit(2);
}
require __DIR__ . "/../db.php";
$password = "Phase2-QA-Only-2026!";
$roleIds = [];
foreach ($conn->query("SELECT RoleName,RoleID FROM roles") as $r) {
    $roleIds[$r["RoleName"]] = (int) $r["RoleID"];
}
$accounts = [];
$rolesForTest = [
    "qa_admin" => "Admin",
    "qa_restaurant" => "Restaurant",
    "qa_driver" => "Driver",
    "qa_other" => "Customer",
    "qa_other_restaurant" => "Restaurant",
];
foreach ($rolesForTest as $username => $role) {
    $email = $username . "@example.test";
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $name = ucwords(str_replace("_", " ", $username));
    $s = $conn->prepare(
        "INSERT INTO users(FullName,UserName,Email,Password,RoleID,IsActive) VALUES(?,?,?,?,?,1)",
    );
    $s->bind_param("ssssi", $name, $username, $email, $hash, $roleIds[$role]);
    $s->execute();
    $accounts[$username] = $conn->insert_id;
}
$restaurant = $conn
    ->query("SELECT id FROM restaurants WHERE name='Saigon Kitchen' LIMIT 1")
    ->fetch_assoc();
$restaurantId = (int) $restaurant["id"];
$owner = (int) $accounts["qa_restaurant"];
$s = $conn->prepare(
    'UPDATE restaurants SET owner_user_id=?,approval_status=\'Approved\',is_open=1 WHERE id=?',
);
$s->bind_param("ii", $owner, $restaurantId);
$s->execute();
$otherOwner = (int) $accounts["qa_other_restaurant"];
$secondName = "QA secondary restaurant";
$s = $conn->prepare(
    "INSERT INTO restaurants(owner_user_id,name,cuisine,address,is_open,approval_status) VALUES(?,?,'Testing','Secondary pickup address',1,'Approved')",
);
$s->bind_param("is", $otherOwner, $secondName);
$s->execute();
$secondRestaurant = (int) $conn->insert_id;
$conn->query(
    "UPDATE menu_items SET stock=50,is_available=1 WHERE restaurant_id=$restaurantId",
);
$dish = $conn
    ->query(
        "SELECT id FROM menu_items WHERE restaurant_id=$restaurantId ORDER BY id LIMIT 1",
    )
    ->fetch_assoc();
echo json_encode(
    [
        "password" => $password,
        "accounts" => $accounts,
        "restaurant_id" => $restaurantId,
        "menu_item_id" => (int) $dish["id"],
        "second_restaurant_id" => $secondRestaurant,
    ],
    JSON_PRETTY_PRINT,
),
    PHP_EOL;
