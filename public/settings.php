<?php
require_once __DIR__ . '/../app/Config/config.php';
require_once __DIR__ . '/../app/Config/database.php';
require_once __DIR__ . '/../app/Config/tenant.php';
require_once __DIR__ . '/../app/HTTP/middleware.php';
require_once __DIR__ . '/../app/Settings/hub.php';

db_init();
$user = require_tenant_user();
$pdo = db_connect();
$current_page = 'settings.php';

$pending_requests = gb_pending_partnership_count($pdo, tenant_business_id($user));
require_once __DIR__ . '/../app/HTTP/helpers.php';

$tabs = settings_allowed_tabs($user);
$tab = $_GET['tab'] ?? 'account';
if (!isset($tabs[$tab])) {
    $tab = 'account';
}

$errors = [];
$flash = isset($_GET['ok']) ? 'Settings saved successfully.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($content_length > 0 && empty($_POST) && empty($_FILES)) {
        $errors[] = 'Upload too large for PHP (post_max_size is ' . ini_get('post_max_size') . '). Use a smaller video or raise limits in php.ini / public/.user.ini.';
    }
    require_csrf_post('settings.php?tab=' . urlencode($tab));
    $redirect_tab = settings_handle_post($pdo, $user, $tab, $errors, $flash);
    if (empty($errors) && !isset($_POST['download_data_export'])) {
        header('Location: settings.php?tab=' . urlencode($redirect_tab) . '&ok=1');
        exit;
    }
    $tab = $redirect_tab;
}

$ctx = settings_build_context($pdo, $user);
$ctx['tab'] = $tab;
$ctx['errors'] = $errors;
$ctx['flash'] = $flash;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
    <link rel="stylesheet" href="/guestbridgerwanda/assets/settings-hub.css?v=20260524">
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
<?php include __DIR__ . '/../app/Views/settings/view.php'; ?>
<?php echo render_app_shell_end(); ?>
</body>
</html>
