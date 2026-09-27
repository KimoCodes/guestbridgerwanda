<?php
require_once __DIR__ . '/../app/Config/config.php';
require_once __DIR__ . '/../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'analytics.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../app/HTTP/helpers.php';

$month = trim($_GET['month'] ?? date('Y-m'));
$errors = [];

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Invalid month format.';
    $month = date('Y-m');
}

$stmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN status = \'used\' THEN 1 ELSE 0 END) AS used_total FROM referrals WHERE source_business_id = ? AND to_char(created_at, \'YYYY-MM\') = ?');
$stmt->execute([$business_id, $month]);
$referral_summary = $stmt->fetch();
$total_referrals = (int)($referral_summary['total'] ?? 0);
$used_referrals = (int)($referral_summary['used_total'] ?? 0);
$conversion_rate = $total_referrals > 0 ? ($used_referrals / $total_referrals) * 100 : 0;

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS earned FROM commissions WHERE owed_to_business_id = ? AND month = ?');
$stmt->execute([$business_id, $month]);
$month_earnings = $stmt->fetch()['earned'];

$stmt = $pdo->prepare('SELECT b.name, COUNT(r.id) AS referrals_count, SUM(CASE WHEN r.status = \'used\' THEN 1 ELSE 0 END) AS used_count, COALESCE(SUM(c.amount),0) AS commission_total
    FROM referrals r
    JOIN businesses b ON b.id = r.target_business_id
    LEFT JOIN commissions c ON c.referral_id = r.id
    WHERE r.source_business_id = ? AND to_char(r.created_at, \'YYYY-MM\') = ?
    GROUP BY b.id, b.name
    ORDER BY referrals_count DESC, used_count DESC, b.name
    LIMIT 5');
$stmt->execute([$business_id, $month]);
$top_partners = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT COALESCE(s.name, ?) AS staff_name, COUNT(r.id) AS referrals_count, SUM(CASE WHEN r.status = \'used\' THEN 1 ELSE 0 END) AS used_count, COALESCE(SUM(c.amount),0) AS commission_total
    FROM referrals r
    LEFT JOIN staff s ON s.id = r.staff_id
    LEFT JOIN commissions c ON c.referral_id = r.id
    WHERE r.source_business_id = ? AND to_char(r.created_at, \'YYYY-MM\') = ?
    GROUP BY staff_name
    ORDER BY referrals_count DESC, used_count DESC, staff_name
    LIMIT 10');
$stmt->execute([$user['name'], $business_id, $month]);
$staff_performance = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT to_char(r.created_at, \'YYYY-MM\') AS activity_month,
        COUNT(*) AS referrals_count,
        SUM(CASE WHEN r.status = \'used\' THEN 1 ELSE 0 END) AS used_count,
        COALESCE(SUM(c.amount),0) AS commission_total
    FROM referrals r
    LEFT JOIN commissions c ON c.referral_id = r.id
    WHERE r.source_business_id = ?
        AND r.created_at >= (to_date(? || \'-01\', \'YYYY-MM-DD\') - interval \'5 month\')
        AND r.created_at < (to_date(? || \'-01\', \'YYYY-MM-DD\') + interval \'1 month\')
    GROUP BY activity_month
    ORDER BY activity_month ASC');
$stmt->execute([$business_id, $month, $month]);
$trend_rows = $stmt->fetchAll();

$trend_referrals = array_map('intval', array_column($trend_rows, 'referrals_count'));
$trend_commissions = array_map('floatval', array_column($trend_rows, 'commission_total'));
$average_referrals = $trend_referrals ? array_sum($trend_referrals) / count($trend_referrals) : 0;
$recent_referrals = $trend_referrals ? end($trend_referrals) : 0;
$previous_referrals = count($trend_referrals) > 1 ? $trend_referrals[count($trend_referrals) - 2] : $recent_referrals;
$momentum = $recent_referrals - $previous_referrals;
$forecast_referrals = max(0, (int)round(($average_referrals * 0.65) + ($recent_referrals * 0.25) + ($momentum * 0.1)));
$average_commission_per_referral = array_sum($trend_referrals) > 0 ? array_sum($trend_commissions) / array_sum($trend_referrals) : 0;
$forecast_revenue = $forecast_referrals * $average_commission_per_referral;
$forecast_label = $momentum > 0 ? 'Increasing' : ($momentum < 0 ? 'Softening' : 'Stable');

$stmt = $pdo->prepare('SELECT b.id, b.name, b.business_type,
        COUNT(r.id) AS referrals_count,
        SUM(CASE WHEN r.status = \'used\' THEN 1 ELSE 0 END) AS used_count,
        COALESCE(SUM(c.amount),0) AS commission_total,
        AVG(c.commission_percentage) AS average_rate,
        b.reliability_score,
        b.payout_compliance_score,
        b.guest_satisfaction_score
    FROM referrals r
    JOIN businesses b ON b.id = r.target_business_id
    LEFT JOIN commissions c ON c.referral_id = r.id
    WHERE r.source_business_id = ?
        AND r.created_at >= (to_date(? || \'-01\', \'YYYY-MM-DD\') - interval \'5 month\')
        AND r.created_at < (to_date(? || \'-01\', \'YYYY-MM-DD\') + interval \'1 month\')
    GROUP BY b.id, b.name, b.business_type, b.reliability_score, b.payout_compliance_score, b.guest_satisfaction_score
    ORDER BY referrals_count DESC, commission_total DESC
    LIMIT 8');
$stmt->execute([$business_id, $month, $month]);
$partner_rankings = $stmt->fetchAll();

foreach ($partner_rankings as &$partner) {
    $referrals_count = max(1, (int)$partner['referrals_count']);
    $used_count = (int)$partner['used_count'];
    $conversion = ($used_count / $referrals_count) * 100;
    $reputation = reputation_average($partner);
    $commission_total = (float)$partner['commission_total'];
    $partner['performance_score'] = round(($conversion * 0.4) + ($reputation * 0.35) + (min(100, $commission_total / 1000) * 0.25), 1);
    $partner['conversion_rate'] = $conversion;
    $partner['expected_next_revenue'] = ($forecast_referrals > 0 && array_sum($trend_referrals) > 0)
        ? ($forecast_referrals * ($referrals_count / array_sum($trend_referrals)) * ($commission_total / $referrals_count))
        : 0;
}
unset($partner);
usort($partner_rankings, function ($a, $b) {
    return $b['performance_score'] <=> $a['performance_score'];
});

$highest_impact_partner = $partner_rankings[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Insights' => 'analytics.php']); ?>
            <?php echo render_page_header(
                'Analytics',
                'Operating signals for referrals, partners, and staff performance.',
                []
            ); ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-3"><?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php echo render_stat_cards([
                ['label' => 'Total Referrals', 'value' => htmlspecialchars($total_referrals), 'variant' => 'info'],
                ['label' => 'Conversion Rate', 'value' => htmlspecialchars(number_format($conversion_rate, 1)) . '%', 'variant' => 'primary'],
                ['label' => 'Commission Earned', 'value' => 'RWF ' . format_money($month_earnings), 'variant' => 'success'],
            ]); ?>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-column flex-lg-row gap-3 mb-3">
                        <div>
                            <h3 class="text-section-heading mb-1">Predictive Insights</h3>
                            <p class="text-muted mb-0 small">Rule-based forecast from referral history. Use as a planning signal, not a guarantee.</p>
                        </div>
                        <form class="d-flex gap-2 align-items-end" method="get">
                            <div>
                                <label class="form-label small">Month</label>
                                <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month); ?>">
                            </div>
                            <button class="btn btn-primary">View</button>
                        </form>
                        <span class="badge bg-<?php echo $forecast_label === 'Increasing' ? 'success' : ($forecast_label === 'Softening' ? 'warning' : 'secondary'); ?>"><?php echo htmlspecialchars($forecast_label); ?></span>
                    </div>
                    <div class="prediction-grid">
                        <div class="prediction-card">
                            <span class="prediction-label">Next Month Forecast</span>
                            <strong><?php echo htmlspecialchars($forecast_referrals); ?> referrals</strong>
                            <span>Based on <?php echo htmlspecialchars(count($trend_rows)); ?> month(s) of activity.</span>
                        </div>
                        <div class="prediction-card">
                            <span class="prediction-label">Revenue Impact</span>
                            <strong>RWF <?php echo format_money($forecast_revenue); ?></strong>
                            <span>Uses average commission per referral.</span>
                        </div>
                        <div class="prediction-card">
                            <span class="prediction-label">Best Partner Focus</span>
                            <strong><?php echo htmlspecialchars($highest_impact_partner['name'] ?? 'No signal yet'); ?></strong>
                            <span><?php echo $highest_impact_partner ? 'Expected RWF ' . format_money($highest_impact_partner['expected_next_revenue']) . ' next month.' : 'Create more referrals to build a signal.'; ?></span>
                        </div>
                    </div>
                    <?php if ($partner_rankings): ?>
                        <div class="table-responsive mt-3">
                            <table class="table table-modern table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Partner</th>
                                        <th>Performance</th>
                                        <th>Conversion</th>
                                        <th>Expected Impact</th>
                                        <th>Signal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($partner_rankings as $partner): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($partner['name']); ?> <span class="badge bg-light text-dark"><?php echo htmlspecialchars(business_type_label($partner['business_type'])); ?></span></td>
                                            <td><?php echo htmlspecialchars($partner['performance_score']); ?>/100</td>
                                            <td><?php echo htmlspecialchars(number_format($partner['conversion_rate'], 1)); ?>%</td>
                                            <td>RWF <?php echo format_money($partner['expected_next_revenue']); ?></td>
                                            <td class="small text-muted"><?php echo ((float)$partner['performance_score'] >= 75) ? 'Prioritize for referrals' : (((float)$partner['performance_score'] >= 55) ? 'Keep testing' : 'Review agreement or fit'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mt-3 mb-0">No predictive signal yet. Forecasts improve once referrals and commissions are recorded.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row gy-4">
                <div class="col-12 col-lg-6">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Top Partners</h3>
                            <?php if ($top_partners): ?>
                                <div class="table-responsive">
                                    <table class="table table-modern table-hover align-middle mb-0">
                                        <thead>
                                            <tr><th>Partner</th><th>Referrals</th><th>Used</th><th>Commission</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($top_partners as $p): ?>
                                                <tr><td><?php echo htmlspecialchars($p['name']); ?></td><td><?php echo htmlspecialchars($p['referrals_count']); ?></td><td><?php echo htmlspecialchars($p['used_count']); ?></td><td>RWF <?php echo format_money($p['commission_total']); ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No partner activity for this month.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Staff Performance</h3>
                            <?php if ($staff_performance): ?>
                                <div class="table-responsive">
                                    <table class="table table-modern table-hover align-middle mb-0">
                                        <thead>
                                            <tr><th>Staff</th><th>Referrals</th><th>Used</th><th>Commission</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($staff_performance as $s): ?>
                                                <tr><td><?php echo htmlspecialchars($s['staff_name']); ?></td><td><?php echo htmlspecialchars($s['referrals_count']); ?></td><td><?php echo htmlspecialchars($s['used_count']); ?></td><td>RWF <?php echo format_money($s['commission_total']); ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No staff referral activity this month.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
