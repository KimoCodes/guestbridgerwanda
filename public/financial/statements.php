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
$current_page = 'statements.php';

$services = ServiceContainer::getInstance($pdo);

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

$statement_month = $_GET['month'] ?? date('Y-m');
$action = $_GET['action'] ?? 'list';
$errors = [];

// Generate statement
if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('statements.php');
    
    try {
        // Calculate statement data
        $month = $statement_month;
        
        // Referral value generated
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(COALESCE(r.transaction_amount, c.estimated_value)), 0) FROM commissions c JOIN referrals r ON c.referral_id = r.id WHERE c.source_business_id = ? AND c.month = ?');
        $stmt->execute([$business_id, $month]);
        $referral_value = (float) $stmt->fetchColumn();
        
        // Commission earned
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(c.amount), 0) FROM commissions c WHERE c.source_business_id = ? AND c.month = ?');
        $stmt->execute([$business_id, $month]);
        $commission_earned = (float) $stmt->fetchColumn();
        
        // Commission payable
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(c.amount), 0) FROM commissions c WHERE c.target_business_id = ? AND c.month = ?');
        $stmt->execute([$business_id, $month]);
        $commission_payable = (float) $stmt->fetchColumn();
        
        // Employee commissions
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(ca.amount), 0) FROM commission_allocations ca 
            JOIN commissions c ON c.id = ca.commission_id 
            WHERE ca.allocation_type = \'employee\' AND c.source_business_id = ? AND c.month = ?');
        $stmt->execute([$business_id, $month]);
        $employee_commission = (float) $stmt->fetchColumn();
        
        // Platform fee
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(ca.amount), 0) FROM commission_allocations ca 
            JOIN commissions c ON c.id = ca.commission_id 
            WHERE ca.allocation_type = \'platform\' AND c.source_business_id = ? AND c.month = ?');
        $stmt->execute([$business_id, $month]);
        $platform_fee = (float) $stmt->fetchColumn();
        
        // Payments made
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(s.amount), 0) FROM settlements s 
            WHERE s.from_business_id = ? AND to_char(s.created_at, \'YYYY-MM\') = ? AND s.status = \'verified\'');
        $stmt->execute([$business_id, $month]);
        $payments_made = (float) $stmt->fetchColumn();
        
        // Payments received
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(s.amount), 0) FROM settlements s 
            WHERE s.to_business_id = ? AND to_char(s.created_at, \'YYYY-MM\') = ? AND s.status = \'verified\'');
        $stmt->execute([$business_id, $month]);
        $payments_received = (float) $stmt->fetchColumn();
        
        // Opening balance (previous month closing)
        $prev_month = date('Y-m', strtotime($month . '-01 -1 month'));
        $stmt = $pdo->prepare('SELECT closing_balance FROM monthly_statements WHERE business_id = ? AND statement_month = ?');
        $stmt->execute([$business_id, $prev_month]);
        $opening_balance = (float) ($stmt->fetchColumn() ?: 0);
        
        // Calculate closing balance
        $closing_balance = $opening_balance + $commission_earned - $commission_payable - $employee_commission - $platform_fee - $payments_made + $payments_received;
        
        // Insert or update statement
        $stmt = $pdo->prepare('INSERT INTO monthly_statements 
            (business_id, statement_month, opening_balance, referral_value_generated, commission_earned, 
             commission_payable, employee_commission, platform_fee, payments_made, payments_received, 
             closing_balance, status, generated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT (business_id, statement_month) DO UPDATE SET
                referral_value_generated = EXCLUDED.referral_value_generated,
                commission_earned = EXCLUDED.commission_earned,
                commission_payable = EXCLUDED.commission_payable,
                employee_commission = EXCLUDED.employee_commission,
                platform_fee = EXCLUDED.platform_fee,
                payments_made = EXCLUDED.payments_made,
                payments_received = EXCLUDED.payments_received,
                closing_balance = EXCLUDED.closing_balance,
                status = \'final\',
                generated_at = NOW()');
        $stmt->execute([
            $business_id, $month, $opening_balance, $referral_value, $commission_earned,
            $commission_payable, $employee_commission, $platform_fee, $payments_made, $payments_received,
            $closing_balance, 'final'
        ]);
        
        log_audit($pdo, $business_id, (int)$user['id'], 'statement_generated', 'monthly_statement', null, null, ['month' => $month]);
        
        header("Location: /guestbridgerwanda/statements.php?month=" . urlencode($month) . "&generated=1");
        exit;
    } catch (Exception $e) {
        $errors[] = 'Error generating statement: ' . $e->getMessage();
    }
}

// Get statement if viewing
$statement = null;
if ($action === 'view') {
    $stmt = $pdo->prepare('SELECT * FROM monthly_statements WHERE business_id = ? AND statement_month = ?');
    $stmt->execute([$business_id, $statement_month]);
    $statement = $stmt->fetch();
}

