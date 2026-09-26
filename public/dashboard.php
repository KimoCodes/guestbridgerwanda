<?php
require_once __DIR__ . '/../app/Config/database.php';
require_once __DIR__ . '/../app/Config/config.php';
require_once __DIR__ . '/../app/Services/ServiceContainer.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$is_platform_admin = is_platform_admin($user);
$current_page = basename($_SERVER['PHP_SELF']);

$services = ServiceContainer::getInstance($pdo);

$stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM referrals WHERE source_business_id = ? OR target_business_id = ?');
$stmt->execute([$business_id, $business_id]);
$total_referrals = $stmt->fetch()['total'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS active_partnerships FROM partnerships WHERE (business_id = ? OR partner_business_id = ?) AND status = ?');
$stmt->execute([$business_id, $business_id, 'active']);
$partners_count = $stmt->fetch()['active_partnerships'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending_requests FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending_requests'];

$stmt = $pdo->prepare('SELECT p.id, b.name AS requester_name, p.commission_rate, p.created_at FROM partnerships p JOIN businesses b ON p.business_id = b.id WHERE p.partner_business_id = ? AND p.status = ? ORDER BY p.created_at DESC LIMIT 3');
$stmt->execute([$business_id, 'pending']);
$latest_requests = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT IFNULL(SUM(amount),0) AS pending FROM commissions WHERE owed_to_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_commission = $stmt->fetch()['pending'];

$current_month = date('Y-m');
$stmt = $pdo->prepare('SELECT IFNULL(SUM(amount),0) AS month_total FROM commissions WHERE owed_to_business_id = ? AND month = ?');
$stmt->execute([$business_id, $current_month]);
$month_total = $stmt->fetch()['month_total'];

// Get pending actions using the new service
$pending_actions = $services->referrals()->getPendingActions($business_id);

// Check for overdue invoices
try {
    $services->billing()->updateOverdueInvoices();
    $overdue_invoices = $services->billing()->getBusinessInvoices($business_id, 'overdue', 1);
} catch (Exception $e) {
    $overdue_invoices = [];
}

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM referrals WHERE source_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'created']);
$open_referrals_count = (int) $stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM commissions WHERE owed_to_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'confirmed']);
$confirmed_to_reconcile = (int) $stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM referrals WHERE target_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'created']);
$incoming_to_confirm = (int) $stmt->fetch()['c'];

// Recent referrals
$stmt = $pdo->prepare('SELECT r.*, CASE WHEN r.source_business_id = ? THEN b2.name ELSE b1.name END AS target_name, CASE WHEN r.source_business_id = ? THEN b2.business_type ELSE b1.business_type END AS business_type FROM referrals r JOIN businesses b1 ON r.source_business_id = b1.id JOIN businesses b2 ON r.target_business_id = b2.id WHERE r.source_business_id = ? OR r.target_business_id = ? ORDER BY r.created_at DESC LIMIT 5');
$stmt->execute([$business_id, $business_id, $business_id, $business_id]);
$recent_referrals = $stmt->fetchAll();

// Active partners
$partner_query = $pdo->prepare('SELECT b.id, b.name, b.business_type, b.city, b.reliability_score, b.payout_compliance_score, b.guest_satisfaction_score FROM partnerships p JOIN businesses b ON b.id = CASE WHEN p.business_id = ? THEN p.partner_business_id ELSE p.business_id END WHERE (p.business_id = ? OR p.partner_business_id = ?) AND p.status = ? ORDER BY b.name ASC LIMIT 6');
$partner_query->execute([$business_id, $business_id, $business_id, 'active']);
$partner_list = $partner_query->fetchAll();

$stmt = $pdo->prepare('SELECT COUNT(*) AS c, SUM(status = "used") AS used FROM referrals WHERE source_business_id = ? OR target_business_id = ?');
$stmt->execute([$business_id, $business_id]);
$ref_all = $stmt->fetch();
$total_all_referrals = (int) ($ref_all['c'] ?? 0);
$total_used = (int) ($ref_all['used'] ?? 0);
$conversion_rate = $total_all_referrals > 0 ? round(($total_used / $total_all_referrals) * 100) : 0;

