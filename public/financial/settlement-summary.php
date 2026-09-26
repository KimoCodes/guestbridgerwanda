<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();
$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$current_page = 'settlement_summary.php';

$pending_requests = gb_pending_partnership_count($pdo, $business_id);

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$month = trim($_GET['month'] ?? date('Y-m'));
$partner_id = intval($_GET['partner_id'] ?? 0);
$errors = [];

tenant_assert_partner_filter($pdo, $business_id, $partner_id);
$flash = flash_get();

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Invalid month format.';
    $month = date('Y-m');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('settlement_summary.php');
    if ($user['role'] !== 'manager') {
        $errors[] = 'Only managers can save partner sign-off notes.';
    }

    $partner_id = intval($_POST['partner_id'] ?? 0);
    $status = trim($_POST['status'] ?? 'pending');
    $note = trim($_POST['note'] ?? '');

    if ($partner_id <= 0) {
        $errors[] = 'Choose one partner before saving sign-off notes.';
    }
    if (!array_key_exists($status, signoff_status_options())) {
        $errors[] = 'Choose a valid sign-off status.';
        $status = 'pending';
    }
    if (strlen($note) > 2000) {
        $errors[] = 'Sign-off notes must stay under 2,000 characters.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM businesses WHERE id = ? AND id != ?');
        $stmt->execute([$partner_id, $business_id]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors[] = 'Partner business not found.';
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM commissions
            WHERE month = ?
                AND (owed_to_business_id = ? OR target_business_id = ?)
                AND (owed_to_business_id = ? OR target_business_id = ?)');
        $stmt->execute([$month, $business_id, $business_id, $partner_id, $partner_id]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors[] = 'No settlement activity exists for this partner and month.';
        }
    }

    if (empty($errors)) {
        $signed_at_sql = $status === 'signed' ? 'NOW()' : 'NULL';
        $signed_by = $status === 'signed' ? (int)$user['id'] : null;
        $stmt = $pdo->prepare('INSERT INTO settlement_signoffs
                (business_id, partner_business_id, billing_month, status, note, signed_by_user_id, signed_at)
            VALUES (?, ?, ?, ?, ?, ?, ' . $signed_at_sql . ')
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                note = VALUES(note),
                signed_by_user_id = VALUES(signed_by_user_id),
                signed_at = VALUES(signed_at)');
        $stmt->execute([$business_id, $partner_id, $month, $status, $note !== '' ? $note : null, $signed_by]);
        flash_set('Partner sign-off notes saved.');
        header('Location: /guestbridgerwanda/settlement_summary.php?month=' . urlencode($month) . '&partner_id=' . intval($partner_id));
        exit;
    }
}

$partner_stmt = $pdo->prepare('SELECT DISTINCT b.id, b.name
    FROM commissions c
    JOIN businesses b ON b.id = CASE WHEN c.owed_to_business_id = ? THEN c.target_business_id ELSE c.owed_to_business_id END
    WHERE (c.owed_to_business_id = ? OR c.target_business_id = ?) AND c.month = ?
    ORDER BY b.name');
$partner_stmt->execute([$business_id, $business_id, $business_id, $month]);
$partner_options = $partner_stmt->fetchAll();

$where = 'WHERE (c.owed_to_business_id = ? OR c.target_business_id = ?) AND c.month = ?';
$params = [$business_id, $business_id, $month];

if ($partner_id > 0) {
    $where .= ' AND (c.owed_to_business_id = ? OR c.target_business_id = ?)';
    $params[] = $partner_id;
    $params[] = $partner_id;
}

$sql = 'SELECT
        partner.id AS partner_id,
        partner.name AS partner_name,
        COUNT(c.id) AS commission_count,
        IFNULL(SUM(CASE WHEN c.owed_to_business_id = ? THEN c.amount ELSE 0 END),0) AS receivable_total,
        IFNULL(SUM(CASE WHEN c.target_business_id = ? THEN c.amount ELSE 0 END),0) AS payable_total,
        IFNULL(SUM(CASE WHEN c.status = "pending" THEN c.amount ELSE 0 END),0) AS pending_total,
        IFNULL(SUM(CASE WHEN c.status = "confirmed" THEN c.amount ELSE 0 END),0) AS confirmed_total,
        IFNULL(SUM(CASE WHEN c.status = "reconciled" THEN c.amount ELSE 0 END),0) AS reconciled_total
    FROM commissions c
    JOIN businesses partner ON partner.id = CASE WHEN c.owed_to_business_id = ? THEN c.target_business_id ELSE c.owed_to_business_id END
    ' . $where . '
    GROUP BY partner.id, partner.name
    ORDER BY partner.name';
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$business_id, $business_id, $business_id], $params));
$summary_rows = $stmt->fetchAll();

$signoff_sql = 'SELECT s.*, u.name AS signed_by_name
    FROM settlement_signoffs s
    LEFT JOIN users u ON u.id = s.signed_by_user_id
    WHERE s.business_id = ? AND s.billing_month = ?';
