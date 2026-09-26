<?php
require_once __DIR__ . '/../app/Config/config.php';
require_once __DIR__ . '/../app/Config/database.php';
db_init();
require_login();
require_manager();
$user = current_user();
$pdo = db_connect();
$business_id = tenant_business_id($user);
$network_view = tenant_can_view_network($user);
$current_page = 'revenue_model.php';
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
    require_csrf_post('revenue_model.php');
    $action = $_POST['action'] ?? 'settings';

    if ($action === 'review_fee') {
        $fee_id = intval($_POST['fee_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'simulated');
        $review_note = trim($_POST['review_note'] ?? '');

        if ($fee_id <= 0) {
            $errors[] = 'Select a platform fee row to review.';
        }
        if (!array_key_exists($status, platform_fee_status_options())) {
            $errors[] = 'Choose a valid platform fee status.';
            $status = 'simulated';
        }
        if (strlen($review_note) > 1000) {
            $errors[] = 'Review note must stay under 1,000 characters.';
        }

        if (empty($errors)) {
            tenant_assert_platform_fee_row($pdo, $fee_id, $user);
            $reviewed_at_sql = $status === 'simulated' ? 'NULL' : 'NOW()';
            $reviewed_by = $status === 'simulated' ? null : (int)$user['id'];
            [$fee_scope_sql, $fee_scope_params] = tenant_platform_fee_scope($user);
            $stmt = $pdo->prepare('UPDATE platform_fees
                SET status = ?, review_note = ?, reviewed_by_user_id = ?, reviewed_at = ' . $reviewed_at_sql . '
                WHERE id = ? AND billing_month = ?' . $fee_scope_sql);
            $stmt->execute(array_merge(
                [$status, $review_note !== '' ? $review_note : null, $reviewed_by, $fee_id, $month],
                $fee_scope_params
            ));

            if ($stmt->rowCount() > 0) {
                flash_set('Platform fee review saved.');
                header('Location: revenue_model.php?month=' . urlencode($month));
                exit;
            }
            $errors[] = 'Platform fee row not found for this month.';
        }
    } else {
        if (!$network_view) {
            $errors[] = 'Only platform operators can change global revenue model settings.';
        }
        $subscription_fee = max(0, floatval($_POST['subscription_fee'] ?? 0));
        $referral_fee_percentage = max(0, floatval($_POST['referral_fee_percentage'] ?? 0));
        $analytics_tier_fee = max(0, floatval($_POST['analytics_tier_fee'] ?? 0));
        $featured_listing_fee = max(0, floatval($_POST['featured_listing_fee'] ?? 0));

        if ($referral_fee_percentage > 100) {
            $errors[] = 'Referral fee percentage cannot exceed 100%.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('UPDATE platform_revenue_settings SET subscription_fee = ?, referral_fee_percentage = ?, analytics_tier_fee = ?, featured_listing_fee = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$subscription_fee, $referral_fee_percentage, $analytics_tier_fee, $featured_listing_fee]);
            refresh_platform_fees($pdo, $month);
            $flash = 'Revenue model updated and monthly platform fees recalculated.';
        }
    }
}

$settings = platform_revenue_settings($pdo);
refresh_platform_fees($pdo, $month);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    [$fee_scope_sql, $fee_scope_params] = tenant_platform_fee_scope($user);
    $stmt = $pdo->prepare('SELECT pf.*, b.name AS business_name, u.name AS reviewed_by_name
        FROM platform_fees pf
        JOIN businesses b ON b.id = pf.business_id
        LEFT JOIN users u ON u.id = pf.reviewed_by_user_id
        WHERE pf.billing_month = ?' . $fee_scope_sql . '
        ORDER BY pf.total_amount DESC, b.name');
    $stmt->execute(array_merge([$month], $fee_scope_params));
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="platform-fees-' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Business', 'Month', 'Subscription', 'Referral Fee', 'Analytics', 'Featured', 'Total', 'Status', 'Review Note', 'Reviewed By', 'Reviewed At']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['business_name'],
            $row['billing_month'],
            $row['subscription_fee'],
            $row['referral_fee'],
            $row['analytics_fee'],
            $row['featured_fee'],
            $row['total_amount'],
            platform_fee_status_label($row['status']),
            $row['review_note'],
            $row['reviewed_by_name'],
            $row['reviewed_at'],
        ]);
    }
    exit;
}

