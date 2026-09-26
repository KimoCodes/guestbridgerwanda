<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();
$user = current_user();
$pdo = db_connect();

$referral_id = intval($_GET['id'] ?? 0);
$business_id = tenant_business_id($user);
$referral = null;

if ($referral_id > 0) {
    $stmt = $pdo->prepare('SELECT r.*, b1.name AS source_business, b2.name AS target_business, b2.phone AS target_phone, s.name AS staff_name, c.status AS commission_status, c.amount AS commission_amount, c.month AS commission_month, p.agreement_text FROM referrals r
        JOIN businesses b1 ON r.source_business_id = b1.id
        JOIN businesses b2 ON r.target_business_id = b2.id
        LEFT JOIN staff s ON r.staff_id = s.id
        LEFT JOIN commissions c ON c.referral_id = r.id
        LEFT JOIN partnerships p ON ((p.business_id = r.source_business_id AND p.partner_business_id = r.target_business_id) OR (p.business_id = r.target_business_id AND p.partner_business_id = r.source_business_id))
        WHERE r.id = ? AND (r.source_business_id = ? OR r.target_business_id = ?)');
    $stmt->execute([$referral_id, $business_id, $business_id]);
    $referral = $stmt->fetch();
}

if (!$referral) {
    flash_set('You do not have access to this referral.');
    gb_redirect('dashboard.php');
}

expire_stale_referrals($pdo);
$is_source = (int) $referral['source_business_id'] === $business_id;
$is_target = (int) $referral['target_business_id'] === $business_id;

$pin_flash = null;
if (!empty($_SESSION['referral_pin_flash']['referral_id']) && (int) $_SESSION['referral_pin_flash']['referral_id'] === $referral_id) {
    $pin_flash = $_SESSION['referral_pin_flash']['pin'] ?? null;
    unset($_SESSION['referral_pin_flash']);
}

$confirm_message = null;
$confirm_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_partner') {
    require_csrf_post('view_referral.php?id=' . $referral_id);
    if (!$is_target) {
        $confirm_error = 'Only the receiving partner business can accept this referral.';
    } else {
        try {
            $services = ServiceContainer::getInstance($pdo);
            $services->referrals()->acceptReferral($referral_id, $business_id, (int) $user['id']);
            $confirm_message = 'Referral accepted successfully.';
            // Refresh referral data
            $stmt = $pdo->prepare('SELECT r.*, b1.name AS source_business, b2.name AS target_business, b2.phone AS target_phone, s.name AS staff_name, c.status AS commission_status, c.amount AS commission_amount, c.month AS commission_month, p.agreement_text FROM referrals r
                JOIN businesses b1 ON r.source_business_id = b1.id
                JOIN businesses b2 ON r.target_business_id = b2.id
                LEFT JOIN staff s ON r.staff_id = s.id
                LEFT JOIN commissions c ON c.referral_id = r.id
                LEFT JOIN partnerships p ON ((p.business_id = r.source_business_id AND p.partner_business_id = r.target_business_id) OR (p.business_id = r.target_business_id AND p.partner_business_id = r.source_business_id))
                WHERE r.id = ?');
            $stmt->execute([$referral_id]);
            $referral = $stmt->fetch();
        } catch (Exception $e) {
            $confirm_error = 'Unable to accept: ' . $e->getMessage();
        }
    }
}

$referral_link = referral_url($referral['referral_code']);
$offer_text = trim($referral['note'] ?: $referral['agreement_text'] ?: 'Guest referral request');
$staff_name = $referral['staff_name'] ?: $user['name'];
$benefit_text = !empty($referral['guest_benefit_description']) ? "\n🎁 Guest Benefit: {$referral['guest_benefit_description']}\n" : '';

// Build status-specific message based on referral status
$status_messages = [
    'created' => "🏨 New Referral\n\n"
        . "From: {$referral['source_business']}\n"
        . "To: {$referral['target_business']}\n"
        . "Referral Code: {$referral['referral_code']}\n"
        . "Staff: {$staff_name}\n"
        . $benefit_text
        . "Offer: {$referral['guest_name']} - " . ($referral['guest_benefit_description'] ?? 'N/A') . "\n"
        . "Transaction Value: RWF " . format_money($referral['transaction_amount'] ?? $referral['estimated_value']) . "\n"
        . "Commission: {$referral['commission_percentage']}%\n\n"
        . "Please accept this referral.",
    
    'accepted' => "✅ Referral Accepted\n\n"
        . "I've accepted the referral for:\n{$referral['target_business']}\n\n"
        . "From: {$referral['source_business']}\n"
        . "Referral Code: {$referral['referral_code']}\n"
        . "Staff: {$staff_name}\n"
        . "Guest: {$referral['guest_name']}\n\n"
        . "I'll confirm when they visit.",
    
    'visited' => "👋 Guest Visit Confirmed\n\n"
        . "The referred guest has visited:\n{$referral['target_business']}\n\n"
        . "From: {$referral['source_business']}\n"
        . "Referral Code: {$referral['referral_code']}\n"
        . "Guest: {$referral['guest_name']}\n\n"
        . "Please record their transaction.",
    
    'converted' => "💰 Transaction Recorded\n\n"
        . "I've recorded a transaction for:\n{$referral['target_business']}\n\n"
        . "Referral Code: {$referral['referral_code']}\n"
        . "Guest: {$referral['guest_name']}\n\n"
        . "The commission will be calculated automatically.",
    
    'settled' => "✅ Referral Complete\n\n"
        . "The referral has been settled:\n{$referral['target_business']}\n\n"
        . "From: {$referral['source_business']}\n"
        . "Referral Code: {$referral['referral_code']}\n"
        . "Guest: {$referral['guest_name']}\n\n"
        . "Thank you for using GuestBridge Rwanda!",
];

$current_status = $referral['status'] ?? 'created';
$whatsapp_message = $status_messages[$current_status] ?? $status_messages['created'];

// Add PIN if this is a new referral being shared
if ($current_status === 'created' && isset($pin_flash) && $pin_flash) {
    $whatsapp_message .= "\n\n🔐 Partner PIN: {$pin_flash}\n(Enter this PIN on the referral page to confirm)";
}

$whatsapp_message .= "\n\nOpen referral:\n{$referral_link}";
$whatsapp_text = rawurlencode($whatsapp_message);
$target_whatsapp_phone = whatsapp_phone_number($referral['target_phone'] ?? '');
$partner_whatsapp_url = $target_whatsapp_phone ? 'https://wa.me/' . $target_whatsapp_phone . '?text=' . $whatsapp_text : 'https://wa.me/?text=' . $whatsapp_text;

$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'view_referral.php';

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Detail - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Referrals' => 'history.php', 'Detail' => false]); ?>
            <?php echo render_page_header(
                'Referral Detail',
                'Share the referral QR code or WhatsApp message with your partner.',
                [
                    ['href' => gb_url('voucher_referral.php?id=' . $referral_id), 'label' => 'Print / Download Voucher', 'style' => 'primary'],
                    ['href' => 'create_referral.php', 'label' => '+ New Referral', 'style' => 'success'],
                ]
            ); ?>
            <?php if ($confirm_message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($confirm_message); ?></div>
            <?php endif; ?>
            <?php if ($confirm_error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($confirm_error); ?></div>
            <?php endif; ?>
            <?php if ($pin_flash && $is_source): ?>
                <div class="alert alert-warning">
                    <strong>Partner PIN (share privately with <?php echo htmlspecialchars($referral['target_business']); ?>):</strong>
                    <span class="fs-4 ms-2"><?php echo htmlspecialchars($pin_flash); ?></span>
                    <p class="small mb-0 mt-2">The partner enters this PIN on the referral link or confirms while logged in. It is shown once.</p>
                </div>
            <?php endif; ?>
            <?php if ($is_target && $referral['status'] === 'created' && !referral_is_expired($referral)): ?>
                <div class="card mb-3 border-success">
                    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div>
                            <strong>Incoming referral</strong>
                            <p class="text-muted small mb-0">Accept this referral to proceed with the guest visit.</p>
                        </div>
                        <form method="post">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="confirm_partner">
                            <button class="btn btn-success">Accept Referral</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            <div class="row gy-4">
                <div class="col-12 col-lg-5">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Referral Information</h3>
                            <dl class="row mb-0">
                                <dt class="col-5">Referral code</dt>
                                <dd class="col-7"><strong><?php echo htmlspecialchars($referral['referral_code']); ?></strong></dd>
                                <dt class="col-5">Source</dt>
                                <dd class="col-7"><?php echo htmlspecialchars($referral['source_business']); ?></dd>
                                <dt class="col-5">Partner</dt>
                                <dd class="col-7"><?php echo htmlspecialchars($referral['target_business']); ?></dd>
                                <dt class="col-5">Partner phone</dt>
                                <dd class="col-7"><?php echo htmlspecialchars($referral['target_phone'] ?: 'Not set'); ?></dd>
                                <dt class="col-5">Staff</dt>
                                <dd class="col-7"><?php echo htmlspecialchars($referral['staff_name'] ?: $user['name']); ?></dd>
                                <dt class="col-5">Status</dt>
                                <dd class="col-7"><span class="badge bg-<?php echo referral_status_color($referral['status']); ?>"><?php echo htmlspecialchars(referral_status_label($referral['status'])); ?></span></dd>
                                <dt class="col-5">Commission</dt>
                                <dd class="col-7"><?php echo htmlspecialchars($referral['commission_percentage']); ?>%</dd>
                                <dt class="col-5">Transaction Value</dt>
                                <dd class="col-7">RWF <?php echo format_money($referral['transaction_amount'] ?? $referral['estimated_value']); ?></dd>
                                <?php if (!empty($referral['transaction_amount'])): ?>
                                <dt class="col-5">Commission Amount</dt>
                                <dd class="col-7">RWF <?php echo format_money($referral['commission_amount']); ?></dd>
                                <?php else: ?>
                                <dt class="col-5">Estimated Fee</dt>
                                <dd class="col-7">RWF <?php echo format_money(($referral['estimated_value'] * $referral['commission_percentage']) / 100); ?></dd>
                                <?php endif; ?>
                                <?php if (!empty($referral['guest_benefit_description'])): ?>
                                <dt class="col-5">Guest Benefit</dt>
                                <dd class="col-7"><strong class="text-success"><?php echo htmlspecialchars($referral['guest_benefit_description']); ?></strong></dd>
                                <?php endif; ?>
                                <?php if (!empty($referral['guest_name'])): ?>
                                <dt class="col-5">Guest Name</dt>
                                <dd class="col-7"><?php echo htmlspecialchars($referral['guest_name']); ?></dd>
                                <?php endif; ?>
                                <?php if (!empty($referral['expires_at'])): ?>
                                <dt class="col-5">Expires</dt>
                                <dd class="col-7"><?php echo htmlspecialchars(date('d M Y', strtotime($referral['expires_at']))); ?></dd>
                                <?php endif; ?>
                                <dt class="col-5">Commission status</dt>
                                <dd class="col-7"><span class="badge bg-<?php echo $referral['commission_status'] === 'confirmed' ? 'success' : ($referral['commission_status'] === 'reconciled' ? 'dark' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($referral['commission_status'] ?? 'pending')); ?></span></dd>
                                <dt class="col-5">Commission month</dt>
                                <dd class="col-7"><?php echo htmlspecialchars($referral['commission_month'] ?? 'n/a'); ?></dd>
                            </dl>
                        </div>
                    </div>
                    <?php
                    // Fetch uploaded receipt for this referral
                    $receipt_stmt = $pdo->prepare('SELECT id, original_filename, file_type, file_size, receipt_total, status, created_at FROM receipts WHERE referral_id = ? ORDER BY created_at DESC LIMIT 1');
                    $receipt_stmt->execute([$referral_id]);
                    $receipt = $receipt_stmt->fetch();
                    ?>
                    <?php if ($receipt): ?>
                    <div class="card mt-3">
                        <div class="card-header bg-light"><h6 class="mb-0"><i class="bi bi-receipt"></i> Uploaded Receipt</h6></div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-5">File</dt>
                                <dd class="col-7">
                                    <a href="<?php echo htmlspecialchars(gb_url('receipt.php?id=' . intval($receipt['id']))); ?>" target="_blank" class="text-decoration-none">
                                        <i class="bi bi-file-earmark-pdf"></i> <?php echo htmlspecialchars($receipt['original_filename']); ?>
                                    </a>
                                    <span class="badge bg-secondary ms-1"><?php echo strtoupper(str_replace('image/', '', $receipt['file_type'])); ?></span>
                                </dd>
                                <?php if ($receipt['receipt_total'] > 0): ?>
                                <dt class="col-5">Receipt Total</dt>
                                <dd class="col-7">RWF <?php echo format_money($receipt['receipt_total']); ?></dd>
                                <?php endif; ?>
                                <dt class="col-5">Uploaded</dt>
                                <dd class="col-7"><?php echo htmlspecialchars(date('d M Y H:i', strtotime($receipt['created_at']))); ?></dd>
                            </dl>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($referral['note']): ?>
                    <div class="card mt-3">
                        <div class="card-body">
                            <h3 class="text-section-heading">Referral Note</h3>
                            <p class="mb-0 text-muted"><?php echo nl2br(htmlspecialchars($referral['note'])); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="card mt-3">
                        <div class="card-body">
                            <h3 class="text-section-heading">Timeline</h3>
                            <p class="mb-1"><strong>Created:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($referral['created_at']))); ?></p>
                            <?php if (!empty($referral['accepted_at'])): ?>
                                <p class="mb-1"><strong>Accepted:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($referral['accepted_at']))); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($referral['verified_at'])): ?>
                                <p class="mb-1"><strong>Verified:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($referral['verified_at']))); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($referral['visited_at'])): ?>
                                <p class="mb-1"><strong>Visited:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($referral['visited_at']))); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($referral['converted_at'])): ?>
                                <p class="mb-1"><strong>Converted:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($referral['converted_at']))); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($referral['settled_at'])): ?>
                                <p class="mb-1"><strong>Settled:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($referral['settled_at']))); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($referral['used_at'])): ?>
                                <p class="mb-1"><strong>Used:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($referral['used_at']))); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($referral['redeemed_at'])): ?>
                                <p class="mb-1"><strong>Redeemed:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($referral['redeemed_at']))); ?></p>
                            <?php endif; ?>
                            <p class="mb-0"><strong>Current status:</strong> <?php echo htmlspecialchars(referral_status_label($referral['status'])); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-7">
                    <div class="card mb-4">
                        <div class="card-body">
                            <h3 class="text-section-heading">QR Code</h3>
                            <div id="qrcode" class="qr-box mx-auto" style="width:200px;height:200px;"></div>
                            <p class="text-muted small mt-3 mb-0">Scan with mobile camera or WhatsApp to open the referral page.</p>
                            <div class="mt-3 d-grid gap-2">
                                <a href="<?php echo htmlspecialchars(gb_url('voucher_referral.php?id=' . $referral_id)); ?>" class="btn btn-primary btn-sm">
                                    View / Print Voucher
                                </a>
                                <a href="<?php echo htmlspecialchars(gb_url('voucher_referral.php?id=' . $referral_id . '&action=print')); ?>" target="_blank" class="btn btn-outline-primary btn-sm" onclick="setTimeout(function(){ window.print(); }, 500);">
                                    Print Referral
                                </a>
                                <?php if (in_array($referral['status'], ['created', 'verified', 'accepted'], true)): ?>
                                <a href="<?php echo htmlspecialchars(gb_url('referral/verify/' . urlencode($referral['secure_token'] ?? $referral['referral_code']))); ?>" class="btn btn-outline-success btn-sm">
                                    Open Verification Page
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-body">
                            <h3 class="text-section-heading">Share with Partner</h3>
                            <div class="mb-3">
                                <label class="form-label">Referral Link</label>
                                <div class="input-group">
                                    <input type="text" readonly class="form-control" value="<?php echo htmlspecialchars($referral_link); ?>">
                                    <button class="btn btn-outline-secondary" type="button" id="copy-link">Copy</button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">WhatsApp Message</label>
                                <textarea id="whatsapp-message" class="form-control mb-3" rows="7" readonly><?php echo htmlspecialchars($whatsapp_message); ?></textarea>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a class="btn btn-success" href="<?php echo htmlspecialchars($partner_whatsapp_url); ?>" target="_blank">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" style="margin-right:4px;" viewBox="0 0 16 16"><path d="M8 0a8 8 0 0 0-2.916 15.452c-.056-.055-.556-.573-1.053-.818-.47-.223-.968-.023-1.321.228L2.016 14.2l-.002-.003a.5.5 0 0 1-.044-.216l.004-.015c.018-.064.053-.13.106-.18a.5.5 0 0 1 .416-.277c1.22-.047 2.44.57 3.28 1.526a.5.5 0 0 1-.063.765c-.652.652-1.72.938-2.744.938-1.046 0-2.086-.342-2.84-.97L.09 15.12A7.49 7.49 0 0 0 8 16c1.91 0 3.68-.73 5.03-1.94a.5.5 0 0 1 .72.028C13.4 14.7 12.12 15 10.78 15c-1.11 0-2.16-.348-3.02-.96l-.12-.087C7.15 13.64 6.5 12.6 6.5 11.5c0-1.08.51-2.06 1.35-2.77a.5.5 0 0 1 .7.012c.44.45.56.96.56 1.54 0 1.07-.5 1.95-1.4 1.95H4.4a.5.5 0 0 1-.5-.5c0-.55.45-1 1-1 .55 0 1 .45 1 1 .5.5.5.5.5.5h.1c1.1 0 2-.9 2-2 0-1.11-.9-2-2-2-.55 0-1 .45-1 1 0 .55.45 1 1 1z"/></svg>
                                    Share on WhatsApp
                                </a>
                                <button class="btn btn-outline-secondary" type="button" id="copy-message">Copy Message</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
<script>
new QRCode(document.getElementById('qrcode'), {
    text: <?php echo json_encode($referral_link); ?>,
    width: 200,
    height: 200,
});

document.getElementById('copy-link').addEventListener('click', function() {
    navigator.clipboard.writeText(<?php echo json_encode($referral_link); ?>);
    document.getElementById('copy-link').textContent = 'Copied!';
    setTimeout(function() {
        document.getElementById('copy-link').textContent = 'Copy';
    }, 1500);
});

document.getElementById('copy-message').addEventListener('click', function() {
    navigator.clipboard.writeText(document.getElementById('whatsapp-message').value);
    document.getElementById('copy-message').textContent = 'Copied!';
    setTimeout(function() {
        document.getElementById('copy-message').textContent = 'Copy Message';
    }, 1500);
});
</script>
<?php echo render_app_shell_end(); ?>