$signoff_params = [$business_id, $month];
if ($partner_id > 0) {
    $signoff_sql .= ' AND s.partner_business_id = ?';
    $signoff_params[] = $partner_id;
}
$stmt = $pdo->prepare($signoff_sql);
$stmt->execute($signoff_params);
$signoff_rows = [];
foreach ($stmt->fetchAll() as $row) {
    $signoff_rows[(int)$row['partner_business_id']] = $row;
}

$payment_sql = 'SELECT
        c.target_business_id AS partner_id,
        IFNULL(SUM(p.amount),0) AS paid_total,
        IFNULL(SUM(CASE WHEN p.status = "verified" THEN p.amount ELSE 0 END),0) AS verified_total,
        IFNULL(SUM(CASE WHEN p.status = "disputed" THEN p.amount ELSE 0 END),0) AS disputed_total
    FROM payments p
    JOIN commissions c ON c.id = p.commission_id
    WHERE c.owed_to_business_id = ? AND c.month = ?';
$payment_params = [$business_id, $month];
if ($partner_id > 0) {
    $payment_sql .= ' AND c.target_business_id = ?';
    $payment_params[] = $partner_id;
}
$payment_sql .= '
    GROUP BY c.target_business_id';
$stmt = $pdo->prepare($payment_sql);
$stmt->execute($payment_params);
$payment_rows = [];
foreach ($stmt->fetchAll() as $row) {
    $payment_rows[(int)$row['partner_id']] = $row;
}

foreach ($summary_rows as &$row) {
    $payment = $payment_rows[(int)$row['partner_id']] ?? [
        'paid_total' => 0,
        'verified_total' => 0,
        'disputed_total' => 0,
    ];
    $row['paid_total'] = (float)$payment['paid_total'];
    $row['verified_total'] = (float)$payment['verified_total'];
    $row['disputed_total'] = (float)$payment['disputed_total'];
    $row['net_position'] = (float)$row['receivable_total'] - (float)$row['payable_total'];
    $row['open_balance'] = max(0, abs((float)$row['net_position']) - (float)$row['paid_total']);
    $row['signoff'] = $signoff_rows[(int)$row['partner_id']] ?? null;
}
unset($row);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="settlement-summary-' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Partner', 'Commissions', 'Receivable', 'Payable', 'Net Position', 'Paid', 'Verified', 'Disputed', 'Open Balance', 'Sign-off Status', 'Sign-off Note', 'Signed By', 'Signed At']);
    foreach ($summary_rows as $row) {
        $signoff = $row['signoff'] ?? null;
        fputcsv($output, [
            $row['partner_name'],
            $row['commission_count'],
            $row['receivable_total'],
            $row['payable_total'],
            $row['net_position'],
            $row['paid_total'],
            $row['verified_total'],
            $row['disputed_total'],
            $row['open_balance'],
            $signoff ? signoff_status_label($signoff['status']) : signoff_status_label('pending'),
            $signoff['note'] ?? '',
            $signoff['signed_by_name'] ?? '',
            $signoff['signed_at'] ?? '',
        ]);
    }
    exit;
}

