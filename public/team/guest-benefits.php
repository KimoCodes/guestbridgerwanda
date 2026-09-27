<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Services/ServiceContainer.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'guest_benefits.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

$errors = [];
$success = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('guest_benefits.php');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $partnership_id = intval($_POST['partnership_id'] ?? 0);
        $benefit_type = $_POST['benefit_type'] ?? 'percentage_discount';
        $benefit_value = trim($_POST['benefit_value'] ?? '');
        $benefit_description = trim($_POST['benefit_description'] ?? '');
        $min_spend = floatval($_POST['min_spend'] ?? 0);
        $valid_from = $_POST['valid_from'] ?? null;
        $valid_until = $_POST['valid_until'] ?? null;

        if ($partnership_id <= 0) {
            $errors[] = 'Please select a partnership.';
        }
        if ($benefit_value === '') {
            $errors[] = 'Benefit value is required.';
        }
        if ($benefit_description === '') {
            $errors[] = 'Benefit description is required.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare('INSERT INTO guest_benefits 
                    (partnership_id, benefit_type, benefit_value, benefit_description, min_spend, valid_from, valid_until, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $partnership_id,
                    $benefit_type,
                    $benefit_value,
                    $benefit_description,
                    $min_spend > 0 ? $min_spend : null,
                    $valid_from ?: null,
                    $valid_until ?: null,
                    'active'
                ]);
                $success = 'Guest benefit created successfully.';
                log_audit($pdo, $business_id, (int)$user['id'], 'benefit_created', 'guest_benefit', (int)$pdo->lastInsertId());
            } catch (Exception $e) {
                $errors[] = 'Error creating benefit: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'toggle' && $is_manager) {
        $benefit_id = intval($_POST['benefit_id'] ?? 0);
        if ($benefit_id > 0) {
            $stmt = $pdo->prepare('SELECT status FROM guest_benefits WHERE id = ? AND partnership_id IN (SELECT id FROM partnerships WHERE business_id = ?)');
            $stmt->execute([$benefit_id, $business_id]);
            $benefit = $stmt->fetch();
            if ($benefit) {
                $new_status = $benefit['status'] === 'active' ? 'inactive' : 'active';
                $pdo->prepare('UPDATE guest_benefits SET status = ? WHERE id = ?')->execute([$new_status, $benefit_id]);
                $success = 'Benefit status updated.';
            }
        }
    }
}

// Get partnerships for this business
$stmt = $pdo->prepare('SELECT p.*, b.name AS partner_name, b.business_type 
    FROM partnerships p 
    JOIN businesses b ON b.id = p.partner_business_id 
    WHERE p.business_id = ? AND p.status = \'active\'
    ORDER BY b.name');
$stmt->execute([$business_id]);
$partnerships = $stmt->fetchAll();

// Get guest benefits for this business
$stmt = $pdo->prepare('SELECT gb.*, p.business_id, b.name AS partner_name 
    FROM guest_benefits gb
    JOIN partnerships p ON p.id = gb.partnership_id
    JOIN businesses b ON b.id = p.partner_business_id
    WHERE p.business_id = ? OR p.partner_business_id = ?
    ORDER BY gb.created_at DESC');
$stmt->execute([$business_id, $business_id]);
$benefits = $stmt->fetchAll();

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Benefits - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Settings' => 'settings.php', 'Guest Benefits' => false]); ?>
            <?php echo render_page_header(
                'Guest Benefits',
                'Configure benefits that guests receive when referred through GuestBridge.',
                []
            ); ?>

            <div class="row justify-content-center">
                <div class="col-12 col-lg-10">
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($partnerships)): ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <div class="text-muted mb-3">
                                    <i data-lucide="gift" style="width:48px;height:48px;"></i>
                                </div>
                                <h5>No active partnerships</h5>
                                <p class="text-muted">You need active partnerships to create guest benefits.</p>
                                <a href="/guestbridgerwanda/partnerships.php" class="btn btn-primary">Find Partners</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card shadow-sm mb-4">
                            <div class="card-body">
                                <h2 class="h5 mb-3">Create Guest Benefit</h2>
                                <form method="post">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="create">
                                    
                                    <div class="row g-3">
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Partnership</label>
                                            <select name="partnership_id" class="form-select" required>
                                                <option value="">Select partnership...</option>
                                                <?php foreach ($partnerships as $p): ?>
                                                    <option value="<?php echo $p['id']; ?>">
                                                        <?php echo htmlspecialchars($p['partner_name']); ?> (<?php echo htmlspecialchars(business_type_label($p['business_type'])); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Benefit Type</label>
                                            <select name="benefit_type" class="form-select" required>
                                                <?php foreach (benefit_type_options() as $value => $label): ?>
                                                    <option value="<?php echo $value; ?>"><?php echo htmlspecialchars($label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Benefit Value</label>
                                            <input type="text" name="benefit_value" class="form-control" required 
                                                placeholder="e.g. 10%, RWF 5000, Free spa session">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Minimum Spend (RWF)</label>
                                            <input type="number" step="0.01" min="0" name="min_spend" class="form-control" 
                                                placeholder="Optional minimum spend">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Benefit Description</label>
                                            <textarea name="benefit_description" class="form-control" rows="2" required
                                                placeholder="Describe the benefit for the guest..."></textarea>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Valid From</label>
                                            <input type="date" name="valid_from" class="form-control">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Valid Until</label>
                                            <input type="date" name="valid_until" class="form-control">
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <button type="submit" class="btn btn-success">Create Benefit</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <?php if (!empty($benefits)): ?>
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Existing Benefits</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Partnership</th>
                                                <th>Type</th>
                                                <th>Value</th>
                                                <th>Description</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($benefits as $b): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($b['partner_name']); ?></td>
                                                <td><?php echo htmlspecialchars(benefit_type_label($b['benefit_type'])); ?></td>
                                                <td><?php echo htmlspecialchars($b['benefit_value']); ?></td>
                                                <td><?php echo htmlspecialchars($b['benefit_description']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $b['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                        <?php echo ucfirst($b['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($is_manager && $b['business_id'] == $business_id): ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Toggle benefit status?');">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="toggle">
                                                        <input type="hidden" name="benefit_id" value="<?php echo $b['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-<?php echo $b['status'] === 'active' ? 'outline-warning' : 'outline-success'; ?>">
                                                            <?php echo $b['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
<script>
(function(){var t=document.getElementById('sidebarToggle'),s=document.getElementById('appSidebar'),o=document.getElementById('sidebarOverlay');function open(){s.classList.add('open');o.classList.add('active');}function close(){s.classList.remove('open');o.classList.remove('active');}if(t)t.addEventListener('click',open);if(o)o.addEventListener('click',close);document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});})();
</script>
</body>
</html>
