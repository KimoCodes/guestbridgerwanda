<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'partners.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$errors = [];
$flash = flash_get();
$search_query = trim($_GET['q'] ?? '');
$city_filter = trim($_GET['city'] ?? '');
$featured_month = trim($_GET['featured_month'] ?? date('Y-m'));
if ($featured_month !== '' && !preg_match('/^\d{4}-\d{2}$/', $featured_month)) {
    $errors[] = 'Invalid featured listing month.';
    $featured_month = date('Y-m');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('partners.php');
    $action = $_POST['action'] ?? 'add_partner';

    if ($action === 'featured_listing') {
        require_manager();
        $featured_business_id = intval($_POST['business_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'active');
        $note = trim($_POST['note'] ?? '');

        if ($featured_business_id <= 0 || $featured_business_id === (int)$business_id) {
            $errors[] = 'Choose a partner business to feature.';
        }
        if (!array_key_exists($status, featured_listing_status_options())) {
            $errors[] = 'Choose a valid featured listing status.';
            $status = 'paused';
        }
        if (strlen($note) > 1000) {
            $errors[] = 'Featured listing note must stay under 1,000 characters.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM businesses WHERE id = ? AND status = ?');
            $stmt->execute([$featured_business_id, 'active']);
            if ((int)$stmt->fetchColumn() === 0) {
                $errors[] = 'Business not found or inactive.';
            } elseif (!tenant_partner_linked($pdo, $business_id, $featured_business_id)) {
                $errors[] = 'You can only feature businesses linked to your network.';
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('INSERT INTO featured_listings (business_id, featured_month, status, note, created_by_user_id)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    note = VALUES(note),
                    created_by_user_id = VALUES(created_by_user_id)');
            $stmt->execute([$featured_business_id, $featured_month, $status, $note !== '' ? $note : null, $user['id']]);
            flash_set('Featured listing simulation saved.');
            header('Location: partners.php?featured_month=' . urlencode($featured_month) . ($search_query !== '' ? '&q=' . urlencode($search_query) : ''));
            exit;
        }
    }

    if ($action === 'add_partner') {
	    $name = trim($_POST['name'] ?? '');
	    $email = trim($_POST['email'] ?? '');
	    $business_type = $_POST['business_type'] ?? 'hotel';
	    $city = trim($_POST['city'] ?? 'Kigali');
	    $phone = trim($_POST['phone'] ?? '');
	    $address = trim($_POST['address'] ?? '');

    if ($name === '') {
        $errors[] = 'Business name is required.';
    }
	    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
	        $errors[] = 'A valid email address is required.';
	    }
	    if (!array_key_exists($business_type, business_type_options())) {
	        $errors[] = 'Choose a valid business type.';
	    }
	    if ($city === '') {
	        $errors[] = 'Choose a city.';
	    }

	    if (empty($errors)) {
	        try {
	            $stmt = $pdo->prepare('INSERT INTO businesses (name, email, business_type, phone, address, city) VALUES (?, ?, ?, ?, ?, ?)');
	            $stmt->execute([$name, $email, $business_type, $phone, $address, $city]);
	            $flash = 'Partner business added successfully. Reputation will update automatically as this partner uses the platform.';
        } catch (PDOException $e) {
            $errors[] = 'Unable to add partner business. Email may already exist.';
        }
    }
    }
}

$query = 'SELECT b.id, b.name, b.email, b.business_type, b.city, b.phone, b.address, b.reliability_score, b.payout_compliance_score, b.guest_satisfaction_score, b.created_at,
        fl.status AS featured_status, fl.note AS featured_note,
        p.status AS partnership_status, p.commission_rate AS partnership_rate
    FROM businesses b
    LEFT JOIN featured_listings fl ON fl.business_id = b.id AND fl.featured_month = ?
    LEFT JOIN partnerships p ON (p.business_id = ? AND p.partner_business_id = b.id) OR (p.business_id = b.id AND p.partner_business_id = ?)
    WHERE b.id != ?';
$params = [$featured_month, $business_id, $business_id, $business_id];
if ($search_query) {
    $query .= ' AND (b.name LIKE ? OR b.email LIKE ? OR b.phone LIKE ? OR b.address LIKE ?)';
    $like = '%' . $search_query . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($city_filter !== '') {
    $query .= ' AND b.city = ?';
    $params[] = $city_filter;
}
$query .= ' ORDER BY (fl.status = "active") DESC, b.name';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$partners = $stmt->fetchAll();

$featured_stmt = $pdo->prepare('SELECT fl.*, b.name AS business_name, b.business_type, u.name AS created_by_name
    FROM featured_listings fl
    JOIN businesses b ON b.id = fl.business_id
    LEFT JOIN users u ON u.id = fl.created_by_user_id
    WHERE fl.featured_month = ?
    ORDER BY (fl.status = "active") DESC, b.name');
$featured_stmt->execute([$featured_month]);
$featured_rows = $featured_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Directory - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Network' => 'partners.php']); ?>
            <?php echo render_page_header(
                'Partner Directory',
                'Browse and manage all registered partner businesses. Search by name, city, or business type.',
                []
            ); ?>
            <?php if ($flash): ?>
                <div class="alert alert-success mb-3"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-3"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <div class="row gy-4">
                <div class="col-12 col-lg-5">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Add Partner</h3>
                            <p class="text-muted small mb-3">Register a business manually so it appears in your partner list.</p>
                            <form method="post">
                            <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="add_partner">
                                <div class="mb-3">
                                    <label class="form-label">Business Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Business Type</label>
                                    <select name="business_type" class="form-select" required>
                                        <?php foreach (business_type_options() as $bv => $bl): ?>
                                            <option value="<?php echo htmlspecialchars($bv); ?>" <?php echo ($_POST['business_type'] ?? 'hotel') === $bv ? 'selected' : ''; ?>><?php echo htmlspecialchars($bl); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">City</label>
                                    <select name="city" class="form-select" required>
                                        <?php foreach (city_options() as $cv => $cl): ?>
                                            <option value="<?php echo htmlspecialchars($cv); ?>" <?php echo ($_POST['city'] ?? 'Kigali') === $cv ? 'selected' : ''; ?>><?php echo htmlspecialchars($cl); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Address</label>
                                    <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">
                                </div>
                                <p class="form-text mb-3">Reputation is calculated automatically from referral acceptance, settlement compliance, and platform activity.</p>
                                <button class="btn btn-primary">Add Partner</button>
                            </form>
                        </div>
                    </div>
                    <?php if ($is_manager): ?>
                    <div class="card mt-3">
                        <div class="card-body">
                            <h3 class="text-section-heading">Featured Listings</h3>
                            <p class="text-muted small mb-3">Simulate featured placement before charging.</p>
                            <form method="get" class="mb-3">
                                <label class="form-label">Simulation Month</label>
                                <div class="d-flex gap-2">
                                    <input type="month" name="featured_month" class="form-control" value="<?php echo htmlspecialchars($featured_month); ?>">
                                    <button class="btn btn-outline-secondary">View</button>
                                </div>
                            </form>
                            <form method="post">
                            <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="featured_listing">
                                <div class="mb-3">
                                    <label class="form-label">Business</label>
                                    <select name="business_id" class="form-select" required>
                                        <option value="">Choose business</option>
                                        <?php foreach ($partners as $popt): ?>
                                            <option value="<?php echo intval($popt['id']); ?>"><?php echo htmlspecialchars($popt['name']); ?> (<?php echo htmlspecialchars(business_type_label($popt['business_type'])); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <?php foreach (featured_listing_status_options() as $sv => $sl): ?>
                                            <option value="<?php echo htmlspecialchars($sv); ?>"><?php echo htmlspecialchars($sl); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Placement Note</label>
                                    <textarea name="note" class="form-control" rows="2" placeholder="Pilot reason or audience notes."></textarea>
                                </div>
                                <button class="btn btn-primary">Save Simulation</button>
                            </form>
                            <?php if ($featured_rows): ?>
                                <hr>
                                <?php foreach ($featured_rows as $fr): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex justify-content-between">
                                            <strong><?php echo htmlspecialchars($fr['business_name']); ?></strong>
                                            <span class="badge bg-<?php echo $fr['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars(featured_listing_status_label($fr['status'])); ?></span>
                                        </div>
                                        <div class="small text-muted"><?php echo htmlspecialchars(business_type_label($fr['business_type'])); ?> · <?php echo htmlspecialchars($fr['featured_month']); ?></div>
                                        <?php if ($fr['note']): ?>
                                            <div class="small"><?php echo htmlspecialchars($fr['note']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-lg-7">
                    <div class="card">
                        <div class="card-body">
                            <form method="get" class="filter-bar">
                                <div class="col-auto flex-grow-1">
                                    <label class="form-label">Search</label>
                                    <input type="text" name="q" class="form-control" placeholder="Search by name, email, phone, or address" value="<?php echo htmlspecialchars($search_query); ?>">
                                </div>
                                <div class="col-auto">
                                    <label class="form-label">City</label>
                                    <select name="city" class="form-select">
                                        <option value="">All cities</option>
                                        <?php foreach (city_options() as $cv => $cl): ?>
                                            <option value="<?php echo htmlspecialchars($cv); ?>" <?php echo $city_filter === $cv ? 'selected' : ''; ?>><?php echo htmlspecialchars($cl); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-auto d-grid">
                                    <label class="form-label">&nbsp;</label>
                                    <button class="btn btn-primary">Search</button>
                                </div>
                            </form>
                            <?php if ($partners): ?>
                                <div class="table-responsive">
                                    <table class="table table-modern table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Business</th>
                                                <th>Contact</th>
                                                <th>Reputation</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($partners as $partner): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($partner['name']); ?></strong>
                                                        <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars(business_type_label($partner['business_type'])); ?></span>
                                                        <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars(city_label($partner['city'])); ?></span>
                                                        <?php if ($partner['partnership_status'] === 'active'): ?>
                                                            <span class="badge bg-success ms-1">Active Partner</span>
                                                        <?php elseif ($partner['partnership_status'] === 'pending'): ?>
                                                            <span class="badge bg-warning text-dark ms-1">Pending</span>
                                                        <?php elseif ($partner['partnership_status'] === 'rejected'): ?>
                                                            <span class="badge bg-danger ms-1">Rejected</span>
                                                        <?php elseif ($partner['partnership_status'] === 'paused'): ?>
                                                            <span class="badge bg-secondary ms-1">Paused</span>
                                                        <?php endif; ?>
                                                        <?php if ($partner['featured_status'] === 'active'): ?>
                                                            <span class="badge bg-success ms-1">Featured</span>
                                                        <?php elseif ($partner['featured_status'] === 'paused'): ?>
                                                            <span class="badge bg-secondary ms-1">Paused</span>
                                                        <?php endif; ?>
                                                        <?php if ($partner['featured_note']): ?>
                                                            <div class="small text-muted"><?php echo htmlspecialchars($partner['featured_note']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($partner['email'] ?: $partner['phone'] ?: '—'); ?>
                                                        <div class="small text-muted"><?php echo htmlspecialchars($partner['address'] ?: ''); ?></div>
                                                        <div class="small text-muted">Added <?php echo htmlspecialchars(date('Y-m-d', strtotime($partner['created_at']))); ?></div>
                                                    </td>
                                                    <td>
                                                        <?php $avg = reputation_average($partner); $rm = business_reputation_metrics($pdo, (int)$partner['id']); ?>
                                                        <span class="badge bg-primary"><?php echo htmlspecialchars(format_score($avg)); ?>/100</span>
                                                        <?php if ($partner['partnership_status'] === 'active' && $partner['partnership_rate']): ?>
                                                            <div class="small text-success mt-1">Rate: <?php echo htmlspecialchars($partner['partnership_rate']); ?>%</div>
                                                        <?php endif; ?>
                                                        <div class="small text-muted mt-1">Rel <?php echo htmlspecialchars(format_score($partner['reliability_score'])); ?> · Payout <?php echo htmlspecialchars(format_score($partner['payout_compliance_score'])); ?> · Guest <?php echo htmlspecialchars(format_score($partner['guest_satisfaction_score'])); ?></div>
                                                        <div class="small text-muted">Used <?php echo htmlspecialchars($rm['used_referrals']); ?>/<?php echo htmlspecialchars($rm['incoming_referrals']); ?> · Paid <?php echo htmlspecialchars($rm['paid_commissions']); ?>/<?php echo htmlspecialchars($rm['payable_commissions']); ?></div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-state-title">No partners found</div>
                                    <div class="empty-state-desc">Try adjusting your search, or add a new partner manually.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<?php echo render_app_shell_end(); ?>
