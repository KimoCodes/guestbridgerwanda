<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Services/ServiceContainer.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = tenant_business_id($user);
$current_page = 'my-identity.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$services = ServiceContainer::getInstance($pdo);
$identityService = $services->staffIdentities();

// Get staff record for current user
$stmt = $pdo->prepare('SELECT id, name, role FROM staff WHERE business_id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$business_id, (int) $user['id']]);
$staff = $stmt->fetch();

$identity = null;
$performance = null;

if ($staff) {
    $identity = $identityService->getActiveIdentity((int) $staff['id'], $business_id);
    if ($identity) {
        $current_month = date('Y-m');
        $performance = $identityService->getEmployeePerformance((int) $identity['id'], $current_month);
    }
}

require_once __DIR__ . '/../../app/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Referral Identity - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
    <style>
        .identity-hero { text-align: center; padding: 2rem; }
        .identity-code { font-family: 'Courier New', monospace; font-size: 2rem; font-weight: bold; letter-spacing: 3px; color: var(--bs-primary); background: var(--bs-primary-bg-subtle); padding: 1rem 2rem; border-radius: 8px; display: inline-block; }
        .stat-card { text-align: center; padding: 1.5rem; }
        .stat-value { font-size: 2rem; font-weight: bold; }
        .stat-label { font-size: 0.85rem; color: var(--bs-secondary); }
        .qr-section { text-align: center; }
    </style>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
    <?php echo render_breadcrumbs(['Dashboard' => 'dashboard.php', 'My Referral Identity' => false]); ?>
    <?php echo render_page_header(
        'My Referral Identity',
        'Your permanent staff referral identity for GuestBridge.',
        [['href' => 'create_referral.php', 'label' => '+ Create Referral', 'style' => 'success']]
    ); ?>

    <?php if (!$staff): ?>
        <div class="alert alert-warning">You don't have a staff record yet. Please contact your manager.</div>
    <?php elseif (!$identity): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <h5>No Referral Identity</h5>
                <p class="text-muted">You don't have a staff referral identity yet. Ask your manager to create one for you.</p>
                <a href="create_referral.php" class="btn btn-primary">Create Referral Anyway</a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-12">
                <div class="card identity-hero">
                    <h5 class="mb-1"><?php echo htmlspecialchars($identity['staff_name']); ?></h5>
                    <p class="text-muted mb-3"><?php echo htmlspecialchars(ucfirst($identity['staff_role'])); ?></p>
                    <div class="identity-code" id="identity-code"><?php echo htmlspecialchars($identity['public_identity_code']); ?></div>
                    <div class="mt-3">
                        <button class="btn btn-outline-primary btn-sm" onclick="copyIdentity()">Copy Identity Code</button>
                        <span id="copy-feedback" class="text-success ms-2" style="display:none;">Copied!</span>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card stat-card">
                    <div class="stat-value text-primary"><?php echo (int) $performance['total_referrals'] ?? 0; ?></div>
                    <div class="stat-label">Referrals This Month</div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card stat-card">
                    <div class="stat-value text-success"><?php echo (int) $performance['converted_count'] ?? 0; ?></div>
                    <div class="stat-label">Converted</div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card stat-card">
                    <div class="stat-value text-info"><?php echo $performance['conversion_rate'] ?? 0; ?>%</div>
                    <div class="stat-label">Conversion Rate</div>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6>Guest Value Generated</h6>
                        <div class="fs-3 fw-bold">RWF <?php echo number_format($performance['total_guest_value'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6>Commission Earned</h6>
                        <div class="fs-3 fw-bold text-success">RWF <?php echo number_format($performance['total_commission'] ?? 0); ?></div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h6 class="mb-3">Your QR Code</h6>
                        <p class="text-muted">Share this QR code. Scanning it starts a referral process with your identity automatically attached.</p>
                        <div class="qr-section">
                            <div id="staff-qr" style="display:inline-block;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php echo render_app_shell_end(); ?>

<?php if ($identity): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new QRCode(document.getElementById('staff-qr'), {
        text: '<?php echo htmlspecialchars(BASE_URL . "/referral/verify/" . $identity['public_identity_code']); ?>',
        width: 200,
        height: 200
    });
});

function copyIdentity() {
    var code = document.getElementById('identity-code').textContent;
    navigator.clipboard.writeText(code).then(function() {
        var feedback = document.getElementById('copy-feedback');
        feedback.style.display = 'inline';
        setTimeout(function() { feedback.style.display = 'none'; }, 2000);
    });
}
</script>
<?php endif; ?>
</body>
</html>
