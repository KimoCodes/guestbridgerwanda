<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
$user = require_super_admin();
$pdo = db_connect();
$current_page = 'admin/users.php';

$search = trim($_GET['search'] ?? '');
$role_filter = trim($_GET['role'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%"]);
}
if ($role_filter !== '' && in_array($role_filter, ['super_admin', 'manager', 'receptionist', 'concierge'], true)) {
    $where .= ' AND u.role = ?';
    $params[] = $role_filter;
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $where");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT u.*, b.name AS business_name
    FROM users u
    LEFT JOIN businesses b ON b.id = u.business_id
    WHERE $where
    ORDER BY u.created_at DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_post();
    $target_user_id = (int)($_POST['user_id'] ?? 0);
    $new_role = $_POST['new_role'] ?? '';
    if ($target_user_id > 0 && in_array($new_role, ['super_admin', 'manager', 'receptionist', 'concierge'], true)) {
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$new_role, $target_user_id]);
        log_audit($pdo, (int)($user['business_id']), (int)$user['id'], 'role_changed', 'user', $target_user_id, null, ['role' => $new_role], $_SERVER['REMOTE_ADDR'] ?? null);
    }
    header("Location: users.php?updated=1");
    exit;
}

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, 0, $current_page, 1); ?>

<?php echo render_breadcrumbs(['Admin Dashboard' => 'index.php', 'Users' => false], 1); ?>
<?php echo render_page_header('Manage Users', "$total total user accounts on the platform."); ?>

<?php if (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">User role updated.</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="filter-bar">
            <div class="col-auto">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Name or email" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-auto">
                <label class="form-label">Role</label>
                <select name="role" class="form-select">
                    <option value="">All roles</option>
                    <option value="super_admin" <?php echo $role_filter === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                    <option value="manager" <?php echo $role_filter === 'manager' ? 'selected' : ''; ?>>Manager</option>
                    <option value="receptionist" <?php echo $role_filter === 'receptionist' ? 'selected' : ''; ?>>Receptionist</option>
                    <option value="concierge" <?php echo $role_filter === 'concierge' ? 'selected' : ''; ?>>Concierge</option>
                </select>
            </div>
            <div class="col-auto d-grid">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-modern table-hover align-middle mb-0">
                <thead>
                    <tr><th>User</th><th>Business</th><th>Role</th><th>Created</th><th>Change Role</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($u['name']); ?></strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($u['email']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($u['business_name'] ?? ''); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $u['role'] === 'super_admin' ? 'danger' : ($u['role'] === 'manager' ? 'primary' : 'secondary'); ?>">
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $u['role']))); ?>
                            </span>
                        </td>
                        <td><small><?php echo htmlspecialchars($u['created_at']); ?></small></td>
                        <td>
                            <form method="post" style="display:inline-flex; gap:4px; align-items:center;" onsubmit="return confirm('Change this user role?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                <select name="new_role" class="form-select form-select-sm" style="width:auto;">
                                    <option value="manager" <?php echo $u['role'] === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                    <option value="receptionist" <?php echo $u['role'] === 'receptionist' ? 'selected' : ''; ?>>Receptionist</option>
                                    <option value="concierge" <?php echo $u['role'] === 'concierge' ? 'selected' : ''; ?>>Concierge</option>
                                    <option value="super_admin" <?php echo $u['role'] === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination pagination-sm justify-content-center">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<?php echo render_app_shell_end(); ?>
</body>
</html>
