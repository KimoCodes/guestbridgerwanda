<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'reconciliation.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$month = trim($_GET['month'] ?? date('Y-m'));
$partner_id = intval($_GET['partner_id'] ?? 0);
$errors = [];

tenant_assert_partner_filter($pdo, $business_id, $partner_id);

$partner_stmt = $pdo->prepare('SELECT DISTINCT b.id, b.name FROM commissions c JOIN businesses b ON c.source_business_id = b.id WHERE c.owed_to_business_id = ? ORDER BY b.name');
$partner_stmt->execute([$business_id]);
$partner_options = $partner_stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = 'SELECT b.name AS partner_name,
        COUNT(c.id) AS referral_count,
        SUM(CASE WHEN c.status = "pending" THEN c.amount ELSE 0 END) AS pending_amount,
        SUM(CASE WHEN c.status = "confirmed" THEN c.amount ELSE 0 END) AS confirmed_amount,
        SUM(CASE WHEN c.status = "reconciled" THEN c.amount ELSE 0 END) AS reconciled_amount
        FROM commissions c
        JOIN businesses b ON c.source_business_id = b.id
        WHERE c.owed_to_business_id = ? AND c.month = ?';
    $params = [$business_id, $month];
    if ($partner_id > 0) {
        $sql .= ' AND c.source_business_id = ?';
        $params[] = $partner_id;
    }
    $sql .= '
        GROUP BY b.id, b.name
        ORDER BY SUM(c.amount) DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reconciliation-' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Partner', 'Referrals', 'Pending', 'Confirmed', 'Reconciled']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['partner_name'],
            $row['referral_count'],
            $row['pending_amount'],
            $row['confirmed_amount'],
            $row['reconciled_amount'],
        ]);
    }
    exit;
}

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Invalid month format.';
    $month = date('Y-m');
}

$sql = 'SELECT b.id AS partner_id, b.name AS partner_name,
    COUNT(c.id) AS referral_count,
    SUM(c.amount) AS total_amount,
    SUM(CASE WHEN c.status = "pending" THEN c.amount ELSE 0 END) AS pending_amount,
    SUM(CASE WHEN c.status = "confirmed" THEN c.amount ELSE 0 END) AS confirmed_amount,
    SUM(CASE WHEN c.status = "reconciled" THEN c.amount ELSE 0 END) AS reconciled_amount
    FROM commissions c
    JOIN businesses b ON c.source_business_id = b.id
    WHERE c.owed_to_business_id = ? AND c.month = ?';
$params = [$business_id, $month];
if ($partner_id > 0) {
    $sql .= ' AND c.source_business_id = ?';
    $params[] = $partner_id;
}
$sql .= '
    GROUP BY b.id, b.name
    ORDER BY total_amount DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$partner_rows = $stmt->fetchAll();

$sql = 'SELECT IFNULL(SUM(amount),0) AS pending_total FROM commissions WHERE owed_to_business_id = ? AND status = ? AND month = ?';
$params = [$business_id, 'pending', $month];
if ($partner_id > 0) {
    $sql .= ' AND source_business_id = ?';
    $params[] = $partner_id;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pending_total = $stmt->fetch()['pending_total'];

$sql = 'SELECT IFNULL(SUM(amount),0) AS confirmed_total FROM commissions WHERE owed_to_business_id = ? AND status = ? AND month = ?';
$params = [$business_id, 'confirmed', $month];
if ($partner_id > 0) {
    $sql .= ' AND source_business_id = ?';
    $params[] = $partner_id;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$confirmed_total = $stmt->fetch()['confirmed_total'];

$sql = 'SELECT IFNULL(SUM(amount),0) AS reconciled_total FROM commissions WHERE owed_to_business_id = ? AND status = ? AND month = ?';
$params = [$business_id, 'reconciled', $month];
if ($partner_id > 0) {
    $sql .= ' AND source_business_id = ?';
    $params[] = $partner_id;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reconciled_total = $stmt->fetch()['reconciled_total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment reconciliation - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Finance' => 'commissions.php', 'Reconciliation' => false]); ?>
            <?php echo render_page_header(
                'Payment Reconciliation',
                'View commission obligations by partner and reconcile confirmed payments.',
                [['href' => 'reconciliation.php?month=' . urlencode($month) . ($partner_id ? '&partner_id=' . $partner_id : '') . '&export=csv', 'label' => 'Download CSV', 'style' => 'secondary']]
            ); ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-3"><?php foreach ($errors as $error): ?><div><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php echo render_stat_cards([
                ['label' => 'Pending', 'value' => 'RWF ' . format_money($pending_total), 'variant' => 'warning'],
                ['label' => 'Confirmed', 'value' => 'RWF ' . format_money($confirmed_total), 'variant' => 'success'],
                ['label' => 'Reconciled', 'value' => 'RWF ' . format_money($reconciled_total), 'variant' => 'default'],
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
                        <div class="col-auto d-grid">
                            <label class="form-label">&nbsp;</label>
                            <button class="btn btn-primary">Filter</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Partner</th>
                                    <th>Referrals</th>
                                    <th>Pending</th>
                                    <th>Confirmed</th>
                                    <th>Reconciled</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($partner_rows): ?>
                                    <?php foreach ($partner_rows as $row): ?>
                                        <tr>
                                            <td><a href="/guestbridgerwanda/commissions.php?month=<?php echo urlencode($month); ?>&partner_id=<?php echo intval($row['partner_id']); ?>"><?php echo htmlspecialchars($row['partner_name']); ?></a></td>
                                            <td><?php echo htmlspecialchars($row['referral_count']); ?></td>
                                            <td>RWF <?php echo format_money($row['pending_amount']); ?></td>
                                            <td>RWF <?php echo format_money($row['confirmed_amount']); ?></td>
                                            <td>RWF <?php echo format_money($row['reconciled_amount']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-muted">No reconciliation data for this month.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        <a href="/guestbridgerwanda/commissions.php?month=<?php echo urlencode($month); ?>" class="btn btn-outline-primary btn-sm">Commission Ledger</a>
                        <a href="/guestbridgerwanda/transactions.php?month=<?php echo urlencode($month); ?><?php echo $partner_id ? '&partner_id=' . intval($partner_id) : ''; ?>" class="btn btn-outline-secondary btn-sm">Transactions</a>
                    </div>
                </div>
            </div>
        </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