$stmt = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE (source_business_id = ? OR target_business_id = ?) AND DATE_FORMAT(created_at, "%Y-%m") = ?');
$stmt->execute([$business_id, $business_id, $current_month]);
$month_referrals = (int) $stmt->fetchColumn();

// Outstanding debt
try {
    refresh_hotel_debts($pdo, $business_id);
    $debts = outstanding_debts($pdo, $business_id);
    $total_outstanding_debt = (float) array_sum(array_column($debts, 'remaining_amount'));
    $next_debt = $debts[0] ?? null;
} catch (Exception $e) {
    $total_outstanding_debt = 0;
    $next_debt = null;
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE (source_business_id = ? OR target_business_id = ?) AND DATE_FORMAT(created_at, "%Y-%m") = ? AND status = ?');
$stmt->execute([$business_id, $business_id, $current_month, 'used']);
$month_used = (int) $stmt->fetchColumn();

$referral_change = $month_used > 0 ? $month_used . ' used' : 'No redemptions yet';

$stmt = $pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM commissions WHERE owed_to_business_id = ? AND month = ?');
$stmt->execute([$business_id, $current_month]);
$month_earned = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM commissions WHERE target_business_id = ? AND month = ?');
$stmt->execute([$business_id, $current_month]);
$month_owed = (float) $stmt->fetchColumn();

$activity_chart = [];
$max_activity = 1;
$week_ago = date('Y-m-d', strtotime('-6 days'));
$stmt = $pdo->prepare('SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM referrals WHERE (source_business_id = ? OR target_business_id = ?) AND DATE(created_at) >= ? GROUP BY DATE(created_at)');
$stmt->execute([$business_id, $business_id, $week_ago]);
$activity_data = [];
foreach ($stmt->fetchAll() as $row) {
    $activity_data[$row['day']] = (int) $row['cnt'];
}
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime('-' . $i . ' days'));
    $count = $activity_data[$day] ?? 0;
    $activity_chart[] = [
        'label' => date('D', strtotime($day)),
        'count' => $count,
    ];
    $max_activity = max($max_activity, $count);
}

