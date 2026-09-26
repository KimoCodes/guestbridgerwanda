<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
$user = require_super_admin();
$pdo = db_connect();
$current_page = 'admin/transactions.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$count_stmt = $pdo->query("SELECT COUNT(*) FROM transactions");
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT t.*, fb.name AS from_business, tb.name AS to_business
    FROM transactions t
    JOIN businesses fb ON fb.id = t.from_business_id
    JOIN businesses tb ON tb.id = t.to_business_id
    ORDER BY t.created_at DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute();
$transactions = $stmt->fetchAll();

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, 0, $current_page, 1); ?>

<?php echo render_breadcrumbs(['Admin Dashboard' => 'index.php', 'Transactions' => false], 1); ?>
<?php echo render_page_header('All Transactions', "$total total transactions across the platform."); ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-modern table-hover align-middle mb-0">
                <thead>
                    <tr><th>ID</th><th>From</th><th>To</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td>#<?php echo $t['id']; ?></td>
                        <td><?php echo htmlspecialchars($t['from_business']); ?></td>
                        <td><?php echo htmlspecialchars($t['to_business']); ?></td>
                        <td><strong>RWF <?php echo format_money($t['amount']); ?></strong></td>
                        <td><?php echo htmlspecialchars(payment_method_label($t['method'])); ?></td>
                        <td><?php echo htmlspecialchars($t['reference'] ?? '-'); ?></td>
                        <td><span class="badge bg-<?php echo $t['status'] === 'verified' ? 'success' : ($t['status'] === 'disputed' ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($t['status'])); ?></span></td>
                        <td><small><?php echo htmlspecialchars($t['transaction_date']); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="8" class="text-center text-muted">No transactions found.</td></tr>
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
