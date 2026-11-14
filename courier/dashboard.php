<?php
require dirname(__DIR__) . "/includes/db.php";
require_role(["Driver"]);
$me = user();
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $id = max(0, (int) ($_POST["order_id"] ?? 0));
    if (isset($_POST["claim"])) {
        $ok = claim_delivery($conn, $id, $me["id"]);
        flash(
            $ok
                ? "Delivery assigned to you."
                : "This delivery is already assigned or is no longer ready.",
            $ok ? "success" : "warning",
        );
    } elseif (isset($_POST["release"])) {
        $ok = release_delivery($conn, $id, $me["id"]);
        flash(
            $ok
                ? "Delivery returned to the available list."
                : "Only an assigned, not-yet-picked-up order can be released.",
            $ok ? "success" : "warning",
        );
    } elseif (isset($_POST["picked_up"])) {
        $ok = transition_order($conn, $id, $me["id"], "Driver", "Picked up");
        flash(
            $ok ? "Pickup confirmed." : "That status change is not allowed.",
            $ok ? "success" : "warning",
        );
    } elseif (isset($_POST["on_the_way"])) {
        $ok = transition_order($conn, $id, $me["id"], "Driver", "On the way");
        flash(
            $ok
                ? "Delivery marked on the way."
                : "That status change is not allowed.",
            $ok ? "success" : "warning",
        );
    } elseif (isset($_POST["deliver"])) {
        $ok = transition_order($conn, $id, $me["id"], "Driver", "Delivered");
        flash(
            $ok ? "Delivery completed." : "That status change is not allowed.",
            $ok ? "success" : "warning",
        );
    } elseif (isset($_POST["location"])) {
        $lat = filter_var($_POST["latitude"] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($_POST["longitude"] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false) {
            flash("The GPS coordinates were not valid.", "error");
        } else {
            flash(
                record_driver_location(
                    $conn,
                    $id,
                    $me["id"],
                    (float) $lat,
                    (float) $lng,
                )
                    ? "Location updated."
                    : "Location updates stop after a delivery is completed.",
                "warning",
            );
        }
    }
    header("Location: courier.php");
    exit();
}
$s = $conn->prepare(
    "SELECT COUNT(*) n FROM orders WHERE driver_id=? AND status='Delivered'",
);
$s->bind_param("i", $me["id"]);
$s->execute();
$completed = (int) $s->get_result()->fetch_assoc()["n"];
$s = $conn->prepare(
    "SELECT COUNT(*) n FROM orders WHERE driver_id=? AND status IN ('Picked up','On the way')",
);
$s->bind_param("i", $me["id"]);
$s->execute();
$active = (int) $s->get_result()->fetch_assoc()["n"];
$s = $conn->prepare(
    "SELECT COUNT(*) n FROM orders WHERE driver_id=? AND status='Ready for pickup'",
);
$s->bind_param("i", $me["id"]);
$s->execute();
$assigned = (int) $s->get_result()->fetch_assoc()["n"];
$available = (int) $conn
    ->query(
        "SELECT COUNT(*) n FROM orders WHERE status='Ready for pickup' AND driver_id IS NULL",
    )
    ->fetch_assoc()["n"];
