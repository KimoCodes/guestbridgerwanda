<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Config/tenant.php';
db_init();

$user = require_tenant_user();
$pdo = db_connect();
$business_id = tenant_business_id($user);
$current_page = 'history.php';

$pending_requests = gb_pending_partnership_count($pdo, $business_id);

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$partner_id = (int) ($_GET['partner_id'] ?? 0);
$staff_filter = $_GET['staff_id'] ?? 'all';
$status_filter = $_GET['status'] ?? '';

tenant_assert_partner_filter($pdo, $business_id, $partner_id);

$partner_stmt = $pdo->prepare('SELECT DISTINCT b.id, b.name FROM (
        SELECT target_business_id AS pid FROM referrals WHERE source_business_id = ?
        UNION
        SELECT source_business_id AS pid FROM referrals WHERE target_business_id = ?
    ) x JOIN businesses b ON b.id = x.pid ORDER BY b.name');
$partner_stmt->execute([$business_id, $business_id]);
$partner_options = $partner_stmt->fetchAll();

$staff_stmt = $pdo->prepare('SELECT id, name FROM staff WHERE business_id = ? ORDER BY name');
$staff_stmt->execute([$business_id]);
$staff_options = $staff_stmt->fetchAll();

$my_staff_id = tenant_staff_id_for_user($pdo, $user);

$status_options = [
    '' => 'All statuses',
    'created' => 'Open (awaiting use)',
    'used' => 'Used',
    'expired' => 'Expired',
];

$sql = 'SELECT r.id, r.referral_code, r.source_business_id, r.target_business_id, r.staff_id,
        r.note, r.commission_percentage, r.estimated_value, r.transaction_amount, r.status, r.created_at,
        s.name AS staff_name,
        source_b.name AS source_business,
        target_b.name AS target_business,
        (SELECT c.status FROM commissions c
            WHERE c.referral_id = r.id
              AND (c.source_business_id = ? OR c.target_business_id = ? OR c.owed_to_business_id = ?)
            ORDER BY c.id DESC LIMIT 1) AS commission_status
    FROM referrals r
    INNER JOIN businesses source_b ON source_b.id = r.source_business_id
    INNER JOIN businesses target_b ON target_b.id = r.target_business_id
    LEFT JOIN staff s ON s.id = r.staff_id AND s.business_id = ?
    WHERE (r.source_business_id = ? OR r.target_business_id = ?)';
$params = [$business_id, $business_id, $business_id, $business_id, $business_id, $business_id];

if ($partner_id > 0) {
    $sql .= ' AND (r.target_business_id = ? OR r.source_business_id = ?)';
    $params[] = $partner_id;
    $params[] = $partner_id;
}

if ($staff_filter === 'me') {
    if ($my_staff_id) {
        $sql .= ' AND r.staff_id = ?';
        $params[] = $my_staff_id;
    } else {
        $sql .= ' AND r.staff_id IS NULL';
    }
} elseif ((int) $staff_filter > 0) {
    $staff_id_filter = (int) $staff_filter;
    if (!tenant_staff_belongs($pdo, $staff_id_filter, $business_id)) {
        http_response_code(403);
        die('Invalid staff filter.');
    }
    $sql .= ' AND r.staff_id = ?';
    $params[] = $staff_id_filter;
}

if (in_array($status_filter, ['created', 'used', 'expired'], true)) {
    $sql .= ' AND r.status = ?';
    $params[] = $status_filter;
}

$search_q = trim($_GET['q'] ?? '');
if ($search_q !== '') {
    $like = '%' . $search_q . '%';
    $sql .= ' AND (r.referral_code LIKE ? OR r.note LIKE ? OR source_b.name LIKE ? OR target_b.name LIKE ? OR COALESCE(s.name, \'\') LIKE ?';
    array_push($params, $like, $like, $like, $like, $like);
    if (ctype_digit($search_q)) {
        $sql .= ' OR r.id = ?';
        $params[] = (int) $search_q;
    }
    $sql .= ')';
}

