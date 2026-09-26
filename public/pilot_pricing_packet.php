<?php
require_once __DIR__ . '/../app/Config/config.php';
require_once __DIR__ . '/../app/Config/database.php';
db_init();
require_login();
require_platform_admin();
$user = current_user();
$pdo = db_connect();
$business_id = (int) $user['business_id'];
$current_page = 'pilot_pricing_packet.php';
$pending_requests = gb_pending_partnership_count($pdo, $business_id);

require_once __DIR__ . '/../app/HTTP/helpers.php';

$month = trim($_GET['month'] ?? date('Y-m'));
$errors = [];
$flash = flash_get();

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Invalid month format.';
    $month = date('Y-m');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('pilot_pricing_packet.php');
    $action = $_POST['action'] ?? 'seasonality';

    if ($action === 'feedback') {
        $city = trim($_POST['city'] ?? '');
        $business_id = intval($_POST['business_id'] ?? 0);
        $sentiment = $_POST['sentiment'] ?? 'neutral';
        $rating = max(1, min(5, intval($_POST['rating'] ?? 3)));
        $note = trim($_POST['note'] ?? '');

        if ($city === '') {
            $errors[] = 'Choose a city for this feedback note.';
        }
        if (!array_key_exists($sentiment, feedback_sentiment_options())) {
            $errors[] = 'Choose a valid feedback sentiment.';
            $sentiment = 'neutral';
        }
        if ($note === '') {
            $errors[] = 'Feedback note is required.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('INSERT INTO pilot_feedback (context, feedback_month, city, business_id, sentiment, rating, note, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute(['pricing_packet', $month, $city, $business_id > 0 ? $business_id : null, $sentiment, $rating, $note, $user['id']]);
            flash_set('Pricing packet feedback saved.');
            header('Location: pilot_pricing_packet.php?month=' . urlencode($month));
            exit;
        }
    }

    if ($action === 'seasonality') {
    $minimum_referrals = max(1, intval($_POST['minimum_referrals'] ?? 6));
    $minimum_active_months = max(1, intval($_POST['minimum_active_months'] ?? 3));
    $high_season_multiplier = max(1, floatval($_POST['high_season_multiplier'] ?? 1.3));
    $low_season_multiplier = max(0.1, min(1, floatval($_POST['low_season_multiplier'] ?? 0.7)));
    $high_season_adjustment = max(0, min(5, floatval($_POST['high_season_adjustment'] ?? 1)));
    $low_season_adjustment = max(-5, min(0, floatval($_POST['low_season_adjustment'] ?? -0.5)));
    $conversion_bonus_threshold = max(0, min(100, floatval($_POST['conversion_bonus_threshold'] ?? 15)));
    $conversion_penalty_threshold = max(0, min(100, floatval($_POST['conversion_penalty_threshold'] ?? 20)));
    $conversion_adjustment = max(0, min(5, floatval($_POST['conversion_adjustment'] ?? 0.5)));

    if ($low_season_multiplier >= $high_season_multiplier) {
        $errors[] = 'Low season multiplier must be lower than high season multiplier.';
    }

    if (empty($errors)) {
        $settings = seasonality_settings($pdo);
        $stmt = $pdo->prepare('UPDATE seasonality_settings
            SET minimum_referrals = ?,
                minimum_active_months = ?,
                high_season_multiplier = ?,
                low_season_multiplier = ?,
                high_season_adjustment = ?,
                low_season_adjustment = ?,
                conversion_bonus_threshold = ?,
                conversion_penalty_threshold = ?,
                conversion_adjustment = ?,
                updated_by_user_id = ?
            WHERE id = ?');
        $stmt->execute([
            $minimum_referrals,
            $minimum_active_months,
            $high_season_multiplier,
            $low_season_multiplier,
            $high_season_adjustment,
            $low_season_adjustment,
            $conversion_bonus_threshold,
            $conversion_penalty_threshold,
            $conversion_adjustment,
            $user['id'],
            $settings['id'],
        ]);
        flash_set('Seasonality thresholds updated for future commission suggestions.');
        header('Location: pilot_pricing_packet.php?month=' . urlencode($month));
        exit;
    }
    }
}

$seasonality_settings = seasonality_settings($pdo);
refresh_platform_fees($pdo, $month);
refresh_hotel_debts($pdo, null, $month);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pilot-pricing-packet-' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Section', 'Business/Partner', 'Metric', 'Value', 'Status/Note']);

    $stmt = $pdo->prepare('SELECT pf.*, b.name AS business_name
        FROM platform_fees pf
        JOIN businesses b ON b.id = pf.business_id
        WHERE pf.billing_month = ?
        ORDER BY pf.total_amount DESC, b.name');
    $stmt->execute([$month]);
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($output, ['Platform fee', $row['business_name'], 'Total simulated fee', $row['total_amount'], platform_fee_status_label($row['status'])]);
    }

    $stmt = $pdo->prepare('SELECT fl.*, b.name AS business_name
        FROM featured_listings fl
        JOIN businesses b ON b.id = fl.business_id
        WHERE fl.featured_month = ?
        ORDER BY (fl.status = "active") DESC, b.name');
    $stmt->execute([$month]);
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($output, ['Featured listing', $row['business_name'], 'Placement', featured_listing_status_label($row['status']), $row['note']]);
    }

    $stmt = $pdo->prepare('SELECT creditor.name AS business_name,
            debtor.name AS partner_name,
            d.total_amount,
            d.paid_amount,
            d.remaining_amount,
            d.status
        FROM hotel_debts d
        JOIN businesses creditor ON creditor.id = d.creditor_business_id
        JOIN businesses debtor ON debtor.id = d.debtor_business_id
        WHERE d.billing_month = ?
        ORDER BY d.remaining_amount DESC, creditor.name, debtor.name');
    $stmt->execute([$month]);
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($output, ['Settlement', $row['business_name'] . ' / ' . $row['partner_name'], 'Open balance', $row['remaining_amount'], debt_status_label($row['status'])]);
    }
    exit;
}

