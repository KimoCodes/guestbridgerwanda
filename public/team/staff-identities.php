<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Services/ServiceContainer.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = tenant_business_id($user);
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'staff-identities.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

if (!$is_manager) {
    flash_set('Only managers can manage staff referral identities.');
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$services = ServiceContainer::getInstance($pdo);
$identityService = $services->staffIdentities();

$errors = [];
$flash = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('staff-identities.php');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $staffId = intval($_POST['staff_id'] ?? 0);
        if ($staffId <= 0) {
            $errors[] = 'Please select a staff member.';
        } else {
            try {
                $identityService->createIdentity($staffId, $business_id);
                $flash = 'Staff referral identity created successfully.';
                log_audit($pdo, $business_id, (int) $user['id'], 'staff_identity_created', 'staff_referral_identity', $staffId);
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($action === 'deactivate') {
        $id = intval($_POST['identity_id'] ?? 0);
        if ($identityService->deactivateIdentity($id, $business_id)) {
            $flash = 'Staff identity deactivated.';
            log_audit($pdo, $business_id, (int) $user['id'], 'staff_identity_deactivated', 'staff_referral_identity', $id);
        } else {
            $errors[] = 'Could not deactivate identity.';
        }
    } elseif ($action === 'reactivate') {
        $id = intval($_POST['identity_id'] ?? 0);
        try {
            if ($identityService->reactivateIdentity($id, $business_id)) {
                $flash = 'Staff identity reactivated.';
                log_audit($pdo, $business_id, (int) $user['id'], 'staff_identity_reactivated', 'staff_referral_identity', $id);
            } else {
                $errors[] = 'Could not reactivate identity.';
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// Get identities
$active_identities = $identityService->getBusinessIdentities($business_id, 'active');
$inactive_identities = $identityService->getBusinessIdentities($business_id, 'inactive');

// Get staff without active identities (for creation form)
$stmt = $pdo->prepare('SELECT s.id, s.name, s.role FROM staff s 
    WHERE s.business_id = ? 
    AND s.id NOT IN (SELECT staff_id FROM staff_referral_identities WHERE business_id = ? AND status = \'active\')
    ORDER BY s.name');
$stmt->execute([$business_id, $business_id]);
$available_staff = $stmt->fetchAll();

require_once __DIR__ . '/../../app/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Referral Identities - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
    <style>
        .identity-card { border-left: 4px solid var(--bs-primary); }
        .identity-card.inactive { border-left-color: var(--bs-secondary); opacity: 0.7; }
        .identity-code { font-family: 'Courier New', monospace; font-size: 1.3rem; font-weight: bold; letter-spacing: 2px; }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 1rem; }
        .stat-item { text-align: center; }
        .stat-value { font-size: 1.5rem; font-weight: bold; }
        .stat-label { font-size: 0.8rem; color: var(--bs-secondary); }
        .qr-container { width: 120px; height: 120px; }
    </style>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
    <?php echo render_breadcrumbs(['Team' => 'staff.php', 'Referral Identities' => false]); ?>
    <?php echo render_page_header(
        'Staff Referral Identities',
        'Manage permanent referral identities for your staff members.',
        []
    ); ?>

    <?php if ($flash): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">Create Staff Identity</h5>
                    <p class="text-muted">Assign a permanent referral identity to a staff member.</p>
                    <?php if (empty($available_staff)): ?>
                        <p class="text-muted mb-0">All staff members already have active referral identities.</p>
                    <?php else: ?>
                        <form method="post" class="row g-3 align-items-end">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="create">
                            <div class="col-md-6">
                                <label class="form-label">Staff Member</label>
                                <select name="staff_id" class="form-select" required>
                                    <option value="">Select staff member...</option>
                                    <?php foreach ($available_staff as $s): ?>
                                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?> (<?php echo htmlspecialchars(ucfirst($s['role'])); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-primary">Create Identity</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12">
            <h5 class="mb-3">Active Identities (<?php echo count($active_identities); ?>)</h5>
            <?php if (empty($active_identities)): ?>
                <div class="alert alert-info">No active staff identities yet. Create one above.</div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($active_identities as $identity): ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="card identity-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($identity['staff_name']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars(ucfirst($identity['staff_role'])); ?></small>
                                        </div>
                                        <span class="badge bg-success">Active</span>
                                    </div>
                                    <div class="identity-code text-primary my-3"><?php echo htmlspecialchars($identity['public_identity_code']); ?></div>
                                    <div class="stat-grid mb-3">
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo (int) $identity['total_referrals']; ?></div>
                                            <div class="stat-label">Referrals</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo (int) $identity['successful_referrals']; ?></div>
                                            <div class="stat-label">Successful</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value">RWF <?php echo number_format($identity['total_revenue'] / 1000, 0); ?>K</div>
                                            <div class="stat-label">Revenue</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value">RWF <?php echo number_format($identity['total_commission'] / 1000, 0); ?>K</div>
                                            <div class="stat-label">Commission</div>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <div id="qr-<?php echo $identity['id']; ?>" class="qr-container"></div>
                                        <div class="flex-grow-1">
                                            <form method="post" class="mt-2">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="deactivate">
                                                <input type="hidden" name="identity_id" value="<?php echo $identity['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this identity?')">Deactivate</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($inactive_identities)): ?>
        <div class="col-12">
            <h5 class="mb-3">Inactive Identities (<?php echo count($inactive_identities); ?>)</h5>
            <div class="row g-3">
                <?php foreach ($inactive_identities as $identity): ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card identity-card inactive h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-0"><?php echo htmlspecialchars($identity['staff_name']); ?></h6>
                                        <small class="text-muted"><?php echo htmlspecialchars(ucfirst($identity['staff_role'])); ?></small>
                                    </div>
                                    <span class="badge bg-secondary">Inactive</span>
                                </div>
                                <div class="identity-code text-secondary my-3"><?php echo htmlspecialchars($identity['public_identity_code']); ?></div>
                                <div class="stat-grid mb-3">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo (int) $identity['total_referrals']; ?></div>
                                        <div class="stat-label">Referrals</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo (int) $identity['successful_referrals']; ?></div>
                                        <div class="stat-label">Successful</div>
                                    </div>
                                </div>
                                <form method="post">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="reactivate">
                                    <input type="hidden" name="identity_id" value="<?php echo $identity['id']; ?>">
                                    <button class="btn btn-sm btn-outline-success">Reactivate</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

<?php echo render_app_shell_end(); ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach (array_merge($active_identities, $inactive_identities) as $identity): ?>
    (function() {
        var container = document.getElementById('qr-<?php echo $identity["id"]; ?>');
        if (container) {
            new QRCode(container, {
                text: <?php echo json_encode(BASE_URL . "/referral/verify/" . $identity["public_identity_code"]); ?>,
                width: 120,
                height: 120
            });
        }
    })();
    <?php endforeach; ?>
});
</script>
</body>
</html>
