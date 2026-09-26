<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'staff.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$errors = [];
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('staff.php');
    if (!$is_manager) {
        $errors[] = 'Only managers can add staff members.';
    } else {
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? 'receptionist';
    $phone = trim($_POST['phone'] ?? '');
    if ($name === '') {
        $errors[] = 'Staff name is required.';
    }
    if (!in_array($role, ['manager', 'receptionist', 'concierge'], true)) {
        $errors[] = 'Invalid staff role.';
    }
    if (empty($errors)) {
        $stmt = $pdo->prepare('INSERT INTO staff (business_id, name, role, phone) VALUES (?, ?, ?, ?)');
        $stmt->execute([$business_id, $name, $role, $phone]);
        $flash = 'Staff member added successfully.';
    }
    }
}

$stmt = $pdo->prepare('SELECT id, name, role, phone, created_at FROM staff WHERE business_id = ? ORDER BY created_at DESC');
$stmt->execute([$business_id]);
$staff_members = $stmt->fetchAll();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Network' => 'partnerships.php', 'Staff' => false]); ?>
            <?php echo render_page_header(
                'Staff Management',
                'Create staff users who handle referrals for your business.',
                []
            ); ?>
            <?php if ($flash): ?>
                <div class="alert alert-success mb-3"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-3"><?php foreach ($errors as $error): ?><div><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <div class="row gy-4">
                <?php if ($is_manager): ?>
                <div class="col-12 col-lg-5">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Add Staff Member</h3>
                            <form method="post">
                            <?php echo csrf_field(); ?>
                                <div class="mb-3">
                                    <label class="form-label">Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Role</label>
                                    <select name="role" class="form-select">
                                        <option value="receptionist" <?php echo (($_POST['role'] ?? '') === 'receptionist') ? 'selected' : ''; ?>>Receptionist</option>
                                        <option value="concierge" <?php echo (($_POST['role'] ?? '') === 'concierge') ? 'selected' : ''; ?>>Concierge</option>
                                        <option value="manager" <?php echo (($_POST['role'] ?? '') === 'manager') ? 'selected' : ''; ?>>Manager</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" placeholder="+250788000000">
                                </div>
                                <button class="btn btn-primary">Add Staff</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-12 col-lg-7">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Staff Members</h3>
                            <?php if ($staff_members): ?>
                                <div class="table-responsive">
                                    <table class="table table-modern table-hover align-middle mb-0">
                                        <thead>
                                            <tr><th>Name</th><th>Role</th><th>Phone</th><th>Added</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($staff_members as $member): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($member['name']); ?></td>
                                                    <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars(ucfirst($member['role'])); ?></span></td>
                                                    <td><?php echo htmlspecialchars($member['phone'] ?: '—'); ?></td>
                                                    <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($member['created_at']))); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No staff members yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
<?php echo render_app_shell_end(); ?>
</body>
</html>