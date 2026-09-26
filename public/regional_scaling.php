<?php
require_once __DIR__ . '/../app/Config/config.php';
require_once __DIR__ . '/../app/Config/database.php';
db_init();
require_login();
require_platform_admin();
$user = current_user();
$pdo = db_connect();
$business_id = (int) $user['business_id'];
$current_page = 'regional_scaling.php';
$pending_requests = gb_pending_partnership_count($pdo, $business_id);

require_once __DIR__ . '/../app/HTTP/helpers.php';

$errors = [];
$flash = flash_get();
$selected_city = trim($_GET['city'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('regional_scaling.php');
    $action = $_POST['action'] ?? 'template';

    if ($action === 'template') {
        $city = trim($_POST['city'] ?? 'Kigali');
        $business_type = $_POST['business_type'] ?? 'hotel';
        $title = trim($_POST['title'] ?? '');
        $default_commission_rate = max(0, floatval($_POST['default_commission_rate'] ?? 10));
        $template_text = trim($_POST['template_text'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($city === '') {
            $errors[] = 'Choose a city.';
        }
        if (!array_key_exists($business_type, business_type_options())) {
            $errors[] = 'Choose a valid business type.';
        }
        if ($title === '') {
            $errors[] = 'Template title is required.';
        }
        if ($template_text === '') {
            $errors[] = 'Template text is required.';
        }
        if (!in_array($status, ['active', 'archived'], true)) {
            $errors[] = 'Choose a valid template status.';
            $status = 'active';
        }
        if ($default_commission_rate <= 0 || $default_commission_rate > 100) {
            $errors[] = 'Default commission rate must be between 0 and 100.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('INSERT INTO contract_templates
                (city, business_type, title, default_commission_rate, template_text, status, created_by_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$city, $business_type, $title, $default_commission_rate, $template_text, $status, $user['id']]);
            flash_set('Contract template added for ' . $city . '.');
            header('Location: regional_scaling.php?city=' . urlencode($city));
            exit;
        }
    }

    if ($action === 'onboarding_add') {
        $city = trim($_POST['city'] ?? 'Kigali');
        $business_id = intval($_POST['business_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $due_date = trim($_POST['due_date'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if ($city === '') {
            $errors[] = 'Choose a city.';
        }
        if ($title === '') {
            $errors[] = 'Checklist item title is required.';
        }
        if ($due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
            $errors[] = 'Use a valid due date.';
        }
        if (empty($errors)) {
            $stmt = $pdo->prepare('INSERT INTO onboarding_checklists (city, business_id, title, due_date, note, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$city, $business_id > 0 ? $business_id : null, $title, $due_date !== '' ? $due_date : null, $note !== '' ? $note : null, $user['id']]);
            flash_set('Onboarding checklist item added.');
            header('Location: regional_scaling.php?city=' . urlencode($city));
            exit;
        }
    }

    if ($action === 'onboarding_update') {
        $item_id = intval($_POST['item_id'] ?? 0);
        $status = $_POST['status'] ?? 'not_started';
        $note = trim($_POST['note'] ?? '');

        if ($item_id <= 0) {
            $errors[] = 'Choose a checklist item.';
        }
        if (!array_key_exists($status, onboarding_status_options())) {
            $errors[] = 'Choose a valid onboarding status.';
            $status = 'not_started';
        }
        if (empty($errors)) {
            $completed_sql = $status === 'done' ? ', completed_by_user_id = ?, completed_at = NOW()' : ', completed_by_user_id = NULL, completed_at = NULL';
            $params = [$status, $note !== '' ? $note : null];
            if ($status === 'done') {
                $params[] = $user['id'];
            }
            $params[] = $item_id;
            $stmt = $pdo->prepare('UPDATE onboarding_checklists SET status = ?, note = ?' . $completed_sql . ' WHERE id = ?');
            $stmt->execute($params);
            flash_set('Onboarding checklist updated.');
            header('Location: regional_scaling.php' . ($selected_city !== '' ? '?city=' . urlencode($selected_city) : ''));
            exit;
        }
    }

    if ($action === 'feedback') {
        $city = trim($_POST['city'] ?? 'Kigali');
        $sentiment = $_POST['sentiment'] ?? 'neutral';
        $rating = max(1, min(5, intval($_POST['rating'] ?? 3)));
        $note = trim($_POST['note'] ?? '');

        if ($city === '') {
            $errors[] = 'Choose a city.';
        }
        if (!array_key_exists($sentiment, feedback_sentiment_options())) {
            $errors[] = 'Choose a valid feedback sentiment.';
            $sentiment = 'neutral';
        }
        if ($note === '') {
            $errors[] = 'Feedback note is required.';
        }
        if (empty($errors)) {
            $stmt = $pdo->prepare('INSERT INTO pilot_feedback (context, city, sentiment, rating, note, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute(['regional_launch', $city, $sentiment, $rating, $note, $user['id']]);
            flash_set('Regional launch feedback saved.');
            header('Location: regional_scaling.php?city=' . urlencode($city));
            exit;
        }
    }

    if ($action === 'deploy_checklist') {
        $city = trim($_POST['city'] ?? 'Kigali');
        if ($city === '') {
            $errors[] = 'Choose a city.';
        }
        if (empty($errors)) {
            $summary = deploy_city_checklist($pdo, $city, $user['id']);
            $msg = 'Checklist seeded for ' . $city . '.';
            if ($summary['template_found']) {
                $msg .= ' Template "' . ($summary['template_title'] ?? '') . '" found for this city.';
            }
            $msg .= ' ' . $summary['businesses_found'] . ' active business(es) found.';
            flash_set($msg);
            header('Location: regional_scaling.php?city=' . urlencode($city));
            exit;
        }
    }
}

$city_where = '';
$business_city_where = '';
$city_params = [];
if ($selected_city !== '') {
    $city_where = ' WHERE city = ?';
    $business_city_where = ' WHERE b.city = ?';
    $city_params[] = $selected_city;
}

$stmt = $pdo->prepare('SELECT
        city,
        COUNT(*) AS business_count,
        SUM(status = "active") AS active_businesses,
        SUM(business_type = "hotel") AS hotel_count,
        SUM(business_type = "tourism") AS tourism_count,
        SUM(business_type = "transport") AS transport_count,
        SUM(business_type = "restaurant") AS restaurant_count
    FROM businesses
    ' . $city_where . '
    GROUP BY city
    ORDER BY FIELD(city, "Kigali", "Kampala", "Nairobi", "Dar es Salaam"), city');
$stmt->execute($city_params);
$city_rows = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT b.city,
        COUNT(p.id) AS partnership_count,
        SUM(p.status = "active") AS active_partnerships,
        SUM(p.status = "pending") AS pending_partnerships
    FROM businesses b
    LEFT JOIN partnerships p ON p.business_id = b.id
    ' . $business_city_where . '
    GROUP BY b.city');
$stmt->execute($city_params);
$partnership_by_city = [];
foreach ($stmt->fetchAll() as $row) {
    $partnership_by_city[$row['city']] = $row;
}

$template_sql = 'SELECT ct.*, u.name AS created_by_name
    FROM contract_templates ct
    LEFT JOIN users u ON u.id = ct.created_by_user_id';
$template_params = [];
if ($selected_city !== '') {
    $template_sql .= ' WHERE ct.city = ?';
    $template_params[] = $selected_city;
}
$template_sql .= ' ORDER BY FIELD(ct.city, "Kigali", "Kampala", "Nairobi", "Dar es Salaam"), ct.city, ct.business_type, ct.title';
$stmt = $pdo->prepare($template_sql);
$stmt->execute($template_params);
$templates = $stmt->fetchAll();

$business_sql = 'SELECT id, name, city FROM businesses';
$business_params = [];
if ($selected_city !== '') {
    $business_sql .= ' WHERE city = ?';
    $business_params[] = $selected_city;
}
$business_sql .= ' ORDER BY city, name';
$stmt = $pdo->prepare($business_sql);
$stmt->execute($business_params);
$business_options = $stmt->fetchAll();

$checklist_sql = 'SELECT oc.*, b.name AS business_name, u.name AS completed_by_name
    FROM onboarding_checklists oc
    LEFT JOIN businesses b ON b.id = oc.business_id
    LEFT JOIN users u ON u.id = oc.completed_by_user_id';
$checklist_params = [];
if ($selected_city !== '') {
    $checklist_sql .= ' WHERE oc.city = ?';
    $checklist_params[] = $selected_city;
}
$checklist_sql .= ' ORDER BY FIELD(oc.status, "blocked", "in_progress", "not_started", "done"), oc.city, oc.due_date IS NULL, oc.due_date, oc.title';
$stmt = $pdo->prepare($checklist_sql);
$stmt->execute($checklist_params);
$checklist_rows = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT oc.city,
        COUNT(*) AS total_items,
        SUM(oc.status = "done") AS done_items,
        SUM(oc.status = "blocked") AS blocked_items
    FROM onboarding_checklists oc
    ' . ($selected_city !== '' ? 'WHERE oc.city = ?' : '') . '
    GROUP BY oc.city');
$stmt->execute($selected_city !== '' ? [$selected_city] : []);
$onboarding_by_city = [];
foreach ($stmt->fetchAll() as $row) {
    $onboarding_by_city[$row['city']] = $row;
}

$cohort_sql = 'SELECT city_rollup.city,
        city_rollup.business_count,
        IFNULL(partner_rollup.partnership_count, 0) AS partnership_count,
        IFNULL(partner_rollup.active_partnerships, 0) AS active_partnerships,
        IFNULL(referral_rollup.referral_count, 0) AS referral_count,
        IFNULL(referral_rollup.used_referrals, 0) AS used_referrals,
        IFNULL(referral_rollup.commission_total, 0) AS commission_total
    FROM (
        SELECT city, COUNT(*) AS business_count
        FROM businesses
        GROUP BY city
    ) city_rollup
    LEFT JOIN (
        SELECT b.city,
            COUNT(p.id) AS partnership_count,
            SUM(p.status = "active") AS active_partnerships
        FROM businesses b
        LEFT JOIN partnerships p ON p.business_id = b.id
        GROUP BY b.city
    ) partner_rollup ON partner_rollup.city = city_rollup.city
    LEFT JOIN (
        SELECT b.city,
            COUNT(r.id) AS referral_count,
            SUM(r.status = "used") AS used_referrals,
            IFNULL(SUM(c.amount),0) AS commission_total
        FROM businesses b
        LEFT JOIN referrals r ON r.source_business_id = b.id
        LEFT JOIN commissions c ON c.referral_id = r.id
        GROUP BY b.city
    ) referral_rollup ON referral_rollup.city = city_rollup.city';
$cohort_params = [];
if ($selected_city !== '') {
    $cohort_sql .= ' WHERE city_rollup.city = ?';
    $cohort_params[] = $selected_city;
}
$cohort_sql .= ' ORDER BY referral_count DESC, business_count DESC, city_rollup.city';
$stmt = $pdo->prepare($cohort_sql);
$stmt->execute($cohort_params);
$cohort_rows = $stmt->fetchAll();

$feedback_sql = 'SELECT pf.*, u.name AS created_by_name
    FROM pilot_feedback pf
    LEFT JOIN users u ON u.id = pf.created_by_user_id
    WHERE pf.context = ?';
$feedback_params = ['regional_launch'];
if ($selected_city !== '') {
    $feedback_sql .= ' AND pf.city = ?';
    $feedback_params[] = $selected_city;
}
$feedback_sql .= ' ORDER BY pf.created_at DESC LIMIT 8';
$stmt = $pdo->prepare($feedback_sql);
$stmt->execute($feedback_params);
$feedback_rows = $stmt->fetchAll();

$all_cities = city_options();
foreach ($city_rows as $row) {
    $all_cities[$row['city']] = $row['city'];
}

$deployment_statuses = [];
foreach ($all_cities as $city_value => $city_label_val) {
    $deployment_statuses[$city_value] = city_deployment_status($pdo, $city_value);
}
$next_steps_by_city = [];
if ($selected_city !== '') {
    $next_steps_by_city[$selected_city] = suggest_next_onboarding_steps($pdo, $selected_city);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Regional scaling - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
<?php echo render_breadcrumbs(['Management' => false, 'Regional scaling' => false]); ?>
<?php echo render_page_header(
    'Regional scaling',
    'Prepare city launches with partner density, onboarding templates, and manual rollout readiness.'
); ?>
<form class="d-flex flex-wrap gap-2 mb-4" method="get">
    <select name="city" class="form-select" style="max-width:220px">
        <option value="">All cities</option>
        <?php foreach ($all_cities as $value => $label): ?>
            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $selected_city === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter city</button>
</form>
    <div class="row gy-3">
        <div class="col-12">
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
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">City readiness</h3>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>City</th>
                                    <th>Businesses</th>
                                    <th>Category mix</th>
                                    <th>Partnerships</th>
                                    <th>Onboarding</th>
                                    <th>Readiness</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($city_rows): ?>
                                    <?php foreach ($city_rows as $row): ?>
                                        <?php
                                            $partnerships = $partnership_by_city[$row['city']] ?? ['active_partnerships' => 0, 'pending_partnerships' => 0];
                                            $onboarding = $onboarding_by_city[$row['city']] ?? ['total_items' => 0, 'done_items' => 0, 'blocked_items' => 0];
                                            $active_partnerships = (int)($partnerships['active_partnerships'] ?? 0);
                                            $onboarding_total = max(1, (int)($onboarding['total_items'] ?? 0));
                                            $onboarding_done = (int)($onboarding['done_items'] ?? 0);
                                            $onboarding_percent = (int)round(($onboarding_done / $onboarding_total) * 100);
                                            $ready = (int)$row['business_count'] >= 3 && $active_partnerships >= 1 && $onboarding_percent >= 60;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars(city_label($row['city'])); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['business_count']); ?> total · <?php echo htmlspecialchars((int)$row['active_businesses']); ?> active</td>
                                            <td class="small text-muted">
                                                Hotels <?php echo htmlspecialchars((int)$row['hotel_count']); ?> ·
                                                Tourism <?php echo htmlspecialchars((int)$row['tourism_count']); ?> ·
                                                Transport <?php echo htmlspecialchars((int)$row['transport_count']); ?> ·
                                                Restaurants <?php echo htmlspecialchars((int)$row['restaurant_count']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($active_partnerships); ?> active · <?php echo htmlspecialchars((int)($partnerships['pending_partnerships'] ?? 0)); ?> pending</td>
                                            <td><?php echo htmlspecialchars($onboarding_percent); ?>% done · <?php echo htmlspecialchars((int)($onboarding['blocked_items'] ?? 0)); ?> blocked</td>
                                            <td>
                                                <span class="badge bg-<?php echo $ready ? 'success' : 'warning'; ?>"><?php echo $ready ? 'Pilot ready' : 'Needs onboarding'; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted">No city data yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">City cohort comparison</h3>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>City</th>
                                    <th>Businesses</th>
                                    <th>Partnerships</th>
                                    <th>Referrals</th>
                                    <th>Conversion</th>
                                    <th>Commission signal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($cohort_rows): ?>
                                    <?php foreach ($cohort_rows as $row): ?>
                                        <?php
                                            $referral_count = (int)$row['referral_count'];
                                            $conversion = $referral_count > 0 ? ((int)$row['used_referrals'] / $referral_count) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars(city_label($row['city'])); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['business_count']); ?></td>
                                            <td><?php echo htmlspecialchars($row['active_partnerships']); ?> active / <?php echo htmlspecialchars($row['partnership_count']); ?> total</td>
                                            <td><?php echo htmlspecialchars($referral_count); ?></td>
                                            <td><?php echo htmlspecialchars(number_format($conversion, 1)); ?>%</td>
                                            <td>RWF <?php echo format_money($row['commission_total']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted">No cohort data yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted mb-0">Read-only pilot comparison. Use this to decide where onboarding support is needed before automating expansion.</p>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Plug-and-play deployment readiness</h3>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>City</th>
                                    <th>Score</th>
                                    <th>Stage</th>
                                    <th>Businesses</th>
                                    <th>Active partners</th>
                                    <th>Checklist</th>
                                    <th>Next step</th>
                                    <th>Deploy</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deployment_statuses as $city_key => $status): ?>
                                    <?php
                                        $stage_colors = [
                                            'not_ready' => 'secondary',
                                            'setup' => 'info',
                                            'onboarding' => 'warning',
                                            'pilot_ready' => 'success',
                                        ];
                                        $stage_labels = [
                                            'not_ready' => 'Not ready',
                                            'setup' => 'Setup',
                                            'onboarding' => 'Onboarding',
                                            'pilot_ready' => 'Pilot ready',
                                        ];
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars(city_label($city_key)); ?></strong></td>
                                        <td>
                                            <div class="progress" style="min-width:80px">
                                                <div class="progress-bar bg-<?php echo $status['score'] >= 75 ? 'success' : ($status['score'] >= 50 ? 'warning' : 'danger'); ?>" style="width:<?php echo $status['score']; ?>%"><?php echo $status['score']; ?>%</div>
                                            </div>
                                        </td>
                                        <td><span class="badge bg-<?php echo $stage_colors[$status['stage']] ?? 'secondary'; ?>"><?php echo $stage_labels[$status['stage']] ?? $status['stage']; ?></span></td>
                                        <td><?php echo $status['active_businesses']; ?> active / <?php echo $status['business_count']; ?> total</td>
                                        <td><?php echo $status['active_partnerships']; ?></td>
                                        <td><?php echo $status['checklist_percent']; ?>% done<?php if ($status['checklist_blocked'] > 0): ?> · <?php echo $status['checklist_blocked']; ?> blocked<?php endif; ?></td>
                                        <td class="small text-muted"><?php echo htmlspecialchars($status['next_action']); ?></td>
                                        <td>
                                            <form method="post" class="m-0">
                            <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="deploy_checklist">
                                                <input type="hidden" name="city" value="<?php echo htmlspecialchars($city_key); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary" onclick="return confirm('Seed checklist items for <?php echo htmlspecialchars($city_key); ?>?')">Deploy</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted mb-0">Plug-and-play panel. Deploy seeds the onboarding checklist for a city. Automations unlock at 75% readiness score.</p>
                </div>
            </div>
        </div>

        <?php if ($selected_city !== '' && !empty($next_steps_by_city[$selected_city])): ?>
        <div class="col-12">
            <div class="card shadow-sm border-primary">
                <div class="card-body">
                    <h3 class="h6">Suggested onboarding steps for <?php echo htmlspecialchars(city_label($selected_city)); ?></h3>
                    <div class="list-group list-group-flush">
                        <?php foreach ($next_steps_by_city[$selected_city] as $step): ?>
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="badge bg-primary me-2">Step <?php echo $step['priority']; ?></span>
                                    <?php echo htmlspecialchars($step['action']); ?>
                                </div>
                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($step['widget']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-12 col-xl-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Add contract template</h3>
                    <form method="post" class="row gy-3">
                            <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="template">
                        <div class="col-12 col-md-6">
                            <label class="form-label">City</label>
                            <select name="city" class="form-select">
                                <?php foreach (city_options() as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($selected_city ?: 'Kigali') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Business type</label>
                            <select name="business_type" class="form-select">
                                <?php foreach (business_type_options() as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Default commission %</label>
                            <input type="number" step="0.1" min="0.1" max="100" name="default_commission_rate" class="form-control" value="10">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active">Active</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Template text</label>
                            <textarea name="template_text" class="form-control" rows="4" required></textarea>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary w-100">Save template</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Onboarding checklist</h3>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>City</th>
                                    <th>Business</th>
                                    <th>Due</th>
                                    <th>Status</th>
                                    <th>Update</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($checklist_rows): ?>
                                    <?php foreach ($checklist_rows as $item): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                                                <?php if ($item['note']): ?>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($item['note']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(city_label($item['city'])); ?></td>
                                            <td><?php echo htmlspecialchars($item['business_name'] ?: 'City-level'); ?></td>
                                            <td><?php echo htmlspecialchars($item['due_date'] ?: 'No date'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $item['status'] === 'done' ? 'success' : ($item['status'] === 'blocked' ? 'danger' : ($item['status'] === 'in_progress' ? 'warning' : 'secondary')); ?>">
                                                    <?php echo htmlspecialchars(onboarding_status_label($item['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <form method="post" class="d-flex flex-column gap-2">
                            <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="onboarding_update">
                                                    <input type="hidden" name="item_id" value="<?php echo intval($item['id']); ?>">
                                                    <select name="status" class="form-select form-select-sm">
                                                        <?php foreach (onboarding_status_options() as $status => $label): ?>
                                                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $item['status'] === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="text" name="note" class="form-control form-control-sm" placeholder="Note" value="<?php echo htmlspecialchars($item['note'] ?? ''); ?>">
                                                    <button class="btn btn-sm btn-outline-primary">Save</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted">No onboarding checklist items yet.</td></tr>
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
                    <h3 class="h6">Add onboarding item</h3>
                    <form method="post" class="row gy-3">
                            <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="onboarding_add">
                        <div class="col-12 col-md-6">
                            <label class="form-label">City</label>
                            <select name="city" class="form-select">
                                <?php foreach (city_options() as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($selected_city ?: 'Kigali') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Business</label>
                            <select name="business_id" class="form-select">
                                <option value="">City-level</option>
                                <?php foreach ($business_options as $business): ?>
                                    <option value="<?php echo intval($business['id']); ?>"><?php echo htmlspecialchars($business['name']); ?> · <?php echo htmlspecialchars(city_label($business['city'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Checklist item</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Due date</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Note</label>
                            <textarea name="note" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary w-100">Add item</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Regional launch feedback</h3>
                    <form method="post" class="row gy-3">
                            <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="feedback">
                        <div class="col-12 col-md-6">
                            <label class="form-label">City</label>
                            <select name="city" class="form-select">
                                <?php foreach (city_options() as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($selected_city ?: 'Kigali') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Rating</label>
                            <input type="number" name="rating" min="1" max="5" class="form-control" value="3">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Sentiment</label>
                            <select name="sentiment" class="form-select">
                                <?php foreach (feedback_sentiment_options() as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
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
                    <h3 class="h6">Latest regional feedback</h3>
                    <?php if ($feedback_rows): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($feedback_rows as $feedback): ?>
                                <div class="list-group-item px-0">
                                    <div class="d-flex justify-content-between gap-2">
                                        <strong><?php echo htmlspecialchars(city_label($feedback['city'])); ?></strong>
                                        <span class="badge bg-<?php echo $feedback['sentiment'] === 'positive' ? 'success' : ($feedback['sentiment'] === 'negative' ? 'danger' : ($feedback['sentiment'] === 'follow_up' ? 'warning' : 'secondary')); ?>">
                                            <?php echo htmlspecialchars(feedback_sentiment_label($feedback['sentiment'])); ?>
                                        </span>
                                    </div>
                                    <div class="small text-muted">Rating <?php echo htmlspecialchars($feedback['rating']); ?>/5 · <?php echo htmlspecialchars($feedback['created_by_name'] ?: 'Manager'); ?> · <?php echo htmlspecialchars(date('Y-m-d', strtotime($feedback['created_at']))); ?></div>
                                    <div><?php echo htmlspecialchars($feedback['note']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No regional feedback captured yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Contract templates</h3>
                    <div class="table-responsive">
                        <table class="table table-modern table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>City</th>
                                    <th>Template</th>
                                    <th>Type</th>
                                    <th>Rate</th>
                                    <th>Status</th>
                                    <th>Text</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($templates): ?>
                                    <?php foreach ($templates as $template): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(city_label($template['city'])); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($template['title']); ?></strong>
                                                <?php if ($template['created_by_name']): ?>
                                                    <div class="small text-muted">By <?php echo htmlspecialchars($template['created_by_name']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(business_type_label($template['business_type'])); ?></td>
                                            <td><?php echo htmlspecialchars($template['default_commission_rate']); ?>%</td>
                                            <td><span class="badge bg-<?php echo $template['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($template['status'])); ?></span></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-secondary" onclick="this.nextElementSibling.classList.toggle('d-none'); this.classList.toggle('d-none')">Show text</button>
                                                <div class="small text-muted d-none" style="max-width:300px"><?php echo htmlspecialchars($template['template_text']); ?></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted">No templates yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>
