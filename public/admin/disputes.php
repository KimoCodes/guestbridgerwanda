<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
$user = require_super_admin();
$pdo = db_connect();
$current_page = 'admin/disputes.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$status_filter = trim($_GET['status'] ?? '');
$where = '1=1';
$params = [];
$valid_statuses = ['open', 'under_review', 'resolved', 'rejected', 'escalated'];
if ($status_filter !== '' && in_array($status_filter, $valid_statuses, true)) {
    $where .= ' AND d.status = ?';
    $params[] = $status_filter;
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM disputes d WHERE $where");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT d.*, b.name AS business_name
    FROM disputes d
    JOIN businesses b ON b.id = d.business_id
    WHERE $where
    ORDER BY d.created_at DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$disputes = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_post();
    $dispute_id = (int)($_POST['dispute_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    if ($dispute_id > 0 && in_array($new_status, $valid_statuses, true)) {
        $pdo->prepare('UPDATE disputes SET status = ? WHERE id = ?')->execute([$new_status, $dispute_id]);
        log_audit($pdo, (int)($user['business_id']), (int)$user['id'], 'dispute_status_changed', 'dispute', $dispute_id, null, ['status' => $new_status], $_SERVER['REMOTE_ADDR'] ?? null);
    }
    header("Location: disputes.php?updated=1");
    exit;
}

require_once __DIR__ . '/../../app/HTTP/helpers.php';
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
<?php echo render_app_shell_start($user, 0, $current_page, 1); ?>

<?php echo render_breadcrumbs(['Admin Dashboard' => 'index.php', 'Disputes' => false], 1); ?>
<?php echo render_page_header('Disputes', "$total total disputes on the platform."); ?>

<?php if (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">Dispute status updated.</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="filter-bar">
            <div class="col-auto">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="under_review" <?php echo $status_filter === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                    <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="escalated" <?php echo $status_filter === 'escalated' ? 'selected' : ''; ?>>Escalated</option>
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
                    <tr><th>ID</th><th>Business</th><th>Subject</th><th>Description</th><th>Status</th><th>Created</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($disputes as $d): ?>
                    <tr>
                        <td>#<?php echo $d['id']; ?></td>
                        <td><?php echo htmlspecialchars($d['business_name']); ?></td>
                        <td><strong><?php echo htmlspecialchars($d['subject'] ?? 'No subject'); ?></strong></td>
                        <td><small class="text-muted"><?php echo htmlspecialchars(mb_strimwidth($d['description'] ?? '', 0, 80, '...')); ?></small></td>
                        <td><span class="badge bg-<?php echo $d['status'] === 'open' ? 'danger' : ($d['status'] === 'resolved' ? 'success' : ($d['status'] === 'rejected' ? 'secondary' : ($d['status'] === 'escalated' ? 'warning' : 'info'))); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $d['status']))); ?></span></td>
                        <td><small><?php echo htmlspecialchars($d['created_at']); ?></small></td>
                        <td>
                            <form method="post" style="display:inline-flex; gap:4px; align-items:center;" onsubmit="return confirm('Update dispute status?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="dispute_id" value="<?php echo $d['id']; ?>">
                                <select name="new_status" class="form-select form-select-sm" style="width:auto;">
                                    <option value="open" <?php echo $d['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                    <option value="under_review" <?php echo $d['status'] === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                    <option value="resolved" <?php echo $d['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="rejected" <?php echo $d['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="escalated" <?php echo $d['status'] === 'escalated' ? 'selected' : ''; ?>>Escalated</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($disputes)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No disputes found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination pagination-sm justify-content-center">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $p; ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $p; ?></a>
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
