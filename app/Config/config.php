<?php
// Session hardening
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    // Only set secure flag when HTTPS is active
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// Development helpers: show errors on localhost only
$gb_is_local = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)
    || strpos((string)($_SERVER['HTTP_HOST'] ?? ''), 'localhost:') === 0;
if ($gb_is_local) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

function dev_error_handler($severity, $message, $file, $line)
{
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
}

function dev_exception_handler($exception)
{
    if (!headers_sent()) {
        http_response_code(500);
    }
    $is_local = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)
        || strpos((string)($_SERVER['HTTP_HOST'] ?? ''), 'localhost:') === 0;
    error_log('GuestBridge exception: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Application error</title>';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/5.3.1/flatly/bootstrap.min.css">';
    echo '</head><body><div class="container py-5"><div class="card shadow-sm border-danger"><div class="card-body"><h1 class="h4 text-danger">Application error</h1><p class="text-muted">A runtime error occurred while loading this page.</p>';
    if ($is_local) {
        echo '<div class="alert alert-danger"><strong>' . htmlspecialchars($exception->getMessage()) . '</strong></div><p><strong>File:</strong> ' . htmlspecialchars($exception->getFile()) . ' on line ' . htmlspecialchars($exception->getLine()) . '</p><pre class="bg-light p-3 rounded">' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
    } else {
        echo '<div class="alert alert-danger"><strong>An unexpected error occurred.</strong></div><p class="text-muted">Please try again later or contact support if the problem persists.</p>';
    }
    echo '<a href="' . htmlspecialchars(gb_url('dashboard.php'), ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary mt-3">Back to dashboard</a></div></div></div></body></html>';
    exit;
}

set_error_handler('dev_error_handler');
set_exception_handler('dev_exception_handler');

/** Send security headers on every response. */
function gb_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' https://images.unsplash.com data:; connect-src 'self'");
}

gb_send_security_headers();

