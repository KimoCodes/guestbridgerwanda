<?php
$biz = $c['business'];
if (!$c['is_manager']): ?>
<p class="text-muted">Managers only.</p>
<?php return; endif; ?>
<form method="post" class="settings-form">
    <?php echo csrf_field(); ?>
    <?php settings_card_open('Business analytics', 'Dashboard and reporting preferences for your hotel.'); ?>
    <?php settings_toggle('analytics_enabled', 'Enable analytics dashboard', !empty($biz['analytics_enabled'])); ?>
    <div class="settings-field">
        <label for="report_export_format">Default export format</label>
        <select id="report_export_format" name="report_export_format">
            <option value="csv"<?php echo ($biz['report_export_format'] ?? 'csv') === 'csv' ? ' selected' : ''; ?>>CSV</option>
            <option value="pdf"<?php echo ($biz['report_export_format'] ?? '') === 'pdf' ? ' selected' : ''; ?>>PDF</option>
        </select>
    </div>
    <p class="text-muted small">Revenue reports (daily / weekly / monthly), occupancy tracking, and KPI tiles are available on the <a href="/guestbridgerwanda/dashboard.php">Dashboard</a> when analytics is enabled.</p>
    <div class="settings-actions">
        <button type="submit" name="update_analytics" value="1" class="btn btn-primary">Save analytics settings</button>
    </div>
    <?php settings_card_close(); ?>
</form>
