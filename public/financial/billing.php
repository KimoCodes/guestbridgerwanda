<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = (int)$user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'billing.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$errors = [];
$flash = null;

refresh_hotel_debts($pdo, $business_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('billing.php');
    $debt_id = intval($_POST['debt_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $method = trim($_POST['method'] ?? '');
    $reference = trim($_POST['reference'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($debt_id <= 0) {
        $errors[] = 'Select a valid outstanding balance.';
    }
    if ($amount <= 0) {
        $errors[] = 'Payment amount must be greater than zero.';
    }
    if (!array_key_exists($method, payment_method_options())) {
        $errors[] = 'Choose a valid payment option.';
    }

    $debt = null;
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT * FROM hotel_debts WHERE id = ? AND debtor_business_id = ? AND remaining_amount > 0');
        $stmt->execute([$debt_id, $business_id]);
        $debt = $stmt->fetch();
        if (!$debt) {
            $errors[] = 'Outstanding balance not found.';
        } elseif ($amount > (float)$debt['remaining_amount']) {
            $errors[] = 'Payment amount cannot exceed the remaining balance.';
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            if (in_array($method, ['mtn_momo', 'paypal', 'stripe'], true)) {
                $provider_reference = strtoupper($method) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
                $stmt = $pdo->prepare('INSERT INTO payment_attempts (debt_id, business_id, provider, amount, reference, status) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$debt_id, $business_id, $method, $amount, $provider_reference, 'pending']);
                $flash = payment_method_label($method) . ' payment request created. Reference: ' . $provider_reference . '. Status will update after provider confirmation.';
            } else {
                record_debt_payment($pdo, $debt_id, $amount, $method, $reference !== '' ? $reference : null, $note, 'recorded');
                $flash = 'Payment recorded. The receiving partner can verify it from the payment journal.';
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

$debts = outstanding_debts($pdo, $business_id);
$total_outstanding = array_sum(array_column($debts, 'remaining_amount'));
$latest_due = $debts ? $debts[0]['due_date'] : null;

$stmt = $pdo->prepare('SELECT pa.*, d.billing_month, d.remaining_amount, b.name AS creditor_name
    FROM payment_attempts pa
    JOIN hotel_debts d ON d.id = pa.debt_id
    JOIN businesses b ON b.id = d.creditor_business_id
    WHERE pa.business_id = ?
    ORDER BY pa.created_at DESC
    LIMIT 20');
$stmt->execute([$business_id]);
$attempts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing & Invoices - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Finance' => 'commissions.php', 'Billing' => false]); ?>
            <?php echo render_page_header(
                'Billing & Invoices',
                'Outstanding balances, payment submission, invoices, and receipts.',
                [['href' => 'settlement_summary.php', 'label' => 'Settlement Reports', 'style' => 'secondary']]
            ); ?>
            <?php if ($total_outstanding > 0): ?>
                <div class="debt-alert-banner">
                    <div>
                        <div class="small text-uppercase" style="opacity:0.8;">Outstanding Balance</div>
                        <strong>RWF <?php echo format_money($total_outstanding); ?></strong>
                        <?php if ($latest_due): ?>
                            <span class="d-block" style="font-size:0.85rem;margin-top:0.2rem;opacity:0.9;">Next due: <?php echo htmlspecialchars($latest_due); ?></span>
                        <?php endif; ?>
                    </div>
                    <a class="btn btn-light btn-sm" href="#payment-form">Make Payment</a>
                </div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-3"><?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="alert alert-success mb-3"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>
            <div class="row gy-4">
                <div class="col-12 col-xl-8">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Outstanding Balances</h3>
                            <?php if ($debts): ?>
                                <div class="table-responsive">
                                    <table class="table table-modern table-hover align-middle mb-0">
                                        <thead>
                                            <tr><th>Partner Owed</th><th>Month</th><th>Due Date</th><th>Total</th><th>Paid</th><th>Remaining</th><th>Status</th><th>Documents</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($debts as $debt): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($debt['creditor_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($debt['billing_month']); ?></td>
                                                    <td><?php echo htmlspecialchars($debt['due_date']); ?></td>
                                                    <td>RWF <?php echo format_money($debt['total_amount']); ?></td>
                                                    <td>RWF <?php echo format_money($debt['paid_amount']); ?></td>
                                                    <td><strong>RWF <?php echo format_money($debt['remaining_amount']); ?></strong></td>
                                                    <td><span class="badge bg-<?php echo $debt['status'] === 'overdue' ? 'danger' : ($debt['status'] === 'partial' ? 'warning' : 'secondary'); ?>"><?php echo htmlspecialchars(debt_status_label($debt['status'])); ?></span></td>
                                                    <td>
                                                        <a class="btn btn-sm btn-outline-primary" href="invoice.php?debt_id=<?php echo intval($debt['id']); ?>&type=invoice&download=1">Invoice</a>
                                                        <a class="btn btn-sm btn-outline-secondary" href="invoice.php?debt_id=<?php echo intval($debt['id']); ?>&type=receipt&download=1">Receipt</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No outstanding balances.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-4">
                    <div class="card" id="payment-form">
                        <div class="card-body">
                            <h3 class="text-section-heading">Submit Payment</h3>
                            <form method="post">
                            <?php echo csrf_field(); ?>
                                <div class="mb-3">
                                    <label class="form-label">Balance</label>
                                    <select name="debt_id" class="form-select" required>
                                        <option value="">Choose balance</option>
                                        <?php foreach ($debts as $debt): ?>
                                            <option value="<?php echo intval($debt['id']); ?>">
                                                <?php echo htmlspecialchars($debt['creditor_name']); ?> / <?php echo htmlspecialchars($debt['billing_month']); ?> / RWF <?php echo format_money($debt['remaining_amount']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Amount</label>
                                    <input type="number" step="0.01" min="1" name="amount" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Payment Method</label>
                                    <select name="method" class="form-select" required>
                                        <option value="mtn_momo">MTN MoMo</option>
                                        <option value="paypal">PayPal</option>
                                        <option value="stripe">Stripe</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Reference</label>
                                    <input type="text" name="reference" class="form-control" placeholder="MoMo, bank, or Stripe reference">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Note</label>
                                    <textarea name="note" class="form-control" rows="2"></textarea>
                                </div>
                                <button class="btn btn-danger w-100">Submit Payment</button>
                            </form>
                            <p class="form-text mt-2">Bank transfer records are stored immediately for verification.</p>
                        </div>
                    </div>
                    <div class="card mt-3">
                        <div class="card-body">
                            <h3 class="text-section-heading">Recent Payment Requests</h3>
                            <?php if ($attempts): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($attempts as $a): ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex justify-content-between">
                                                <strong><?php echo htmlspecialchars(payment_method_label($a['provider'])); ?></strong>
                                                <span class="badge bg-<?php echo $a['status'] === 'successful' ? 'success' : ($a['status'] === 'failed' ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($a['status'])); ?></span>
                                            </div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($a['creditor_name']); ?> / RWF <?php echo format_money($a['amount']); ?></div>
                                            <div class="small">Ref: <?php echo htmlspecialchars($a['reference'] ?: '-'); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No payment requests yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
