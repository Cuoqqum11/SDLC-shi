<?php
require "../db.php";
require_role(["Admin"]);
$me = user();
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $action = $_POST["action"] ?? "";
    if ($action === "application") {
        $id = (int) ($_POST["application_id"] ?? 0);
        $decision = $_POST["decision"] ?? "";
        if (!in_array($decision, ["Approved", "Rejected"], true)) {
            $error = "Invalid review decision.";
        } else {
            $conn->begin_transaction();
            try {
                $s = $conn->prepare(
                    "SELECT a.*,u.IsActive FROM restaurant_applications a JOIN users u ON u.UserID=a.user_id WHERE a.id=? AND a.status='Pending' FOR UPDATE",
                );
                $s->bind_param("i", $id);
                $s->execute();
                $app = $s->get_result()->fetch_assoc();
                if (!$app) {
                    throw new RuntimeException(
                        "This application was already reviewed.",
                    );
                }
                if ($decision === "Approved" && !(int) $app["IsActive"]) {
                    throw new RuntimeException(
                        "Activate the applicant account before approving.",
                    );
                }
                $s = $conn->prepare(
                    "UPDATE restaurant_applications SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='Pending'",
                );
                $s->bind_param("sii", $decision, $me["id"], $id);
                $s->execute();
                if ($decision === "Approved") {
                    $role = $conn
                        ->query(
                            "SELECT RoleID FROM roles WHERE RoleName='Restaurant'",
                        )
                        ->fetch_assoc();
                    $roleId = (int) $role["RoleID"];
                    $s = $conn->prepare(
                        "UPDATE users SET RoleID=? WHERE UserID=?",
                    );
                    $s->bind_param("ii", $roleId, $app["user_id"]);
                    $s->execute();
                    $s = $conn->prepare(
                        "INSERT INTO restaurants(owner_user_id,name,description,cuisine,address,phone,is_open,approval_status) VALUES(?,?,?,?,?,?,1,'Approved')",
                    );
                    $s->bind_param(
                        "isssss",
                        $app["user_id"],
                        $app["name"],
                        $app["description"],
                        $app["cuisine"],
                        $app["address"],
                        $app["phone"],
                    );
                    $s->execute();
                }
                $actionText = "Restaurant application " . $decision;
                $type = "application";
                $entity = (string) $id;
                $details = $actionText . " for " . $app["name"];
                $s = $conn->prepare(
                    "INSERT INTO admin_activity_log(admin_id,action,entity_type,entity_id,details) VALUES(?,?,?,?,?)",
                );
                $s->bind_param(
                    "issss",
                    $me["id"],
                    $actionText,
                    $type,
                    $entity,
                    $details,
                );
                $s->execute();
                notify_user(
                    $conn,
                    (int) $app["user_id"],
                    "Restaurant application " . $decision,
                    $decision === "Approved"
                        ? "Your restaurant is approved. Sign out and back in to open the Restaurant Studio."
                        : "Your application was not approved at this time. You may update your details and apply again.",
                    "account.php",
                );
                $conn->commit();
                flash($actionText . ".");
                header("Location: restaurants.php");
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $error =
                    $e instanceof RuntimeException
                        ? $e->getMessage()
                        : "Review could not be completed.";
                if (!($e instanceof RuntimeException)) {
                    error_log(
                        "Restaurant application review failed: " .
                            $e->getMessage(),
                    );
                }
            }
        }
    } elseif ($action === "status") {
        $id = (int) ($_POST["restaurant_id"] ?? 0);
        $status = $_POST["status"] ?? "";
        if (!in_array($status, ["Approved", "Suspended", "Rejected"], true)) {
            $error = "Invalid restaurant status.";
        } else {
            $s = $conn->prepare(
                'UPDATE restaurants SET approval_status=?,is_open=IF(?=\'Approved\',is_open,0) WHERE id=?',
            );
            $s->bind_param("ssi", $status, $status, $id);
            $s->execute();
            if ($s->affected_rows) {
                $s = $conn->prepare(
                    "SELECT owner_user_id FROM restaurants WHERE id=?",
                );
                $s->bind_param("i", $id);
                $s->execute();
                $owner = $s->get_result()->fetch_assoc();
                if ($owner && $owner["owner_user_id"]) {
                    notify_user(
                        $conn,
                        (int) $owner["owner_user_id"],
                        "Restaurant status changed",
                        "Restaurant status: " . $status . ".",
                        "restaurant.php",
                    );
                }
                $actionText = "Restaurant " . $status;
                $type = "restaurant";
                $entity = (string) $id;
                $details = $actionText;
                $s = $conn->prepare(
                    "INSERT INTO admin_activity_log(admin_id,action,entity_type,entity_id,details) VALUES(?,?,?,?,?)",
                );
                $s->bind_param(
                    "issss",
                    $me["id"],
                    $actionText,
                    $type,
                    $entity,
                    $details,
                );
                $s->execute();
                flash($actionText . ".");
                header("Location: restaurants.php");
                exit();
            }
        }
    }
}
$applications = $conn->query(
    "SELECT a.*,u.UserName,u.Email,u.IsActive FROM restaurant_applications a JOIN users u ON u.UserID=a.user_id WHERE a.status='Pending' ORDER BY a.created_at",
);
$restaurants = $conn->query(
    'SELECT r.*,u.UserName,u.Email FROM restaurants r LEFT JOIN users u ON u.UserID=r.owner_user_id ORDER BY FIELD(r.approval_status,\'Pending\',\'Suspended\',\'Approved\',\'Rejected\'),r.name',
);
$msg = take_flash();
page_start("Restaurant management", "admin");
?>
<section class="section-heading"><div><p class="eyebrow">MERCHANT ONBOARDING</p><h2>Restaurants</h2><p>Review applicants and keep the restaurant directory accurate.</p></div></section><?php
if ($msg): ?><div class="flash flash-success" role="status"><?= h(
    $msg["message"],
) ?></div><?php endif;
if ($error): ?><div class="flash flash-error" role="alert"><?= h(
    $error,
) ?></div><?php endif;
?>
<section class="section"><div class="section-heading"><div><h2>Applications waiting for review</h2><p>Only approved restaurants appear in customer discovery.</p></div></div><?php if (
    !$applications->num_rows
): ?><div class="empty-state"><strong>No applications waiting</strong>New partner applications will appear here.</div><?php else: ?><div class="stack"><?php while (
    $a = $applications->fetch_assoc()
): ?><article class="panel"><div class="row"><div><p class="eyebrow">APPLICATION #<?= $a[
    "id"
] ?> · <?= h($a["created_at"]) ?></p><h3><?= h($a["name"]) ?> · <?= h(
     $a["cuisine"],
 ) ?></h3><p><?= h($a["description"]) ?></p><p><?= h($a["address"]) ?> · <?= h(
     $a["phone"],
 ) ?></p><p class="muted">Applicant <?= h($a["UserName"]) ?> · <?= h(
     $a["Email"],
 ) ?> · <?= $a["IsActive"]
     ? "Active account"
     : "Inactive account" ?></p></div><span class="badge badge-warn">Pending</span></div><form method="post" class="row"><?php csrf_field(); ?><input type="hidden" name="application_id" value="<?= $a[
    "id"
] ?>"><button class="button" name="decision" value="Approved" <?= $a["IsActive"]
    ? ""
    : "disabled" ?>>Approve & create restaurant</button><button class="button button-danger" name="decision" value="Rejected">Reject application</button><input type="hidden" name="action" value="application"></form></article><?php endwhile; ?></div><?php endif; ?></section>
