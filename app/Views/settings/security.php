<?php
$two = $c['two_fa'];
?>
<form method="post" class="settings-form">
    <?php echo csrf_field(); ?>
    <?php settings_card_open('Change password', 'Verify your current password before setting a new one.'); ?>
    <div class="settings-grid-2">
        <div class="settings-field">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div></div>
        <div class="settings-field">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="settings-field">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
        </div>
    </div>
    <div class="settings-actions">
        <button type="submit" name="change_password" value="1" class="btn btn-primary">Update password</button>
    </div>
    <?php settings_card_close(); ?>
</form>

<?php settings_card_open('Two-factor authentication', 'Placeholder — connect TOTP app in production.'); ?>
<p class="text-muted mb-2">Status: <strong><?php echo !empty($two['enabled']) ? 'Enabled' : 'Disabled'; ?></strong></p>
<form method="post" style="display:inline;">
    <?php echo csrf_field(); ?>
    <button type="submit" name="toggle_2fa" value="1" class="btn btn-outline-secondary">
        <?php echo !empty($two['enabled']) ? 'Disable 2FA' : 'Enable 2FA (placeholder)'; ?>
    </button>
</form>
<?php settings_card_close(); ?>

<?php settings_card_open('Sessions', 'Sign out all other devices. Current session stays active.'); ?>
<form method="post">
    <?php echo csrf_field(); ?>
    <button type="submit" name="logout_all_sessions" value="1" class="btn btn-warning">Logout all other devices</button>
</form>
<?php if (!empty($c['active_sessions'])): ?>
<div class="settings-table-wrap mt-3">
    <table class="settings-table">
        <thead><tr><th>IP</th><th>Last active</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($c['active_sessions'], 0, 5) as $s): ?>
            <tr>
                <td><?php echo sh($s['ip_address'] ?? '—'); ?></td>
                <td><?php echo sh($s['last_active_at'] ?? $s['created_at'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php settings_card_close(); ?>

<?php settings_card_open('Login history', 'Recent sign-ins for your account.'); ?>
<div class="settings-table-wrap">
    <table class="settings-table">
        <thead><tr><th>When</th><th>IP</th><th>Device</th></tr></thead>
        <tbody>
        <?php if (empty($c['login_history'])): ?>
            <tr><td colspan="3">No login history yet.</td></tr>
        <?php else: ?>
            <?php foreach ($c['login_history'] as $row): ?>
            <tr>
                <td><?php echo sh($row['logged_in_at'] ?? ''); ?></td>
                <td><?php echo sh($row['ip_address'] ?? ''); ?></td>
                <td><?php echo sh(trim(($row['browser'] ?? '') . ' ' . ($row['os'] ?? '') . ' ' . ($row['device'] ?? ''))); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php settings_card_close(); ?>
