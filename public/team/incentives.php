<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();
require_manager();
$user = current_user();
$pdo = db_connect();
$business_id = (int) $user['business_id'];
$current_page = 'incentives.php';
$pending_requests = gb_pending_partnership_count($pdo, $business_id);

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$month = trim($_GET['month'] ?? date('Y-m'));
$status_filter = trim($_GET['status'] ?? '');
$staff_filter = intval($_GET['staff_id'] ?? 0);
$errors = [];
$flash = flash_get();

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Invalid month format.';
    $month = date('Y-m');
}
if ($status_filter !== '' && !array_key_exists($status_filter, reward_status_options())) {
    $errors[] = 'Invalid reward status.';
    $status_filter = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('incentives.php');
    $reward_id = intval($_POST['reward_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if ($reward_id <= 0) {
        $errors[] = 'Select a reward record to update.';
    }
    if (!in_array($action, ['approve', 'reject'], true)) {
        $errors[] = 'Choose a valid reward action.';
    }
    if (strlen($note) > 1000) {
        $errors[] = 'Reward notes must stay under 1,000 characters.';
    }

    if (empty($errors)) {
        $new_status = $action === 'approve' ? 'approved' : 'rejected';
        $approved_at_sql = $new_status === 'approved' ? 'NOW()' : 'NULL';
        $stmt = $pdo->prepare('UPDATE staff_rewards
            SET status = ?, note = ?, approved_by_user_id = ?, approved_at = ' . $approved_at_sql . '
            WHERE id = ? AND business_id = ?');
        $stmt->execute([$new_status, $note !== '' ? $note : null, $user['id'], $reward_id, $business_id]);

        if ($stmt->rowCount() > 0) {
            flash_set($new_status === 'approved' ? 'Reward points approved.' : 'Reward points rejected.');
            header('Location: incentives.php?month=' . urlencode($month) . ($status_filter !== '' ? '&status=' . urlencode($status_filter) : '') . ($staff_filter > 0 ? '&staff_id=' . intval($staff_filter) : ''));
            exit;
        }
        $errors[] = 'Reward record not found for your business.';
    }
}

$staff_stmt = $pdo->prepare('SELECT id, name FROM staff WHERE business_id = ? ORDER BY name');
$staff_stmt->execute([$business_id]);
$staff_options = $staff_stmt->fetchAll();

$where = 'WHERE sr.business_id = ? AND DATE_FORMAT(sr.created_at, "%Y-%m") = ?';
$params = [$business_id, $month];
if ($status_filter !== '') {
    $where .= ' AND sr.status = ?';
    $params[] = $status_filter;
}
if ($staff_filter > 0) {
    $where .= ' AND sr.staff_id = ?';
    $params[] = $staff_filter;
}

$stmt = $pdo->prepare('SELECT
        IFNULL(SUM(sr.points),0) AS total_points,
        IFNULL(SUM(CASE WHEN sr.status = "approved" THEN sr.points ELSE 0 END),0) AS approved_points,
        IFNULL(SUM(CASE WHEN sr.status = "pending" THEN sr.points ELSE 0 END),0) AS pending_points,
        IFNULL(SUM(CASE WHEN sr.status = "rejected" THEN sr.points ELSE 0 END),0) AS rejected_points,
        COUNT(*) AS reward_count
    FROM staff_rewards sr
    ' . $where);
$stmt->execute($params);
$summary = $stmt->fetch();

$stmt = $pdo->prepare('SELECT sr.staff_id, s.name AS staff_name,
        IFNULL(SUM(CASE WHEN sr.status = "approved" THEN sr.points ELSE 0 END),0) AS approved_points,
        IFNULL(SUM(CASE WHEN sr.status = "pending" THEN sr.points ELSE 0 END),0) AS pending_points,
        COUNT(*) AS referral_rewards
    FROM staff_rewards sr
    JOIN staff s ON s.id = sr.staff_id
    ' . $where . '
    GROUP BY sr.staff_id, s.name
    ORDER BY approved_points DESC, pending_points DESC, s.name
    LIMIT 20');
$stmt->execute($params);
$staff_totals = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT sr.*, s.name AS staff_name, r.referral_code, r.estimated_value, r.transaction_amount, r.commission_percentage, b.name AS partner_name, u.name AS approved_by_name
    FROM staff_rewards sr
    JOIN staff s ON s.id = sr.staff_id
    JOIN referrals r ON r.id = sr.referral_id
    JOIN businesses b ON b.id = r.target_business_id
    LEFT JOIN users u ON u.id = sr.approved_by_user_id
    ' . $where . '
    ORDER BY sr.created_at DESC
    LIMIT 150');
$stmt->execute($params);
$rewards = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff incentives - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
<?php echo render_breadcrumbs(['Management' => false, 'Staff incentives' => false]); ?>
<?php echo render_page_header(
    'Staff incentives',
    'Approve referral reward points before they count toward staff recognition.',
    [['href' => 'staff.php', 'label' => 'Manage staff', 'style' => 'outline-secondary']]
); ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <form class="row gx-2 gy-2 align-items-end" method="get">
                <div class="col-auto">
                    <label class="visually-hidden" for="month">Month</label>
                    <input type="month" id="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month); ?>">
                </div>
                <div class="col-auto">
                    <label class="visually-hidden" for="status">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="">All statuses</option>
                        <?php foreach (reward_status_options() as $status => $label): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="visually-hidden" for="staff_id">Staff</label>
                    <select id="staff_id" name="staff_id" class="form-select">
                        <option value="">All staff</option>
                        <?php foreach ($staff_options as $staff_option): ?>
                            <option value="<?php echo intval($staff_option['id']); ?>" <?php echo $staff_filter === (int)$staff_option['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($staff_option['name']); ?></option>
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
                            <h3 class="h6">Approved</h3>
                            <div class="display-6"><?php echo htmlspecialchars((int)$summary['approved_points']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <div class="card summary-card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Pending</h3>
                            <div class="display-6"><?php echo htmlspecialchars((int)$summary['pending_points']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <div class="card summary-card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Rejected</h3>
                            <div class="display-6"><?php echo htmlspecialchars((int)$summary['rejected_points']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <div class="card summary-card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Reward records</h3>
                            <div class="display-6"><?php echo htmlspecialchars((int)$summary['reward_count']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row gy-3 mt-2">
                <div class="col-12 col-xl-4">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Staff totals</h3>
                            <?php if ($staff_totals): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($staff_totals as $row): ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex justify-content-between">
                                                <strong><?php echo htmlspecialchars($row['staff_name']); ?></strong>
                                                <span><?php echo htmlspecialchars((int)$row['approved_points']); ?> approved</span>
                                            </div>
                                            <div class="small text-muted"><?php echo htmlspecialchars((int)$row['pending_points']); ?> pending from <?php echo htmlspecialchars((int)$row['referral_rewards']); ?> referral reward<?php echo (int)$row['referral_rewards'] === 1 ? '' : 's'; ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No reward records for this filter.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-8">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Reward log</h3>
                            <div class="table-responsive">
                                <table class="table table-modern table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Staff</th>
                                            <th>Referral</th>
                                            <th>Partner</th>
                                            <th>Points</th>
                                            <th>Status</th>
                                            <th>Review</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($rewards): ?>
                                            <?php foreach ($rewards as $reward): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($reward['created_at']))); ?></td>
                                                    <td><?php echo htmlspecialchars($reward['staff_name']); ?></td>
                                                    <td><a href="/guestbridgerwanda/view_referral.php?id=<?php echo intval($reward['referral_id']); ?>"><?php echo htmlspecialchars($reward['referral_code']); ?></a></td>
                                                    <td><?php echo htmlspecialchars($reward['partner_name']); ?></td>
                                                    <td><strong><?php echo htmlspecialchars((int)$reward['points']); ?></strong></td>
                                                    <td><span class="badge bg-<?php echo $reward['status'] === 'approved' ? 'success' : ($reward['status'] === 'rejected' ? 'secondary' : 'warning'); ?>"><?php echo htmlspecialchars(reward_status_label($reward['status'])); ?></span></td>
                                                    <td>
                                                        <?php if ($reward['status'] === 'pending'): ?>
                                                            <form method="post" class="d-flex flex-column gap-2">
                            <?php echo csrf_field(); ?>
                                                                <input type="hidden" name="reward_id" value="<?php echo intval($reward['id']); ?>">
                                                                <input type="text" name="note" class="form-control form-control-sm" placeholder="Optional note">
                                                                <div class="d-flex gap-2">
                                                                    <button class="btn btn-sm btn-success" name="action" value="approve">Approve</button>
                                                                    <button class="btn btn-sm btn-outline-secondary" name="action" value="reject">Reject</button>
                                                                </div>
                                                            </form>
                                                        <?php else: ?>
                                                            <div class="small text-muted">
                                                                <?php echo htmlspecialchars($reward['approved_by_name'] ?: 'Reviewed'); ?>
                                                                <?php if ($reward['approved_at']): ?>
                                                                    · <?php echo htmlspecialchars(date('Y-m-d', strtotime($reward['approved_at']))); ?>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if ($reward['note']): ?>
                                                                <div class="small"><?php echo htmlspecialchars($reward['note']); ?></div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted">No reward records yet. New referrals with staff selected will appear here.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
