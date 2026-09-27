<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
$user = require_super_admin();
$pdo = db_connect();
$current_page = 'admin/commissions.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$count_stmt = $pdo->query("SELECT COUNT(*) FROM commissions");
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT c.*, sb.name AS source_name, tb.name AS target_name, ob.name AS owed_to_name
    FROM commissions c
    JOIN businesses sb ON sb.id = c.source_business_id
    JOIN businesses tb ON tb.id = c.target_business_id
    JOIN businesses ob ON ob.id = c.owed_to_business_id
    ORDER BY c.created_at DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute();
$commissions = $stmt->fetchAll();

$total_amount_stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM commissions");
$total_amount = $total_amount_stmt->fetchColumn();

$confirmed_stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE status = 'confirmed'");
$confirmed_amount = $confirmed_stmt->fetchColumn();

$reconciled_stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE status = 'reconciled'");
$reconciled_amount = $reconciled_stmt->fetchColumn();

$pending_stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE status = 'pending'");
$pending_amount = $pending_stmt->fetchColumn();

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commissions - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, 0, $current_page, 1); ?>

<?php echo render_breadcrumbs(['Admin Dashboard' => 'index.php', 'Commissions' => false], 1); ?>
<?php echo render_page_header('All Commissions', "$total total commission records on the platform."); ?>

<div class="admin-stat-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="admin-stat-card"><div class="stat-label">Total Commissions</div><div class="stat-value">RWF <?php echo format_money($total_amount); ?></div></div>
    <div class="admin-stat-card stat-success"><div class="stat-label">Confirmed</div><div class="stat-value">RWF <?php echo format_money($confirmed_amount); ?></div></div>
    <div class="admin-stat-card stat-info"><div class="stat-label">Reconciled</div><div class="stat-value">RWF <?php echo format_money($reconciled_amount); ?></div></div>
    <div class="admin-stat-card stat-warning"><div class="stat-label">Pending</div><div class="stat-value">RWF <?php echo format_money($pending_amount); ?></div></div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-modern table-hover align-middle mb-0">
                <thead>
                    <tr><th>ID</th><th>Source</th><th>Target</th><th>Owed To</th><th>Rate</th><th>Amount</th><th>Status</th><th>Month</th><th>Created</th></tr>
                </thead>
                <tbody>
                <?php foreach ($commissions as $c): ?>
                    <tr>
                        <td>#<?php echo $c['id']; ?></td>
                        <td><?php echo htmlspecialchars($c['source_name']); ?></td>
                        <td><?php echo htmlspecialchars($c['target_name']); ?></td>
                        <td><?php echo htmlspecialchars($c['owed_to_name']); ?></td>
                        <td><?php echo htmlspecialchars($c['commission_percentage']); ?>%</td>
                        <td><strong>RWF <?php echo format_money($c['amount']); ?></strong></td>
                        <td><span class="badge bg-<?php echo $c['status'] === 'reconciled' ? 'success' : ($c['status'] === 'confirmed' ? 'primary' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($c['status'])); ?></span></td>
                        <td><?php echo htmlspecialchars($c['month']); ?></td>
                        <td><small><?php echo htmlspecialchars($c['created_at']); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($commissions)): ?>
                    <tr><td colspan="9" class="text-center text-muted">No commissions found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination pagination-sm justify-content-center">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $p; ?>"><?php echo $p; ?></a>
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
