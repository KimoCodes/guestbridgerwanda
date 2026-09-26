<?php
require_once __DIR__ . '/../app/Config/config.php';
require_once __DIR__ . '/../app/Config/database.php';
require_once __DIR__ . '/../app/Services/ServiceContainer.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$is_super_admin = is_super_admin($user);
$current_page = 'disputes.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

$errors = [];
$success = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('disputes.php');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $entity_type = $_POST['entity_type'] ?? '';
        $entity_id = intval($_POST['entity_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($subject === '' || $description === '') {
            $errors[] = 'Subject and description are required.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare('INSERT INTO disputes 
                    (business_id, subject, description, entity_type, entity_id, raised_by_user_id, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $business_id,
                    $subject,
                    $description,
                    $entity_type ?: null,
                    $entity_id ?: null,
                    (int)$user['id'],
                    'open'
                ]);
                $success = 'Dispute created successfully.';
                log_audit($pdo, $business_id, (int)$user['id'], 'dispute_created', 'dispute', (int)$pdo->lastInsertId());
            } catch (Exception $e) {
                $errors[] = 'Error creating dispute: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'resolve' && $is_super_admin) {
        $dispute_id = intval($_POST['dispute_id'] ?? 0);
        $resolution = trim($_POST['resolution'] ?? '');
        $new_status = $_POST['new_status'] ?? 'resolved';

        if ($dispute_id > 0 && $resolution !== '') {
            try {
                $stmt = $pdo->prepare('UPDATE disputes SET 
                    status = ?, resolution = ?, resolved_by_user_id = ?, resolved_at = NOW() 
                    WHERE id = ?');
                $stmt->execute([$new_status, $resolution, (int)$user['id'], $dispute_id]);
                $success = 'Dispute updated successfully.';
                log_audit($pdo, $business_id, (int)$user['id'], 'dispute_resolved', 'dispute', $dispute_id);
            } catch (Exception $e) {
                $errors[] = 'Error resolving dispute: ' . $e->getMessage();
            }
        }
    }
}

// Get disputes
if ($is_super_admin) {
    $stmt = $pdo->prepare('SELECT d.*, b.name AS business_name, u.name AS raised_by_name
        FROM disputes d
        LEFT JOIN businesses b ON b.id = d.business_id
        LEFT JOIN users u ON u.id = d.raised_by_user_id
        ORDER BY d.created_at DESC');
    $stmt->execute();
} else {
    $stmt = $pdo->prepare('SELECT d.*, b.name AS business_name, u.name AS raised_by_name
        FROM disputes d
        LEFT JOIN businesses b ON b.id = d.business_id
        LEFT JOIN users u ON u.id = d.raised_by_user_id
        WHERE d.business_id = ?
        ORDER BY d.created_at DESC');
    $stmt->execute([$business_id]);
}
$disputes = $stmt->fetchAll();

// Get recent referrals and transactions for dispute linking
$stmt = $pdo->prepare('SELECT id, referral_code FROM referrals WHERE source_business_id = ? OR target_business_id = ? ORDER BY created_at DESC LIMIT 10');
$stmt->execute([$business_id, $business_id]);
$referrals = $stmt->fetchAll();

require_once __DIR__ . '/../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disputes - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Finance' => '#', 'Disputes' => false]); ?>
            <?php echo render_page_header(
                'Disputes',
                'Raise and manage disputes related to referrals, transactions, or commissions.',
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

                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <h2 class="h5 mb-3">Create Dispute</h2>
                            <form method="post">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="create">
                                
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Related Referral (Optional)</label>
                                        <select name="entity_id" class="form-select">
                                            <option value="">Select referral...</option>
                                            <?php foreach ($referrals as $r): ?>
                                                <option value="<?php echo $r['id']; ?>">
                                                    <?php echo htmlspecialchars($r['referral_code']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="entity_type" value="referral">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Subject</label>
                                        <input type="text" name="subject" class="form-control" required 
                                            placeholder="Brief description of the dispute">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-control" rows="3" required
                                            placeholder="Provide details about the dispute..."></textarea>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-warning">Create Dispute</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if (!empty($disputes)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Disputes</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Subject</th>
                                            <th>Business</th>
                                            <th>Raised By</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                            <?php if ($is_super_admin): ?>
                                            <th>Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($disputes as $d): ?>
                                        <tr>
                                            <td>#<?php echo $d['id']; ?></td>
                                            <td><?php echo htmlspecialchars($d['subject']); ?></td>
                                            <td><?php echo htmlspecialchars($d['business_name']); ?></td>
                                            <td><?php echo htmlspecialchars($d['raised_by_name']); ?></td>
                                            <td>
                                                <?php 
                                                $status_colors = [
                                                    'open' => 'warning',
                                                    'under_review' => 'info',
                                                    'resolved' => 'success',
                                                    'rejected' => 'danger',
                                                    'escalated' => 'danger',
                                                ];
                                                $color = $status_colors[$d['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $color; ?>"><?php echo htmlspecialchars(dispute_status_label($d['status'])); ?></span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($d['created_at'])); ?></td>
                                            <?php if ($is_super_admin): ?>
                                            <td>
                                                <?php if (in_array($d['status'], ['open', 'under_review'])): ?>
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#resolveModal<?php echo $d['id']; ?>">Resolve</button>
                                                
                                                <div class="modal fade" id="resolveModal<?php echo $d['id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <form method="post">
                                                                <?php echo csrf_field(); ?>
                                                                <input type="hidden" name="action" value="resolve">
                                                                <input type="hidden" name="dispute_id" value="<?php echo $d['id']; ?>">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title">Resolve Dispute #<?php echo $d['id']; ?></h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <p><strong>Subject:</strong> <?php echo htmlspecialchars($d['subject']); ?></p>
                                                                    <p><strong>Description:</strong><br><?php echo nl2br(htmlspecialchars($d['description'])); ?></p>
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Resolution</label>
                                                                        <textarea name="resolution" class="form-control" rows="3" required placeholder="Describe the resolution..."></textarea>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Status</label>
                                                                        <select name="new_status" class="form-select">
                                                                            <option value="resolved">Resolved</option>
                                                                            <option value="rejected">Rejected</option>
                                                                            <option value="escalated">Escalated</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" class="btn btn-primary">Submit Resolution</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
<script>
(function(){var t=document.getElementById('sidebarToggle'),s=document.getElementById('appSidebar'),o=document.getElementById('sidebarOverlay');function open(){s.classList.add('open');o.classList.add('active');}function close(){s.classList.remove('open');o.classList.remove('active');}if(t)t.addEventListener('click',open);if(o)o.addEventListener('click',close);document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});})();
</script>
</body>
</html>
