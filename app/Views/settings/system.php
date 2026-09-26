<?php
$p = $c['preferences'];
$acc = $c['account'];
?>
<form method="post" class="settings-form">
    <?php echo csrf_field(); ?>
    <?php settings_card_open('System preferences', 'Theme, language, timezone, and dashboard defaults.'); ?>
    <div class="settings-grid-2">
        <div class="settings-field">
            <label for="theme">Theme</label>
            <select id="theme" name="theme">
                <option value="light"<?php echo ($p['theme'] ?? $acc['theme'] ?? 'light') === 'light' ? ' selected' : ''; ?>>Light</option>
                <option value="dark"<?php echo ($p['theme'] ?? '') === 'dark' ? ' selected' : ''; ?>>Dark</option>
            </select>
        </div>
        <div class="settings-field">
            <label for="sys_language">Language</label>
            <select id="sys_language" name="language">
                <option value="en"<?php echo ($p['language'] ?? 'en') === 'en' ? ' selected' : ''; ?>>English</option>
                <option value="fr"<?php echo ($p['language'] ?? '') === 'fr' ? ' selected' : ''; ?>>Français</option>
            </select>
        </div>
        <div class="settings-field">
            <label for="timezone">Timezone</label>
            <input type="text" id="timezone" name="timezone" value="<?php echo sh($p['timezone'] ?? 'Africa/Kigali'); ?>">
        </div>
        <div class="settings-field">
            <label for="ui_density">UI density</label>
            <select id="ui_density" name="ui_density">
                <option value="default"<?php echo ($p['ui_density'] ?? 'default') === 'default' ? ' selected' : ''; ?>>Standard</option>
                <option value="compact"<?php echo ($p['ui_density'] ?? '') === 'compact' ? ' selected' : ''; ?>>Compact</option>
                <option value="comfortable"<?php echo ($p['ui_density'] ?? '') === 'comfortable' ? ' selected' : ''; ?>>Comfortable</option>
            </select>
        </div>
        <div class="settings-field">
            <label for="default_dashboard_view">Default dashboard view</label>
            <select id="default_dashboard_view" name="default_dashboard_view">
                <option value="overview"<?php echo ($p['default_dashboard_view'] ?? 'overview') === 'overview' ? ' selected' : ''; ?>>Overview</option>
                <option value="bookings"<?php echo ($p['default_dashboard_view'] ?? '') === 'bookings' ? ' selected' : ''; ?>>Bookings</option>
                <option value="finance"<?php echo ($p['default_dashboard_view'] ?? '') === 'finance' ? ' selected' : ''; ?>>Finance</option>
            </select>
        </div>
    </div>
    <div class="settings-actions">
        <button type="submit" name="update_system" value="1" class="btn btn-primary">Save system preferences</button>
    </div>
    <?php settings_card_close(); ?>
</form>
