<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
$user = require_super_admin();
$pdo = db_connect();
$current_page = 'admin/index.php';

$stmt = $pdo->query('SELECT
    (SELECT COUNT(*) FROM businesses) AS total_businesses,
    (SELECT COUNT(*) FROM businesses WHERE status = "active") AS active_businesses,
    (SELECT COUNT(*) FROM users) AS total_users,
    (SELECT COUNT(*) FROM referrals) AS total_referrals,
    (SELECT COUNT(*) FROM referrals WHERE status = "used") AS used_referrals,
    (SELECT IFNULL(SUM(amount), 0) FROM commissions) AS total_commissions,
    (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE status = "verified") AS total_verified_payments,
    (SELECT COUNT(*) FROM disputes WHERE status IN ("open", "pending")) AS open_disputes,
    (SELECT COUNT(*) FROM partnerships WHERE status = "active") AS active_partnerships,
    (SELECT COUNT(*) FROM platform_fees WHERE status = "simulated") AS pending_fees');
$stats = $stmt->fetch();

$stmt = $pdo->query('SELECT b.*, COUNT(DISTINCT u.id) AS user_count,
    (SELECT COUNT(*) FROM referrals WHERE source_business_id = b.id OR target_business_id = b.id) AS referral_count
    FROM businesses b
    LEFT JOIN users u ON u.business_id = b.id
    GROUP BY b.id
    ORDER BY b.created_at DESC
    LIMIT 10');
$recent_businesses = $stmt->fetchAll();

$stmt = $pdo->query('SELECT r.*, sb.name AS source_name, tb.name AS target_name
    FROM referrals r
    JOIN businesses sb ON sb.id = r.source_business_id
    JOIN businesses tb ON tb.id = r.target_business_id
    ORDER BY r.created_at DESC
    LIMIT 10');
$recent_referrals = $stmt->fetchAll();

$stmt = $pdo->query('SELECT al.*, u.name AS actor_name, b.name AS business_name
    FROM audit_logs al
    JOIN users u ON u.id = al.user_id
    JOIN businesses b ON b.id = al.business_id
    ORDER BY al.created_at DESC
    LIMIT 10');
$recent_audit = $stmt->fetchAll();

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
    <style>
        .admin-stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .admin-stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem 1.25rem; }
        .admin-stat-card .stat-label { font-size: 0.75rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
        .admin-stat-card .stat-value { font-size: 1.5rem; font-weight: 700; color: #111827; }
        .admin-stat-card.stat-success .stat-value { color: #059669; }
        .admin-stat-card.stat-warning .stat-value { color: #d97706; }
        .admin-stat-card.stat-danger .stat-value { color: #dc2626; }
        .admin-stat-card.stat-info .stat-value { color: #2563eb; }
        .admin-section { margin-bottom: 2rem; }
        .admin-section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .admin-section-header h2 { font-size: 1.125rem; font-weight: 600; margin: 0; }
        .admin-quick-links { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 0.75rem; margin-bottom: 2rem; }
        .admin-quick-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.875rem 1rem; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; text-decoration: none; color: #374151; transition: border-color 0.15s, box-shadow 0.15s; }
        .admin-quick-link:hover { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); color: #111827; }
        .admin-quick-link .ql-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.125rem; }
        .admin-quick-link .ql-icon.ql-blue { background: #eff6ff; color: #2563eb; }
        .admin-quick-link .ql-icon.ql-green { background: #ecfdf5; color: #059669; }
        .admin-quick-link .ql-icon.ql-amber { background: #fffbeb; color: #d97706; }
        .admin-quick-link .ql-icon.ql-red { background: #fef2f2; color: #dc2626; }
        .admin-quick-link .ql-icon.ql-purple { background: #f5f3ff; color: #7c3aed; }
        .admin-quick-link .ql-label { font-size: 0.875rem; font-weight: 500; }
    </style>
</head>
<body>
<?php echo render_app_shell_start($user, 0, $current_page, 1); ?>

<?php echo render_breadcrumbs(['Admin Dashboard' => false], 1); ?>
<?php echo render_page_header('Super Admin Dashboard', 'Platform-wide overview and management.'); ?>

<div class="admin-stat-grid">
    <div class="admin-stat-card stat-info">
        <div class="stat-label">Total Businesses</div>
        <div class="stat-value"><?php echo number_format($stats['total_businesses']); ?></div>
    </div>
    <div class="admin-stat-card stat-success">
        <div class="stat-label">Active Businesses</div>
        <div class="stat-value"><?php echo number_format($stats['active_businesses']); ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="stat-label">Total Users</div>
        <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
    </div>
    <div class="admin-stat-card stat-info">
        <div class="stat-label">Total Referrals</div>
        <div class="stat-value"><?php echo number_format($stats['total_referrals']); ?></div>
    </div>
    <div class="admin-stat-card stat-success">
        <div class="stat-label">Used Referrals</div>
        <div class="stat-value"><?php echo number_format($stats['used_referrals']); ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="stat-label">Active Partnerships</div>
        <div class="stat-value"><?php echo number_format($stats['active_partnerships']); ?></div>
    </div>
    <div class="admin-stat-card stat-info">
        <div class="stat-label">Total Commissions</div>
        <div class="stat-value">RWF <?php echo format_money($stats['total_commissions']); ?></div>
    </div>
    <div class="admin-stat-card stat-success">
        <div class="stat-label">Verified Payments</div>
        <div class="stat-value">RWF <?php echo format_money($stats['total_verified_payments']); ?></div>
    </div>
    <div class="admin-stat-card stat-warning">
        <div class="stat-label">Open Disputes</div>
        <div class="stat-value"><?php echo number_format($stats['open_disputes']); ?></div>
    </div>
    <div class="admin-stat-card stat-danger">
        <div class="stat-label">Pending Fees</div>
        <div class="stat-value"><?php echo number_format($stats['pending_fees']); ?></div>
    </div>
</div>

<div class="admin-quick-links">
    <a href="businesses.php" class="admin-quick-link">
        <div class="ql-icon ql-blue"><i data-lucide="building-2" style="width:20px;height:20px;"></i></div>
        <div class="ql-label">Manage Businesses</div>
    </a>
    <a href="users.php" class="admin-quick-link">
        <div class="ql-icon ql-green"><i data-lucide="users" style="width:20px;height:20px;"></i></div>
        <div class="ql-label">Manage Users</div>
    </a>
    <a href="referrals.php" class="admin-quick-link">
        <div class="ql-icon ql-purple"><i data-lucide="link" style="width:20px;height:20px;"></i></div>
        <div class="ql-label">All Referrals</div>
    </a>
    <a href="transactions.php" class="admin-quick-link">
        <div class="ql-icon ql-amber"><i data-lucide="arrow-left-right" style="width:20px;height:20px;"></i></div>
        <div class="ql-label">Transactions</div>
    </a>
    <a href="commissions.php" class="admin-quick-link">
        <div class="ql-icon ql-green"><i data-lucide="percent" style="width:20px;height:20px;"></i></div>
        <div class="ql-label">Commissions</div>
    </a>
    <a href="settlements.php" class="admin-quick-link">
        <div class="ql-icon ql-blue"><i data-lucide="credit-card" style="width:20px;height:20px;"></i></div>
        <div class="ql-label">Settlements</div>
    </a>
    <a href="disputes.php" class="admin-quick-link">
        <div class="ql-icon ql-red"><i data-lucide="alert-triangle" style="width:20px;height:20px;"></i></div>
        <div class="ql-label">Disputes</div>
    </a>
    <a href="audit_logs.php" class="admin-quick-link">
        <div class="ql-icon ql-amber"><i data-lucide="scroll-text" style="width:20px;height:20px;"></i></div>
        <div class="ql-label">Audit Logs</div>
    </a>
    <a href="settings.php" class="admin-quick-link">
        <div class="ql-icon ql-blue"><i data-lucide="settings" style="width:20px;height:20px;"></i></div>
        <div class="ql-label">System Settings</div>
    </a>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-body">
                <div class="admin-section-header">
                    <h2>Recent Businesses</h2>
                    <a href="businesses.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-modern table-hover align-middle mb-0">
                        <thead><tr><th>Business</th><th>City</th><th>Status</th><th>Users</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent_businesses as $b): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($b['name']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($b['email']); ?></small></td>
                                <td><?php echo htmlspecialchars($b['city'] ?? 'Kigali'); ?></td>
                                <td><span class="badge bg-<?php echo $b['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($b['status'])); ?></span></td>
                                <td><?php echo (int)$b['user_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_businesses)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No businesses yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-body">
                <div class="admin-section-header">
                    <h2>Recent Referrals</h2>
                    <a href="referrals.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-modern table-hover align-middle mb-0">
                        <thead><tr><th>Source</th><th>Target</th><th>Status</th><th>Value</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent_referrals as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['source_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['target_name']); ?></td>
                                <td><span class="badge bg-<?php echo $r['status'] === 'used' ? 'success' : ($r['status'] === 'expired' ? 'secondary' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span></td>
                                <td>RWF <?php echo format_money($r['transaction_amount'] ?? $r['estimated_value']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_referrals)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No referrals yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-body">
        <div class="admin-section-header">
            <h2>Recent Audit Activity</h2>
            <a href="audit_logs.php" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="table-responsive">
            <table class="table table-modern table-hover align-middle mb-0">
                <thead><tr><th>Time</th><th>Actor</th><th>Business</th><th>Action</th><th>Entity</th></tr></thead>
                <tbody>
                <?php foreach ($recent_audit as $log): ?>
                    <tr>
                        <td><small><?php echo htmlspecialchars($log['created_at']); ?></small></td>
                        <td><?php echo htmlspecialchars($log['actor_name']); ?></td>
                        <td><?php echo htmlspecialchars($log['business_name']); ?></td>
                        <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($log['action']); ?></span></td>
                        <td><?php echo htmlspecialchars($log['entity_type'] . ' #' . ($log['entity_id'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recent_audit)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No audit records yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php echo render_app_shell_end(); ?>
</body>
</html>
