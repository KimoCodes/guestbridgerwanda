<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'partnerships.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$errors = [];
$flash = null;
$search_query = trim($_GET['q'] ?? '');
$type_filter = $_GET['type'] ?? '';
$city_filter = trim($_GET['city'] ?? '');
$featured_month = date('Y-m');
if ($type_filter !== '' && !array_key_exists($type_filter, business_type_options())) {
    $type_filter = '';
}

$business_stmt = $pdo->prepare('SELECT city FROM businesses WHERE id = ?');
$business_stmt->execute([$business_id]);
$business_city = city_label($business_stmt->fetchColumn());

$template_stmt = $pdo->prepare('SELECT id, city, business_type, title, default_commission_rate, template_text FROM contract_templates WHERE status = ? ORDER BY (city = ?) DESC, city, business_type, title');
$template_stmt->execute(['active', $business_city]);
$contract_templates = $template_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('partnerships.php');
    $action = $_POST['action'] ?? '';
    $partnership_id = intval($_POST['partnership_id'] ?? 0);

    if ($action === 'request') {
        $target_business_id = intval($_POST['target_business_id'] ?? 0);
        $commission_rate = floatval($_POST['commission_rate'] ?? 10);
        $template_id = intval($_POST['template_id'] ?? 0);
        $raw_agreement = trim($_POST['agreement_text'] ?? '');
        $agreement_text = $raw_agreement;

        if ($target_business_id <= 0) {
            $errors[] = 'Choose a business to request partnership with.';
        }
        if ($commission_rate <= 0) {
            $errors[] = 'Commission rate must be greater than zero.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM partnerships WHERE business_id = ? AND partner_business_id = ?');
            $stmt->execute([$business_id, $target_business_id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'A partnership request already exists with that business.';
            } else {
                if ($template_id > 0 && $raw_agreement === '') {
                    $stmt = $pdo->prepare('SELECT * FROM contract_templates WHERE id = ? AND status = "active"');
                    $stmt->execute([$template_id]);
                    $template = $stmt->fetch();
                    if ($template) {
                        $stmt_target = $pdo->prepare('SELECT name, city FROM businesses WHERE id = ?');
                        $stmt_target->execute([$target_business_id]);
                        $target = $stmt_target->fetch();
                        $agreement_text = merge_contract_template($template['template_text'], [
                            'partner_name' => $target['name'] ?? '',
                            'city' => $target['city'] ?? '',
                            'commission_rate' => $commission_rate,
                        ]);
                        if ($commission_rate <= 0) {
                            $commission_rate = (float)$template['default_commission_rate'];
                        }
                    }
                }
                $stmt = $pdo->prepare('INSERT INTO partnerships (business_id, partner_business_id, status, commission_rate, agreement_text) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$business_id, $target_business_id, 'pending', $commission_rate, $agreement_text]);
                $flash = 'Partnership request sent.';
            }
        }
    }

    if ($action === 'apply_template') {
        $template_id = intval($_POST['template_id'] ?? 0);
        $partnership_id = intval($_POST['partnership_id'] ?? 0);
        if ($template_id > 0 && $partnership_id > 0) {
            if (!tenant_fetch_partnership($pdo, $partnership_id, $business_id)) {
                $errors[] = 'Partnership not found for your business.';
            } else {
                apply_contract_template_to_partnership($pdo, $partnership_id, $template_id, $user['id']);
                $flash = 'Contract template applied to partnership.';
            }
        } else {
            $errors[] = 'Choose a partnership and a template.';
        }
    }

    if ($action === 'approve' || $action === 'reject') {
        $new_status = $action === 'approve' ? 'active' : 'rejected';
        $stmt = $pdo->prepare('SELECT * FROM partnerships WHERE id = ? AND partner_business_id = ? AND status = ?');
        $stmt->execute([$partnership_id, $business_id, 'pending']);
        $partner_request = $stmt->fetch();
        if (!$partner_request) {
            $errors[] = 'Partnership request not found.';
        } else {
            $stmt = $pdo->prepare('UPDATE partnerships SET status = ? WHERE id = ?');
            $stmt->execute([$new_status, $partnership_id]);
            $flash = $new_status === 'active' ? 'Partnership approved.' : 'Partnership rejected.';
        }
    }
}

$available_sql = 'SELECT b.id, b.name, b.email, b.business_type, b.city, b.phone, b.address, b.reliability_score, b.payout_compliance_score, b.guest_satisfaction_score, fl.status AS featured_status, fl.note AS featured_note FROM businesses b LEFT JOIN featured_listings fl ON fl.business_id = b.id AND fl.featured_month = ? WHERE b.id != ? AND b.status = ?';
$params = [$featured_month, $business_id, 'active'];
if ($search_query) {
    $available_sql .= ' AND (b.name LIKE ? OR b.email LIKE ? OR b.phone LIKE ? OR b.address LIKE ?)';
    $like_query = '%' . $search_query . '%';
    $params[] = $like_query;
    $params[] = $like_query;
    $params[] = $like_query;
    $params[] = $like_query;
}
$available_sql .= $type_filter ? ' AND business_type = ?' : '';
if ($type_filter) {
    $params[] = $type_filter;
}
if ($city_filter !== '') {
    $available_sql .= ' AND b.city = ?';
    $params[] = $city_filter;
}
$available_sql .= ' ORDER BY (fl.status = "active") DESC, b.name';
$stmt = $pdo->prepare($available_sql);
$stmt->execute($params);
$available_businesses = $stmt->fetchAll();

