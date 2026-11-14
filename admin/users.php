<?php
require "../db.php";
require_role(["Admin"]);
$me = user();
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $id = (int) ($_POST["user_id"] ?? 0);
    $action = $_POST["action"] ?? "";
    if ($id === $me["id"]) {
        $error = "You cannot change your own access from this screen.";
    } else {
        $s = $conn->prepare(
            "SELECT u.UserID,u.IsActive,r.RoleName FROM users u JOIN roles r ON r.RoleID=u.RoleID WHERE u.UserID=?",
        );
        $s->bind_param("i", $id);
        $s->execute();
        $target = $s->get_result()->fetch_assoc();
        if (!$target) {
            $error = "Account not found.";
        } elseif ($action === "role") {
            $roleId = (int) ($_POST["role_id"] ?? 0);
            $s = $conn->prepare("SELECT RoleName FROM roles WHERE RoleID=?");
            $s->bind_param("i", $roleId);
            $s->execute();
            $role = $s->get_result()->fetch_assoc();
            if (!$role) {
                $error = "Choose a valid role.";
            } elseif ($role["RoleName"] === "Restaurant") {
                $s = $conn->prepare(
                    "SELECT id FROM restaurants WHERE owner_user_id=?",
                );
                $s->bind_param("i", $id);
                $s->execute();
                if (!$s->get_result()->num_rows) {
                    $error =
                        "Approve this account’s restaurant application before assigning the Restaurant role.";
                }
            }
            if (!$error) {
                $s = $conn->prepare("UPDATE users SET RoleID=? WHERE UserID=?");
                $s->bind_param("ii", $roleId, $id);
                $s->execute();
                $log = "Role changed to " . $role["RoleName"];
                $details = $log;
                $type = "user";
                $entity = (string) $id;
                $s = $conn->prepare(
                    "INSERT INTO admin_activity_log(admin_id,action,entity_type,entity_id,details) VALUES(?,?,?,?,?)",
                );
                $s->bind_param(
                    "issss",
                    $me["id"],
                    $log,
                    $type,
                    $entity,
                    $details,
                );
                $s->execute();
                notify_user(
                    $conn,
                    $id,
                    "Account role updated",
                    $log . ".",
                    "account.php",
                );
                flash($log . ".");
                header("Location: users.php");
                exit();
            }
        } elseif ($action === "active") {
            $active = (int) !((int) $target["IsActive"]);
            if ($target["RoleName"] === "Admin" && !$active) {
                $s = $conn->prepare(
                    "SELECT COUNT(*) n FROM users u JOIN roles r ON r.RoleID=u.RoleID WHERE r.RoleName='Admin' AND u.IsActive=1",
                );
                $s->execute();
                if ((int) $s->get_result()->fetch_assoc()["n"] <= 1) {
                    $error =
                        "The last active administrator cannot be deactivated.";
                }
            }
            if (!$error) {
                $s = $conn->prepare(
                    "UPDATE users SET IsActive=? WHERE UserID=?",
                );
                $s->bind_param("ii", $active, $id);
                $s->execute();
                $actionText = $active
                    ? "Account activated"
                    : "Account deactivated";
                $type = "user";
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
                notify_user(
                    $conn,
                    $id,
                    "Account status changed",
                    $actionText . ".",
                    "account.php",
                );
                flash($actionText . ".");
                header("Location: users.php");
                exit();
            }
        }
    }
}
$q = trim($_GET["q"] ?? "");
$roleFilter = (int) ($_GET["role"] ?? 0);
$page = max(1, (int) ($_GET["page"] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;
$where = [];
$params = [];
if ($q !== "") {
    $where[] = "(u.UserName LIKE ? OR u.FullName LIKE ? OR u.Email LIKE ?)";
    $like = "%" . $q . "%";
    array_push($params, $like, $like, $like);
}
if ($roleFilter) {
    $where[] = "u.RoleID=?";
    $params[] = $roleFilter;
}
$clause = $where ? "WHERE " . implode(" AND ", $where) : "";
$paramsTotal = $params;
$count = $conn->prepare("SELECT COUNT(*) n FROM users u $clause");
if ($paramsTotal) {
    execute_params($count, $paramsTotal);
} else {
    $count->execute();
}
$total = (int) $count->get_result()->fetch_assoc()["n"];
$params[] = $limit;
$params[] = $offset;
$list = $conn->prepare(
    "SELECT u.UserID,u.UserName,u.FullName,u.Email,u.PhoneNumber,u.IsActive,u.CreatedAt,r.RoleID,r.RoleName FROM users u JOIN roles r ON r.RoleID=u.RoleID $clause ORDER BY u.UserID DESC LIMIT ? OFFSET ?",
);
execute_params($list, $params);
$users = $list->get_result();
$roles = $conn
    ->query("SELECT RoleID,RoleName FROM roles ORDER BY RoleID")
    ->fetch_all(MYSQLI_ASSOC);
$msg = take_flash();
page_start("User management", "admin");
?><section class="section-heading"><div><p class="eyebrow">PLATFORM ACCESS</p><h2>Users</h2><p>Find accounts, manage roles, and deactivate access when needed.</p></div></section><?php
if ($msg): ?><div class="flash flash-success" role="status"><?= h(
    $msg["message"],
) ?></div><?php endif;
if ($error): ?><div class="flash flash-error" role="alert"><?= h(
    $error,
) ?></div><?php endif;
?><form method="get" class="panel filter-bar"><label for="q">Search</label><input class="input" id="q" name="q" value="<?= h(
    $q,
) ?>" placeholder="Name, username or email"><label for="role">Role</label><select class="select" id="role" name="role"><option value="0">All roles</option><?php foreach (
    $roles
    as $role
): ?><option value="<?= $role["RoleID"] ?>" <?= $roleFilter === $role["RoleID"]
    ? "selected"
    : "" ?>><?= h(
    $role["RoleName"],
) ?></option><?php endforeach; ?></select><button class="button button-sm">Apply</button></form><section class="panel"><p class="muted"><?= $total ?> account<?= $total ===
 1
     ? ""
     : "s" ?> found. Page <?= $page ?>.</p><?php