$total_receivable = array_sum(array_column($summary_rows, 'receivable_total'));
$total_payable = array_sum(array_column($summary_rows, 'payable_total'));
$total_paid = array_sum(array_column($summary_rows, 'paid_total'));
$total_disputed = array_sum(array_column($summary_rows, 'disputed_total'));
$total_net = $total_receivable - $total_payable;
$selected_summary = null;
foreach ($summary_rows as $row) {
    if ((int)$row['partner_id'] === $partner_id) {
        $selected_summary = $row;
        break;
    }
}
$selected_signoff = $selected_summary['signoff'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settlement summary - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
<?php echo render_breadcrumbs(['Finance' => 'commissions.php', 'Settlement summary' => false]); ?>
<?php echo render_page_header(
    'Settlement summary',
    'Monthly partner review sheet for manual settlement conversations.',
    [
        ['href' => 'settlement_summary.php?month=' . urlencode($month) . ($partner_id ? '&partner_id=' . intval($partner_id) : '') . '&export=csv', 'label' => 'Export summary', 'style' => 'outline-secondary'],
        ['href' => 'transactions.php?month=' . urlencode($month) . ($partner_id ? '&partner_id=' . intval($partner_id) : ''), 'label' => 'Transactions', 'style' => 'outline-secondary'],
    ]
); ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <form class="row gx-2 gy-2 align-items-end" method="get">
                <div class="col-auto">
                    <label class="visually-hidden" for="month">Month</label>
                    <input type="month" id="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month); ?>">
                </div>
                <div class="col-auto">
                    <label class="visually-hidden" for="partner_id">Partner</label>
                    <select id="partner_id" name="partner_id" class="form-select">
                        <option value="">All partners</option>
                        <?php foreach ($partner_options as $partner_option): ?>
                            <option value="<?php echo intval($partner_option['id']); ?>" <?php echo $partner_id === intval($partner_option['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($partner_option['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto d-grid">
                    <button class="btn btn-primary">Filter</button>
                </div>
            </form>

            <?php if ($errors): ?>
                <div class="alert alert-danger mt-3">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="alert alert-success mt-3"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>

            <div class="row gy-3 mt-3">
                <div class="col-12 col-md-3">
                    <div class="card summary-card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Receivable</h3>
                            <div class="display-6">RWF <?php echo format_money($total_receivable); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <div class="card summary-card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Payable</h3>
                            <div class="display-6">RWF <?php echo format_money($total_payable); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <div class="card summary-card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Paid</h3>
                            <div class="display-6">RWF <?php echo format_money($total_paid); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <div class="card summary-card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Net</h3>
                            <div class="display-6">RWF <?php echo format_money($total_net); ?></div>
                            <div class="small text-muted"><?php echo $total_net >= 0 ? 'Net receivable' : 'Net payable'; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive mt-4">
                <table class="table table-modern table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Partner</th>
                            <th>Commissions</th>
                            <th>Receivable</th>
                            <th>Payable</th>
                            <th>Net</th>
                            <th>Paid</th>
                            <th>Verified</th>
                            <th>Disputed</th>
                            <th>Open balance</th>
                            <th>Sign-off</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($summary_rows): ?>
                            <?php foreach ($summary_rows as $row): ?>
                                <tr>
                                    <td><a href="/guestbridgerwanda/settlement_summary.php?month=<?php echo urlencode($month); ?>&partner_id=<?php echo intval($row['partner_id']); ?>"><?php echo htmlspecialchars($row['partner_name']); ?></a></td>
                                    <td><?php echo htmlspecialchars($row['commission_count']); ?></td>
                                    <td>RWF <?php echo format_money($row['receivable_total']); ?></td>
                                    <td>RWF <?php echo format_money($row['payable_total']); ?></td>
                                    <td>RWF <?php echo format_money($row['net_position']); ?></td>
                                    <td>RWF <?php echo format_money($row['paid_total']); ?></td>
                                    <td>RWF <?php echo format_money($row['verified_total']); ?></td>
                                    <td>RWF <?php echo format_money($row['disputed_total']); ?></td>
                                    <td>RWF <?php echo format_money($row['open_balance']); ?></td>
                                    <td>
                                        <?php $signoff = $row['signoff'] ?? null; ?>
                                        <span class="badge bg-<?php echo ($signoff['status'] ?? 'pending') === 'signed' ? 'success' : (($signoff['status'] ?? 'pending') === 'disputed' ? 'danger' : 'warning'); ?>">
                                            <?php echo htmlspecialchars(signoff_status_label($signoff['status'] ?? 'pending')); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted">No settlement data for this month.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($selected_summary): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-column flex-lg-row gap-2">
                            <div>
                                <h3 class="h6 mb-1">Monthly partner sign-off</h3>
                                <p class="text-muted mb-0">Record the outcome of the review with <?php echo htmlspecialchars($selected_summary['partner_name']); ?> for <?php echo htmlspecialchars($month); ?>.</p>
                            </div>
                            <?php if ($selected_signoff && $selected_signoff['signed_at']): ?>
                                <span class="badge bg-success">Signed <?php echo htmlspecialchars(date('M j, Y', strtotime($selected_signoff['signed_at']))); ?></span>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="row gy-3 mt-2">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="partner_id" value="<?php echo intval($selected_summary['partner_id']); ?>">
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="status">Review status</label>
                                <select id="status" name="status" class="form-select" <?php echo $user['role'] === 'manager' ? '' : 'disabled'; ?>>
                                    <?php foreach (signoff_status_options() as $status_value => $status_label): ?>
                                        <option value="<?php echo htmlspecialchars($status_value); ?>" <?php echo ($selected_signoff['status'] ?? 'pending') === $status_value ? 'selected' : ''; ?>><?php echo htmlspecialchars($status_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="note">Partner review notes</label>
                                <textarea id="note" name="note" class="form-control" rows="4" maxlength="2000" <?php echo $user['role'] === 'manager' ? '' : 'readonly'; ?>><?php echo htmlspecialchars($selected_signoff['note'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-12 d-flex justify-content-between align-items-center flex-column flex-md-row gap-2">
                                <div class="small text-muted">
                                    <?php if (!empty($selected_signoff['signed_by_name'])): ?>
                                        Last signed by <?php echo htmlspecialchars($selected_signoff['signed_by_name']); ?>.
                                    <?php else: ?>
                                        Select a single partner above to capture review notes.
                                    <?php endif; ?>
                                </div>
                                <?php if ($user['role'] === 'manager'): ?>
                                    <button class="btn btn-primary">Save sign-off notes</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            <?php elseif ($partner_id > 0): ?>
                <div class="alert alert-info mt-4 mb-0">No settlement rows found for this partner and month, so sign-off notes are not available yet.</div>
            <?php else: ?>
                <div class="alert alert-info mt-4 mb-0">Choose one partner to record monthly sign-off notes after a review call or meeting.</div>
            <?php endif; ?>

            <?php if ($total_disputed > 0): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    RWF <?php echo format_money($total_disputed); ?> is disputed. Review the payment journal before confirming final settlement.
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
