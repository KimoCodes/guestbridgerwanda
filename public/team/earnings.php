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
$current_page = 'staff.php';

$services = ServiceContainer::getInstance($pdo);

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

// Get staff members with their earnings
$stmt = $pdo->prepare('SELECT s.*, u.email AS user_email
    FROM staff s
    LEFT JOIN users u ON u.id = s.user_id
    WHERE s.business_id = ?
    ORDER BY s.name');
$stmt->execute([$business_id]);
$staff_members = $stmt->fetchAll();

// Get current month earnings for each staff member
$current_month = date('Y-m');

// Batch query for all-time stats (avoids N+1)
$all_time_stats = [];
$stmt = $pdo->prepare('SELECT 
    r.staff_id,
    COUNT(DISTINCT r.id) AS total_referrals,
    SUM(CASE WHEN r.status IN (\'converted\',\'settled\') THEN 1 ELSE 0 END) AS successful_referrals,
    COALESCE(SUM(CASE WHEN r.status IN (\'converted\',\'settled\') THEN gt.eligible_amount ELSE 0 END), 0) AS total_guest_value,
    COALESCE(SUM(ca.amount), 0) AS total_commission,
    COALESCE(SUM(CASE WHEN ca.status = \'pending\' THEN ca.amount ELSE 0 END), 0) AS pending_commission,
    COALESCE(SUM(CASE WHEN ca.status = \'settled\' THEN ca.amount ELSE 0 END), 0) AS settled_commission
    FROM referrals r
    LEFT JOIN guest_transactions gt ON gt.referral_id = r.id
    LEFT JOIN commission_allocations ca ON ca.commission_id = (
        SELECT id FROM commissions WHERE referral_id = r.id LIMIT 1
    ) AND ca.allocation_type = \'employee\' AND ca.recipient_employee_id = r.staff_id
    WHERE r.staff_id IN (SELECT id FROM staff WHERE business_id = ?)
    GROUP BY r.staff_id');
$stmt->execute([$business_id]);
foreach ($stmt->fetchAll() as $row) {
    $all_time_stats[(int)$row['staff_id']] = $row;
}

foreach ($staff_members as &$member) {
    $earnings = $services->commissions()->getEmployeeEarnings((int)$member['id'], $current_month);
    $member['earnings'] = $earnings;
    $member['all_time'] = $all_time_stats[(int)$member['id']] ?? [];
}
unset($member);

// Get selected staff member for detailed view
$selected_id = intval($_GET['id'] ?? 0);
$selected_member = null;

if ($selected_id > 0) {
    foreach ($staff_members as $m) {
        if ((int)$m['id'] === $selected_id) {
            $selected_member = $m;
            break;
        }
    }
}

// Get recent referrals for selected staff member
$recent_referrals = [];
if ($selected_member) {
    $stmt = $pdo->prepare('SELECT r.*, 
        sb.name AS source_business_name, 
        tb.name AS target_business_name
        FROM referrals r 
        LEFT JOIN businesses sb ON sb.id = r.source_business_id 
        LEFT JOIN businesses tb ON tb.id = r.target_business_id
        WHERE r.staff_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10');
    $stmt->execute([(int)$selected_member['id']]);
    $recent_referrals = $stmt->fetchAll();
}

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff & Earnings - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Network' => '#', 'Staff & Earnings' => false]); ?>
            <?php echo render_page_header(
                'Staff & Earnings',
                'View staff performance and commission earnings.',
                []
            ); ?>

            <div class="row g-4">
                <!-- Staff List -->
                <div class="col-12 col-lg-4">
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <h5 class="mb-0">Staff Members</h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($staff_members)): ?>
                                <div class="text-center py-4 text-muted">
                                    <p>No staff members found.</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($staff_members as $member): ?>
                                    <a href="?id=<?php echo $member['id']; ?>" 
                                       class="list-group-item list-group-item-action <?php echo $selected_id == $member['id'] ? 'active' : ''; ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($member['name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars(ucfirst($member['role'])); ?></small>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-bold text-success">RWF <?php echo format_money($member['all_time']['total_commission'] ?? 0); ?></div>
                                                <small class="text-muted"><?php echo (int)($member['all_time']['total_referrals'] ?? 0); ?> referrals</small>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Staff Detail -->
                <div class="col-12 col-lg-8">
                    <?php if ($selected_member): ?>
                        <div class="card shadow-sm mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><?php echo htmlspecialchars($selected_member['name']); ?> - Earnings</h5>
                                <span class="badge bg-primary"><?php echo htmlspecialchars(ucfirst($selected_member['role'])); ?></span>
                            </div>
                            <div class="card-body">
                                <!-- Current Month Stats -->
                                <h6 class="text-uppercase text-muted mb-3">Current Month (<?php echo date('F Y'); ?>)</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-3">
                                                <div class="h4 mb-0"><?php echo (int)($selected_member['earnings']['total_referrals'] ?? 0); ?></div>
                                                <small class="text-muted">Referrals</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-3">
                                                <div class="h4 mb-0"><?php echo (int)($selected_member['earnings']['successful_referrals'] ?? 0); ?></div>
                                                <small class="text-muted">Successful</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-3">
                                                <div class="h4 mb-0 text-success">RWF <?php echo format_money($selected_member['earnings']['pending_commission'] ?? 0); ?></div>
                                                <small class="text-muted">Pending</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-3">
                                                <div class="h4 mb-0 text-primary">RWF <?php echo format_money($selected_member['earnings']['settled_commission'] ?? 0); ?></div>
                                                <small class="text-muted">Settled</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- All-Time Stats -->
                                <h6 class="text-uppercase text-muted mb-3">All-Time Performance</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-6 col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-3">
                                                <div class="h4 mb-0"><?php echo (int)($selected_member['all_time']['total_referrals'] ?? 0); ?></div>
                                                <small class="text-muted">Total Referrals</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-3">
                                                <div class="h4 mb-0"><?php echo (int)($selected_member['all_time']['successful_referrals'] ?? 0); ?></div>
                                                <small class="text-muted">Successful</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-3">
                                                <div class="h4 mb-0">RWF <?php echo format_money($selected_member['all_time']['total_guest_value'] ?? 0); ?></div>
                                                <small class="text-muted">Guest Value Generated</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-3">
                                                <div class="h4 mb-0 text-success">RWF <?php echo format_money($selected_member['all_time']['total_commission'] ?? 0); ?></div>
                                                <small class="text-muted">Total Commission</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-3">
                                                <div class="h4 mb-0">RWF <?php echo format_money($selected_member['all_time']['pending_commission'] ?? 0); ?></div>
                                                <small class="text-muted">Pending Commission</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-3">
                                                <div class="h4 mb-0 text-primary">RWF <?php echo format_money($selected_member['all_time']['settled_commission'] ?? 0); ?></div>
                                                <small class="text-muted">Settled Commission</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Conversion Rate -->
                                <?php
                                $total_ref = (int)($selected_member['all_time']['total_referrals'] ?? 0);
                                $successful_ref = (int)($selected_member['all_time']['successful_referrals'] ?? 0);
                                $conversion_rate = $total_ref > 0 ? round(($successful_ref / $total_ref) * 100) : 0;
                                ?>
                                <div class="mb-4">
                                    <h6 class="text-uppercase text-muted mb-2">Conversion Rate</h6>
                                    <div class="progress" style="height: 24px;">
                                        <div class="progress-bar bg-<?php echo $conversion_rate >= 50 ? 'success' : ($conversion_rate >= 25 ? 'warning' : 'danger'); ?>" 
                                             style="width: <?php echo $conversion_rate; ?>%">
                                            <?php echo $conversion_rate; ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Referrals -->
                        <?php if (!empty($recent_referrals)): ?>
                        <div class="card shadow-sm">
                            <div class="card-header">
                                <h5 class="mb-0">Recent Referrals</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Code</th>
                                                <th>Partner</th>
                                                <th>Value</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_referrals as $ref): ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($ref['referral_code']); ?></code></td>
                                                <td><?php echo htmlspecialchars($ref['target_business_name']); ?></td>
                                                <td>RWF <?php echo format_money($ref['transaction_amount'] ?? $ref['estimated_value']); ?></td>
                                                <td><span class="badge bg-<?php echo referral_status_color($ref['status']); ?>"><?php echo htmlspecialchars(referral_status_label($ref['status'])); ?></span></td>
                                                <td><?php echo date('M j', strtotime($ref['created_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="card shadow-sm">
                            <div class="card-body text-center py-5">
                                <div class="text-muted mb-3">
                                    <i data-lucide="users" style="width:48px;height:48px;"></i>
                                </div>
                                <h5>Select a staff member</h5>
                                <p class="text-muted">Click on a staff member from the list to view their earnings and performance.</p>
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
