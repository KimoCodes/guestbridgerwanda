<?php
/**
 * Settings System Helper Functions (CLEAN + FIXED VERSION)
 * - Secure
 * - Consistent
 * - Production-safe
 * - Reduced hidden bugs
 */

// ================================================================
// CSRF PROTECTION
// ================================================================
if (!function_exists('generate_csrf_token')) {
function generate_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}
}

if (!function_exists('verify_csrf')) {
function verify_csrf(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
}

if (!function_exists('csrf_field')) {
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(generate_csrf_token())
        . '">';
}
}

// ================================================================
// INPUT SANITIZATION / VALIDATION
// ================================================================
if (!function_exists('sanitize_username')) {
function sanitize_username(string $input): ?string {
    $input = trim($input);

    if (strlen($input) < 2 || strlen($input) > 100) {
        return null;
    }

    $clean = preg_replace('/[^\w\s\-\.\']/', '', $input);
    return $clean ?: null;
}
}

if (!function_exists('sanitize_email')) {
function sanitize_email(string $input): string {
    $email = trim($input);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}
}

if (!function_exists('sanitize_phone')) {
function sanitize_phone(string $input): string {
    return preg_replace('/[^\+\d\-\s\(\)]/', '', trim($input));
}
}

if (!function_exists('sanitize_text')) {
function sanitize_text(string $input, int $max = 500): string {
    $input = trim($input);

    if (strlen($input) > $max) {
        $input = substr($input, 0, $max);
    }

    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}
}

if (!function_exists('validate_password_strength')) {
function validate_password_strength(string $pwd): bool {
    return strlen($pwd) >= 8;
}
}

// ================================================================
// BUSINESS SETTINGS
// ================================================================
if (!function_exists('get_business_settings')) {
function get_business_settings(int $business_id): array {
    $pdo = db_connect();

    $stmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
    $stmt->execute([$business_id]);

    return $stmt->fetch() ?: [];
}
}

if (!function_exists('update_business_setting')) {
function update_business_setting(int $business_id, string $key, $value): void {
    $pdo = db_connect();

    // SAFE WHITELIST (prevents column injection)
    $allowed = [
        'name',
        'address',
        'city',
        'phone',
        'public_profile',
        'payout_method',
        'payment_account',
        'currency_pref',
    ];

    if (!in_array($key, $allowed, true)) {
        return;
    }

    $stmt = $pdo->prepare("UPDATE businesses SET \"$key\"=? WHERE id=?");
    $stmt->execute([$value, $business_id]);
}
}

// ================================================================
// AUDIT LOGGING
// ================================================================
// log_audit() is defined in app/Config/database.php (supports JSON old/new values).

// ================================================================
// DEVICE DETECTION
// ================================================================
if (!function_exists('detect_device')) {
function detect_device(string $user_agent): array {
    $ua = strtolower($user_agent);

    $device = 'Desktop';

    if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false) {
        $device = 'Mobile';
    } elseif (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) {
        $device = 'Tablet';
    }

    $browser = 'Unknown';

    if (strpos($ua, 'chrome') !== false) $browser = 'Chrome';
    elseif (strpos($ua, 'firefox') !== false) $browser = 'Firefox';
    elseif (strpos($ua, 'safari') !== false) $browser = 'Safari';
    elseif (strpos($ua, 'edge') !== false) $browser = 'Edge';

    return [
        'device' => $device,
        'browser' => $browser
    ];
}
}

// ================================================================
// USER META
// ================================================================
if (!function_exists('get_user_meta')) {
function get_user_meta(int $user_id, string $key, string $default = ''): string {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        SELECT meta_value
        FROM user_meta
        WHERE user_id = ? AND meta_key = ?
    ");

    $stmt->execute([$user_id, $key]);
    $row = $stmt->fetch();

    return $row['meta_value'] ?? $default;
}
}

