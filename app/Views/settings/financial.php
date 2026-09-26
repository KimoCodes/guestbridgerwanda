<?php
$biz = $c['business'];
$fin = $c['financial'];
$rate = $biz['default_commission_rate'] ?? $biz['commission_rate'] ?? 10;
?>
<form method="post" class="settings-form">
    <?php echo csrf_field(); ?>
    <?php settings_card_open('Payout & currency', 'How you receive commissions and referral earnings.'); ?>
    <div class="settings-grid-2">
        <div class="settings-field">
            <label for="payout_method">Payout method</label>
            <select id="payout_method" name="payout_method">
                <option value="mobile_money"<?php echo ($biz['payout_method'] ?? '') === 'mobile_money' ? ' selected' : ''; ?>>Mobile Money</option>
                <option value="bank"<?php echo ($biz['payout_method'] ?? '') === 'bank' ? ' selected' : ''; ?>>Bank transfer</option>
                <option value="paypal"<?php echo ($biz['payout_method'] ?? '') === 'paypal' ? ' selected' : ''; ?>>PayPal</option>
            </select>
        </div>
        <div class="settings-field">
            <label for="currency_pref">Currency preference</label>
            <select id="currency_pref" name="currency_pref">
                <?php foreach (['RWF', 'USD', 'EUR'] as $cur): ?>
                <option value="<?php echo $cur; ?>"<?php echo ($biz['currency_pref'] ?? 'RWF') === $cur ? ' selected' : ''; ?>><?php echo $cur; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="settings-field">
        <label for="payment_account">Payment account details</label>
        <input type="text" id="payment_account" name="payment_account" placeholder="MoMo number, IBAN, or PayPal email"
               value="<?php echo sh($biz['payment_account'] ?? ''); ?>">
    </div>
    <div class="settings-actions">
        <button type="submit" name="update_financial" value="1" class="btn btn-primary">Save payment settings</button>
    </div>
    <?php settings_card_close(); ?>
</form>

<?php settings_card_open('Earnings overview', 'Read-only balances from your commission ledger.'); ?>
<div class="settings-stats">
    <div class="settings-stat"><strong><?php echo number_format($fin['month_earned'], 0); ?> RWF</strong><span>Earned this month</span></div>
    <div class="settings-stat"><strong><?php echo number_format($fin['pending_earned'], 0); ?> RWF</strong><span>Pending</span></div>
    <div class="settings-stat"><strong><?php echo number_format($fin['total_confirmed'], 0); ?> RWF</strong><span>Confirmed total</span></div>
    <div class="settings-stat"><strong><?php echo sh((string) $rate); ?>%</strong><span>Commission rate (system)</span></div>
</div>
<?php settings_card_close(); ?>

<?php settings_card_open('Transactions', 'Recent booking and payout movements.'); ?>
<div class="settings-table-wrap">
    <table class="settings-table">
        <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($fin['transactions'])): ?>
            <tr><td colspan="4">No transactions yet.</td></tr>
        <?php else: ?>
            <?php foreach ($fin['transactions'] as $t): ?>
            <tr>
                <td><?php echo sh($t['transaction_date'] ?? ''); ?></td>
                <td><?php echo sh($t['type'] ?? ''); ?></td>
                <td><?php echo number_format((float)($t['amount'] ?? 0), 0); ?> <?php echo sh($t['currency'] ?? 'RWF'); ?></td>
                <td><?php echo sh($t['status'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<p class="mt-2"><a href="/guestbridgerwanda/transactions.php">View all transactions →</a> · <a href="/guestbridgerwanda/commissions.php">Commissions →</a></p>
<?php settings_card_close(); ?>

<?php settings_card_open('Invoices & disputes'); ?>
<div class="settings-grid-2">
    <div>
        <h3 class="h6">Invoices</h3>
        <div class="settings-table-wrap">
            <table class="settings-table">
                <thead><tr><th>#</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (empty($fin['invoices'])): ?>
                    <tr><td colspan="3">None</td></tr>
                <?php else: foreach ($fin['invoices'] as $inv): ?>
                    <tr>
                        <td><?php echo sh($inv['invoice_number'] ?? $inv['id']); ?></td>
                        <td><?php echo number_format((float)($inv['amount'] ?? 0), 0); ?></td>
                        <td><?php echo sh($inv['status'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div>
        <h3 class="h6">Disputes</h3>
        <div class="settings-table-wrap">
            <table class="settings-table">
                <thead><tr><th>Subject</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (empty($fin['disputes'])): ?>
                    <tr><td colspan="2">No open disputes</td></tr>
                <?php else: foreach ($fin['disputes'] as $d): ?>
                    <tr>
                        <td><?php echo sh($d['subject'] ?? 'Dispute'); ?></td>
                        <td><?php echo sh($d['status'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php settings_card_close(); ?>