<section class="section"><div class="section-heading"><div><h2>Registered restaurants</h2></div></div><div class="stack"><?php while (
    $r = $restaurants->fetch_assoc()
): ?><article class="panel"><div class="row"><div><h3><?= h(
    $r["name"],
) ?></h3><p class="muted"><?= h($r["cuisine"]) ?> · Owner <?= h(
     $r["UserName"] ?? "Unassigned",
 ) ?> · <?= h($r["address"]) ?></p></div><span class="badge <?= $r[
    "approval_status"
] === "Approved"
    ? "badge-good"
    : ($r["approval_status"] === "Suspended"
        ? "badge-bad"
        : "badge-warn") ?>"><?= h(
    $r["approval_status"],
) ?></span></div><form method="post" class="row"><?php csrf_field(); ?><input type="hidden" name="restaurant_id" value="<?= $r[
    "id"
] ?>"><input type="hidden" name="action" value="status"><label class="small" for="status-<?= $r[
    "id"
] ?>">Status</label><select class="select" id="status-<?= $r[
    "id"
] ?>" name="status"><option value="Approved" <?= $r["approval_status"] ===
"Approved"
    ? "selected"
    : "" ?>>Approved / reactivate</option><option value="Suspended" <?= $r[
    "approval_status"
] === "Suspended"
    ? "selected"
    : "" ?>>Suspend</option><option value="Rejected" <?= $r[
    "approval_status"
] === "Rejected"
    ? "selected"
    : "" ?>>Reject</option></select><button class="button button-sm" data-confirm="Save this restaurant status?">Save</button></form></article><?php endwhile; ?></div></section><?php page_end();
