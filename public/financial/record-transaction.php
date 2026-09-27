<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Services/ServiceContainer.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'transactions.php';

$services = ServiceContainer::getInstance($pdo);

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

// Handle transaction recording
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('record_transaction.php');
    
    $referral_id = intval($_POST['referral_id'] ?? 0);
    $gross_amount = floatval($_POST['gross_amount'] ?? 0);
    $eligible_amount = floatval($_POST['eligible_amount'] ?? 0);
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $transaction_ref = trim($_POST['transaction_ref'] ?? '');

    if ($referral_id <= 0) {
        $errors[] = 'Please select a referral.';
    }
    if ($gross_amount <= 0) {
        $errors[] = 'Gross amount must be greater than zero.';
    }
    if ($eligible_amount <= 0) {
        $eligible_amount = $gross_amount;
    }
    if ($eligible_amount > $gross_amount) {
        $errors[] = 'Eligible amount cannot exceed gross amount.';
    }

    if (empty($errors)) {
        try {
            $result = $services->commissions()->processTransaction(
                $referral_id,
                $gross_amount,
                $eligible_amount,
                $employee_id ?: null,
                $notes ?: null
            );
            
            $success = "Transaction recorded successfully. Commission of RWF " . format_money($result['commission']['total_commission']) . " has been calculated.";
            
            log_audit($pdo, $business_id, (int)$user['id'], 'transaction_recorded', 'guest_transaction', $result['transaction_id'], null, [
                'referral_id' => $referral_id,
                'gross_amount' => $gross_amount,
                'eligible_amount' => $eligible_amount,
                'commission' => $result['commission']['total_commission'],
            ]);
        } catch (Exception $e) {
            $errors[] = 'Error recording transaction: ' . $e->getMessage();
        }
    }
}

// Get referrals that can have transactions recorded (accepted or visited status)
$stmt = $pdo->prepare('SELECT r.*, 
    sb.name AS source_business_name, 
    tb.name AS target_business_name,
    s.name AS staff_name
    FROM referrals r 
    LEFT JOIN businesses sb ON sb.id = r.source_business_id 
    LEFT JOIN businesses tb ON tb.id = r.target_business_id
    LEFT JOIN staff s ON s.id = r.staff_id
    WHERE r.target_business_id = ? AND r.status IN (\'accepted\',\'visited\')
    ORDER BY r.created_at DESC');
$stmt->execute([$business_id]);
$available_referrals = $stmt->fetchAll();

// Get staff for this business
$stmt = $pdo->prepare('SELECT id, name, role FROM staff WHERE business_id = ? ORDER BY name');
$stmt->execute([$business_id]);
$staff = $stmt->fetchAll();

// Get recent transactions
$stmt = $pdo->prepare('SELECT gt.*, 
    r.referral_code,
    sb.name AS source_business_name
    FROM guest_transactions gt
    JOIN referrals r ON r.id = gt.referral_id
    LEFT JOIN businesses sb ON sb.id = r.source_business_id
    WHERE gt.business_id = ?
    ORDER BY gt.created_at DESC
    LIMIT 10');
$stmt->execute([$business_id]);
$recent_transactions = $stmt->fetchAll();

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Transaction - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Transactions' => 'transactions.php', 'Record' => false]); ?>
            <?php echo render_page_header(
                'Record Guest Transaction',
                'Record a guest transaction for a referred visit and calculate commission.',
                []
            ); ?>

            <div class="row justify-content-center">
                <div class="col-12 col-lg-8">
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($available_referrals)): ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <div class="text-muted mb-3">
                                    <i data-lucide="inbox" style="width:48px;height:48px;"></i>
                                </div>
                                <h5>No referrals available for transaction recording</h5>
                                <p class="text-muted">You need referrals in "Accepted" or "Visited" status to record transactions.</p>
                                <a href="/guestbridgerwanda/create_referral.php" class="btn btn-primary">Create Referral</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h2 class="h5 mb-3">Record Transaction</h2>
                                <p class="text-muted">Select a referral and enter the transaction details. Commission will be calculated automatically.</p>
                                
                                <form method="post">
                                    <?php echo csrf_field(); ?>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Select Referral</label>
                                        <select name="referral_id" class="form-select" required>
                                            <option value="">Choose a referral...</option>
                                            <?php foreach ($available_referrals as $ref): ?>
                                                <option value="<?php echo $ref['id']; ?>" 
                                                    data-guest="<?php echo htmlspecialchars($ref['guest_name'] ?? ''); ?>"
                                                    data-estimated="<?php echo $ref['estimated_value']; ?>"
                                                    data-commission="<?php echo $ref['commission_percentage']; ?>">
                                                    <?php echo htmlspecialchars($ref['referral_code']); ?> - 
                                                    <?php echo htmlspecialchars($ref['source_business_name']); ?> → 
                                                    <?php echo htmlspecialchars($ref['target_business_name']); ?>
                                                    (<?php echo htmlspecialchars(referral_status_label($ref['status'])); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Gross Amount (RWF)</label>
                                            <input type="number" step="0.01" min="0" name="gross_amount" class="form-control" required 
                                                placeholder="e.g. 100000">
                                            <div class="form-text">Total amount spent by the guest</div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Eligible Amount (RWF)</label>
                                            <input type="number" step="0.01" min="0" name="eligible_amount" class="form-control" 
                                                placeholder="Defaults to gross amount">
                                            <div class="form-text">Amount eligible for commission calculation</div>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Transaction Reference</label>
                                            <input type="text" name="transaction_ref" class="form-control" 
                                                placeholder="e.g. Receipt #12345">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Staff Member</label>
                                            <select name="employee_id" class="form-select">
                                                <option value="">Select staff...</option>
                                                <?php foreach ($staff as $s): ?>
                                                    <option value="<?php echo $s['id']; ?>">
                                                        <?php echo htmlspecialchars($s['name']); ?> (<?php echo htmlspecialchars($s['role']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" class="form-control" rows="2" 
                                            placeholder="Optional notes about the transaction..."></textarea>
                                    </div>

                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-success btn-lg">Record Transaction</button>
                                        <a href="/guestbridgerwanda/dashboard.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($recent_transactions)): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">Recent Transactions</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Referral</th>
                                            <th>Source</th>
                                            <th>Gross</th>
                                            <th>Eligible</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_transactions as $t): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($t['referral_code']); ?></code></td>
                                            <td><?php echo htmlspecialchars($t['source_business_name']); ?></td>
                                            <td>RWF <?php echo format_money($t['gross_amount']); ?></td>
                                            <td>RWF <?php echo format_money($t['eligible_amount']); ?></td>
                                            <td><span class="badge bg-<?php echo $t['status'] === 'verified' ? 'success' : 'warning'; ?>"><?php echo ucfirst($t['status']); ?></span></td>
                                            <td><?php echo date('M j, Y', strtotime($t['transaction_date'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
<script>
(function(){var t=document.getElementById('sidebarToggle'),s=document.getElementById('appSidebar'),o=document.getElementById('sidebarOverlay');function open(){s.classList.add('open');o.classList.add('active');}function close(){s.classList.remove('open');o.classList.remove('active');}if(t)t.addEventListener('click',open);if(o)o.addEventListener('click',close);document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});})();
</script>
</body>
</html>