$s = $conn->prepare(
    "SELECT o.*,r.name restaurant,r.address restaurant_address,r.phone restaurant_phone,u.FullName customer_name,u.PhoneNumber customer_phone,(SELECT dl.recorded_at FROM delivery_locations dl WHERE dl.order_id=o.id ORDER BY dl.recorded_at DESC LIMIT 1) location_updated FROM orders o JOIN restaurants r ON r.id=o.restaurant_id JOIN users u ON u.UserID=o.customer_id WHERE (o.status='Ready for pickup' AND o.driver_id IS NULL) OR (o.driver_id=? AND o.status IN ('Ready for pickup','Picked up','On the way','Delivered')) ORDER BY (o.driver_id=?) DESC,o.created_at DESC",
);
$s->bind_param("ii", $me["id"], $me["id"]);
$s->execute();
$jobs = $s->get_result();
$message = take_flash();
page_start("Courier deliveries", "courier");
?>
<section class="dashboard-hero"><h2>Make every delivery count.</h2><p>Claim a ready order, keep the customer updated and finish safely.</p></section><div class="stats-grid"><div class="stat-card"><span class="stat-icon">◎</span><div class="stat-label">Available now</div><div class="stat-value"><?= $available ?></div></div><div class="stat-card"><span class="stat-icon">↗</span><div class="stat-label">Assigned</div><div class="stat-value"><?= $assigned ?></div></div><div class="stat-card"><span class="stat-icon">↻</span><div class="stat-label">Active deliveries</div><div class="stat-value"><?= $active ?></div></div><div class="stat-card"><span class="stat-icon">✓</span><div class="stat-label">Completed</div><div class="stat-value"><?= $completed ?></div></div></div><p class="help-text">Earnings are not calculated in this version because a courier pay rate has not been defined.</p>
<section class="section"><div class="section-heading"><div><p class="eyebrow">YOUR WORK QUEUE</p><h2>Delivery opportunities & assignments</h2></div><a href="account.php">Edit profile →</a></div><?php
if ($message): ?><div class="flash flash-success" role="status"><?= h(
    $message["message"],
) ?></div><?php endif;
if (
    !$jobs->num_rows
): ?><div class="empty-state"><strong>No deliveries at the moment</strong>When a restaurant marks an order ready, it will appear here.</div><?php else: ?><div class="stack"><?php while (
    $o = $jobs->fetch_assoc()
):
    $mine =
        (int) $o["driver_id"] ===
        (int) $me[
            "id"
        ]; ?><article class="panel"><div class="row"><div><p class="eyebrow"><?= $mine
    ? "YOUR DELIVERY"
    : "AVAILABLE DELIVERY" ?></p><h2>Order #<?= $o["id"] ?> · <?= h(
     $o["restaurant"],
 ) ?></h2></div><span class="badge <?= $o["status"] === "Delivered"
    ? "badge-good"
    : ($o["status"] === "On the way"
        ? "badge-warn"
        : "") ?>"><?= h(
    $o["status"],
) ?></span></div><div class="two-col"><div><p><strong>Pickup</strong><br><?= h(
    $o["restaurant_address"],
) .
    ($o["restaurant_phone"]
        ? " · " . h($o["restaurant_phone"])
        : "") ?></p><?php if ($mine): ?><p><strong>Deliver to <?= h(
    $o["recipient_name"] ?: $o["customer_name"],
) ?></strong><br><?= h($o["delivery_address"]) ?><br><?= h(
    $o["recipient_phone"] ?: $o["customer_phone"],
) ?></p><?php else: ?><p><strong>Delivery destination</strong><br>Recipient details are shown after you accept.</p><?php endif; ?><p class="muted small">Order total: <?= money(
    $o["total"],
) ?> · <?= h($o["payment_method"]) ?></p></div><div><?php
if (
    !$mine
): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="order_id" value="<?= $o[
    "id"
] ?>"><button class="button delivery-action" name="claim" data-confirm="Accept delivery #<?= $o[
    "id"
] ?>?">Accept delivery</button></form><?php elseif (
    $o["status"] === "Ready for pickup"
): ?><div class="row"><form method="post"><?php csrf_field(); ?><input type="hidden" name="order_id" value="<?= $o[
    "id"
] ?>"><button class="button delivery-action" name="picked_up">Mark picked up</button></form><form method="post"><?php csrf_field(); ?><input type="hidden" name="order_id" value="<?= $o[
    "id"
] ?>"><button class="button button-quiet" name="release" data-confirm="Return this delivery to the available list?">Release</button></form></div><?php elseif (
    in_array($o["status"], ["Picked up", "On the way"], true)
):
    if (
        $o["status"] === "Picked up"
    ): ?><form method="post" class="row"><?php csrf_field(); ?><input type="hidden" name="order_id" value="<?= $o[
    "id"
] ?>"><button class="button button-quiet" name="on_the_way">Mark on the way</button></form><?php endif; ?><form method="post" class="row"><?php csrf_field(); ?><input type="hidden" name="order_id" value="<?= $o[
    "id"
] ?>"><button class="button delivery-action" name="deliver" data-confirm="Mark this delivery complete?">Complete delivery</button></form><button class="button button-quiet" type="button" data-location-order="<?= $o[
    "id"
] ?>">Update my location</button><p class="small muted">Location sharing is manual; this page does not send background updates.</p><form method="post" id="gps-<?= $o[
    "id"
] ?>" hidden><?php csrf_field(); ?><input type="hidden" name="location" value="1"><input type="hidden" name="order_id" value="<?= $o[
    "id"
] ?>"><input type="hidden" name="latitude"><input type="hidden" name="longitude"></form><?php
elseif (
    $o["status"] === "Delivered"
): ?><p class="badge badge-good">Delivered</p><?php endif;
if (
    $mine &&
    $o["location_updated"]
): ?><p class="small muted">Last GPS update: <?= h(
    $o["location_updated"],
) ?></p><?php endif;
?></div></div></article><?php
endwhile; ?></div><?php endif;
?></section><section class="panel"><h2>Location permission</h2><p class="muted">MealDash asks for location only when you press “Update my location.” If you deny access or GPS is unavailable, you can continue the delivery without a location update.</p><p class="small" role="status" data-gps-status></p></section>
<script>document.querySelectorAll('[data-location-order]').forEach(button=>button.addEventListener('click',()=>{const status=document.querySelector('[data-gps-status]');if(!navigator.geolocation){status.textContent='This browser does not provide location services.';return;}status.textContent='Requesting your current location…';navigator.geolocation.getCurrentPosition(position=>{const form=document.getElementById('gps-'+button.dataset.locationOrder);form.elements.latitude.value=position.coords.latitude;form.elements.longitude.value=position.coords.longitude;form.requestSubmit();},error=>{status.textContent=error.code===1?'Location permission was denied. You can continue without sharing it.':'Could not get a location fix. Try again outdoors or continue without one.';},{enableHighAccuracy:true,timeout:12000,maximumAge:30000});}));</script><?php page_end();