define('DB_HOST', getenv('GB_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('GB_DB_NAME') ?: 'guestbridgerwanda');
define('DB_USER', getenv('GB_DB_USER') ?: 'root');
define('DB_PASS', getenv('GB_DB_PASS') ?: '');


define('APP_NAME', 'GuestBridge Rwanda');

define('BASE_URL', '/guestbridgerwanda');
define('PAYMENT_WEBHOOK_SECRET', getenv('GB_PAYMENT_WEBHOOK_SECRET') ?: '');

function gb_url(string $path = ''): string
{
    $base = rtrim((string) BASE_URL, '/');
    $path = trim((string) $path, '/');
    if ($path === '') {
        return $base === '' ? '/' : $base;
    }
    return $base === '' ? '/' . $path : $base . '/' . $path;
}

function gb_redirect(string $path): void
{
    header('Location: ' . gb_url($path));
    exit;
}

/** Days until an unused referral expires (0 = no expiry). */
define('REFERRAL_EXPIRY_DAYS', (int)(getenv('GB_REFERRAL_EXPIRY_DAYS') ?: 30));

/** When true, new businesses need REGISTRATION_INVITE_CODE to sign up. */
define('INVITE_ONLY_REGISTRATION', filter_var(getenv('GB_INVITE_ONLY') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('REGISTRATION_INVITE_CODE', getenv('GB_INVITE_CODE') ?: 'pilot-kigali-2026');

/** Comma-separated emails allowed to access platform-wide admin finance. */
define('PLATFORM_ADMIN_EMAILS', getenv('GB_ADMIN_EMAILS') ?: 'admin@pilot.kig');

/** SMTP settings used to send password reset emails (Gmail SMTP + app password). */
define('SMTP_HOST', getenv('GB_SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int)(getenv('GB_SMTP_PORT') ?: 587));
define('SMTP_USERNAME', getenv('GB_SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('GB_SMTP_PASSWORD') ?: '');
define('SMTP_FROM_EMAIL', getenv('GB_SMTP_FROM_EMAIL') ?: '');
define('SMTP_FROM_NAME', getenv('GB_SMTP_FROM_NAME') ?: APP_NAME);

/** Minutes a password reset link remains valid. */
define('PASSWORD_RESET_TOKEN_TTL_MINUTES', (int)(getenv('GB_PASSWORD_RESET_TTL') ?: 60));

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/mailer.php';

function is_platform_admin(?array $user): bool
{
    if (!$user || empty($user['email'])) {
        return false;
    }
    if (($user['role'] ?? '') === 'super_admin') {
        return true;
    }
    $admin_emails_str = get_platform_config('platform_admin_emails', PLATFORM_ADMIN_EMAILS);
    $allowed = array_filter(array_map('trim', explode(',', $admin_emails_str)));
    if ($allowed === []) {
        return false;
    }
    $email = strtolower((string)$user['email']);
    foreach ($allowed as $entry) {
        if (strtolower($entry) === $email) {
            return true;
        }
    }
    return false;
}

function is_staff_simple_mode(?array $user): bool
{
    $role = $user['role'] ?? '';
    return in_array($role, ['receptionist', 'concierge'], true);
}

function is_super_admin(?array $user): bool
{
    return ($user['role'] ?? '') === 'super_admin';
}

function require_super_admin(): array
{
    require_login();
    $user = current_user();
    if (!is_super_admin($user)) {
        flash_set('Super Admin access is required for that page.');
        header('Location: /guestbridgerwanda/dashboard.php');
        exit;
    }
    return $user;
}

function registration_is_allowed(?string $invite_code = null): bool
{
    $invite_only = get_platform_config('invite_only_registration', INVITE_ONLY_REGISTRATION ? '1' : '0');
    if ($invite_only !== '1') {
        return true;
    }
    $stored_code = get_platform_config('registration_invite_code', REGISTRATION_INVITE_CODE);
    return hash_equals($stored_code, trim((string)$invite_code));
}

/** Shared stylesheets for authenticated app pages. */
function gb_render_stylesheets(): void
{
    $v = '20260522';
    $base = rtrim(BASE_URL, '/');
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/5.3.1/flatly/bootstrap.min.css" crossorigin="anonymous">';
    echo '<link rel="stylesheet" href="' . $base . '/assets/style.css?v=' . $v . '">';
    echo '<link rel="stylesheet" href="' . $base . '/assets/design-upgrade.css?v=' . $v . '">';
}

function gb_render_app_scripts(): string
{
    $base = rtrim(BASE_URL, '/');
    return '<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>'
        . '<script src="' . $base . '/assets/app.js?v=20260522"></script>';
}

function db_connect()
{
    static $pdo;
    if ($pdo) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        $is_local = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)
            || strpos((string)($_SERVER['HTTP_HOST'] ?? ''), 'localhost:') === 0;
        if ($is_local) {
            die('Database connection failed: ' . $e->getMessage());
        }
        error_log('GuestBridge DB connection failed: ' . $e->getMessage());
        die('A database error occurred. Please try again later or contact support.');
    }

    return $pdo;
}

function flash_set($message)
{
    $_SESSION['flash_message'] = $message;
}

function flash_get()
{
    if (!empty($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

function current_user()
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT u.*, b.name AS business_name, b.business_type, b.city AS business_city, b.email AS business_email
        FROM users u
        INNER JOIN businesses b ON b.id = u.business_id
        WHERE u.id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['business_id'] <= 0) {
        return null;
    }
    if (!empty($_SESSION['business_id']) && (int) $_SESSION['business_id'] !== (int) $user['business_id']) {
        return null;
    }
    return $user;
}

function gb_pending_partnership_count(PDO $pdo, int $business_id): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
    $stmt->execute([$business_id, 'pending']);
    return (int) $stmt->fetch()['pending'];
}

function require_login()
{
    if (!current_user()) {
        gb_redirect('login.php');
    }
}

function require_tenant_login(): array
{
    require_once __DIR__ . '/tenant.php';
    return require_tenant_user();
}

function require_manager()
{
    require_login();
    $user = current_user();
    if (!$user || ($user['role'] !== 'manager' && $user['role'] !== 'super_admin')) {
        flash_set('Manager access is required for that page.');
        gb_redirect('dashboard.php');
    }
}

function require_platform_admin()
{
    require_login();
    if (!is_platform_admin(current_user())) {
        flash_set('Platform admin access is required for that page.');
        gb_redirect('dashboard.php');
    }
}

function referral_status_options()
{
    return [
        'created' => 'Created',
        'accepted' => 'Accepted',
        'visited' => 'Visited',
        'converted' => 'Converted',
        'settled' => 'Settled',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'rejected' => 'Rejected',
        'disputed' => 'Disputed',
    ];
}

function referral_status_label($status)
{
    $options = referral_status_options();
    return $options[$status] ?? ucfirst($status);
}

function referral_status_color($status)
{
    $colors = [
        'created' => 'info',
        'accepted' => 'primary',
        'visited' => 'warning',
        'converted' => 'success',
        'settled' => 'success',
        'cancelled' => 'secondary',
        'expired' => 'secondary',
        'rejected' => 'danger',
        'disputed' => 'danger',
    ];
    return $colors[$status] ?? 'secondary';
}

function settlement_status_options()
{
    return [
        'pending' => 'Pending',
        'submitted' => 'Submitted',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'reversed' => 'Reversed',
    ];
}

function settlement_status_label($status)
{
    $options = settlement_status_options();
    return $options[$status] ?? ucfirst($status);
}

function dispute_status_options()
{
    return [
        'open' => 'Open',
        'under_review' => 'Under Review',
        'resolved' => 'Resolved',
        'rejected' => 'Rejected',
        'escalated' => 'Escalated',
    ];
}

function dispute_status_label($status)
{
    $options = dispute_status_options();
    return $options[$status] ?? ucfirst($status);
}

function business_approval_status_options()
{
    return [
        'pending' => 'Pending Approval',
        'approved' => 'Approved',
        'suspended' => 'Suspended',
        'rejected' => 'Rejected',
        'deactivated' => 'Deactivated',
    ];
}

function business_approval_status_label($status)
{
    $options = business_approval_status_options();
    return $options[$status] ?? ucfirst($status);
}

function benefit_type_options()
{
    return [
        'percentage_discount' => 'Percentage Discount',
        'fixed_discount' => 'Fixed Discount',
        'free_item' => 'Free Item',
        'complimentary_service' => 'Complimentary Service',
        'upgrade' => 'Upgrade',
        'special_package' => 'Special Package',
        'other' => 'Other',
    ];
}

function benefit_type_label($type)
{
    $options = benefit_type_options();
    return $options[$type] ?? 'Other';
}

function format_money($value)
{
    return number_format((float)$value, 0, '.', ',');
}

function format_score($value)
{
    return number_format((float)$value, 0);
}

function business_type_options()
{
    return [
        'hotel' => 'Hotel',
        'restaurant' => 'Restaurant',
        'spa' => 'Spa',
        'transport' => 'Transport',
        'tourism' => 'Tourism',
        'nightlife' => 'Nightlife',
        'other' => 'Other',
    ];
}

function business_type_label($type)
{
    $options = business_type_options();
    return $options[$type] ?? $options['other'];
}

function city_options()
{
    return [
        'Kigali' => 'Kigali',
        // Future cities can be added here
        // 'Kampala' => 'Kampala',
        // 'Nairobi' => 'Nairobi',
        // 'Dar es Salaam' => 'Dar es Salaam',
    ];
}

function city_label($city)
{
    $city = trim((string)$city);
    return $city !== '' ? $city : 'Kigali';
}

function payment_method_options()
{
    return [
        'mobile_money' => 'Mobile money',
        'mtn_momo' => 'MTN MoMo',
        'paypal' => 'PayPal',
        'stripe' => 'Stripe',
        'bank_transfer' => 'Bank transfer',
        'internal_credit' => 'Internal credit',
        'cash' => 'Cash',
        'other' => 'Other',
    ];
}

function payment_method_label($method)
{
    $options = payment_method_options();
    return $options[$method] ?? $options['other'];
}

function debt_status_label($status)
{
    $labels = [
        'unpaid' => 'Unpaid',
        'partial' => 'Partial',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
    ];

    return $labels[$status] ?? $labels['unpaid'];
}

function signoff_status_options()
{
    return [
        'pending' => 'Pending review',
        'signed' => 'Signed off',
        'needs_followup' => 'Needs follow-up',
        'disputed' => 'Disputed',
    ];
}

function signoff_status_label($status)
{
    $options = signoff_status_options();
    return $options[$status] ?? $options['pending'];
}

function reward_status_options()
{
    return [
        'pending' => 'Pending approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];
}

function reward_status_label($status)
{
    $options = reward_status_options();
    return $options[$status] ?? $options['pending'];
}

function platform_fee_status_options()
{
    return [
        'simulated' => 'Simulated',
        'approved' => 'Approved',
        'waived' => 'Waived',
    ];
}

function platform_fee_status_label($status)
{
    $options = platform_fee_status_options();
    return $options[$status] ?? $options['simulated'];
}

function featured_listing_status_options()
{
    return [
        'active' => 'Featured',
        'paused' => 'Paused',
    ];
}

function featured_listing_status_label($status)
{
    $options = featured_listing_status_options();
    return $options[$status] ?? $options['paused'];
}

function reputation_average(array $business)
{
    $scores = [
        (float)($business['reliability_score'] ?? 0),
        (float)($business['payout_compliance_score'] ?? 0),
        (float)($business['guest_satisfaction_score'] ?? 0),
    ];

    return array_sum($scores) / count($scores);
}

function reputation_label($score)
{
    $score = (float)$score;
    if ($score >= 90) {
        return 'Excellent';
    }
    if ($score >= 75) {
        return 'Strong';
    }
    if ($score >= 60) {
        return 'Watch';
    }
    return 'Risk';
}

function whatsapp_phone_number($phone)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '250') === 0) {
        return $digits;
    }
    if (strlen($digits) === 10 && $digits[0] === '0') {
        return '250' . substr($digits, 1);
    }
    if (strlen($digits) === 9) {
        return '250' . $digits;
    }
    return $digits;
}

function referral_url($referral_code)
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $host;
    return rtrim($base . BASE_URL, '/') . '/referral.php?code=' . urlencode($referral_code);
}

/**
 * Get a platform config value from the database, falling back to constant defaults.
 */
function get_platform_config(string $key, $default = null)
{
    static $config_cache = null;
    if ($config_cache === null) {
        $config_cache = [];
        try {
            $pdo = db_connect();
            $pdo->exec("CREATE TABLE IF NOT EXISTS platform_config (
                config_key VARCHAR(100) PRIMARY KEY,
                config_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $rows = $pdo->query('SELECT config_key, config_value FROM platform_config')->fetchAll(PDO::FETCH_KEY_PAIR);
            $config_cache = $rows ?: [];
        } catch (Exception $e) {
            $config_cache = [];
        }
    }
    return $config_cache[$key] ?? $default;
}

function whatsapp_referral_message($referral, $source_business, $staff_name, $referral_link, $status = 'new')
{
    $status_labels = [
        'new' => 'New Referral',
        'accepted' => 'Referral Accepted',
        'visited' => 'Guest Visited',
        'converted' => 'Guest Converted',
        'settled' => 'Referral Settled',
    ];
    
    $status_label = $status_labels[$status] ?? 'Referral Update';
    $benefit_text = !empty($referral['guest_benefit_description']) ? "\n🎁 Guest Benefit: {$referral['guest_benefit_description']}" : '';
    
    $message = "🏨 {$status_label}\n\n"
        . "From: {$source_business}\n"
        . "To: {$referral['target_business']}\n"
        . "Referral Code: {$referral['referral_code']}\n"
        . "Staff: {$staff_name}\n"
        . $benefit_text . "\n"
        . "Offer: {$referral['guest_name']} - " . ($referral['guest_benefit_description'] ?? 'N/A') . "\n"
        . "Estimated Value: RWF " . format_money($referral['estimated_value']) . "\n"
        . "Commission: {$referral['commission_percentage']}%\n\n"
        . "Open referral:\n{$referral_link}";
    
    return $message;
}

function whatsapp_action_message($action, $referral, $source_business, $staff_name, $referral_link, $pin = null)
{
    $action_messages = [
        'accept' => "✅ Referral Accepted\n\n"
            . "I've accepted the referral for:\n{$referral['target_business']}\n\n"
            . "From: {$source_business}\n"
            . "Referral Code: {$referral['referral_code']}\n"
            . "Staff: {$staff_name}\n"
            . "Guest: {$referral['guest_name']}\n\n"
            . "I'll confirm when they visit.",
        
        'confirm_visit' => "👋 Guest Visit Confirmed\n\n"
            . "The referred guest has visited:\n{$referral['target_business']}\n\n"
            . "From: {$source_business}\n"
            . "Referral Code: {$referral['referral_code']}\n"
            . "Guest: {$referral['guest_name']}\n\n"
            . "Please record their transaction.",
        
        'record_transaction' => "💰 Transaction Recorded\n\n"
            . "I've recorded a transaction for:\n{$referral['target_business']}\n\n"
            . "Referral Code: {$referral['referral_code']}\n"
            . "Guest: {$referral['guest_name']}\n\n"
            . "The commission will be calculated automatically.",
    ];
    
    $message = $action_messages[$action] ?? "Update on referral: {$referral['referral_code']}";
    
    if ($pin) {
        $message .= "\n\n🔐 Partner PIN: {$pin}\n(Enter this PIN on the referral page to confirm)";
    }
    
    $message .= "\n\nView details:\n{$referral_link}";
    
    return $message;
}
