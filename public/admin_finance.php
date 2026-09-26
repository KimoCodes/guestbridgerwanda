<?php
require_once __DIR__ . '/../app/Config/config.php';
require_once __DIR__ . '/../app/Config/database.php';
db_init();
require_login();
require_platform_admin();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'admin_finance.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../app/HTTP/helpers.php';

refresh_hotel_debts($pdo);

$month = trim($_GET['month'] ?? date('Y-m'));
$status_filter = trim($_GET['status'] ?? '');
$errors = [];

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Invalid month format.';
    $month = date('Y-m');
}
if ($status_filter !== '' && !in_array($status_filter, ['unpaid', 'partial', 'paid', 'overdue'], true)) {
    $errors[] = 'Invalid status.';
    $status_filter = '';
}

$where = 'WHERE d.billing_month = ?';
$params = [$month];
if ($status_filter !== '') {
    $where .= ' AND d.status = ?';
    $params[] = $status_filter;
}

$stmt = $pdo->prepare('SELECT
        IFNULL(SUM(d.total_amount),0) AS total_billed,
        IFNULL(SUM(d.paid_amount),0) AS total_paid,
        IFNULL(SUM(d.remaining_amount),0) AS total_remaining,
        COUNT(*) AS debt_count,
        SUM(d.status = "overdue") AS overdue_count,
        SUM(d.status = "paid") AS paid_count
    FROM hotel_debts d
    ' . $where);
$stmt->execute($params);
$summary = $stmt->fetch();

$stmt = $pdo->prepare('SELECT d.*, debtor.name AS debtor_name, creditor.name AS creditor_name
    FROM hotel_debts d
    JOIN businesses debtor ON debtor.id = d.debtor_business_id
    JOIN businesses creditor ON creditor.id = d.creditor_business_id
    ' . $where . '
    ORDER BY d.remaining_amount DESC, d.due_date ASC');
$stmt->execute($params);
$debts = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT d.debtor_business_id, debtor.name AS debtor_name, IFNULL(SUM(d.remaining_amount),0) AS outstanding
    FROM hotel_debts d
    JOIN businesses debtor ON debtor.id = d.debtor_business_id
    WHERE d.remaining_amount > 0
    GROUP BY d.debtor_business_id, debtor.name
    ORDER BY outstanding DESC
    LIMIT 10');
$stmt->execute();
$top_debtors = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT pa.*, b.name AS business_name
    FROM payment_attempts pa
    JOIN businesses b ON b.id = pa.business_id
    ORDER BY pa.created_at DESC
    LIMIT 20');
$stmt->execute();
$attempts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Finance - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Management' => 'admin_finance.php']); ?>
            <?php echo render_page_header(
                'Admin Finance',
                'System-wide debt monitoring, payment status, and settlement risk across the partner network.',
                []
            ); ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-3"><?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php echo render_stat_cards([
                ['label' => 'Total Billed', 'value' => 'RWF ' . format_money($summary['total_billed']), 'variant' => 'info'],
                ['label' => 'Total Paid', 'value' => 'RWF ' . format_money($summary['total_paid']), 'variant' => 'success'],
                ['label' => 'Outstanding', 'value' => 'RWF ' . format_money($summary['total_remaining']), 'variant' => 'danger'],
                ['label' => 'Overdue Count', 'value' => htmlspecialchars((int)$summary['overdue_count']), 'variant' => 'warning'],
            ]); ?>
            <div class="card">
                <div class="card-body">
                    <form method="get" class="filter-bar mb-3">
                        <div class="col-auto">
                            <label class="form-label">Month</label>
                            <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month); ?>">
                        </div>
                        <div class="col-auto">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All statuses</option>
                                <?php foreach (['unpaid', 'partial', 'paid', 'overdue'] as $st): ?>
                                    <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $status_filter === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars(debt_status_label($st)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto d-grid">
                            <label class="form-label">&nbsp;</label>
                            <button class="btn btn-primary">Filter</button>
                        </div>
                    </form>
                    <h3 class="text-section-heading">Debt Monitor</h3>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle mb-0">
                            <thead>
                                <tr><th>Hotel Owing</th><th>Partner Owed</th><th>Month</th><th>Due</th><th>Total</th><th>Paid</th><th>Remaining</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($debts): ?>
                                    <?php foreach ($debts as $debt): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($debt['debtor_name']); ?></td>
                                            <td><?php echo htmlspecialchars($debt['creditor_name']); ?></td>
                                            <td><?php echo htmlspecialchars($debt['billing_month']); ?></td>
                                            <td><?php echo htmlspecialchars($debt['due_date']); ?></td>
                                            <td>RWF <?php echo format_money($debt['total_amount']); ?></td>
                                            <td>RWF <?php echo format_money($debt['paid_amount']); ?></td>
                                            <td><strong>RWF <?php echo format_money($debt['remaining_amount']); ?></strong></td>
                                            <td><span class="badge bg-<?php echo $debt['status'] === 'overdue' ? 'danger' : ($debt['status'] === 'paid' ? 'success' : 'warning'); ?>"><?php echo htmlspecialchars(debt_status_label($debt['status'])); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-center text-muted">No debt records for this filter.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="row gy-4 mt-2">
                <div class="col-12 col-lg-6">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Highest Outstanding Hotels</h3>
                            <?php if ($top_debtors): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($top_debtors as $d): ?>
                                        <div class="list-group-item px-0 d-flex justify-content-between">
                                            <span><?php echo htmlspecialchars($d['debtor_name']); ?></span>
                                            <strong>RWF <?php echo format_money($d['outstanding']); ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No outstanding hotel debt.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Provider Payment Attempts</h3>
                            <?php if ($attempts): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($attempts as $a): ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex justify-content-between">
                                                <strong><?php echo htmlspecialchars($a['business_name']); ?></strong>
                                                <span class="badge bg-<?php echo $a['status'] === 'successful' ? 'success' : ($a['status'] === 'failed' ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($a['status'])); ?></span>
                                            </div>
                                            <div class="small text-muted"><?php echo htmlspecialchars(payment_method_label($a['provider'])); ?> / RWF <?php echo format_money($a['amount']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No provider attempts yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
