<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
$user = require_super_admin();
$pdo = db_connect();
$current_page = 'admin/referrals.php';

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (r.referral_code LIKE ? OR sb.name LIKE ? OR tb.name LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($status_filter !== '' && in_array($status_filter, ['created', 'used', 'expired'], true)) {
    $where .= ' AND r.status = ?';
    $params[] = $status_filter;
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM referrals r JOIN businesses sb ON sb.id = r.source_business_id JOIN businesses tb ON tb.id = r.target_business_id WHERE $where");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT r.*, sb.name AS source_name, tb.name AS target_name, st.name AS staff_name
    FROM referrals r
    JOIN businesses sb ON sb.id = r.source_business_id
    JOIN businesses tb ON tb.id = r.target_business_id
    LEFT JOIN staff st ON st.id = r.staff_id
    WHERE $where
    ORDER BY r.created_at DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$referrals = $stmt->fetchAll();

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Referrals - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, 0, $current_page, 1); ?>

<?php echo render_breadcrumbs(['Admin Dashboard' => 'index.php', 'Referrals' => false], 1); ?>
<?php echo render_page_header('All Referrals', "$total total referrals across all businesses."); ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="filter-bar">
            <div class="col-auto">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Code, source, target" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-auto">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="created" <?php echo $status_filter === 'created' ? 'selected' : ''; ?>>Created</option>
                    <option value="used" <?php echo $status_filter === 'used' ? 'selected' : ''; ?>>Used</option>
                    <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
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
                    <tr><th>Code</th><th>Source</th><th>Target</th><th>Staff</th><th>Rate</th><th>Value</th><th>Status</th><th>Created</th></tr>
                </thead>
                <tbody>
                <?php foreach ($referrals as $r): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($r['referral_code']); ?></code></td>
                        <td><?php echo htmlspecialchars($r['source_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['target_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['staff_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($r['commission_percentage']); ?>%</td>
                        <td>RWF <?php echo format_money($r['transaction_amount'] ?? $r['estimated_value']); ?></td>
                        <td><span class="badge bg-<?php echo $r['status'] === 'used' ? 'success' : ($r['status'] === 'expired' ? 'secondary' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span></td>
                        <td><small><?php echo htmlspecialchars($r['created_at']); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($referrals)): ?>
                    <tr><td colspan="8" class="text-center text-muted">No referrals found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination pagination-sm justify-content-center">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $p; ?></a>
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
