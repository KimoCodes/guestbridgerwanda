<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
$user = require_super_admin();
$pdo = db_connect();
$current_page = 'admin/audit_logs.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

$search = trim($_GET['search'] ?? '');
$action_filter = trim($_GET['action'] ?? '');

$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (u.name LIKE ? OR b.name LIKE ? OR al.action LIKE ? OR al.entity_type LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($action_filter !== '') {
    $where .= ' AND al.action = ?';
    $params[] = $action_filter;
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al JOIN users u ON u.id = al.user_id JOIN businesses b ON b.id = al.business_id WHERE $where");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT al.*, u.name AS actor_name, u.email AS actor_email, b.name AS business_name
    FROM audit_logs al
    JOIN users u ON u.id = al.user_id
    JOIN businesses b ON b.id = al.business_id
    WHERE $where
    ORDER BY al.created_at DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actions_stmt = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action");
$available_actions = $actions_stmt->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, 0, $current_page, 1); ?>

<?php echo render_breadcrumbs(['Admin Dashboard' => 'index.php', 'Audit Logs' => false], 1); ?>
<?php echo render_page_header('Audit Logs', "$total total audit records across all businesses."); ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="filter-bar">
            <div class="col-auto">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Actor, business, action, entity" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-auto">
                <label class="form-label">Action</label>
                <select name="action" class="form-select">
                    <option value="">All actions</option>
                    <?php foreach ($available_actions as $act): ?>
                        <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $action_filter === $act ? 'selected' : ''; ?>><?php echo htmlspecialchars($act); ?></option>
                    <?php endforeach; ?>
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
                    <tr><th>ID</th><th>Time</th><th>Actor</th><th>Business</th><th>Action</th><th>Entity</th><th>Old Value</th><th>New Value</th><th>IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>#<?php echo $log['id']; ?></td>
                        <td><small><?php echo htmlspecialchars($log['created_at']); ?></small></td>
                        <td>
                            <?php echo htmlspecialchars($log['actor_name']); ?><br>
                            <small class="text-muted"><?php echo htmlspecialchars($log['actor_email']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($log['business_name']); ?></td>
                        <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($log['action']); ?></span></td>
                        <td><?php echo htmlspecialchars($log['entity_type'] . ' #' . ($log['entity_id'] ?? '')); ?></td>
                        <td><small class="text-muted"><?php echo htmlspecialchars(mb_strimwidth($log['old_value'] ?? '-', 0, 50, '...')); ?></small></td>
                        <td><small class="text-muted"><?php echo htmlspecialchars(mb_strimwidth($log['new_value'] ?? '-', 0, 50, '...')); ?></small></td>
                        <td><small><?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="9" class="text-center text-muted">No audit logs found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination pagination-sm justify-content-center">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&action=<?php echo urlencode($action_filter); ?>"><?php echo $p; ?></a>
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
