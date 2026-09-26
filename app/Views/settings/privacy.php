<?php
$biz = $c['business'];
$cons = $c['consents'];
?>
<form method="post" class="settings-form">
    <?php echo csrf_field(); ?>
    <?php settings_card_open('Visibility & consent'); ?>
    <?php settings_toggle('public_profile', 'Public hotel profile on partner directory', !empty($biz['public_profile'])); ?>
    <?php settings_toggle('marketing_consent', 'Marketing & tracking consent', !empty($biz['marketing_consent'])); ?>
    <hr>
    <p class="small text-muted mb-2">Legal consents</p>
    <?php settings_toggle('consent_terms', 'Terms of service', !empty($cons['terms'])); ?>
    <?php settings_toggle('consent_privacy', 'Privacy policy', !empty($cons['privacy'])); ?>
    <?php settings_toggle('consent_marketing', 'Marketing communications', !empty($cons['marketing'])); ?>
    <div class="settings-actions">
        <button type="submit" name="update_privacy" value="1" class="btn btn-primary">Save privacy settings</button>
    </div>
    <?php settings_card_close(); ?>
</form>

<?php settings_card_open('Data control', 'Export or request deletion of your account data.'); ?>
<?php if ($c['export_pending']): ?>
    <p class="alert alert-info">Export request status: <strong><?php echo sh($c['export_pending']); ?></strong></p>
<?php endif; ?>
<div class="settings-actions">
    <form method="post" style="display:inline;">
        <?php echo csrf_field(); ?>
        <button type="submit" name="request_data_export" value="1" class="btn btn-outline-secondary">Request data export</button>
    </form>
    <form method="post" style="display:inline;">
        <?php echo csrf_field(); ?>
        <button type="submit" name="download_data_export" value="1" class="btn btn-outline-primary">Download my data (JSON)</button>
    </form>
    <form method="post" style="display:inline;" onsubmit="return confirm('Submit account deletion request? This is reviewed by an admin.');">
        <?php echo csrf_field(); ?>
        <button type="submit" name="request_account_deletion" value="1" class="btn btn-outline-danger">Request account deletion</button>
    </form>
</div>
<?php settings_card_close(); ?>
