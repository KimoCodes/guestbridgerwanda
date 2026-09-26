<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];

$statement_month = $_GET['month'] ?? null;

if (!$statement_month) {
    header("Location: /guestbridgerwanda/statements.php");
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM monthly_statements WHERE business_id = ? AND statement_month = ?');
$stmt->execute([$business_id, $statement_month]);
$statement = $stmt->fetch();

if (!$statement) {
    header("Location: /guestbridgerwanda/statements.php");
    exit;
}

$business = null;
$stmt = $pdo->prepare('SELECT * FROM businesses WHERE id = ?');
$stmt->execute([$business_id]);
$business = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statement <?php echo htmlspecialchars($statement_month); ?> - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12pt; color: #333; line-height: 1.5; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; border-bottom: 3px solid #2563eb; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { color: #2563eb; font-size: 24pt; margin-bottom: 5px; }
        .header .subtitle { color: #666; font-size: 14pt; }
        .business-info { margin-bottom: 30px; padding: 15px; background: #f8fafc; border-radius: 8px; }
        .business-info h3 { color: #2563eb; margin-bottom: 10px; font-size: 14pt; }
        .statement-meta { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .statement-meta div { padding: 10px; background: #f1f5f9; border-radius: 6px; }
        .statement-meta .label { font-size: 10pt; color: #666; text-transform: uppercase; }
        .statement-meta .value { font-size: 14pt; font-weight: bold; color: #1e293b; }
        .section { margin-bottom: 25px; }
        .section h4 { color: #475569; font-size: 12pt; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 15px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dotted #e2e8f0; }
        .row:last-child { border-bottom: none; }
        .row .label { color: #64748b; }
        .row .value { font-weight: 600; }
        .row .value.positive { color: #16a34a; }
        .row .value.negative { color: #dc2626; }
        .total-row { display: flex; justify-content: space-between; padding: 12px 0; border-top: 2px solid #2563eb; margin-top: 10px; font-size: 14pt; font-weight: bold; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 2px solid #e2e8f0; text-align: center; color: #94a3b8; font-size: 10pt; }
        .print-btn { position: fixed; top: 20px; right: 20px; background: #2563eb; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 12pt; }
        @media print {
            .print-btn { display: none; }
            body { font-size: 11pt; }
            .container { padding: 0; }
        }
    </style>
</head>
<body>
<button class="print-btn" onclick="window.print()">Print / Save as PDF</button>

<div class="container">
    <div class="header">
        <h1><?php echo htmlspecialchars(APP_NAME); ?></h1>
        <div class="subtitle">Monthly Financial Statement</div>
    </div>

    <div class="business-info">
        <h3>Business Information</h3>
        <p><strong><?php echo htmlspecialchars($business['name'] ?? ''); ?></strong></p>
        <p><?php echo htmlspecialchars($business['email'] ?? ''); ?></p>
        <p><?php echo htmlspecialchars($business['address'] ?? ''); ?></p>
        <p><?php echo htmlspecialchars($business['phone'] ?? ''); ?></p>
    </div>

    <div class="statement-meta">
        <div>
            <div class="label">Statement Period</div>
            <div class="value"><?php echo htmlspecialchars(date('F Y', strtotime($statement_month . '-01'))); ?></div>
        </div>
        <div>
            <div class="label">Generated</div>
            <div class="value"><?php echo $statement['generated_at'] ? date('M j, Y', strtotime($statement['generated_at'])) : 'N/A'; ?></div>
        </div>
        <div>
            <div class="label">Status</div>
            <div class="value"><?php echo ucfirst($statement['status']); ?></div>
        </div>
    </div>

    <div class="section">
        <h4>Balance Summary</h4>
        <div class="row">
            <span class="label">Opening Balance</span>
            <span class="value">RWF <?php echo format_money($statement['opening_balance']); ?></span>
        </div>
        <div class="row">
            <span class="label">Closing Balance</span>
            <span class="value <?php echo $statement['closing_balance'] >= 0 ? 'positive' : 'negative'; ?>">RWF <?php echo format_money($statement['closing_balance']); ?></span>
        </div>
    </div>

    <div class="section">
        <h4>Income</h4>
        <div class="row">
            <span class="label">Referral Value Generated</span>
            <span class="value positive">RWF <?php echo format_money($statement['referral_value_generated']); ?></span>
        </div>
        <div class="row">
            <span class="label">Commission Earned</span>
            <span class="value positive">RWF <?php echo format_money($statement['commission_earned']); ?></span>
        </div>
        <div class="row">
            <span class="label">Payments Received</span>
            <span class="value positive">RWF <?php echo format_money($statement['payments_received']); ?></span>
        </div>
    </div>

    <div class="section">
        <h4>Expenses</h4>
        <div class="row">
            <span class="label">Commission Payable</span>
            <span class="value negative">RWF <?php echo format_money($statement['commission_payable']); ?></span>
        </div>
        <div class="row">
            <span class="label">Employee Commissions</span>
            <span class="value negative">RWF <?php echo format_money($statement['employee_commission']); ?></span>
        </div>
        <div class="row">
            <span class="label">GuestBridge Platform Fee</span>
            <span class="value negative">RWF <?php echo format_money($statement['platform_fee']); ?></span>
        </div>
        <div class="row">
            <span class="label">Payments Made</span>
            <span class="value negative">RWF <?php echo format_money($statement['payments_made']); ?></span>
        </div>
    </div>

    <div class="total-row">
        <span>Net Position</span>
        <span class="<?php echo ($statement['commission_earned'] - $statement['commission_payable'] - $statement['employee_commission'] - $statement['platform_fee']) >= 0 ? 'positive' : 'negative'; ?>">
            RWF <?php echo format_money($statement['commission_earned'] - $statement['commission_payable'] - $statement['employee_commission'] - $statement['platform_fee']); ?>
        </span>
    </div>

    <div class="footer">
        <p>This statement is generated by <?php echo htmlspecialchars(APP_NAME); ?>.</p>
        <p>For questions, contact support@guestbridgerwanda.com</p>
        <p>Document generated on: <?php echo date('M j, Y g:i A'); ?></p>
    </div>
</div>
</body>
</html>