if (
    !$users->num_rows
): ?><div class="empty-state"><strong>No accounts match</strong>Try a different search or role.</div><?php else: ?><div class="table-scroll"><table><thead><tr><th>User</th><th>Contact</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead><tbody><?php while (
    $u = $users->fetch_assoc()
): ?><tr><td><?= h(
    $u["FullName"] ?: $u["UserName"],
) ?><div class="muted small">@<?= h($u["UserName"]) ?></div></td><td><?= h(
    $u["Email"],
) ?><div class="muted small"><?= h(
    $u["PhoneNumber"],
) ?></div></td><td><?php if (
    $u["UserID"] !== $me["id"]
): ?><form method="post" class="row"><?php csrf_field(); ?><input type="hidden" name="user_id" value="<?= $u[
    "UserID"
] ?>"><select class="select" name="role_id" aria-label="Role for <?= h(
    $u["UserName"],
) ?>"><?php foreach ($roles as $role): ?><option value="<?= $role[
    "RoleID"
] ?>" <?= $role["RoleID"] === $u["RoleID"] ? "selected" : "" ?>><?= h(
    $role["RoleName"],
) ?></option><?php endforeach; ?></select><button class="button button-sm" name="action" value="role">Save</button></form><?php else: ?><span class="badge">Your Admin account</span><?php endif; ?></td><td><span class="badge <?= $u[
    "IsActive"
]
    ? "badge-good"
    : "badge-bad" ?>"><?= $u["IsActive"]
    ? "Active"
    : "Deactivated" ?></span></td><td><?= h(
    $u["CreatedAt"],
) ?></td><td><?php if (
    $u["UserID"] !== $me["id"]
): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="user_id" value="<?= $u[
    "UserID"
] ?>"><button class="button button-sm <?= $u["IsActive"]
    ? "button-danger"
    : "button-quiet" ?>" name="action" value="active" data-confirm="Change this account's sign-in access?"><?= $u[
    "IsActive"
]
    ? "Deactivate"
    : "Activate" ?></button></form><?php else: ?><span class="muted">Protected</span><?php endif; ?></td></tr><?php endwhile; ?></tbody></table></div><?php endif;
if (
    $total > $limit
): ?><nav class="pagination" aria-label="User pages"><?php if (
    $page > 1
): ?><a href="?q=<?= urlencode($q) ?>&role=<?= $roleFilter ?>&page=<?= $page -
    1 ?>">Previous</a><?php endif; ?><span class="current">Page <?= $page ?></span><?php if (
    $offset + $limit <
    $total
): ?><a href="?q=<?= urlencode($q) ?>&role=<?= $roleFilter ?>&page=<?= $page +
    1 ?>">Next</a><?php endif; ?></nav><?php endif;
?></section><?php page_end();
