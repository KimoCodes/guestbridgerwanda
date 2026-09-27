<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
$user = require_super_admin();
$pdo = db_connect();
$current_page = 'admin/settlements.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$count_stmt = $pdo->query("SELECT COUNT(*) FROM payments");
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT p.*, c.commission_percentage, c.month AS billing_month,
    sb.name AS source_name, tb.name AS target_name, ob.name AS owed_to_name
    FROM payments p
    JOIN commissions c ON c.id = p.commission_id
    JOIN businesses sb ON sb.id = c.source_business_id
    JOIN businesses tb ON tb.id = c.target_business_id
    JOIN businesses ob ON ob.id = c.owed_to_business_id
    ORDER BY p.created_at DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute();
$payments = $stmt->fetchAll();

$total_paid_stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'verified'");
$total_paid = $total_paid_stmt->fetchColumn();

$pending_verification_stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'recorded'");
$pending_verification = $pending_verification_stmt->fetchColumn();

$disputed_stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'disputed'");
$disputed_amount = $disputed_stmt->fetchColumn();

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settlements - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, 0, $current_page, 1); ?>

<?php echo render_breadcrumbs(['Admin Dashboard' => 'index.php', 'Settlements' => false], 1); ?>
<?php echo render_page_header('Settlement Payments', "$total total payment records across the platform."); ?>

<div class="admin-stat-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="admin-stat-card stat-success"><div class="stat-label">Verified Payments</div><div class="stat-value">RWF <?php echo format_money($total_paid); ?></div></div>
    <div class="admin-stat-card stat-warning"><div class="stat-label">Pending Verification</div><div class="stat-value">RWF <?php echo format_money($pending_verification); ?></div></div>
    <div class="admin-stat-card stat-danger"><div class="stat-label">Disputed</div><div class="stat-value">RWF <?php echo format_money($disputed_amount); ?></div></div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-modern table-hover align-middle mb-0">
                <thead>
                    <tr><th>ID</th><th>From</th><th>To</th><th>Owed To</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>Billing Month</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td>#<?php echo $p['id']; ?></td>
                        <td><?php echo htmlspecialchars($p['source_name']); ?></td>
                        <td><?php echo htmlspecialchars($p['target_name']); ?></td>
                        <td><?php echo htmlspecialchars($p['owed_to_name']); ?></td>
                        <td><strong>RWF <?php echo format_money($p['amount']); ?></strong></td>
                        <td><?php echo htmlspecialchars(payment_method_label($p['method'])); ?></td>
                        <td><?php echo htmlspecialchars($p['reference'] ?? '-'); ?></td>
                        <td><span class="badge bg-<?php echo $p['status'] === 'verified' ? 'success' : ($p['status'] === 'disputed' ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($p['status'])); ?></span></td>
                        <td><?php echo htmlspecialchars($p['billing_month']); ?></td>
                        <td><small><?php echo htmlspecialchars($p['paid_at']); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="10" class="text-center text-muted">No payments found.</td></tr>
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