[$fee_scope_sql, $fee_scope_params] = tenant_platform_fee_scope($user);
$stmt = $pdo->prepare('SELECT pf.*, b.name AS business_name, u.name AS reviewed_by_name
    FROM platform_fees pf
    JOIN businesses b ON b.id = pf.business_id
    LEFT JOIN users u ON u.id = pf.reviewed_by_user_id
    WHERE pf.billing_month = ?' . $fee_scope_sql . '
    ORDER BY pf.total_amount DESC, b.name');
$stmt->execute(array_merge([$month], $fee_scope_params));
$fee_rows = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT
        IFNULL(SUM(subscription_fee),0) AS subscription_total,
        IFNULL(SUM(referral_fee),0) AS referral_total,
        IFNULL(SUM(analytics_fee),0) AS analytics_total,
        IFNULL(SUM(featured_fee),0) AS featured_total,
        IFNULL(SUM(total_amount),0) AS grand_total,
        SUM(status = "approved") AS approved_count,
        SUM(status = "waived") AS waived_count,
        SUM(status = "simulated") AS simulated_count
    FROM platform_fees pf
    WHERE pf.billing_month = ?' . $fee_scope_sql);
$stmt->execute(array_merge([$month], $fee_scope_params));
$summary = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue model - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
<?php echo render_breadcrumbs(['Management' => false, 'Revenue model' => false]); ?>
<?php echo render_page_header(
    'Revenue model',
    'Simulation only. No hotel is charged automatically.',
    [
        ['href' => 'revenue_model.php?month=' . urlencode($month) . '&export=csv', 'label' => 'Export CSV', 'style' => 'outline-secondary'],
    ]
); ?>
    <div class="row gy-3">
        <div class="col-12 col-xl-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="text-section-heading">Revenue model settings</h3>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo htmlspecialchars($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($flash): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($flash); ?></div>
                    <?php endif; ?>
                    <?php if (!$network_view): ?>
                        <p class="text-muted mb-0">Global pricing is managed by the platform operator. You can review simulated fees for <strong>your business only</strong> in the table.</p>
                    <?php else: ?>
                    <p class="text-muted">Simulation only. No hotel is charged automatically.</p>
                    <form method="post">
                            <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">Monthly subscription fee</label>
                            <input type="number" step="0.01" min="0" name="subscription_fee" class="form-control" value="<?php echo htmlspecialchars($settings['subscription_fee']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Referral platform fee (%)</label>
                            <input type="number" step="0.01" min="0" max="100" name="referral_fee_percentage" class="form-control" value="<?php echo htmlspecialchars($settings['referral_fee_percentage']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Analytics tier fee</label>
                            <input type="number" step="0.01" min="0" name="analytics_tier_fee" class="form-control" value="<?php echo htmlspecialchars($settings['analytics_tier_fee']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Featured listing fee</label>
                            <input type="number" step="0.01" min="0" name="featured_listing_fee" class="form-control" value="<?php echo htmlspecialchars($settings['featured_listing_fee']); ?>">
                        </div>
                        <button class="btn btn-primary w-100">Save and recalculate</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-column flex-md-row gap-3">
                        <div>
                            <h2 class="h5 mb-1">Platform fee simulation</h2>
                            <p class="text-muted mb-0">Monthly forecast for SaaS, referral fee, analytics, and featured listing revenue.</p>
                        </div>
                        <form class="d-flex gap-2" method="get">
                            <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month); ?>">
                            <button class="btn btn-primary">View</button>
                            <a class="btn btn-outline-secondary" href="revenue_model.php?month=<?php echo urlencode($month); ?>&export=csv">CSV</a>
                        </form>
                    </div>

                    <div class="row gy-3 mt-3">
                        <div class="col-12 col-md-3">
                            <div class="card summary-card shadow-sm">
                                <div class="card-body">
                                    <h3 class="h6">Subscription</h3>
                                    <div class="display-6">RWF <?php echo format_money($summary['subscription_total']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <div class="card summary-card shadow-sm">
                                <div class="card-body">
                                    <h3 class="h6">Referral fees</h3>
                                    <div class="display-6">RWF <?php echo format_money($summary['referral_total']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <div class="card summary-card shadow-sm">
                                <div class="card-body">
                                    <h3 class="h6">Analytics</h3>
                                    <div class="display-6">RWF <?php echo format_money($summary['analytics_total']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <div class="card summary-card shadow-sm">
                                <div class="card-body">
                                    <h3 class="h6">Total</h3>
                                    <div class="display-6">RWF <?php echo format_money($summary['grand_total']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars((int)$summary['approved_count']); ?> approved · <?php echo htmlspecialchars((int)$summary['waived_count']); ?> waived</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive mt-4">
                        <table class="table table-modern table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Business</th>
                                    <th>Subscription</th>
                                    <th>Referral fee</th>
                                    <th>Analytics</th>
                                    <th>Featured</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Review</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($fee_rows): ?>
                                    <?php foreach ($fee_rows as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['business_name']); ?></td>
                                            <td>RWF <?php echo format_money($row['subscription_fee']); ?></td>
                                            <td>RWF <?php echo format_money($row['referral_fee']); ?></td>
                                            <td>RWF <?php echo format_money($row['analytics_fee']); ?></td>
                                            <td>RWF <?php echo format_money($row['featured_fee']); ?></td>
                                            <td><strong>RWF <?php echo format_money($row['total_amount']); ?></strong></td>
                                            <td>
                                                <span class="badge bg-<?php echo $row['status'] === 'approved' ? 'success' : ($row['status'] === 'waived' ? 'secondary' : 'warning'); ?>">
                                                    <?php echo htmlspecialchars(platform_fee_status_label($row['status'])); ?>
                                                </span>
                                                <?php if ($row['reviewed_at']): ?>
                                                    <div class="small text-muted"><?php echo htmlspecialchars(date('Y-m-d', strtotime($row['reviewed_at']))); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="post" class="d-flex flex-column gap-2">
                            <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="review_fee">
                                                    <input type="hidden" name="fee_id" value="<?php echo intval($row['id']); ?>">
                                                    <select name="status" class="form-select form-select-sm">
                                                        <?php foreach (platform_fee_status_options() as $status => $label): ?>
                                                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $row['status'] === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="text" name="review_note" class="form-control form-control-sm" placeholder="Review note" value="<?php echo htmlspecialchars($row['review_note'] ?? ''); ?>">
                                                    <button class="btn btn-sm btn-outline-primary">Save review</button>
                                                    <?php if ($row['reviewed_by_name']): ?>
                                                        <div class="small text-muted">By <?php echo htmlspecialchars($row['reviewed_by_name']); ?></div>
                                                    <?php endif; ?>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">No platform fees simulated for this month.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted mb-0">Use this to test pricing conversations during pilots before adding real billing.</p>
                </div>
            </div>
        </div>
    </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
