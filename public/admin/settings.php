<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
$user = require_super_admin();
$pdo = db_connect();
$current_page = 'admin/settings.php';

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post();
    $section = $_POST['section'] ?? '';

    if ($section === 'revenue') {
        $subscription_fee = (float)($_POST['subscription_fee'] ?? 0);
        $referral_fee_pct = (float)($_POST['referral_fee_percentage'] ?? 0);
        $analytics_fee = (float)($_POST['analytics_tier_fee'] ?? 0);
        $featured_fee = (float)($_POST['featured_listing_fee'] ?? 0);

        if ($subscription_fee < 0 || $referral_fee_pct < 0 || $analytics_fee < 0 || $featured_fee < 0) {
            $errors[] = 'All fees must be non-negative.';
        } else {
            $pdo->prepare('UPDATE platform_revenue_settings SET subscription_fee = ?, referral_fee_percentage = ?, analytics_tier_fee = ?, featured_listing_fee = ? WHERE id = 1')
                ->execute([$subscription_fee, $referral_fee_pct, $analytics_fee, $featured_fee]);
            log_audit($pdo, (int)$user['business_id'], (int)$user['id'], 'revenue_settings_updated', 'platform_revenue_settings', 1, null, ['subscription_fee' => $subscription_fee, 'referral_fee_percentage' => $referral_fee_pct, 'analytics_tier_fee' => $analytics_fee, 'featured_listing_fee' => $featured_fee], $_SERVER['REMOTE_ADDR'] ?? null);
            $success = 'Revenue settings updated.';
        }
    }

    if ($section === 'seasonality') {
        $min_referrals = max(1, (int)($_POST['minimum_referrals'] ?? 6));
        $min_months = max(1, (int)($_POST['minimum_active_months'] ?? 3));
        $high_mult = (float)($_POST['high_season_multiplier'] ?? 1.30);
        $low_mult = (float)($_POST['low_season_multiplier'] ?? 0.70);
        $high_adj = (float)($_POST['high_season_adjustment'] ?? 1.00);
        $low_adj = (float)($_POST['low_season_adjustment'] ?? -0.50);
        $conv_bonus = (float)($_POST['conversion_bonus_threshold'] ?? 15.00);
        $conv_penalty = (float)($_POST['conversion_penalty_threshold'] ?? 20.00);
        $conv_adj = (float)($_POST['conversion_adjustment'] ?? 0.50);

        $pdo->prepare('UPDATE seasonality_settings SET minimum_referrals = ?, minimum_active_months = ?, high_season_multiplier = ?, low_season_multiplier = ?, high_season_adjustment = ?, low_season_adjustment = ?, conversion_bonus_threshold = ?, conversion_penalty_threshold = ?, conversion_adjustment = ?, updated_by_user_id = ? WHERE id = 1')
            ->execute([$min_referrals, $min_months, $high_mult, $low_mult, $high_adj, $low_adj, $conv_bonus, $conv_penalty, $conv_adj, $user['id']]);
        log_audit($pdo, (int)$user['business_id'], (int)$user['id'], 'seasonality_settings_updated', 'seasonality_settings', 1, null, ['minimum_referrals' => $min_referrals], $_SERVER['REMOTE_ADDR'] ?? null);
        $success = 'Seasonality settings updated.';
    }

    if ($section === 'config') {
        $referral_expiry = max(0, (int)($_POST['referral_expiry_days'] ?? 30));
        $invite_only = isset($_POST['invite_only']) ? 1 : 0;
        $invite_code = trim($_POST['invite_code'] ?? '');
        $admin_emails = trim($_POST['admin_emails'] ?? '');

        // Store config in database instead of putenv (which has no effect)
        $pdo->exec("CREATE TABLE IF NOT EXISTS platform_config (
            config_key VARCHAR(100) PRIMARY KEY,
            config_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $upsert = $pdo->prepare('INSERT INTO platform_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()');
        $upsert->execute(['referral_expiry_days', (string)$referral_expiry]);
        $upsert->execute(['invite_only_registration', (string)$invite_only]);
        $upsert->execute(['registration_invite_code', $invite_code]);
        $upsert->execute(['platform_admin_emails', $admin_emails]);

        log_audit($pdo, (int)$user['business_id'], (int)$user['id'], 'platform_config_updated', 'platform_config', 0, null, [
            'referral_expiry_days' => $referral_expiry,
            'invite_only' => $invite_only,
            'invite_code' => $invite_code,
            'admin_emails' => $admin_emails
        ], $_SERVER['REMOTE_ADDR'] ?? null);
        $success = 'Platform configuration updated.';
    }
}

$revenue = platform_revenue_settings($pdo);
$seasonality = seasonality_settings($pdo);