if (!function_exists('save_user_meta')) {
function save_user_meta(int $user_id, string $key, string $value): void {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        INSERT INTO user_meta (user_id, meta_key, meta_value)
        VALUES (?, ?, ?)
        ON CONFLICT (user_id, meta_key) DO UPDATE SET meta_value = EXCLUDED.meta_value
    ");

    $stmt->execute([$user_id, $key, $value]);
}
}

// ================================================================
// 2FA
// ================================================================
if (!function_exists('get_user_2fa_status')) {
function get_user_2fa_status(int $user_id): array {
    $enabled = get_user_meta($user_id, '2fa_enabled', '0');
    $secret  = get_user_meta($user_id, '2fa_secret', '');

    return [
        '2fa_enabled' => ((int)$enabled === 1),
        '2fa_secret' => $secret
    ];
}
}

if (!function_exists('enable_user_2fa')) {
function enable_user_2fa(int $user_id, string $secret): void {
    save_user_meta($user_id, '2fa_secret', $secret);
    save_user_meta($user_id, '2fa_enabled', '1');
}
}

if (!function_exists('disable_user_2fa')) {
function disable_user_2fa(int $user_id): void {
    save_user_meta($user_id, '2fa_enabled', '0');
    save_user_meta($user_id, '2fa_secret', '');
}
}

// ================================================================
// SESSIONS
// ================================================================
if (!function_exists('invalidate_all_sessions_except_current')) {
function invalidate_all_sessions_except_current(int $user_id, string $current_session_id): void {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        DELETE FROM login_sessions
        WHERE user_id = ? AND id != ?
    ");

    $stmt->execute([$user_id, $current_session_id]);
}
}

if (!function_exists('get_active_sessions')) {
function get_active_sessions(int $user_id): array {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        SELECT *
        FROM login_sessions
        WHERE user_id = ?
        ORDER BY last_active_at DESC
        LIMIT 10
    ");

    $stmt->execute([$user_id]);
    return $stmt->fetchAll() ?: [];
}
}

if (!function_exists('get_login_history')) {
function get_login_history(int $user_id, int $limit = 20): array {
    $pdo = db_connect();
    $limit = max(1, min(100, (int)$limit));

    $stmt = $pdo->prepare("
        SELECT *
        FROM login_history
        WHERE user_id = ?
        ORDER BY COALESCE(logged_in_at, NOW()) DESC
        LIMIT ?
    ");

    $stmt->execute([$user_id, $limit]);
    return $stmt->fetchAll() ?: [];
}
}

// ================================================================
// NOTIFICATIONS
// ================================================================
if (!defined('GB_DEFAULT_NOTIFICATION_PREFS')) {
    define('GB_DEFAULT_NOTIFICATION_PREFS', json_encode([
        'email_notifications' => 1,
        'sms_alerts' => 0,
        'booking_alerts' => 1,
        'payment_alerts' => 1,
        'marketing_emails' => 0,
        'system_announcements' => 1,
    ]));
}

if (!function_exists('gb_default_notification_prefs')) {
function gb_default_notification_prefs(): array {
    return json_decode(GB_DEFAULT_NOTIFICATION_PREFS, true) ?: [];
}
}

if (!function_exists('get_notification_preferences')) {
function get_notification_preferences(int $user_id): array {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        SELECT pref_key, pref_value
        FROM notification_preferences
        WHERE user_id = ?
    ");

    $stmt->execute([$user_id]);

    $prefs = gb_default_notification_prefs();

    foreach ($stmt->fetchAll() as $r) {
        $prefs[$r['pref_key']] = (int)$r['pref_value'];
    }

    return $prefs;
}
}

if (!function_exists('save_notification_preferences')) {
function save_notification_preferences(int $user_id, array $prefs): void {
    $pdo = db_connect();

    foreach ($prefs as $k => $v) {
        $stmt = $pdo->prepare("
            INSERT INTO notification_preferences
            (user_id, pref_key, pref_value)
            VALUES (?, ?, ?)
            ON CONFLICT (user_id, pref_key) DO UPDATE SET pref_value = EXCLUDED.pref_value
        ");

        $stmt->execute([$user_id, $k, (int)$v]);
    }
}
}

// ================================================================
// STAFF
// ================================================================
if (!function_exists('get_staff_members')) {
function get_staff_members($pdo, int $business_id): array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM staff
        WHERE business_id = ?
        ORDER BY created_at DESC
    ");

    $stmt->execute([$business_id]);
    return $stmt->fetchAll() ?: [];
}
}

if (!function_exists('add_staff_member')) {
function add_staff_member($pdo, int $business_id, array $data): int {
    $stmt = $pdo->prepare("
        INSERT INTO staff (business_id, name, phone, role)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $business_id,
        $data['name'],
        $data['phone'] ?? '',
        $data['role'] ?? 'receptionist',
    ]);

    return (int)$pdo->lastInsertId();
}
}

