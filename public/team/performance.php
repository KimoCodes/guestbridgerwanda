<?php
/**
 * Employee Referral Performance
 * Shows individual employee referral stats, earnings, and leaderboard position.
 */
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Services/ServiceContainer.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$services = ServiceContainer::getInstance($pdo);
$business_id = tenant_business_id($user);
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);

// Determine which staff to show
$staff_id = 0;
$staff = null;

if ($is_manager && isset($_GET['staff_id'])) {
    $staff_id = intval($_GET['staff_id']);
    $stmt = $pdo->prepare('SELECT s.*, u.name AS user_name FROM staff s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ? AND s.business_id = ?');
    $stmt->execute([$staff_id, $business_id]);
    $staff = $stmt->fetch();
} else {
    $stmt = $pdo->prepare('SELECT s.*, u.name AS user_name FROM staff s LEFT JOIN users u ON s.user_id = u.id WHERE s.user_id = ? AND s.business_id = ?');
    $stmt->execute([(int) $user['id'], $business_id]);
    $staff = $stmt->fetch();
    if ($staff) {
        $staff_id = (int) $staff['id'];
    }
}

if (!$staff) {
    flash_set('No staff profile found. Contact your manager.');
    header('Location: /guestbridgerwanda/dashboard.php');
    exit;
}

// Get identity first (needed for getEmployeePerformance)
$identity = $services->staffIdentities()->getActiveIdentity($staff_id, $business_id);

// Get performance stats using identity_id
$stats = [];
$current_month = date('Y-m');
if ($identity) {
    $stats = $services->staffIdentities()->getEmployeePerformance((int) $identity['id'], $current_month);
}

