<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'wallet.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$month = trim($_GET['month'] ?? date('Y-m'));
$errors = [];

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Invalid month format.';
    $month = date('Y-m');
}

$stmt = $pdo->prepare('SELECT IFNULL(SUM(amount),0) AS earned FROM commissions WHERE owed_to_business_id = ? AND month = ?');
$stmt->execute([$business_id, $month]);
$earned = (float)$stmt->fetch()['earned'];

$stmt = $pdo->prepare('SELECT IFNULL(SUM(amount),0) AS owed FROM commissions WHERE target_business_id = ? AND month = ?');
$stmt->execute([$business_id, $month]);
$owed = (float)$stmt->fetch()['owed'];

$stmt = $pdo->prepare('SELECT IFNULL(SUM(p.amount),0) AS paid_out
    FROM payments p
    JOIN commissions c ON c.id = p.commission_id
    WHERE c.target_business_id = ? AND DATE_FORMAT(p.paid_at, "%Y-%m") = ?');
$stmt->execute([$business_id, $month]);
$paid_out = (float)$stmt->fetch()['paid_out'];

$stmt = $pdo->prepare('SELECT IFNULL(SUM(p.amount),0) AS received
    FROM payments p
    JOIN commissions c ON c.id = p.commission_id
    WHERE c.owed_to_business_id = ? AND DATE_FORMAT(p.paid_at, "%Y-%m") = ?');
$stmt->execute([$business_id, $month]);
$received = (float)$stmt->fetch()['received'];

$receivable = max(0, $earned - $received);
$payable = max(0, $owed - $paid_out);
$net_position = $receivable - $payable;

$stmt = $pdo->prepare('SELECT b.name AS partner_name,
        IFNULL(SUM(CASE WHEN c.owed_to_business_id = ? THEN GREATEST(c.amount - IFNULL(paid.amount_paid, 0), 0) ELSE 0 END),0) AS receivable,
        IFNULL(SUM(CASE WHEN c.target_business_id = ? THEN GREATEST(c.amount - IFNULL(paid.amount_paid, 0), 0) ELSE 0 END),0) AS payable
    FROM commissions c
    LEFT JOIN (
        SELECT commission_id, SUM(amount) AS amount_paid
        FROM payments
        GROUP BY commission_id
    ) paid ON paid.commission_id = c.id
    JOIN businesses b ON b.id = CASE WHEN c.owed_to_business_id = ? THEN c.target_business_id ELSE c.owed_to_business_id END
    WHERE (c.owed_to_business_id = ? OR c.target_business_id = ?) AND c.month = ?
    GROUP BY b.id, b.name
    ORDER BY b.name');
$stmt->execute([$business_id, $business_id, $business_id, $business_id, $business_id, $month]);
$partner_balances = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wallet Overview - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Finance' => 'commissions.php', 'Wallet' => false]); ?>
            <?php echo render_page_header(
                'Wallet Overview',
                'Simulated monthly position: earneds, receivables, payables, and net position.',
                [['href' => 'reconciliation.php', 'label' => 'Go to Reconciliation', 'style' => 'secondary']]
            ); ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-3"><?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php echo render_stat_cards([
                ['label' => 'Receivable', 'value' => 'RWF ' . format_money($receivable), 'variant' => 'success'],
                ['label' => 'Payable', 'value' => 'RWF ' . format_money($payable), 'variant' => 'danger'],
                ['label' => 'Net Position', 'value' => 'RWF ' . format_money($net_position), 'variant' => 'primary'],
            ]); ?>
            <div class="card">
                <div class="card-body">
                    <form method="get" class="filter-bar mb-3">
                        <div class="col-auto">
                            <label class="form-label">Month</label>
                            <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month); ?>">
                        </div>
                        <div class="col-auto d-grid">
                            <label class="form-label">&nbsp;</label>
                            <button class="btn btn-primary">View</button>
                        </div>
                    </form>
                    <div class="row gy-4">
                        <div class="col-12 col-lg-5">
                            <h3 class="text-section-heading">Monthly Movement</h3>
                            <dl class="row mb-0">
                                <dt class="col-6">Total earned</dt><dd class="col-6 text-end">RWF <?php echo format_money($earned); ?></dd>
                                <dt class="col-6">Received</dt><dd class="col-6 text-end">RWF <?php echo format_money($received); ?></dd>
                                <dt class="col-6">Total owed</dt><dd class="col-6 text-end">RWF <?php echo format_money($owed); ?></dd>
                                <dt class="col-6">Paid out</dt><dd class="col-6 text-end">RWF <?php echo format_money($paid_out); ?></dd>
                            </dl>
                        </div>
                        <div class="col-12 col-lg-7">
                            <h3 class="text-section-heading">Partner Balances</h3>
                            <?php if ($partner_balances): ?>
                                <div class="table-responsive">
                                    <table class="table table-modern table-hover align-middle mb-0">
                                        <thead>
                                            <tr><th>Partner</th><th>They Owe You</th><th>You Owe Them</th><th>Net</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($partner_balances as $bal): ?>
                                                <?php $pnet = (float)$bal['receivable'] - (float)$bal['payable']; ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($bal['partner_name']); ?></td>
                                                    <td>RWF <?php echo format_money($bal['receivable']); ?></td>
                                                    <td>RWF <?php echo format_money($bal['payable']); ?></td>
                                                    <td>RWF <?php echo format_money($pnet); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No partner balances for this month.</p>
                            <?php endif; ?>
                            <div class="mt-3">
                                <a class="btn btn-outline-primary btn-sm" href="/guestbridgerwanda/reconciliation.php?month=<?php echo urlencode($month); ?>">Reconciliation</a>
                                <a class="btn btn-outline-secondary btn-sm" href="/guestbridgerwanda/payments.php?month=<?php echo urlencode($month); ?>">Record Payment</a>
                                <a class="btn btn-outline-secondary btn-sm" href="/guestbridgerwanda/transactions.php?month=<?php echo urlencode($month); ?>">Transactions</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php echo render_app_shell_end(); ?>
</html>
