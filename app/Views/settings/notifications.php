<?php
$n = $c['notifications'];
?>
<form method="post" class="settings-form">
    <?php echo csrf_field(); ?>
    <?php settings_card_open('Notification channels', 'Choose how GuestBridge reaches you.'); ?>
    <?php
    settings_toggle('email_notifications', 'Email notifications', !empty($n['email_notifications']));
    settings_toggle('sms_alerts', 'SMS / Mobile Money alerts', !empty($n['sms_alerts']));
    settings_toggle('booking_alerts', 'Booking alerts', !empty($n['booking_alerts']));
    settings_toggle('payment_alerts', 'Payment alerts', !empty($n['payment_alerts']));
    settings_toggle('marketing_emails', 'Marketing emails (opt-in)', !empty($n['marketing_emails']));
    settings_toggle('system_announcements', 'System announcements', !empty($n['system_announcements']));
    ?>
    <div class="settings-actions">
        <button type="submit" name="update_notifications" value="1" class="btn btn-primary">Save notifications</button>
    </div>
    <?php settings_card_close(); ?>
</form>