// Get list of statements
$stmt = $pdo->prepare('SELECT * FROM monthly_statements WHERE business_id = ? ORDER BY statement_month DESC');
$stmt->execute([$business_id]);
$statements = $stmt->fetchAll();

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Statements - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Finance' => '#', 'Statements' => false]); ?>
            <?php echo render_page_header(
                'Monthly Statements',
                'View and generate monthly financial statements for your business.',
                []
            ); ?>

            <div class="row justify-content-center">
                <div class="col-12 col-lg-10">
                    <?php if (!empty($_GET['generated'])): ?>
                        <div class="alert alert-success">Statement generated successfully.</div>
                    <?php endif; ?>

                    <?php if ($action === 'view' && $statement): ?>
                        <div class="card shadow-sm">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Statement for <?php echo htmlspecialchars($statement['statement_month']); ?></h5>
                                <div>
                                    <a href="statement_print.php?month=<?php echo urlencode($statement['statement_month']); ?>" target="_blank" class="btn btn-sm btn-outline-primary me-2">
                                        <i data-lucide="printer" style="width:16px;height:16px;"></i> Print / PDF
                                    </a>
                                    <a href="/guestbridgerwanda/statements.php" class="btn btn-sm btn-outline-secondary">Back to List</a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row g-4">
                                    <div class="col-12 col-md-6">
                                        <h6 class="text-uppercase text-muted">Opening Balance</h6>
                                        <p class="h4">RWF <?php echo format_money($statement['opening_balance']); ?></p>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <h6 class="text-uppercase text-muted">Closing Balance</h6>
                                        <p class="h4">RWF <?php echo format_money($statement['closing_balance']); ?></p>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="row g-4">
                                    <div class="col-12 col-md-6">
                                        <h6 class="text-uppercase text-muted">Referral Value Generated</h6>
                                        <p class="h5">RWF <?php echo format_money($statement['referral_value_generated']); ?></p>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <h6 class="text-uppercase text-muted">Commission Earned</h6>
                                        <p class="h5 text-success">RWF <?php echo format_money($statement['commission_earned']); ?></p>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <h6 class="text-uppercase text-muted">Commission Payable</h6>
                                        <p class="h5 text-warning">RWF <?php echo format_money($statement['commission_payable']); ?></p>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <h6 class="text-uppercase text-muted">Employee Commissions</h6>
                                        <p class="h5">RWF <?php echo format_money($statement['employee_commission']); ?></p>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <h6 class="text-uppercase text-muted">GuestBridge Platform Fee</h6>
                                        <p class="h5">RWF <?php echo format_money($statement['platform_fee']); ?></p>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <h6 class="text-uppercase text-muted">Payments Made</h6>
                                        <p class="h5">RWF <?php echo format_money($statement['payments_made']); ?></p>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <h6 class="text-uppercase text-muted">Payments Received</h6>
                                        <p class="h5 text-success">RWF <?php echo format_money($statement['payments_received']); ?></p>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Generated: <?php echo $statement['generated_at'] ? date('M j, Y g:i A', strtotime($statement['generated_at'])) : 'Not generated'; ?></small>
                                    <span class="badge bg-<?php echo $statement['status'] === 'final' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($statement['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card shadow-sm mb-4">
                            <div class="card-body">
                                <h2 class="h5 mb-3">Generate Statement</h2>
                                <form method="post" action="statements.php?action=generate">
                                    <?php echo csrf_field(); ?>
                                    <div class="row g-3 align-items-end">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Month</label>
                                            <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($statement_month); ?>" required>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <button type="submit" class="btn btn-success">Generate Statement</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <?php if (!empty($statements)): ?>
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Previous Statements</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th>Referral Value</th>
                                                <th>Commission Earned</th>
                                                <th>Platform Fee</th>
                                                <th>Closing Balance</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($statements as $s): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($s['statement_month']); ?></td>
                                                <td>RWF <?php echo format_money($s['referral_value_generated']); ?></td>
                                                <td>RWF <?php echo format_money($s['commission_earned']); ?></td>
                                                <td>RWF <?php echo format_money($s['platform_fee']); ?></td>
                                                <td>RWF <?php echo format_money($s['closing_balance']); ?></td>
                                                <td><span class="badge bg-<?php echo $s['status'] === 'final' ? 'success' : 'warning'; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                                                <td>
                                                    <a href="/guestbridgerwanda/statements.php?action=view&month=<?php echo urlencode($s['statement_month']); ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
<script>
(function(){var t=document.getElementById('sidebarToggle'),s=document.getElementById('appSidebar'),o=document.getElementById('sidebarOverlay');function open(){s.classList.add('open');o.classList.add('active');}function close(){s.classList.remove('open');o.classList.remove('active');}if(t)t.addEventListener('click',open);if(o)o.addEventListener('click',close);document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});})();
</script>
</body>
</html>
