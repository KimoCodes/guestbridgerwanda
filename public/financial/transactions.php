<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'transactions.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$month = trim($_GET['month'] ?? date('Y-m'));
$partner_id = intval($_GET['partner_id'] ?? 0);
$method_filter = trim($_GET['method'] ?? '');
$errors = [];

tenant_assert_partner_filter($pdo, $business_id, $partner_id);

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Invalid month format.';
    $month = date('Y-m');
}

if ($method_filter !== '' && !array_key_exists($method_filter, payment_method_options())) {
    $errors[] = 'Invalid settlement method.';
    $method_filter = '';
}

$partner_stmt = $pdo->prepare('SELECT DISTINCT b.id, b.name
    FROM transactions t
    JOIN businesses b ON b.id = CASE WHEN t.from_business_id = ? THEN t.to_business_id ELSE t.from_business_id END
    WHERE t.from_business_id = ? OR t.to_business_id = ?
    ORDER BY b.name');
$partner_stmt->execute([$business_id, $business_id, $business_id]);
$partner_options = $partner_stmt->fetchAll();

$where = 'WHERE (t.from_business_id = ? OR t.to_business_id = ?) AND to_char(t.transaction_date, \'YYYY-MM\') = ?';
$params = [$business_id, $business_id, $month];

if ($partner_id > 0) {
    $where .= ' AND (t.from_business_id = ? OR t.to_business_id = ?)';
    $params[] = $partner_id;
    $params[] = $partner_id;
}

if ($method_filter !== '') {
    $where .= ' AND t.method = ?';
    $params[] = $method_filter;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = 'SELECT t.transaction_date, from_b.name AS from_business, to_b.name AS to_business,
        t.amount, t.method, t.reference, t.status
        FROM transactions t
        JOIN businesses from_b ON from_b.id = t.from_business_id
        JOIN businesses to_b ON to_b.id = t.to_business_id
        ' . $where . '
        ORDER BY t.transaction_date DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions-' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'From', 'To', 'Amount', 'Method', 'Reference', 'Status']);
    foreach ($rows as $row) {
        fputcsv($output, [
            date('Y-m-d H:i:s', strtotime($row['transaction_date'])),
            $row['from_business'],
            $row['to_business'],
            $row['amount'],
            payment_method_label($row['method']),
            $row['reference'],
            ucfirst($row['status']),
        ]);
    }
    exit;
}

$stmt = $pdo->prepare('SELECT
        COALESCE(SUM(CASE WHEN t.to_business_id = ? THEN t.amount ELSE 0 END),0) AS incoming,
        COALESCE(SUM(CASE WHEN t.from_business_id = ? THEN t.amount ELSE 0 END),0) AS outgoing,
        COALESCE(SUM(CASE WHEN t.status = \'verified\' THEN t.amount ELSE 0 END),0) AS verified_total,
        COALESCE(SUM(CASE WHEN t.status = \'disputed\' THEN t.amount ELSE 0 END),0) AS disputed_total
    FROM transactions t
    ' . $where);
$stmt->execute(array_merge([$business_id, $business_id], $params));
$summary = $stmt->fetch();

$sql = 'SELECT t.*, from_b.name AS from_business, to_b.name AS to_business,
        CASE WHEN t.from_business_id = ? THEN to_b.name ELSE from_b.name END AS partner_name
    FROM transactions t
    JOIN businesses from_b ON from_b.id = t.from_business_id
    JOIN businesses to_b ON to_b.id = t.to_business_id
    ' . $where . '
    ORDER BY t.transaction_date DESC
    LIMIT 150';
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$business_id], $params));
$transactions = $stmt->fetchAll();

$method_sql = 'SELECT t.method, COUNT(*) AS count_total, COALESCE(SUM(t.amount),0) AS amount_total
    FROM transactions t
    ' . $where . '
    GROUP BY t.method
    ORDER BY amount_total DESC';
$stmt = $pdo->prepare($method_sql);
$stmt->execute($params);
$method_rows = $stmt->fetchAll();

$incoming = (float)$summary['incoming'];
$outgoing = (float)$summary['outgoing'];
$net_movement = $incoming - $outgoing;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction report - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Finance' => 'commissions.php', 'Transactions' => false]); ?>
            <?php echo render_page_header(
                'Transaction Report',
                'Review settlement movement by partner, month, and payment method.',
                [['href' => 'transactions.php?month=' . urlencode($month) . ($partner_id ? '&partner_id=' . $partner_id : '') . ($method_filter ? '&method=' . urlencode($method_filter) : '') . '&export=csv', 'label' => 'Export CSV', 'style' => 'secondary']]
            ); ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-3"><?php foreach ($errors as $error): ?><div><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php echo render_stat_cards([
                ['label' => 'Incoming', 'value' => 'RWF ' . format_money($incoming), 'variant' => 'success'],
                ['label' => 'Outgoing', 'value' => 'RWF ' . format_money($outgoing), 'variant' => 'danger'],
                ['label' => 'Net Movement', 'value' => 'RWF ' . format_money($net_movement), 'variant' => 'primary'],
                ['label' => 'Disputed', 'value' => 'RWF ' . format_money($summary['disputed_total']), 'variant' => 'warning'],
            ]); ?>
            <div class="card">
                <div class="card-body">
                    <form method="get" class="filter-bar">
                        <div class="col-auto">
                            <label class="form-label">Month</label>
                            <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month); ?>">
                        </div>
                        <div class="col-auto">
                            <label class="form-label">Partner</label>
                            <select name="partner_id" class="form-select">
                                <option value="">All partners</option>
                                <?php foreach ($partner_options as $po): ?>
                                    <option value="<?php echo intval($po['id']); ?>" <?php echo $partner_id === intval($po['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($po['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="form-label">Method</label>
                            <select name="method" class="form-select">
                                <option value="">All methods</option>
                                <?php foreach (payment_method_options() as $mv => $ml): ?>
                                    <option value="<?php echo htmlspecialchars($mv); ?>" <?php echo $method_filter === $mv ? 'selected' : ''; ?>><?php echo htmlspecialchars($ml); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto d-grid">
                            <label class="form-label">&nbsp;</label>
                            <button class="btn btn-primary">Filter</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle mb-0">
                            <thead>
                                <tr><th>Date</th><th>Partner</th><th>Direction</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($transactions): ?>
                                    <?php foreach ($transactions as $tx): ?>
                                        <?php $is_incoming = (int)$tx['to_business_id'] === $business_id; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($tx['transaction_date']))); ?></td>
                                            <td><?php echo htmlspecialchars($tx['partner_name']); ?></td>
                                            <td><span class="badge bg-<?php echo $is_incoming ? 'success' : 'secondary'; ?>"><?php echo $is_incoming ? 'Incoming' : 'Outgoing'; ?></span></td>
                                            <td>RWF <?php echo format_money($tx['amount']); ?></td>
                                            <td><?php echo htmlspecialchars(payment_method_label($tx['method'])); ?></td>
                                            <td><?php echo htmlspecialchars($tx['reference'] ?: '-'); ?></td>
                                            <td><span class="badge bg-<?php echo $tx['status'] === 'verified' ? 'success' : ($tx['status'] === 'disputed' ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($tx['status'])); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center text-muted">No transactions for this filter.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