require_once __DIR__ . '/../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs([]); ?>

            <!-- Outstanding Debt Alert -->
            <?php if ($total_outstanding_debt > 0 && $next_debt): ?>
            <div class="debt-alert-banner animate-fade-in-up">
                <div class="debt-alert-icon">
                    <i data-lucide="alert-triangle"></i>
                </div>
                <div class="debt-alert-content">
                    <div class="debt-alert-label">Payment Required</div>
                    <strong>RWF <?php echo format_money($total_outstanding_debt); ?> outstanding</strong>
                    <span class="debt-alert-meta">
                        Billing: <?php echo htmlspecialchars($next_debt['billing_month']); ?> &middot;
                        Due: <?php echo htmlspecialchars($next_debt['due_date']); ?> &middot;
                        <?php echo htmlspecialchars(debt_status_label($next_debt['status'])); ?>
                    </span>
                </div>
                <a class="btn btn-light" href="<?php echo htmlspecialchars(gb_url('billing.php')); ?>">
                    <i data-lucide="credit-card" style="width:16px;height:16px;"></i>
                    Pay Now
                </a>
            </div>
            <?php endif; ?>

            <!-- Pending Actions -->
            <?php if (!empty($pending_actions) || $pending_requests > 0): ?>
            <div class="gb-action-alerts row g-3 mb-4 animate-fade-in-up">
                <?php if ($pending_requests > 0): ?>
                <div class="col-12 col-md-6 col-xl-3">
                    <a href="<?php echo htmlspecialchars(gb_url('partnerships.php')); ?>" class="card text-decoration-none h-100 border-warning">
                        <div class="card-body py-3">
                            <div class="small text-muted">Partnerships</div>
                            <strong><?php echo (int) $pending_requests; ?> request(s) to review</strong>
                        </div>
                    </a>
                </div>
                <?php endif; ?>
                <?php foreach ($pending_actions as $action): ?>
                <div class="col-12 col-md-6 col-xl-3">
                    <a href="<?php echo htmlspecialchars($action['url']); ?>" class="card text-decoration-none h-100 border-<?php echo $action['priority'] === 'high' ? 'danger' : 'info'; ?>">
                        <div class="card-body py-3">
                            <div class="small text-muted"><?php echo ucfirst(str_replace('_', ' ', $action['type'])); ?></div>
                            <strong><?php echo htmlspecialchars($action['message']); ?></strong>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
                <?php if (!empty($overdue_invoices)): ?>
                <div class="col-12 col-md-6 col-xl-3">
                    <a href="<?php echo htmlspecialchars(gb_url('billing.php')); ?>" class="card text-decoration-none h-100 border-danger">
                        <div class="card-body py-3">
                            <div class="small text-muted">Billing</div>
                            <strong><?php echo count($overdue_invoices); ?> overdue invoice(s)</strong>
                        </div>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="gb-page-header animate-fade-in-up">
                <div class="gb-page-header-text">
                    <h1 class="gb-page-title">
                        <span class="greeting">Welcome back,</span>
                        <?php echo htmlspecialchars(explode(' ', $user['name'])[0]); ?>
                    </h1>
                    <p class="gb-page-description">Here's what's happening with your referral network today.</p>
                </div>
                <div class="gb-page-actions">
                    <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(gb_url('partner_places.php')); ?>" target="_blank" rel="noopener">
                        <i data-lucide="map-pin" style="width:18px;height:18px;"></i>
                        Public places
                    </a>
                    <a class="btn btn-primary btn-lg" href="<?php echo htmlspecialchars(gb_url('create_referral.php')); ?>">
                        <i data-lucide="plus-circle" style="width:18px;height:18px;"></i>
                        New Referral
                    </a>
                </div>
            </div>

            <!-- Key metrics (live data) -->
            <div class="gb-metrics-grid stagger-children">
                <div class="gb-stat-card gb-stat-primary animate-fade-in-up">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="stat-card-icon-wrap primary"><i data-lucide="share-2" style="width:22px;height:22px;"></i></div>
                        <span class="stat-trend-pill neutral"><?php echo htmlspecialchars($referral_change); ?></span>
                    </div>
                    <div class="gb-stat-label">Referrals this month</div>
                    <div class="gb-stat-value"><?php echo (int) $month_referrals; ?></div>
                    <div class="gb-stat-trend"><span class="stat-trend-up"><?php echo (int) $month_used; ?> used this month</span></div>
                </div>
                <div class="gb-stat-card gb-stat-success animate-fade-in-up">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="stat-card-icon-wrap success"><i data-lucide="handshake" style="width:22px;height:22px;"></i></div>
                        <span class="stat-trend-pill up"><?php echo (int) $partners_count; ?> active</span>
                    </div>
                    <div class="gb-stat-label">Partner network</div>
                    <div class="gb-stat-value"><?php echo (int) $partners_count; ?></div>
                    <div class="gb-stat-trend text-muted small"><?php echo (int) $pending_requests; ?> pending requests</div>
                </div>
                <div class="gb-stat-card gb-stat-info animate-fade-in-up">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="stat-card-icon-wrap primary"><i data-lucide="percent" style="width:22px;height:22px;"></i></div>
                        <span class="stat-trend-pill <?php echo $conversion_rate >= 50 ? 'up' : 'neutral'; ?>"><?php echo $conversion_rate; ?>%</span>
                    </div>
                    <div class="gb-stat-label">Conversion rate</div>
                    <div class="gb-stat-value"><?php echo $conversion_rate; ?>%</div>
                    <div class="gb-stat-trend text-muted small">All-time outbound referrals</div>
                </div>
                <div class="gb-stat-card gb-stat-warning animate-fade-in-up">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="stat-card-icon-wrap warning"><i data-lucide="banknote" style="width:22px;height:22px;"></i></div>
                        <span class="stat-trend-pill neutral"><?php echo htmlspecialchars($current_month); ?></span>
                    </div>
                    <div class="gb-stat-label">Est. commission (month)</div>
                    <div class="gb-stat-value">RWF <?php echo format_money($month_total); ?></div>
                    <div class="gb-stat-trend text-muted small">Earned RWF <?php echo format_money($month_earned); ?> · Owing RWF <?php echo format_money($month_owed); ?></div>
                </div>
            </div>

            <div class="activity-chart-panel animate-fade-in-up">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h3>Referral activity</h3>
                        <p class="text-muted small mb-0">Outbound referrals created in the last 7 days</p>
                    </div>
                    <a href="/guestbridgerwanda/analytics.php" class="btn btn-sm btn-outline-primary">View analytics</a>
                </div>
                <div class="activity-chart-bars">
                    <?php foreach ($activity_chart as $bar): ?>
                        <?php $h = $max_activity > 0 ? max(8, round(($bar['count'] / $max_activity) * 100)) : 8; ?>
                        <div class="activity-bar-col">
                            <div class="activity-bar-fill" style="height: <?php echo (int) $h; ?>%;" title="<?php echo (int) $bar['count']; ?> referrals"></div>
                            <span class="activity-bar-label"><?php echo htmlspecialchars($bar['label']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Referral Identity Widget (for employees) -->
            <?php
            $user_staff_id = 0;
            $user_identity = null;
            $stmt_staff = $pdo->prepare('SELECT id FROM staff WHERE user_id = ? AND business_id = ?');
            $stmt_staff->execute([(int) $user['id'], $business_id]);
            $user_staff_id = (int) ($stmt_staff->fetchColumn() ?: 0);
            if ($user_staff_id > 0) {
                $user_identity = $services->staffIdentities()->getActiveIdentity($user_staff_id, $business_id);
            }
            ?>
            <?php if ($user_identity): ?>
            <div class="card mb-4 animate-fade-in-up border-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h5 class="mb-1"><i data-lucide="badge-check" style="width:20px;height:20px;"></i> My Referral Identity</h5>
                            <p class="text-muted small mb-0">Share your identity code with guests so referrals are tracked to you.</p>
                        </div>
                        <div class="text-end">
                            <code class="fs-5"><?php echo htmlspecialchars($user_identity['public_identity_code']); ?></code>
                            <div class="small text-muted mt-1">
                                <?php echo (int) ($user_identity['total_referrals'] ?? 0); ?> referrals &middot;
                                <?php echo (int) ($user_identity['total_referrals'] ?? 0) > 0 ? round(((int) ($user_identity['successful_referrals'] ?? 0) / (int) ($user_identity['total_referrals'] ?? 1)) * 100) : 0; ?>% conversion
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <a href="/guestbridgerwanda/my_identity.php" class="btn btn-outline-primary btn-sm">View Full Stats</a>
                        <a href="/guestbridgerwanda/performance.php" class="btn btn-outline-secondary btn-sm">Performance Dashboard</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pending Partnership Requests -->
            <?php if ($pending_requests > 0): ?>
            <div class="pending-requests-panel animate-fade-in-up">
                <div class="pending-requests-icon">
                    <i data-lucide="user-plus"></i>
                </div>
                <div class="pending-requests-content">
                    <div class="pending-requests-title">
                        <?php echo htmlspecialchars($pending_requests); ?> Partnership Request<?php echo $pending_requests === 1 ? '' : 's'; ?> Waiting
                    </div>
                    <div class="pending-requests-desc">Review incoming requests to unlock new referral channels.</div>
                </div>
                <a href="<?php echo htmlspecialchars(gb_url('partnerships.php')); ?>" class="btn btn-warning">
                    <i data-lucide="eye" style="width:16px;height:16px;"></i>
                    Review Now
                </a>
            </div>
            <?php endif; ?>

            <!-- Main Features Grid -->
            <div class="feature-section-grid stagger-children">

                <!-- Daily Referrals Section -->
                <div class="feature-section animate-fade-in-up">
                    <div class="feature-section-header">
                        <div class="feature-section-icon feature-section-icon-primary">
                            <i data-lucide="share-2"></i>
                        </div>
                        <div>
                            <h4>Daily Referrals</h4>
                            <p>Create, share, and track partner referrals.</p>
                        </div>
                    </div>
                    <div class="feature-link-grid">
                        <a href="<?php echo htmlspecialchars(gb_url('create_referral.php')); ?>" class="feature-link feature-link-primary">
                            <div class="feature-link-icon">
                                <i data-lucide="plus"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Create Referral</span>
                                <span class="feature-link-description">Generate QR code, link, and WhatsApp message.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="<?php echo htmlspecialchars(gb_url('history.php')); ?>" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="history"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Referral History</span>
                                <span class="feature-link-description">Review all referrals by date and status.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="<?php echo htmlspecialchars(gb_url('view_referral.php')); ?>" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="qr-code"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">QR & Share Codes</span>
                                <span class="feature-link-description">Access previously generated codes.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                    </div>
                </div>

                <!-- Partner Network Section -->
                <div class="feature-section animate-fade-in-up">
                    <div class="feature-section-header">
                        <div class="feature-section-icon feature-section-icon-success">
                            <i data-lucide="handshake"></i>
                        </div>
                        <div>
                            <h4>Partner Network</h4>
                            <p>Grow and manage your trusted partnerships.</p>
                        </div>
                    </div>
                    <div class="feature-link-grid">
                        <?php
                        $partnership_class = $pending_requests > 0 ? 'feature-link-warning' : '';
                        ?>
                        <a href="<?php echo htmlspecialchars(gb_url('partnerships.php')); ?>" class="feature-link <?php echo $partnership_class; ?>">
                            <div class="feature-link-icon <?php echo $pending_requests > 0 ? 'feature-link-icon-warning' : ''; ?>">
                                <i data-lucide="file-signature"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">
                                    Partnerships
                                    <?php if ($pending_requests > 0): ?>
                                        <span class="badge badge-warning"><?php echo htmlspecialchars($pending_requests); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="feature-link-description"><?php echo $pending_requests > 0 ? $pending_requests . ' request(s) awaiting review' : 'Review and send agreements'; ?>.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="partners.php" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="building-2"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Partner Directory</span>
                                <span class="feature-link-description">Browse registered partner businesses.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="<?php echo htmlspecialchars(gb_url('staff.php')); ?>" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="user-cog"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Staff Members</span>
                                <span class="feature-link-description">Manage referral staff performance.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                    </div>
                </div>

                <!-- Settlement & Finance Section -->
                <div class="feature-section animate-fade-in-up">
                    <div class="feature-section-header">
                        <div class="feature-section-icon feature-section-icon-warning">
                            <i data-lucide="landmark"></i>
                        </div>
                        <div>
                            <h4>Settlement & Finance</h4>
                            <p>Track commissions, payments, and billing.</p>
                        </div>
                    </div>
                    <div class="feature-link-grid">
                        <a href="<?php echo htmlspecialchars(gb_url('commissions.php')); ?>" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="scroll-text"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Commission Ledger</span>
                                <span class="feature-link-description">View pending and reconciled commissions.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="<?php echo htmlspecialchars(gb_url('wallet.php')); ?>" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="banknote"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Wallet Overview</span>
                                <span class="feature-link-description">Earnings and owing at a glance.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="<?php echo htmlspecialchars(gb_url('billing.php')); ?>" class="feature-link <?php echo $total_outstanding_debt > 0 ? 'feature-link-danger' : ''; ?>">
                            <div class="feature-link-icon <?php echo $total_outstanding_debt > 0 ? 'feature-link-icon-danger' : ''; ?>">
                                <i data-lucide="receceipt"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Billing & Invoices</span>
                                <span class="feature-link-description"><?php echo $total_outstanding_debt > 0 ? 'Outstanding balance needs attention.' : 'Invoices and payment tracking.'; ?></span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="<?php echo htmlspecialchars(gb_url('payments.php')); ?>" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="move-right"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Payment Journal</span>
                                <span class="feature-link-description">Record and audit payments.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                    </div>
                </div>

                <!-- Insights Section -->
                <div class="feature-section animate-fade-in-up">
                    <div class="feature-section-header">
                        <div class="feature-section-icon feature-section-icon-info">
                            <i data-lucide="bar-chart-3"></i>
                        </div>
                        <div>
                            <h4>Insights</h4>
                            <p>Performance analytics and trends.</p>
                        </div>
                    </div>
                    <div class="feature-link-grid">
                        <a href="<?php echo htmlspecialchars(gb_url('analytics.php')); ?>" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="pie-chart"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Analytics</span>
                                <span class="feature-link-description">Conversion rates and referral trends.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="<?php echo htmlspecialchars(gb_url('reconciliation.php')); ?>" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="clipboard-check"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Reconciliation</span>
                                <span class="feature-link-description">Confirm commissions before settlement.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="<?php echo htmlspecialchars(gb_url('transactions.php')); ?>" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="file-text"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Transaction Audit</span>
                                <span class="feature-link-description">Detailed movement records.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                    </div>
                </div>

                <?php if ($is_manager): ?>
                <!-- Management Tools Section -->
                <div class="feature-section animate-fade-in-up">
                    <div class="feature-section-header">
                        <div class="feature-section-icon feature-section-icon-purple">
                            <i data-lucide="shield"></i>
                        </div>
                        <div>
                            <h4>Management Tools</h4>
                            <p>Pilot pricing, finance oversight, and regional scaling.</p>
                        </div>
                    </div>
                    <div class="feature-link-grid">
                        <?php if ($is_platform_admin): ?>
                        <a href="admin_finance.php" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="briefcase"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Platform Finance</span>
                                <span class="feature-link-description">Pilot network-wide debt and risk overview.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($is_platform_admin): ?>
                        <a href="pilot_pricing_packet.php" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="tag"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Pricing Configuration</span>
                                <span class="feature-link-description">Fees, placements, and seasonality.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="regional_scaling.php" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="map"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Regional Scaling</span>
                                <span class="feature-link-description">City readiness and expansion.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <?php endif; ?>
                        <a href="revenue_model.php" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="calculator"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Revenue Model</span>
                                <span class="feature-link-description">Simulate platform fees.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                        <a href="<?php echo htmlspecialchars(gb_url('incentives.php')); ?>" class="feature-link">
                            <div class="feature-link-icon">
                                <i data-lucide="gift"></i>
                            </div>
                            <div class="feature-link-content">
                                <span class="feature-link-title">Staff Incentives</span>
                                <span class="feature-link-description">Approve referral reward points.</span>
                            </div>
                            <i data-lucide="chevron-right" class="feature-link-arrow"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Bottom Section: Recent Activity + Partner List -->
            <div class="dashboard-bottom-grid stagger-children">

                <!-- Recent Referrals -->
                <div class="dashboard-card animate-fade-in-up">
                    <div class="dashboard-card-header">
                        <div class="dashboard-card-title">
                            <i data-lucide="activity" style="width:20px;height:20px;"></i>
                            Recent Referrals
                        </div>
                        <a href="/guestbridgerwanda/history.php" class="btn btn-sm btn-outline-primary">
                            View All
                            <i data-lucide="arrow-right" style="width:14px;height:14px;"></i>
                        </a>
                    </div>
                    <div class="dashboard-card-body">
                        <?php if ($recent_referrals): ?>
                            <div class="referral-list">
                                <?php foreach ($recent_referrals as $ref): ?>
                                <div class="referral-item">
                                    <div class="referral-item-info">
                                        <div class="referral-code">
                                            <i data-lucide="hash" style="width:14px;height:14px;"></i>
                                            <?php echo htmlspecialchars($ref['referral_code']); ?>
                                        </div>
                                        <div class="referral-partner">
                                            <i data-lucide="building" style="width:14px;height:14px;"></i>
                                            <?php echo htmlspecialchars($ref['target_name']); ?>
                                            <span class="badge badge-gray"><?php echo htmlspecialchars(business_type_label($ref['business_type'])); ?></span>
                                        </div>
                                    </div>
                                <div class="referral-item-meta">
                                    <div class="referral-commission">
                                        <i data-lucide="percent" style="width:14px;height:14px;"></i>
                                        <?php echo htmlspecialchars($ref['commission_percentage']); ?>%
                                    </div>
                                    <span class="badge badge-<?php echo referral_status_color($ref['status']); ?>">
                                        <?php echo htmlspecialchars(referral_status_label($ref['status'])); ?>
                                    </span>
                                </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i data-lucide="inbox"></i>
                                </div>
                                <div class="empty-state-title">No referrals yet</div>
                                <div class="empty-state-desc">Create your first referral to start tracking commissions.</div>
                                <a href="/guestbridgerwanda/create_referral.php" class="btn btn-primary">
                                    <i data-lucide="plus" style="width:16px;height:16px;"></i>
                                    Create Referral
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Active Partners -->
                <div class="dashboard-card animate-fade-in-up">
                    <div class="dashboard-card-header">
                        <div class="dashboard-card-title">
                            <i data-lucide="users" style="width:20px;height:20px;"></i>
                            Active Partners
                        </div>
                        <a href="/guestbridgerwanda/partnerships.php" class="btn btn-sm btn-outline-primary">
                            Manage
                            <i data-lucide="arrow-right" style="width:14px;height:14px;"></i>
                        </a>
                    </div>
                    <div class="dashboard-card-body">
                        <?php if ($partner_list): ?>
                            <div class="partner-list">
                                <?php foreach ($partner_list as $partner): ?>
                                <div class="partner-item">
                                    <div class="partner-avatar">
                                        <?php echo strtoupper(substr($partner['name'], 0, 1)); ?>
                                    </div>
                                    <div class="partner-info">
                                        <div class="partner-name"><?php echo htmlspecialchars($partner['name']); ?></div>
                                        <div class="partner-meta">
                                            <span class="badge badge-gray"><?php echo htmlspecialchars(business_type_label($partner['business_type'])); ?></span>
                                            <span class="partner-location">
                                                <i data-lucide="map-pin" style="width:12px;height:12px;"></i>
                                                <?php echo htmlspecialchars(city_label($partner['city'])); ?>
                                            </span>
                                            <span class="partner-score">
                                                <i data-lucide="star" style="width:12px;height:12px;"></i>
                                                <?php echo htmlspecialchars(format_score(reputation_average($partner))); ?>/100
                                            </span>
                                        </div>
                                    </div>
                                    <a href="/guestbridgerwanda/create_referral.php?partner=<?php echo intval($partner['id']); ?>" class="btn btn-sm btn-primary">
                                        <i data-lucide="send" style="width:14px;height:14px;"></i>
                                        Refer
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i data-lucide="users"></i>
                                </div>
                                <div class="empty-state-title">No active partnerships</div>
                                <div class="empty-state-desc">Request partnerships to unlock referral creation.</div>
                                <a href="/guestbridgerwanda/partnerships.php" class="btn btn-primary">
                                    <i data-lucide="search" style="width:16px;height:16px;"></i>
                                    Find Partners
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Stats Footer -->
            <div class="dashboard-footer animate-fade-in-up">
                <div class="footer-stat">
                    <i data-lucide="calendar" style="width:16px;height:16px;"></i>
                    <span>Last updated: <?php echo date('M j, Y g:i A'); ?></span>
                </div>
                <div class="footer-stat">
                    <i data-lucide="zap" style="width:16px;height:16px;"></i>
                    <span>System Status: Operational</span>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>

<script>
// Animate metrics on scroll
(function() {
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    document.querySelectorAll('.animate-fade-in-up').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        observer.observe(el);
    });
})();
</script>
</body>
</html>