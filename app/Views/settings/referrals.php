<?php
$ref = $c['referrals'];
?>
<?php settings_card_open('Your referral presence', 'Share your public listing and track network activity.'); ?>
<div class="settings-field">
    <label>Public listing URL</label>
    <div class="settings-readonly" style="word-break:break-all;">
        <a href="<?php echo sh($ref['referral_link']); ?>" target="_blank" rel="noopener"><?php echo sh($ref['referral_link']); ?></a>
    </div>
</div>
<div class="settings-field">
    <label>Referral code</label>
    <div class="settings-readonly"><code><?php echo sh($ref['referral_code']); ?></code></div>
</div>
<div class="settings-stats">
    <div class="settings-stat"><strong><?php echo (int) $ref['outbound_referrals']; ?></strong><span>Outbound referrals sent</span></div>
    <div class="settings-stat"><strong><?php echo (int) $ref['place_referral_codes']; ?></strong><span>Place referral codes</span></div>
</div>
<p class="text-muted">Referral earnings and withdrawal thresholds are reconciled in <a href="/guestbridgerwanda/commissions.php">Commissions</a>. Multi-tier levels apply when configured platform-wide.</p>
<?php settings_card_close(); ?>
