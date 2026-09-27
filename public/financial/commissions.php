<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'commissions.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$month = trim($_GET['month'] ?? date('Y-m'));
$partner_id = intval($_GET['partner_id'] ?? 0);

tenant_assert_partner_filter($pdo, $business_id, $partner_id);
$errors = [];
$flash = null;

$partner_stmt = $pdo->prepare('SELECT DISTINCT b2.id, b2.name FROM commissions c JOIN businesses b2 ON c.source_business_id = b2.id WHERE c.owed_to_business_id = ? ORDER BY b2.name');
$partner_stmt->execute([$business_id]);
$partner_options = $partner_stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = 'SELECT c.*, r.referral_code, COALESCE(r.transaction_amount, c.estimated_value) AS transaction_value, b2.name AS partner_name FROM commissions c
        JOIN referrals r ON c.referral_id = r.id
        JOIN businesses b2 ON c.source_business_id = b2.id
        WHERE c.owed_to_business_id = ? AND c.month = ?';
    $params = [$business_id, $month];
    if ($partner_id > 0) {
        $sql .= ' AND c.source_business_id = ?';
        $params[] = $partner_id;
    }
    $sql .= ' ORDER BY c.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="commissions-' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Referral', 'Partner', 'Value', 'Commission %', 'Amount', 'Status']);
    foreach ($rows as $row) {
        fputcsv($output, [
            date('Y-m-d H:i:s', strtotime($row['created_at'])),
            $row['referral_code'],
            $row['partner_name'],
            $row['transaction_value'],
            $row['commission_percentage'],
            $row['amount'],
            $row['status'],
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('commissions.php');
    $action = $_POST['action'] ?? '';
    $commission_id = intval($_POST['commission_id'] ?? 0);

    if ($commission_id > 0) {
        $commission_row = tenant_fetch_commission($pdo, $commission_id, $business_id);
        if (!$commission_row || (int) $commission_row['owed_to_business_id'] !== $business_id) {
            $commission_id = 0;
        }
        $target_business_id = $commission_row ? (int) $commission_row['target_business_id'] : 0;
    }
    if ($commission_id > 0) {

        if ($action === 'confirm') {
            $stmt = $pdo->prepare('UPDATE commissions SET status = ? WHERE id = ? AND owed_to_business_id = ? AND status = ?');
            $stmt->execute(['confirmed', $commission_id, $business_id, 'pending']);
            if ($stmt->rowCount()) {
                recalculate_business_reputation($pdo, $target_business_id);
                $flash = 'Commission marked as confirmed.';
            }
        } elseif ($action === 'reconcile') {
            $stmt = $pdo->prepare('UPDATE commissions SET status = ? WHERE id = ? AND owed_to_business_id = ? AND status = ?');
            $stmt->execute(['reconciled', $commission_id, $business_id, 'confirmed']);
            if ($stmt->rowCount()) {
                recalculate_business_reputation($pdo, $target_business_id);
                $flash = 'Commission marked as reconciled.';
            }
        }
    }
}

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Invalid month format.';
    $month = date('Y-m');
}

$sql = 'SELECT COALESCE(SUM(amount),0) AS pending_total FROM commissions WHERE owed_to_business_id = ? AND status = ? AND month = ?';
$params = [$business_id, 'pending', $month];
if ($partner_id > 0) {
    $sql .= ' AND source_business_id = ?';
    $params[] = $partner_id;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pending_total = $stmt->fetch()['pending_total'];

$sql = 'SELECT COALESCE(SUM(amount),0) AS confirmed_total FROM commissions WHERE owed_to_business_id = ? AND status = ? AND month = ?';
$params = [$business_id, 'confirmed', $month];
if ($partner_id > 0) {
    $sql .= ' AND source_business_id = ?';
    $params[] = $partner_id;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$confirmed_total = $stmt->fetch()['confirmed_total'];

$sql = 'SELECT c.*, r.referral_code, COALESCE(r.transaction_amount, c.estimated_value) AS transaction_value, b2.name AS partner_name FROM commissions c
    JOIN referrals r ON c.referral_id = r.id
    JOIN businesses b2 ON c.source_business_id = b2.id
    WHERE c.owed_to_business_id = ? AND c.month = ?';
$params = [$business_id, $month];
if ($partner_id > 0) {
    $sql .= ' AND c.source_business_id = ?';
    $params[] = $partner_id;
}
$sql .= ' ORDER BY c.created_at DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission Ledger - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Finance' => 'commissions.php', 'Ledger' => false]); ?>
            <?php echo render_page_header(
                'Commission Ledger',
                'Summary of referral commissions for ' . htmlspecialchars($month) . '. Track pending, confirmed, and reconciled amounts.',
                [
                    ['href' => 'commissions.php?month=' . urlencode($month) . ($partner_id ? '&partner_id=' . $partner_id : '') . '&export=csv', 'label' => 'Download CSV', 'style' => 'secondary'],
                ]
            ); ?>
            <?php echo render_stat_cards([
                ['label' => 'Pending Total', 'value' => 'RWF ' . format_money($pending_total), 'variant' => 'warning'],
                ['label' => 'Confirmed Total', 'value' => 'RWF ' . format_money($confirmed_total), 'variant' => 'success'],
            ]); ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-3"><?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="alert alert-success mb-3"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>
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
                                    <th>Date</th>
                                    <th>Referral</th>
                                    <th>Partner</th>
                                    <th>Value</th>
                                    <th>Rate</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($row['created_at']))); ?></td>
                                            <td><a href="/guestbridgerwanda/view_referral.php?id=<?php echo intval($row['referral_id']); ?>"><?php echo htmlspecialchars($row['referral_code']); ?></a></td>
                                            <td><?php echo htmlspecialchars($row['partner_name']); ?></td>
                                            <td>RWF <?php echo format_money($row['transaction_value']); ?></td>
                                            <td><?php echo htmlspecialchars($row['commission_percentage']); ?>%</td>
                                            <td>RWF <?php echo format_money($row['amount']); ?></td>
                                            <td><span class="badge bg-<?php echo $row['status'] === 'confirmed' ? 'success' : ($row['status'] === 'reconciled' ? 'dark' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($row['status'])); ?></span></td>
                                            <td class="table-actions">
                                                <?php if ($row['status'] === 'pending'): ?>
                                                    <form method="post" class="d-inline">
                            <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="commission_id" value="<?php echo intval($row['id']); ?>">
                                                        <button type="submit" name="action" value="confirm" class="btn btn-sm btn-success">Confirm</button>
                                                    </form>
                                                <?php elseif ($row['status'] === 'confirmed'): ?>
                                                    <form method="post" class="d-inline">
                            <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="commission_id" value="<?php echo intval($row['id']); ?>">
                                                        <button type="submit" name="action" value="reconcile" class="btn btn-sm btn-secondary">Reconcile</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-center text-muted">No commission records found for this period.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
