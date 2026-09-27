<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
$user = require_super_admin();
$pdo = db_connect();
$current_page = 'admin/businesses.php';

$action = $_GET['action'] ?? 'list';
$business_id = (int)($_GET['id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (b.name LIKE ? OR b.email LIKE ? OR b.phone LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($status_filter !== '' && in_array($status_filter, ['pending', 'approved', 'suspended', 'rejected', 'deactivated'], true)) {
    $where .= ' AND b.approval_status = ?';
    $params[] = $status_filter;
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM businesses b WHERE $where");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT b.*, COUNT(DISTINCT u.id) AS user_count,
    (SELECT COUNT(*) FROM referrals WHERE source_business_id = b.id OR target_business_id = b.id) AS referral_count
    FROM businesses b
    LEFT JOIN users u ON u.business_id = b.id
    WHERE $where
    GROUP BY b.id
    ORDER BY b.created_at DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$businesses = $stmt->fetchAll();

// Handle approval actions
if ($action === 'approve' && $business_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post();
    $stmt = $pdo->prepare('SELECT approval_status FROM businesses WHERE id = ?');
    $stmt->execute([$business_id]);
    $biz = $stmt->fetch();
    if ($biz) {
        $pdo->prepare('UPDATE businesses SET approval_status = \'approved\', status = \'active\' WHERE id = ?')->execute([$business_id]);
        log_audit($pdo, $business_id, (int)$user['id'], 'business_approved', 'business', $business_id, ['approval_status' => $biz['approval_status']], ['approval_status' => 'approved'], $_SERVER['REMOTE_ADDR'] ?? null);
    }
    header("Location: businesses.php?updated=1");
    exit;
}

if ($action === 'suspend' && $business_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post();
    $stmt = $pdo->prepare('SELECT approval_status FROM businesses WHERE id = ?');
    $stmt->execute([$business_id]);
    $biz = $stmt->fetch();
    if ($biz) {
        $pdo->prepare('UPDATE businesses SET approval_status = \'suspended\', status = \'inactive\' WHERE id = ?')->execute([$business_id]);
        log_audit($pdo, $business_id, (int)$user['id'], 'business_suspended', 'business', $business_id, ['approval_status' => $biz['approval_status']], ['approval_status' => 'suspended'], $_SERVER['REMOTE_ADDR'] ?? null);
    }
    header("Location: businesses.php?updated=1");
    exit;
}

if ($action === 'reject' && $business_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post();
    $stmt = $pdo->prepare('SELECT approval_status FROM businesses WHERE id = ?');
    $stmt->execute([$business_id]);
    $biz = $stmt->fetch();
    if ($biz) {
        $pdo->prepare('UPDATE businesses SET approval_status = \'rejected\', status = \'inactive\' WHERE id = ?')->execute([$business_id]);
        log_audit($pdo, $business_id, (int)$user['id'], 'business_rejected', 'business', $business_id, ['approval_status' => $biz['approval_status']], ['approval_status' => 'rejected'], $_SERVER['REMOTE_ADDR'] ?? null);
    }
    header("Location: businesses.php?updated=1");
    exit;
}

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Businesses - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, 0, $current_page, 1); ?>

<?php echo render_breadcrumbs(['Admin Dashboard' => 'index.php', 'Businesses' => false], 1); ?>
<?php echo render_page_header('Manage Businesses', "$total total businesses registered on the platform."); ?>

<?php if (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">Business status updated.</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="filter-bar">
            <div class="col-auto">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Name, email, phone" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-auto">
                <label class="form-label">Approval Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="deactivated" <?php echo $status_filter === 'deactivated' ? 'selected' : ''; ?>>Deactivated</option>
                </select>
            </div>
            <div class="col-auto d-grid">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-modern table-hover align-middle mb-0">
                <thead>
                    <tr><th>Business</th><th>City</th><th>Type</th><th>Users</th><th>Referrals</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($businesses as $b): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($b['name']); ?></strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($b['email']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($b['city'] ?? 'Kigali'); ?></td>
                        <td><?php echo htmlspecialchars(business_type_label($b['business_type'] ?? '')); ?></td>
                        <td><?php echo (int)$b['user_count']; ?></td>
                        <td><?php echo (int)$b['referral_count']; ?></td>
                        <td>
                            <?php 
                            $status_colors = [
                                'pending' => 'warning',
                                'approved' => 'success',
                                'suspended' => 'danger',
                                'rejected' => 'danger',
                                'deactivated' => 'secondary',
                            ];
                            $status = $b['approval_status'] ?? 'pending';
                            $color = $status_colors[$status] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $color; ?>"><?php echo htmlspecialchars(business_approval_status_label($status)); ?></span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <?php if ($status === 'pending'): ?>
                                    <form method="post" action="businesses.php?action=approve&id=<?php echo $b['id']; ?>" style="display:inline;" onsubmit="return confirm('Approve this business?');">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form method="post" action="businesses.php?action=reject&id=<?php echo $b['id']; ?>" style="display:inline;" onsubmit="return confirm('Reject this business?');">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                <?php elseif ($status === 'approved'): ?>
                                    <form method="post" action="businesses.php?action=suspend&id=<?php echo $b['id']; ?>" style="display:inline;" onsubmit="return confirm('Suspend this business?');">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-warning btn-sm">Suspend</button>
                                    </form>
                                <?php elseif ($status === 'suspended'): ?>
                                    <form method="post" action="businesses.php?action=approve&id=<?php echo $b['id']; ?>" style="display:inline;" onsubmit="return confirm('Reactivate this business?');">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-success btn-sm">Reactivate</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($businesses)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No businesses found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination pagination-sm justify-content-center">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<?php echo render_app_shell_end(); ?>
</body>
</html>