// Load config from database
$pdo->exec("CREATE TABLE IF NOT EXISTS platform_config (
    config_key VARCHAR(100) PRIMARY KEY,
    config_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$config_rows = $pdo->query('SELECT config_key, config_value FROM platform_config')->fetchAll(PDO::FETCH_KEY_PAIR);
$config_referral_expiry = $config_rows['referral_expiry_days'] ?? (string)REFERRAL_EXPIRY_DAYS;
$config_invite_only = ($config_rows['invite_only_registration'] ?? (INVITE_ONLY_REGISTRATION ? '1' : '0')) === '1';
$config_invite_code = $config_rows['registration_invite_code'] ?? REGISTRATION_INVITE_CODE;
$config_admin_emails = $config_rows['platform_admin_emails'] ?? PLATFORM_ADMIN_EMAILS;

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, 0, $current_page, 1); ?>

<?php echo render_breadcrumbs(['Admin Dashboard' => 'index.php', 'System Settings' => false], 1); ?>
<?php echo render_page_header('System Settings', 'Configure platform-wide revenue, seasonality, and system settings.'); ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Revenue Settings</h5></div>
            <div class="card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="section" value="revenue">
                    <div class="mb-3">
                        <label class="form-label">Monthly Subscription Fee (RWF)</label>
                        <input type="number" step="0.01" name="subscription_fee" class="form-control" value="<?php echo htmlspecialchars($revenue['subscription_fee']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Referral Fee Percentage (%)</label>
                        <input type="number" step="0.01" name="referral_fee_percentage" class="form-control" value="<?php echo htmlspecialchars($revenue['referral_fee_percentage']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Analytics Tier Fee (RWF)</label>
                        <input type="number" step="0.01" name="analytics_tier_fee" class="form-control" value="<?php echo htmlspecialchars($revenue['analytics_tier_fee']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Featured Listing Fee (RWF)</label>
                        <input type="number" step="0.01" name="featured_listing_fee" class="form-control" value="<?php echo htmlspecialchars($revenue['featured_listing_fee']); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Save Revenue Settings</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Seasonality Settings</h5></div>
            <div class="card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="section" value="seasonality">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">Min Referrals</label>
                            <input type="number" name="minimum_referrals" class="form-control" value="<?php echo htmlspecialchars($seasonality['minimum_referrals']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Min Active Months</label>
                            <input type="number" name="minimum_active_months" class="form-control" value="<?php echo htmlspecialchars($seasonality['minimum_active_months']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">High Season Multiplier</label>
                            <input type="number" step="0.01" name="high_season_multiplier" class="form-control" value="<?php echo htmlspecialchars($seasonality['high_season_multiplier']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Low Season Multiplier</label>
                            <input type="number" step="0.01" name="low_season_multiplier" class="form-control" value="<?php echo htmlspecialchars($seasonality['low_season_multiplier']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">High Season Adjustment</label>
                            <input type="number" step="0.01" name="high_season_adjustment" class="form-control" value="<?php echo htmlspecialchars($seasonality['high_season_adjustment']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Low Season Adjustment</label>
                            <input type="number" step="0.01" name="low_season_adjustment" class="form-control" value="<?php echo htmlspecialchars($seasonality['low_season_adjustment']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Conversion Bonus Threshold (%)</label>
                            <input type="number" step="0.01" name="conversion_bonus_threshold" class="form-control" value="<?php echo htmlspecialchars($seasonality['conversion_bonus_threshold']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Conversion Penalty Threshold (%)</label>
                            <input type="number" step="0.01" name="conversion_penalty_threshold" class="form-control" value="<?php echo htmlspecialchars($seasonality['conversion_penalty_threshold']); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Conversion Adjustment</label>
                            <input type="number" step="0.01" name="conversion_adjustment" class="form-control" value="<?php echo htmlspecialchars($seasonality['conversion_adjustment']); ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">Save Seasonality Settings</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><h5 class="mb-0">Platform Configuration</h5></div>
    <div class="card-body">
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="section" value="config">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label">Referral Expiry (days, 0 = no expiry)</label>
                    <input type="number" name="referral_expiry_days" class="form-control" value="<?php echo htmlspecialchars($config_referral_expiry); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Platform Admin Emails</label>
                    <input type="text" name="admin_emails" class="form-control" value="<?php echo htmlspecialchars($config_admin_emails); ?>" placeholder="comma-separated emails">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Invite Code</label>
                    <input type="text" name="invite_code" class="form-control" value="<?php echo htmlspecialchars($config_invite_code); ?>">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="invite_only" id="inviteOnly" <?php echo $config_invite_only ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="inviteOnly">Invite-only registration</label>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary mt-3">Save Configuration</button>
        </form>
    </div>
</div>

<?php echo render_app_shell_end(); ?>
</body>
</html>