if (!function_exists('remove_staff_member')) {
function remove_staff_member($pdo, int $staff_id): void {
    $stmt = $pdo->prepare("DELETE FROM staff WHERE id = ?");
    $stmt->execute([$staff_id]);
}
}

// ================================================================
// AUDIT LOGS
// ================================================================
if (!function_exists('get_audit_logs')) {
function get_audit_logs($pdo, int $business_id, int $limit = 50): array {
    $limit = max(1, min(200, (int)$limit));

    $stmt = $pdo->prepare("
        SELECT al.*, u.name AS user_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.business_id = ?
        ORDER BY al.created_at DESC
        LIMIT ?
    ");

    $stmt->execute([$business_id, $limit]);
    return $stmt->fetchAll() ?: [];
}
}

// ================================================================
// FINANCIAL
// ================================================================
if (!function_exists('get_financial_summary')) {
function get_financial_summary(int $business_id): array {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status='available' THEN amount ELSE 0 END),0) AS available_balance,
            COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) AS pending_balance,
            COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) AS total_paid_out
        FROM earnings
        WHERE business_id = ?
    ");

    $stmt->execute([$business_id]);
    return $stmt->fetch() ?: [];
}
}

// ================================================================
// ACCOUNT / PREFERENCES (settings page)
// ================================================================
if (!function_exists('get_account_settings')) {
function get_account_settings(int $user_id): array {
    if (function_exists('get_user_settings')) {
        return get_user_settings($user_id);
    }
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: [];
}
}

if (!function_exists('get_system_preferences')) {
function get_system_preferences(int $user_id): array {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT pref_key, pref_value FROM user_preferences WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $prefs = [
        'theme' => 'light',
        'language' => 'en',
    ];
    foreach ($stmt->fetchAll() as $row) {
        $prefs[$row['pref_key']] = $row['pref_value'];
    }
    return $prefs;
}
}

if (!function_exists('save_system_preference')) {
function save_system_preference(int $user_id, string $key, string $value): void {
    $allowed = ['theme', 'language', 'timezone', 'ui_density', 'default_dashboard_view'];
    if (!in_array($key, $allowed, true)) {
        return;
    }
    $value = substr(trim($value), 0, 255);
    $pdo = db_connect();
    $stmt = $pdo->prepare('INSERT INTO user_preferences (user_id, pref_key, pref_value)
        VALUES (?, ?, ?)
        ON CONFLICT (user_id, pref_key) DO UPDATE SET pref_value = EXCLUDED.pref_value');
    $stmt->execute([$user_id, $key, $value]);
}
}

if (!function_exists('get_consent_status')) {
function get_consent_status(int $user_id): array {
    $pdo = db_connect();
    $defaults = [
        'terms' => 0,
        'privacy' => 0,
        'marketing' => 0,
    ];
    $stmt = $pdo->prepare('SELECT consent_type, granted FROM consents WHERE user_id = ?');
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll() as $row) {
        $defaults[$row['consent_type']] = (int)$row['granted'];
    }
    return $defaults;
}
}