$sql .= ' ORDER BY r.created_at DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$referrals = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral History - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Referrals' => 'history.php']); ?>
            <?php echo render_page_header(
                'Referral History',
                'Referrals sent or received by your business only.',
                [['href' => 'create_referral.php', 'label' => '+ New Referral', 'style' => 'success']]
            ); ?>

            <div class="alert alert-light border mb-3 py-2">
                <strong>Your business:</strong>
                <?php echo htmlspecialchars($user['business_name']); ?>
                <span class="text-muted">· <?php echo htmlspecialchars(business_type_label($user['business_type'] ?? 'hotel')); ?>
                · <?php echo htmlspecialchars(city_label($user['business_city'] ?? 'Kigali')); ?></span>
            </div>

            <?php if ($search_q !== ''): ?>
            <div class="alert alert-info py-2">Showing results for <strong><?php echo htmlspecialchars($search_q); ?></strong>. <a href="/guestbridgerwanda/history.php">Clear search</a></div>
            <?php endif; ?>
            <div class="card">
                <div class="card-body">
                    <form method="get" class="filter-bar">
                        <?php if ($search_q !== ''): ?>
                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($search_q); ?>">
                        <?php endif; ?>
                        <div class="col-auto">
                            <label class="form-label">Partner</label>
                            <select name="partner_id" class="form-select">
                                <option value="">All partners</option>
                                <?php foreach ($partner_options as $po): ?>
                                    <option value="<?php echo (int) $po['id']; ?>" <?php echo $partner_id === (int) $po['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($po['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="form-label">Staff</label>
                            <select name="staff_id" class="form-select">
                                <option value="all" <?php echo $staff_filter === 'all' ? 'selected' : ''; ?>>All staff</option>
                                <option value="me" <?php echo $staff_filter === 'me' ? 'selected' : ''; ?>>My referrals</option>
                                <?php foreach ($staff_options as $so): ?>
                                    <option value="<?php echo (int) $so['id']; ?>" <?php echo $staff_filter === (string) $so['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($so['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach ($status_options as $val => $label): ?>
                                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $status_filter === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto d-grid">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary">Filter</button>
                        </div>
                    </form>
                    <?php if ($referrals): ?>
                        <div class="table-responsive">
                            <table class="table table-modern table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Counterparty</th>
                                        <th>Direction</th>
                                        <th>Staff</th>
                                        <th>Referral Status</th>
                                        <th>Commission Status</th>
                                        <th>Commission</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($referrals as $ref): ?>
                                    <?php
                                        $outbound = (int) $ref['source_business_id'] === $business_id;
                                        $involves_you = (int) $ref['source_business_id'] === $business_id
                                            || (int) $ref['target_business_id'] === $business_id;
                                    ?>
                                    <?php if (!$involves_you) {
                                        continue;
                                    } ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($ref['created_at']))); ?></td>
                                        <td><?php echo htmlspecialchars($outbound ? $ref['target_business'] : $ref['source_business']); ?></td>
                                        <td><span class="badge bg-<?php echo $outbound ? 'primary' : 'info'; ?>"><?php echo $outbound ? 'Outbound' : 'Inbound'; ?></span></td>
                                        <td><?php echo htmlspecialchars($ref['staff_name'] ?: '—'); ?></td>
                                        <td><span class="badge bg-<?php echo $ref['status'] === 'used' ? 'success' : ($ref['status'] === 'expired' ? 'secondary' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($ref['status'])); ?></span></td>
                                        <td><span class="badge bg-<?php echo $ref['commission_status'] === 'confirmed' ? 'success' : ($ref['commission_status'] === 'reconciled' ? 'dark' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($ref['commission_status'] ?? 'pending')); ?></span></td>
                                        <td>RWF <?php echo format_money(!empty($ref['transaction_amount']) ? $ref['transaction_amount'] : ($ref['estimated_value'] * $ref['commission_percentage']) / 100); ?></td>
                                        <td><a href="/guestbridgerwanda/view_referral.php?id=<?php echo (int) $ref['id']; ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-title">No referrals found</div>
                            <div class="empty-state-desc">Try adjusting your filters, or create your first referral to get started.</div>
                            <a href="/guestbridgerwanda/create_referral.php" class="btn btn-primary btn-sm">Create Referral</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