$stmt = $pdo->prepare('SELECT pf.*, b.name AS business_name, u.name AS reviewed_by_name
    FROM platform_fees pf
    JOIN businesses b ON b.id = pf.business_id
    LEFT JOIN users u ON u.id = pf.reviewed_by_user_id
    WHERE pf.billing_month = ?
    ORDER BY pf.total_amount DESC, b.name');
$stmt->execute([$month]);
$platform_rows = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT
        IFNULL(SUM(total_amount),0) AS total_amount,
        IFNULL(SUM(subscription_fee),0) AS subscription_fee,
        IFNULL(SUM(referral_fee),0) AS referral_fee,
        IFNULL(SUM(analytics_fee),0) AS analytics_fee,
        IFNULL(SUM(featured_fee),0) AS featured_fee,
        SUM(status = "approved") AS approved_count,
        SUM(status = "waived") AS waived_count,
        SUM(status = "simulated") AS simulated_count
    FROM platform_fees
    WHERE billing_month = ?');
$stmt->execute([$month]);
$platform_summary = $stmt->fetch();

$stmt = $pdo->prepare('SELECT fl.*, b.name AS business_name, b.business_type, u.name AS created_by_name
    FROM featured_listings fl
    JOIN businesses b ON b.id = fl.business_id
    LEFT JOIN users u ON u.id = fl.created_by_user_id
    WHERE fl.featured_month = ?
    ORDER BY (fl.status = "active") DESC, b.name');
$stmt->execute([$month]);
$featured_rows = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT creditor.name AS business_name,
        debtor.name AS partner_name,
        d.total_amount,
        d.paid_amount,
        d.remaining_amount,
        d.due_date,
        d.status
    FROM hotel_debts d
    JOIN businesses creditor ON creditor.id = d.creditor_business_id
    JOIN businesses debtor ON debtor.id = d.debtor_business_id
    WHERE d.billing_month = ?
    ORDER BY d.remaining_amount DESC, creditor.name, debtor.name
    LIMIT 20');
$stmt->execute([$month]);
$settlement_rows = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT
        IFNULL(SUM(total_amount),0) AS total_amount,
        IFNULL(SUM(paid_amount),0) AS paid_amount,
        IFNULL(SUM(remaining_amount),0) AS remaining_amount,
        SUM(status = "overdue") AS overdue_count,
        SUM(status = "partial") AS partial_count,
        SUM(status = "paid") AS paid_count
    FROM hotel_debts
    WHERE billing_month = ?');
$stmt->execute([$month]);
$settlement_summary = $stmt->fetch();

$stmt = $pdo->prepare('SELECT b.id, b.name, b.business_type,
        p.business_id AS source_business_id,
        source.name AS source_business_name,
        p.commission_rate,
        COUNT(r.id) AS referrals_count,
        COUNT(DISTINCT DATE_FORMAT(r.created_at, "%Y-%m")) AS active_months
    FROM partnerships p
    JOIN businesses b ON b.id = p.partner_business_id
    JOIN businesses source ON source.id = p.business_id
    LEFT JOIN referrals r ON r.source_business_id = p.business_id AND r.target_business_id = p.partner_business_id
    WHERE p.status = "active"
    GROUP BY b.id, b.name, b.business_type, p.business_id, source.name, p.commission_rate
    ORDER BY referrals_count DESC, source.name, b.name
    LIMIT 12');
$stmt->execute();
$seasonality_rows = [];
foreach ($stmt->fetchAll() as $row) {
    $signal = commission_seasonality_signal($pdo, (int)$row['source_business_id'], (int)$row['id'], $month);
    $row['seasonality_signal'] = $signal;
    $row['suggested_rate'] = max(1, min(30, round((float)$row['commission_rate'] + (float)$signal['adjustment'], 1)));
    $seasonality_rows[] = $row;
}

$stmt = $pdo->query('SELECT id, name, city FROM businesses ORDER BY city, name');
$business_options = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT pf.*, b.name AS business_name, u.name AS created_by_name
    FROM pilot_feedback pf
    LEFT JOIN businesses b ON b.id = pf.business_id
    LEFT JOIN users u ON u.id = pf.created_by_user_id
    WHERE pf.context = ? AND pf.feedback_month = ?
    ORDER BY pf.created_at DESC
    LIMIT 8');
$stmt->execute(['pricing_packet', $month]);
$pricing_feedback_rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilot pricing packet - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
<?php echo render_breadcrumbs(['Management' => false, 'Pricing configuration' => false]); ?>
<?php echo render_page_header(
    'Pilot pricing packet',
    'Monthly review pack for pricing conversations, placement tests, and manual settlement readiness.'
); ?>
<form class="d-flex flex-wrap gap-2 mb-4" method="get">
    <input type="month" name="month" class="form-control" style="max-width:200px" value="<?php echo htmlspecialchars($month); ?>">
    <button class="btn btn-primary">View month</button>
    <a class="btn btn-outline-secondary" href="pilot_pricing_packet.php?month=<?php echo urlencode($month); ?>&export=csv">Export CSV</a>
</form>
    <div class="card shadow-sm">
        <div class="card-body">
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

            <div class="alert alert-info mt-3 mb-0">
                Real payment collection, subscription billing, paid featured listings, and AI model calls are still disabled for the pilot. This page prepares decisions for manual review only.
            </div>
        </div>
    </div>

    <div class="row gy-3 mt-1">
        <div class="col-12 col-md-4">
            <div class="card summary-card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Simulated platform fees</h3>
                    <div class="display-6">RWF <?php echo format_money($platform_summary['total_amount']); ?></div>
                    <div class="small text-muted"><?php echo htmlspecialchars((int)$platform_summary['approved_count']); ?> approved · <?php echo htmlspecialchars((int)$platform_summary['waived_count']); ?> waived</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card summary-card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Featured placements</h3>
                    <div class="display-6"><?php echo htmlspecialchars(count($featured_rows)); ?></div>
                    <div class="small text-muted"><?php echo htmlspecialchars(count(array_filter($featured_rows, function ($row) { return $row['status'] === 'active'; }))); ?> active this month</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card summary-card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Settlement open balance</h3>
                    <div class="display-6">RWF <?php echo format_money($settlement_summary['remaining_amount']); ?></div>
                    <div class="small text-muted"><?php echo htmlspecialchars((int)$settlement_summary['overdue_count']); ?> overdue · <?php echo htmlspecialchars((int)$settlement_summary['partial_count']); ?> partial</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row gy-3 mt-1">
        <div class="col-12 col-xl-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Platform fee review</h3>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Business</th>
                                    <th>Total</th>
                                    <th>Breakdown</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($platform_rows): ?>
                                    <?php foreach ($platform_rows as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['business_name']); ?></td>
                                            <td>RWF <?php echo format_money($row['total_amount']); ?></td>
                                            <td class="small text-muted">
                                                SaaS <?php echo format_money($row['subscription_fee']); ?> ·
                                                referral <?php echo format_money($row['referral_fee']); ?> ·
                                                analytics <?php echo format_money($row['analytics_fee']); ?> ·
                                                featured <?php echo format_money($row['featured_fee']); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $row['status'] === 'approved' ? 'success' : ($row['status'] === 'waived' ? 'secondary' : 'warning'); ?>">
                                                    <?php echo htmlspecialchars(platform_fee_status_label($row['status'])); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center text-muted">No platform fee simulation for this month.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <a href="revenue_model.php?month=<?php echo urlencode($month); ?>" class="btn btn-outline-primary btn-sm">Open revenue model</a>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Featured placement review</h3>
                    <?php if ($featured_rows): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($featured_rows as $row): ?>
                                <div class="list-group-item px-0">
                                    <div class="d-flex justify-content-between gap-2">
                                        <strong><?php echo htmlspecialchars($row['business_name']); ?></strong>
                                        <span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars(featured_listing_status_label($row['status'])); ?></span>
                                    </div>
                                    <div class="small text-muted"><?php echo htmlspecialchars(business_type_label($row['business_type'])); ?> · <?php echo htmlspecialchars($row['created_by_name'] ?: 'Manager'); ?></div>
                                    <?php if ($row['note']): ?>
                                        <div class="small"><?php echo htmlspecialchars($row['note']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No featured placements set for this month.</p>
                    <?php endif; ?>
                    <a href="partners.php?featured_month=<?php echo urlencode($month); ?>" class="btn btn-outline-primary btn-sm mt-2">Open placements</a>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Settlement readiness</h3>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Creditor</th>
                                    <th>Debtor</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Open</th>
                                    <th>Due</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($settlement_rows): ?>
                                    <?php foreach ($settlement_rows as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['business_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['partner_name']); ?></td>
                                            <td>RWF <?php echo format_money($row['total_amount']); ?></td>
                                            <td>RWF <?php echo format_money($row['paid_amount']); ?></td>
                                            <td>RWF <?php echo format_money($row['remaining_amount']); ?></td>
                                            <td><?php echo htmlspecialchars($row['due_date']); ?></td>
                                            <td><span class="badge bg-<?php echo $row['status'] === 'paid' ? 'success' : ($row['status'] === 'overdue' ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars(debt_status_label($row['status'])); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center text-muted">No settlement debts for this month.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <a href="settlement_summary.php?month=<?php echo urlencode($month); ?>" class="btn btn-outline-primary btn-sm">Open settlement summary</a>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Seasonality threshold review</h3>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Route</th>
                                    <th>History</th>
                                    <th>Signal</th>
                                    <th>Suggested</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($seasonality_rows): ?>
                                    <?php foreach ($seasonality_rows as $row): ?>
                                        <?php $signal = $row['seasonality_signal']; ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($row['source_business_name']); ?> → <?php echo htmlspecialchars($row['name']); ?><br>
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars(business_type_label($row['business_type'])); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['referrals_count']); ?> referrals · <?php echo htmlspecialchars($row['active_months']); ?> months</td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($signal['label']); ?></strong><br>
                                                <span class="small text-muted"><?php echo htmlspecialchars($signal['description']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['commission_rate']); ?>% → <?php echo htmlspecialchars($row['suggested_rate']); ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center text-muted">No active partnership routes available yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Seasonality settings</h3>
                    <form method="post" class="row gy-3">
                            <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="seasonality">
                        <div class="col-6">
                            <label class="form-label">Minimum referrals</label>
                            <input type="number" min="1" name="minimum_referrals" class="form-control" value="<?php echo htmlspecialchars($seasonality_settings['minimum_referrals']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Minimum months</label>
                            <input type="number" min="1" name="minimum_active_months" class="form-control" value="<?php echo htmlspecialchars($seasonality_settings['minimum_active_months']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">High season multiplier</label>
                            <input type="number" step="0.01" min="1" name="high_season_multiplier" class="form-control" value="<?php echo htmlspecialchars($seasonality_settings['high_season_multiplier']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Low season multiplier</label>
                            <input type="number" step="0.01" min="0.1" max="1" name="low_season_multiplier" class="form-control" value="<?php echo htmlspecialchars($seasonality_settings['low_season_multiplier']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">High adjustment</label>
                            <input type="number" step="0.1" min="0" max="5" name="high_season_adjustment" class="form-control" value="<?php echo htmlspecialchars($seasonality_settings['high_season_adjustment']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Low adjustment</label>
                            <input type="number" step="0.1" min="-5" max="0" name="low_season_adjustment" class="form-control" value="<?php echo htmlspecialchars($seasonality_settings['low_season_adjustment']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Conversion bonus gap</label>
                            <input type="number" step="0.1" min="0" max="100" name="conversion_bonus_threshold" class="form-control" value="<?php echo htmlspecialchars($seasonality_settings['conversion_bonus_threshold']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Conversion penalty gap</label>
                            <input type="number" step="0.1" min="0" max="100" name="conversion_penalty_threshold" class="form-control" value="<?php echo htmlspecialchars($seasonality_settings['conversion_penalty_threshold']); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Conversion adjustment</label>
                            <input type="number" step="0.1" min="0" max="5" name="conversion_adjustment" class="form-control" value="<?php echo htmlspecialchars($seasonality_settings['conversion_adjustment']); ?>">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary w-100">Save thresholds</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Pricing review feedback</h3>
                    <form method="post" class="row gy-3">
                            <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="feedback">
                        <div class="col-12 col-md-6">
                            <label class="form-label">City</label>
                            <select name="city" class="form-select" required>
                                <?php foreach (city_options() as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Business</label>
                            <select name="business_id" class="form-select">
                                <option value="">General packet note</option>
                                <?php foreach ($business_options as $business): ?>
                                    <option value="<?php echo intval($business['id']); ?>"><?php echo htmlspecialchars($business['name']); ?> · <?php echo htmlspecialchars(city_label($business['city'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Sentiment</label>
                            <select name="sentiment" class="form-select">
                                <?php foreach (feedback_sentiment_options() as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Rating</label>
                            <input type="number" name="rating" min="1" max="5" class="form-control" value="3">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Feedback note</label>
                            <textarea name="note" class="form-control" rows="4" required></textarea>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary w-100">Save feedback</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Latest pricing feedback</h3>
                    <?php if ($pricing_feedback_rows): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($pricing_feedback_rows as $feedback): ?>
                                <div class="list-group-item px-0">
                                    <div class="d-flex justify-content-between gap-2">
                                        <strong><?php echo htmlspecialchars($feedback['business_name'] ?: city_label($feedback['city'])); ?></strong>
                                        <span class="badge bg-<?php echo $feedback['sentiment'] === 'positive' ? 'success' : ($feedback['sentiment'] === 'negative' ? 'danger' : ($feedback['sentiment'] === 'follow_up' ? 'warning' : 'secondary')); ?>">
                                            <?php echo htmlspecialchars(feedback_sentiment_label($feedback['sentiment'])); ?>
                                        </span>
                                    </div>
                                    <div class="small text-muted"><?php echo htmlspecialchars(city_label($feedback['city'])); ?> · Rating <?php echo htmlspecialchars($feedback['rating']); ?>/5 · <?php echo htmlspecialchars($feedback['created_by_name'] ?: 'Manager'); ?> · <?php echo htmlspecialchars(date('Y-m-d', strtotime($feedback['created_at']))); ?></div>
                                    <div><?php echo htmlspecialchars($feedback['note']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No pricing packet feedback captured for this month.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
