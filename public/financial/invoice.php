<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();
$user = current_user();
$pdo = db_connect();
$business_id = (int)$user['business_id'];

$debt_id = intval($_GET['debt_id'] ?? 0);
$type = $_GET['type'] ?? 'invoice';
$download = isset($_GET['download']);

if (!in_array($type, ['invoice', 'receipt'], true)) {
    $type = 'invoice';
}

$stmt = $pdo->prepare('SELECT d.*, debtor.name AS debtor_name, debtor.email AS debtor_email, debtor.phone AS debtor_phone,
        creditor.name AS creditor_name, creditor.email AS creditor_email, creditor.phone AS creditor_phone
    FROM hotel_debts d
    JOIN businesses debtor ON debtor.id = d.debtor_business_id
    JOIN businesses creditor ON creditor.id = d.creditor_business_id
    WHERE d.id = ? AND (d.debtor_business_id = ? OR d.creditor_business_id = ?)');
$stmt->execute([$debt_id, $business_id, $business_id]);
$debt = $stmt->fetch();

if (!$debt) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
}

$stmt = $pdo->prepare('SELECT c.*, r.referral_code, COALESCE(r.transaction_amount, c.estimated_value) AS transaction_value
    FROM commissions c
    JOIN referrals r ON r.id = c.referral_id
    WHERE c.target_business_id = ? AND c.owed_to_business_id = ? AND c.month = ?
    ORDER BY c.created_at ASC');
$stmt->execute([$debt['debtor_business_id'], $debt['creditor_business_id'], $debt['billing_month']]);
$commissions = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT p.*
    FROM payments p
    JOIN commissions c ON c.id = p.commission_id
    WHERE c.target_business_id = ? AND c.owed_to_business_id = ? AND c.month = ?
    ORDER BY p.paid_at ASC');
$stmt->execute([$debt['debtor_business_id'], $debt['creditor_business_id'], $debt['billing_month']]);
$payments = $stmt->fetchAll();

$title = $type === 'receipt' ? 'Payment receipt' : 'Settlement invoice';
$document_number = strtoupper($type) . '-' . str_pad((string)$debt['id'], 5, '0', STR_PAD_LEFT) . '-' . str_replace('-', '', $debt['billing_month']);

if ($download) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . strtolower($document_number) . '.html"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - <?php echo htmlspecialchars($document_number); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body class="document-body">
<div class="container py-4">
    <div class="card shadow-sm document-card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-column flex-md-row gap-3 border-bottom pb-3 mb-3">
                <div>
                    <h1 class="h4 mb-1"><?php echo htmlspecialchars($title); ?></h1>
                    <div class="text-muted"><?php echo htmlspecialchars(APP_NAME); ?></div>
                </div>
                <div class="text-md-end">
                    <strong><?php echo htmlspecialchars($document_number); ?></strong><br>
                    <span class="text-muted">Generated <?php echo htmlspecialchars(date('Y-m-d')); ?></span>
                </div>
            </div>

            <div class="row gy-3 mb-4">
                <div class="col-12 col-md-6">
                    <h2 class="h6">Hotel owing</h2>
                    <p class="mb-0"><strong><?php echo htmlspecialchars($debt['debtor_name']); ?></strong></p>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($debt['debtor_email']); ?></p>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($debt['debtor_phone']); ?></p>
                </div>
                <div class="col-12 col-md-6">
                    <h2 class="h6">Partner owed</h2>
                    <p class="mb-0"><strong><?php echo htmlspecialchars($debt['creditor_name']); ?></strong></p>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($debt['creditor_email']); ?></p>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($debt['creditor_phone']); ?></p>
                </div>
            </div>

            <div class="row gy-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="small text-muted">Billing month</div>
                    <strong><?php echo htmlspecialchars($debt['billing_month']); ?></strong>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted">Due date</div>
                    <strong><?php echo htmlspecialchars($debt['due_date']); ?></strong>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted">Status</div>
                    <strong><?php echo htmlspecialchars(debt_status_label($debt['status'])); ?></strong>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted">Remaining</div>
                    <strong>RWF <?php echo format_money($debt['remaining_amount']); ?></strong>
                </div>
            </div>

            <h2 class="h6">Commission lines</h2>
            <div class="table-responsive mb-4">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Referral</th>
                            <th>Value</th>
                            <th>Rate</th>
                            <th>Commission</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commissions as $commission): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($commission['created_at']))); ?></td>
                                <td><?php echo htmlspecialchars($commission['referral_code']); ?></td>
                                <td>RWF <?php echo format_money($commission['transaction_value']); ?></td>
                                <td><?php echo htmlspecialchars($commission['commission_percentage']); ?>%</td>
                                <td>RWF <?php echo format_money($commission['amount']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($commission['status'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h2 class="h6">Payments</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($payments): ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($payment['paid_at']))); ?></td>
                                    <td>RWF <?php echo format_money($payment['amount']); ?></td>
                                    <td><?php echo htmlspecialchars(payment_method_label($payment['method'])); ?></td>
                                    <td><?php echo htmlspecialchars($payment['reference'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($payment['status'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">No payments recorded.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="row gy-2 mt-4 border-top pt-3">
                <div class="col-12 col-md-4"><strong>Total owed:</strong> RWF <?php echo format_money($debt['total_amount']); ?></div>
                <div class="col-12 col-md-4"><strong>Total paid:</strong> RWF <?php echo format_money($debt['paid_amount']); ?></div>
                <div class="col-12 col-md-4"><strong>Balance:</strong> RWF <?php echo format_money($debt['remaining_amount']); ?></div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