// Get referral history for this staff
$stmt = $pdo->prepare('SELECT r.*, b1.name AS source_business, b2.name AS target_business
    FROM referrals r
    JOIN businesses b1 ON r.source_business_id = b1.id
    JOIN businesses b2 ON r.target_business_id = b2.id
    WHERE r.staff_id = ? AND (r.source_business_id = ? OR r.target_business_id = ?)
    ORDER BY r.created_at DESC LIMIT 20');
$stmt->execute([$staff_id, $business_id, $business_id]);
$recent_referrals = $stmt->fetchAll();

// Get all-time earnings from commissions this staff generated
$stmt = $pdo->prepare('SELECT COALESCE(SUM(c.amount), 0) AS total_earned
    FROM commissions c
    JOIN referrals r ON c.referral_id = r.id
    WHERE r.staff_id = ? AND c.owed_to_business_id = ?');
$stmt->execute([$staff_id, $business_id]);
$total_earned = $stmt->fetchColumn();

// Current month earnings
$stmt = $pdo->prepare('SELECT COALESCE(SUM(c.amount), 0)
    FROM commissions c
    JOIN referrals r ON c.referral_id = r.id
    WHERE r.staff_id = ? AND c.owed_to_business_id = ? AND c.month = ?');
$stmt->execute([$staff_id, $business_id, $current_month]);
$month_earned = $stmt->fetchColumn();

// Leaderboard
$leaderboard = $services->staffIdentities()->getLeaderboard($business_id, null, 10);

// Compute conversion rate for leaderboard entries
foreach ($leaderboard as &$entry) {
    $entry['conversion_rate'] = (int) $entry['total_referrals'] > 0
        ? round(((int) $entry['successful_referrals'] / (int) $entry['total_referrals']) * 100, 1)
        : 0;
}
unset($entry);

// All staff for manager dropdown
$all_staff = [];
if ($is_manager) {
    $stmt = $pdo->prepare('SELECT s.id, s.name, s.role FROM staff s WHERE s.business_id = ? ORDER BY s.name');
    $stmt->execute([$business_id]);
    $all_staff = $stmt->fetchAll();
}

$pending_requests = 0;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = (int) $stmt->fetchColumn();

$current_page = 'performance.php';

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Performance - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Team' => 'staff.php', 'My Performance' => false]); ?>
            <?php echo render_page_header(
                'Referral Performance',
                'Your referral stats and leaderboard position.',
                $is_manager ? [['href' => 'my-identity.php', 'label' => 'My Identity', 'style' => 'outline-primary']] : []
            ); ?>

            <?php if ($is_manager && $all_staff): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end">
                        <div class="col-auto">
                            <label class="form-label small">View Employee</label>
                            <select name="staff_id" class="form-select" onchange="this.form.submit()">
                                <?php foreach ($all_staff as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo $s['id'] == $staff_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?> (<?php echo htmlspecialchars($s['role']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="fs-2 fw-bold text-primary"><?php echo $stats['total_referrals'] ?? 0; ?></div>
                            <div class="text-muted small">Total Referrals</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="fs-2 fw-bold text-success"><?php echo $stats['converted_count'] ?? 0; ?></div>
                            <div class="text-muted small">Converted</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="fs-2 fw-bold text-info"><?php echo $stats['conversion_rate'] ?? 0; ?>%</div>
                            <div class="text-muted small">Conversion Rate</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="fs-2 fw-bold text-warning">RWF <?php echo number_format((float) ($stats['total_commission'] ?? 0)); ?></div>
                            <div class="text-muted small">Total Commission</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Performance Details -->
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-body">
                            <h3 class="text-section-heading">Performance Details</h3>
                            <dl class="row mb-0">
                                <dt class="col-5">Staff Name</dt>
                                <dd class="col-7"><?php echo htmlspecialchars($staff['name']); ?></dd>
                                <dt class="col-5">Role</dt>
                                <dd class="col-7"><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($staff['role'])); ?></span></dd>
                                <dt class="col-5">Identity Code</dt>
                                <dd class="col-7"><code><?php echo $identity ? htmlspecialchars($identity['public_identity_code']) : 'N/A'; ?></code></dd>
                                <dt class="col-5">Pending Verification</dt>
                                <dd class="col-7"><?php echo $stats['verified_count'] ?? 0; ?></dd>
                                <dt class="col-5">Accepted</dt>
                                <dd class="col-7"><?php echo $stats['accepted_count'] ?? 0; ?></dd>
                                <dt class="col-5">Redeemed</dt>
                                <dd class="col-7"><?php echo $stats['redeemed_count'] ?? 0; ?></dd>
                                <dt class="col-5">Settled Commission</dt>
                                <dd class="col-7">RWF <?php echo number_format((float) ($stats['settled_commission'] ?? 0)); ?></dd>
                                <dt class="col-5">Pending Commission</dt>
                                <dd class="col-7">RWF <?php echo number_format((float) ($stats['pending_commission'] ?? 0)); ?></dd>
                            </dl>
                        </div>
                    </div>

                    <!-- Recent Referrals -->
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Recent Referrals</h3>
                            <?php if (empty($recent_referrals)): ?>
                                <p class="text-muted text-center mb-0">No referrals yet.</p>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>To</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($recent_referrals as $r): ?>
                                        <tr>
                                            <td><code class="small"><?php echo htmlspecialchars($r['referral_code']); ?></code></td>
                                            <td class="small"><?php echo htmlspecialchars($r['target_business']); ?></td>
                                            <td><span class="badge bg-<?php echo referral_status_color($r['status']); ?> small"><?php echo htmlspecialchars(referral_status_label($r['status'])); ?></span></td>
                                            <td class="small text-muted"><?php echo htmlspecialchars(date('d M', strtotime($r['created_at']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Leaderboard -->
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Leaderboard</h3>
                            <?php if (empty($leaderboard)): ?>
                                <p class="text-muted text-center mb-0">No data yet.</p>
                            <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($leaderboard as $i => $entry): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center <?php echo $entry['staff_id'] == $staff_id ? 'bg-light' : ''; ?>">
                                    <div>
                                        <span class="badge bg-<?php echo $i === 0 ? 'warning' : ($i === 1 ? 'secondary' : ($i === 2 ? 'info' : 'light')); ?> text-dark me-2">#<?php echo $i + 1; ?></span>
                                        <?php echo htmlspecialchars($entry['staff_name']); ?>
                                        <?php if ($entry['staff_id'] == $staff_id): ?>
                                            <small class="text-muted">(You)</small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold"><?php echo $entry['total_referrals']; ?> referrals</div>
                                        <small class="text-muted"><?php echo $entry['conversion_rate']; ?>% converted</small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

<?php echo render_app_shell_end(); ?>
</body>
</html>