$featured_businesses = array_values(array_filter($available_businesses, function ($business) {
    return ($business['featured_status'] ?? '') === 'active';
}));

$stmt = $pdo->prepare('SELECT p.*, b.name AS partner_name, b.business_type, b.city, b.reliability_score, b.payout_compliance_score, b.guest_satisfaction_score FROM partnerships p JOIN businesses b ON b.id = CASE WHEN p.business_id = ? THEN p.partner_business_id ELSE p.business_id END WHERE (p.business_id = ? OR p.partner_business_id = ?) ORDER BY p.created_at DESC');
$stmt->execute([$business_id, $business_id, $business_id]);
$outgoing = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT p.*, b.name AS requester_name, b.business_type, b.city, b.reliability_score, b.payout_compliance_score, b.guest_satisfaction_score FROM partnerships p JOIN businesses b ON p.business_id = b.id WHERE p.partner_business_id = ? AND p.status = ? ORDER BY p.created_at DESC');
$stmt->execute([$business_id, 'pending']);
$incoming = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT p.*, b.name AS partner_name, b.business_type, b.city, b.reliability_score, b.payout_compliance_score, b.guest_satisfaction_score FROM partnerships p JOIN businesses b ON b.id = CASE WHEN p.business_id = ? THEN p.partner_business_id ELSE p.business_id END WHERE (p.business_id = ? OR p.partner_business_id = ?) AND p.status = ? ORDER BY p.created_at DESC');
$stmt->execute([$business_id, $business_id, $business_id, 'active']);
$active = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partnerships - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Network' => 'partnerships.php']); ?>
            <?php echo render_page_header(
                'Partnerships',
                'Manage your partnership network. Send requests, review approvals, and apply contract templates.',
                []
            ); ?>
            <?php if ($flash): ?>
                <div class="alert alert-success mb-3"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-3"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <?php if ($incoming): ?>
                <div class="alert-notice mb-3">
                    <div class="alert-notice-title"><?php echo count($incoming); ?> Incoming Request<?php echo count($incoming) === 1 ? '' : 's'; ?></div>
                    <div class="alert-notice-body">These businesses want to partner with you. Approve or reject below.</div>
                </div>
            <?php endif; ?>
            <div class="row gy-4">
                <div class="col-12 col-lg-5">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Request Partnership</h3>
                            <?php if ($featured_businesses): ?>
                                <div class="featured-discovery mb-3">
                                    <div class="small text-uppercase text-muted mb-2">Featured this month</div>
                                    <?php foreach (array_slice($featured_businesses, 0, 3) as $featured): ?>
                                        <div class="featured-discovery-item">
                                            <div>
                                                <strong><?php echo htmlspecialchars($featured['name']); ?></strong>
                                                <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars(business_type_label($featured['business_type'])); ?></span>
                                                <div class="small text-muted">Rep <?php echo htmlspecialchars(format_score(reputation_average($featured))); ?>/100</div>
                                                <?php if ($featured['featured_note']): ?>
                                                    <div class="small"><?php echo htmlspecialchars($featured['featured_note']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <span class="badge bg-success">Featured</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <form method="get" class="mb-3">
                                <div class="row g-2">
                                    <div class="col-12 col-md-7">
                                        <input type="text" name="q" class="form-control" placeholder="Search businesses" value="<?php echo htmlspecialchars($search_query); ?>">
                                    </div>
                                    <div class="col-12 col-md-5">
                                        <select name="type" class="form-select">
                                            <option value="">All types</option>
                                            <?php foreach (business_type_options() as $v => $l): ?>
                                                <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $type_filter === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <select name="city" class="form-select">
                                            <option value="">All cities</option>
                                            <?php foreach (city_options() as $v => $l): ?>
                                                <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $city_filter === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 d-grid">
                                        <button class="btn btn-outline-secondary" type="submit">Search</button>
                                    </div>
                                </div>
                            </form>
                            <form method="post">
                            <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="request">
                                <div class="mb-3">
                                    <label class="form-label">Partner Business</label>
                                    <select name="target_business_id" class="form-select" required>
                                        <option value="">Select a business</option>
                                        <?php foreach ($available_businesses as $biz): ?>
                                            <option value="<?php echo intval($biz['id']); ?>">
                                                <?php echo ($biz['featured_status'] ?? '') === 'active' ? '★ ' : ''; ?><?php echo htmlspecialchars($biz['name']); ?> (<?php echo htmlspecialchars(business_type_label($biz['business_type'])); ?>, <?php echo htmlspecialchars(city_label($biz['city'])); ?>) - rep <?php echo htmlspecialchars(format_score(reputation_average($biz))); ?>/100
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Commission Rate (%)</label>
                                    <input type="number" step="0.1" min="1" name="commission_rate" class="form-control" value="10" required>
                                </div>
                                <?php if ($contract_templates): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Contract Template</label>
                                        <select id="contract_template" class="form-select" onchange="previewTemplate(this)">
                                            <option value="">No template</option>
                                            <?php foreach ($contract_templates as $tpl): ?>
                                                <option value="<?php echo intval($tpl['id']); ?>"
                                                    data-rate="<?php echo htmlspecialchars($tpl['default_commission_rate']); ?>"
                                                    data-text="<?php echo htmlspecialchars($tpl['template_text']); ?>"
                                                    data-title="<?php echo htmlspecialchars($tpl['title']); ?>">
                                                    <?php echo htmlspecialchars($tpl['title']); ?> · <?php echo htmlspecialchars(city_label($tpl['city'])); ?> · <?php echo htmlspecialchars(business_type_label($tpl['business_type'])); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="template_id" id="template_id" value="">
                                    </div>
                                    <div id="template_preview" class="alert alert-info d-none">
                                        <strong id="template_preview_title"></strong>
                                        <div id="template_preview_text" class="small mt-1"></div>
                                    </div>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label class="form-label">Agreement Details</label>
                                    <textarea name="agreement_text" class="form-control" rows="3" placeholder="Auto-filled from selected template if blank."></textarea>
                                </div>
                                <button class="btn btn-primary">Send Request</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-7">
                    <?php if ($incoming): ?>
                    <div class="card mb-4" style="border-left:4px solid var(--color-warning);">
                        <div class="card-body">
                            <h3 class="text-section-heading">Incoming Requests <span class="badge bg-warning text-dark ms-2"><?php echo count($incoming); ?></span></h3>
                            <div class="list-group list-group-flush">
                                <?php foreach ($incoming as $req): ?>
                                    <form method="post" class="list-group-item">
                            <?php echo csrf_field(); ?>
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <strong><?php echo htmlspecialchars($req['requester_name']); ?></strong>
                                                <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars(business_type_label($req['business_type'])); ?></span>
                                                <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars(city_label($req['city'])); ?></span>
                                                <div class="small text-muted mt-1">Rate: <?php echo htmlspecialchars($req['commission_rate']); ?>% &middot; Rep <?php echo htmlspecialchars(format_score(reputation_average($req))); ?>/100</div>
                                                <?php if ($req['agreement_text']): ?>
                                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($req['agreement_text']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="btn-group" role="group">
                                                <input type="hidden" name="partnership_id" value="<?php echo intval($req['id']); ?>">
                                                <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve</button>
                                                <button type="submit" name="action" value="reject" class="btn btn-outline-danger btn-sm">Reject</button>
                                            </div>
                                        </div>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="card mb-4">
                        <div class="card-body">
                            <h3 class="text-section-heading">Active Partnerships</h3>
                            <?php if ($active): ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($active as $p): ?>
                                        <li class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($p['partner_name']); ?></strong>
                                                    <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars(business_type_label($p['business_type'])); ?></span>
                                                    <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars(city_label($p['city'])); ?></span>
                                                    <div class="small text-muted mt-1">Rate: <?php echo htmlspecialchars($p['commission_rate']); ?>% &middot; Rep <?php echo htmlspecialchars(format_score(reputation_average($p))); ?>/100</div>
                                                    <?php if ($p['agreement_text']): ?>
                                                        <div class="small text-muted"><?php echo htmlspecialchars($p['agreement_text']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-success">Active</span>
                                                    <?php if ($contract_templates): ?>
                                                        <form method="post" class="d-flex gap-1 mt-1">
                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="action" value="apply_template">
                                                            <input type="hidden" name="partnership_id" value="<?php echo intval($p['id']); ?>">
                                                            <select name="template_id" class="form-select form-select-sm" style="max-width:180px;">
                                                                <option value="">Apply template...</option>
                                                                <?php foreach ($contract_templates as $tpl): ?>
                                                                    <option value="<?php echo intval($tpl['id']); ?>"><?php echo htmlspecialchars($tpl['title']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted mb-0">No active partnerships yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Outgoing Requests</h3>
                            <?php if ($outgoing): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($outgoing as $r): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($r['partner_name']); ?></strong>
                                                    <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars(business_type_label($r['business_type'])); ?></span>
                                                    <div class="small text-muted mt-1">Rate: <?php echo htmlspecialchars($r['commission_rate']); ?>% &middot; Rep <?php echo htmlspecialchars(format_score(reputation_average($r))); ?>/100</div>
                                                </div>
                                                <span class="badge bg-<?php echo $r['status'] === 'pending' ? 'warning' : ($r['status'] === 'active' ? 'success' : 'secondary'); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No outgoing partnership requests.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>