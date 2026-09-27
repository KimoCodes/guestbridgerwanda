<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'payments.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$month = trim($_GET['month'] ?? date('Y-m'));
$partner_id = intval($_GET['partner_id'] ?? 0);
$errors = [];
$flash = null;

$partner_stmt = $pdo->prepare('SELECT DISTINCT b.id, b.name FROM commissions c JOIN businesses b ON c.source_business_id = b.id WHERE c.owed_to_business_id = ? ORDER BY b.name');
$partner_stmt->execute([$business_id]);
$partner_options = $partner_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('payments.php');
    $action = $_POST['action'] ?? 'record';
    $payment_id = intval($_POST['payment_id'] ?? 0);
    $commission_id = intval($_POST['commission_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $method = trim($_POST['method'] ?? 'bank_transfer');
    $reference = trim($_POST['reference'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $payment_methods = payment_method_options();

    if (in_array($action, ['verify', 'dispute'], true)) {
        if ($payment_id <= 0) {
            $errors[] = 'Select a payment to update.';
        }

        if (empty($errors)) {
            $new_status = $action === 'verify' ? 'verified' : 'disputed';
            $stmt = $pdo->prepare('SELECT p.id FROM payments p JOIN commissions c ON p.commission_id = c.id
                WHERE p.id = ? AND (c.owed_to_business_id = ? OR c.target_business_id = ? OR c.source_business_id = ?)');
            $stmt->execute([$payment_id, $business_id, $business_id, $business_id]);
            if (!$stmt->fetch()) {
                $errors[] = 'Payment not found for your business.';
            }
        }

        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE payments SET status = ? WHERE id = ?');
                $stmt->execute([$new_status, $payment_id]);

                $stmt = $pdo->prepare('UPDATE transactions SET status = ? WHERE payment_id = ?');
                $stmt->execute([$new_status, $payment_id]);

                $pdo->commit();
                $flash = $new_status === 'verified' ? 'Payment marked as verified.' : 'Payment marked as disputed.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Unable to update payment status. Please try again.';
            }
        }
    } else {
        if ($commission_id <= 0) {
            $errors[] = 'Select a commission to record payment against.';
        }
        if ($amount <= 0) {
            $errors[] = 'Payment amount must be greater than zero.';
        }
        if (!isset($payment_methods[$method])) {
            $errors[] = 'Choose a valid payment method.';
        }

        if (empty($errors)) {
            $commission = tenant_fetch_commission($pdo, $commission_id, $business_id);

            if (!$commission) {
                $errors[] = 'Commission not found for your business.';
            } elseif ((int) $commission['target_business_id'] !== $business_id) {
                $errors[] = 'Only the partner that owes the commission can record this payment.';
            }
        }

        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO payments (commission_id, amount, paid_at, method, reference, note) VALUES (?, ?, NOW(), ?, ?, ?)');
                $stmt->execute([$commission_id, $amount, $method, $reference !== '' ? $reference : null, $note]);
                $payment_id = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare('INSERT INTO transactions (payment_id, from_business_id, to_business_id, amount, method, reference) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$payment_id, $commission['target_business_id'], $commission['source_business_id'], $amount, $method, $reference !== '' ? $reference : null]);

                $paid_stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE commission_id = ? AND status != ?');
                $paid_stmt->execute([$commission_id, 'disputed']);
                $paid_total = (float)$paid_stmt->fetchColumn();

                $amount_stmt = $pdo->prepare('SELECT amount FROM commissions WHERE id = ?');
                $amount_stmt->execute([$commission_id]);
                $commission_total = (float)$amount_stmt->fetchColumn();

                $new_commission_status = $paid_total >= $commission_total ? 'reconciled' : 'confirmed';
                $update = $pdo->prepare('UPDATE commissions SET status = ? WHERE id = ?');
                $update->execute([$new_commission_status, $commission_id]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Unable to record payment. Please try again.';
            }

            if (empty($errors)) {
                refresh_hotel_debts($pdo, (int)$commission['target_business_id']);
                recalculate_business_reputation($pdo, (int)$commission['target_business_id']);
                $flash = 'Payment recorded successfully.';
            }
        }
    }
}

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Invalid month format.';
    $month = date('Y-m');
}

$sql = 'SELECT COALESCE(SUM(p.amount),0) AS total_paid FROM payments p JOIN commissions c ON p.commission_id = c.id WHERE c.owed_to_business_id = ? AND to_char(p.paid_at, \'YYYY-MM\') = ?';
$params = [$business_id, $month];
if ($partner_id > 0) {
    $sql .= ' AND c.source_business_id = ?';
    $params[] = $partner_id;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$total_paid = $stmt->fetch()['total_paid'];

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = 'SELECT p.paid_at, c.referral_id, r.referral_code, b2.name AS partner_name, p.amount, p.method, p.reference, p.status, p.note
        FROM payments p
        JOIN commissions c ON p.commission_id = c.id
        JOIN referrals r ON c.referral_id = r.id
        JOIN businesses b2 ON c.source_business_id = b2.id
        WHERE c.owed_to_business_id = ? AND to_char(p.paid_at, \'YYYY-MM\') = ?';
    $params = [$business_id, $month];
    if ($partner_id > 0) {
        $sql .= ' AND c.source_business_id = ?';
        $params[] = $partner_id;
    }
    $sql .= ' ORDER BY p.paid_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments_export = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments-' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Partner', 'Referral', 'Payment Amount', 'Method', 'Reference', 'Status', 'Note']);
    foreach ($payments_export as $payment_export) {
        fputcsv($output, [
            date('Y-m-d H:i:s', strtotime($payment_export['paid_at'])),
            $payment_export['partner_name'],
            $payment_export['referral_code'],
            $payment_export['amount'],
            payment_method_label($payment_export['method']),
            $payment_export['reference'],
            ucfirst($payment_export['status']),
            $payment_export['note'],
        ]);
    }
    exit;
}

$sql = 'SELECT p.*, c.referral_id, c.amount AS commission_amount, c.commission_percentage, c.month AS commission_month, r.referral_code, b2.name AS partner_name
    FROM payments p
    JOIN commissions c ON p.commission_id = c.id
    JOIN referrals r ON c.referral_id = r.id
    JOIN businesses b2 ON c.source_business_id = b2.id
    WHERE c.owed_to_business_id = ? AND to_char(p.paid_at, \'YYYY-MM\') = ?';
$params = [$business_id, $month];
if ($partner_id > 0) {
    $sql .= ' AND c.source_business_id = ?';
    $params[] = $partner_id;
}
$sql .= ' ORDER BY p.paid_at DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$sql = 'SELECT c.id, r.referral_code, b2.name AS partner_name, c.amount, c.status FROM commissions c
    JOIN referrals r ON c.referral_id = r.id
    JOIN businesses b2 ON c.source_business_id = b2.id
    WHERE c.owed_to_business_id = ? AND c.status IN (\'confirmed\',\'reconciled\')';
$params = [$business_id];
if ($partner_id > 0) {
    $sql .= ' AND c.source_business_id = ?';
    $params[] = $partner_id;
}
$sql .= ' ORDER BY c.created_at DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$available_commissions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Journal - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Finance' => 'commissions.php', 'Payments' => false]); ?>
            <?php echo render_page_header(
                'Payment Journal',
                'Record and audit manual settlement payments for confirmed commissions.',
                [
                    ['href' => 'payments.php?month=' . urlencode($month) . ($partner_id ? '&partner_id=' . $partner_id : '') . '&export=csv', 'label' => 'Export CSV', 'style' => 'secondary'],
                    ['href' => 'reconciliation.php', 'label' => 'Reconciliation', 'style' => 'secondary'],
                    ['href' => 'transactions.php', 'label' => 'Transactions', 'style' => 'secondary'],
                ]
            ); ?>
            <?php echo render_stat_cards([
                ['label' => 'Paid This Month', 'value' => 'RWF ' . format_money($total_paid), 'variant' => 'success'],
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
                    <div class="row g-4">
                        <div class="col-12 col-lg-5">
                            <h3 class="text-section-heading">Record Payment</h3>
                            <form method="post">
                            <?php echo csrf_field(); ?>
                                <div class="mb-3">
                                    <label class="form-label">Commission</label>
                                    <select name="commission_id" class="form-select" required>
                                        <option value="">Choose confirmed commission</option>
                                        <?php foreach ($available_commissions as $c): ?>
                                            <option value="<?php echo intval($c['id']); ?>">
                                                <?php echo htmlspecialchars($c['referral_code']); ?> / <?php echo htmlspecialchars($c['partner_name']); ?> / RWF <?php echo format_money($c['amount']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Payment Amount</label>
                                    <input type="number" step="0.01" min="0" name="amount" class="form-control" value="0" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Settlement Method</label>
                                    <select name="method" class="form-select" required>
                                        <?php foreach (payment_method_options() as $mv => $ml): ?>
                                            <option value="<?php echo htmlspecialchars($mv); ?>" <?php echo $mv === ($_POST['method'] ?? 'bank_transfer') ? 'selected' : ''; ?>><?php echo htmlspecialchars($ml); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Reference</label>
                                    <input type="text" name="reference" class="form-control" value="<?php echo htmlspecialchars($_POST['reference'] ?? ''); ?>" placeholder="MoMo, bank, or internal reference">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Note</label>
                                    <textarea name="note" class="form-control" rows="3"></textarea>
                                </div>
                                <button class="btn btn-success">Save Payment</button>
                            </form>
                        </div>
                        <div class="col-12 col-lg-7">
                            <h3 class="text-section-heading">Payment Records</h3>
                            <div class="table-responsive">
                                <table class="table table-modern table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Partner</th>
                                            <th>Referral</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($payments): ?>
                                            <?php foreach ($payments as $pmt): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($pmt['paid_at']))); ?></td>
                                                    <td><?php echo htmlspecialchars($pmt['partner_name']); ?></td>
                                                    <td><a href="/guestbridgerwanda/view_referral.php?id=<?php echo intval($pmt['referral_id']); ?>"><?php echo htmlspecialchars($pmt['referral_code']); ?></a></td>
                                                    <td>RWF <?php echo format_money($pmt['amount']); ?></td>
                                                    <td><?php echo htmlspecialchars(payment_method_label($pmt['method'])); ?></td>
                                                    <td><?php echo htmlspecialchars($pmt['reference'] ?: '-'); ?></td>
                                                    <td><span class="badge bg-<?php echo $pmt['status'] === 'verified' ? 'success' : ($pmt['status'] === 'disputed' ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($pmt['status'])); ?></span></td>
                                                    <td class="table-actions">
                                                        <form method="post" class="d-flex gap-1">
                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="payment_id" value="<?php echo intval($pmt['id']); ?>">
                                                            <button class="btn btn-sm btn-outline-success" name="action" value="verify">Verify</button>
                                                            <button class="btn btn-sm btn-outline-danger" name="action" value="dispute">Dispute</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="8" class="text-center text-muted">No payments recorded for this period.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
