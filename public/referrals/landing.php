<?php
/**
 * Public referral landing page — QR, share link, WhatsApp, and partner confirmation.
 * Open via referral.php?code=YOUR_CODE (from QR or shared link).
 */
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Services/ServiceContainer.php';
db_init();
$pdo = db_connect();
expire_stale_referrals($pdo);

$code = trim($_GET['code'] ?? '');
$referral = null;
$error = null;
$message = null;

if ($code !== '') {
    $stmt = $pdo->prepare('SELECT r.*, b1.name AS source_business, b2.name AS target_business, b2.phone AS target_phone,
        s.name AS staff_name, p.agreement_text
        FROM referrals r
        JOIN businesses b1 ON r.source_business_id = b1.id
        JOIN businesses b2 ON r.target_business_id = b2.id
        LEFT JOIN staff s ON r.staff_id = s.id
        LEFT JOIN partnerships p ON p.business_id = r.source_business_id AND p.partner_business_id = r.target_business_id
        WHERE r.referral_code = ? LIMIT 1');
    $stmt->execute([$code]);
    $referral = $stmt->fetch();
}

if ($code === '') {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest referral - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8">
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <h1 class="h4 mb-3">Guest referral</h1>
                    <p class="text-muted mb-4">Scan the QR code or open the link shared by the referring hotel. The URL should look like:</p>
                    <code class="d-block mb-4">referral.php?code=your-referral-code</code>
                    <a href="<?php echo htmlspecialchars(gb_url('login.php')); ?>" class="btn btn-primary">Staff login</a>
                    <a href="<?php echo htmlspecialchars(gb_url('partner_places.php')); ?>" class="btn btn-outline-secondary ms-2">Partner places</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

if (!$referral) {
    header('HTTP/1.1 404 Not Found');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral not found</title>
    <?php gb_render_stylesheets(); ?>
</head>
<body class="bg-light">
<div class="container py-5 text-center">
    <h1 class="h4">Referral not found</h1>
    <p class="text-muted">Check the code in your link or ask the hotel to send a new referral.</p>
    <a href="<?php echo htmlspecialchars(gb_url('login.php')); ?>" class="btn btn-primary mt-3">Staff login</a>
</div>
</body>
</html>
    <?php
    exit;
}

if (referral_is_expired($referral) && $referral['status'] === 'created') {
    $pdo->prepare('UPDATE referrals SET status = ? WHERE id = ?')->execute(['expired', $referral['id']]);
    $referral['status'] = 'expired';
}

$referral_link = referral_url($referral['referral_code']);
$offer_text = trim($referral['note'] ?: $referral['agreement_text'] ?: 'Guest referral request');
$staff_name = $referral['staff_name'] ?: 'Hotel staff';
$benefit_text = !empty($referral['guest_benefit_description']) ? "\n🎁 Guest Benefit: {$referral['guest_benefit_description']}\n" : '';

// Build status-specific message based on referral status
$status_messages = [
    'created' => "🏨 New Referral\n\n"
        . "From: {$referral['source_business']}\n"
        . "To: {$referral['target_business']}\n"
        . "Referral Code: {$referral['referral_code']}\n"
        . "Staff: {$staff_name}\n"
        . $benefit_text
        . "Offer: {$offer_text}\n"
        . "Estimated Value: RWF " . format_money($referral['estimated_value']) . "\n"
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
$whatsapp_message .= "\n\nOpen referral:\n{$referral_link}";
$whatsapp_text = rawurlencode($whatsapp_message);
$target_whatsapp_phone = whatsapp_phone_number($referral['target_phone'] ?? '');
$partner_whatsapp_url = $target_whatsapp_phone
    ? 'https://wa.me/' . $target_whatsapp_phone . '?text=' . $whatsapp_text
    : 'https://wa.me/?text=' . $whatsapp_text;

$can_confirm = $referral['status'] === 'created' && !referral_is_expired($referral);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    if ($referral['status'] !== 'created') {
        $message = 'Referral is already ' . referral_status_label($referral['status']) . '.';
    } elseif (referral_is_expired($referral)) {
        $error = 'This referral has expired. Ask the source hotel to create a new one.';
    } else {
        try {
            $services = ServiceContainer::getInstance($pdo);
            $services->referrals()->updateStatus((int)$referral['id'], 'accepted');
            $referral['status'] = 'accepted';
            $message = 'Referral accepted. Thank you — the guest can now visit.';
            $can_confirm = false;
        } catch (Exception $e) {
            $error = 'Unable to accept: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest referral - <?php echo htmlspecialchars($referral['referral_code']); ?></title>
    <?php gb_render_stylesheets(); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body class="bg-light">
<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-10">
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h1 class="h4 mb-2">Guest referral</h1>
                    <p class="text-muted mb-0">
                        From <strong><?php echo htmlspecialchars($referral['source_business']); ?></strong>
                        to <strong><?php echo htmlspecialchars($referral['target_business']); ?></strong>
                    </p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-12 col-md-5">
                    <div class="card shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h6 text-uppercase text-muted mb-3">Referral details</h2>
                            <dl class="row mb-0 small">
                                <dt class="col-5">Code</dt>
                                <dd class="col-7"><strong><?php echo htmlspecialchars($referral['referral_code']); ?></strong></dd>
                                <dt class="col-5">Status</dt>
                                <dd class="col-7">
                                    <span class="badge bg-<?php echo referral_status_color($referral['status']); ?>">
                                        <?php echo htmlspecialchars(referral_status_label($referral['status'])); ?>
                                    </span>
                                </dd>
                                <dt class="col-5">Staff</dt>
                                <dd class="col-7"><?php echo htmlspecialchars($staff_name); ?></dd>
                                <dt class="col-5">Est. value</dt>
                                <dd class="col-7">RWF <?php echo format_money($referral['estimated_value']); ?></dd>
                                <dt class="col-5">Commission</dt>
                                <dd class="col-7"><?php echo htmlspecialchars($referral['commission_percentage']); ?>%</dd>
                            </dl>
                            <?php if ($offer_text): ?>
                                <hr>
                                <p class="small mb-0"><strong>Note:</strong><br><?php echo nl2br(htmlspecialchars($offer_text)); ?></p>
                            <?php endif; ?>

                            <?php if ($can_confirm): ?>
                                <form method="post" class="mt-4">
                                    <input type="hidden" name="action" value="confirm">
                                    <button type="submit" class="btn btn-success w-100">Accept Referral</button>
                                    <p class="small text-muted mt-2 mb-0">For <?php echo htmlspecialchars($referral['target_business']); ?> — tap to accept this referral.</p>
                                </form>
                            <?php elseif ($referral['status'] === 'expired'): ?>
                                <p class="text-muted small mt-3 mb-0">This referral expired. Contact <?php echo htmlspecialchars($referral['source_business']); ?> for a new code.</p>
                            <?php elseif ($referral['status'] === 'accepted'): ?>
                                <p class="text-muted small mt-3 mb-0">This referral has been accepted. The guest can now visit.</p>
                            <?php elseif ($referral['status'] === 'visited'): ?>
                                <p class="text-muted small mt-3 mb-0">Guest visit recorded. Awaiting transaction.</p>
                            <?php elseif ($referral['status'] === 'converted'): ?>
                                <p class="text-muted small mt-3 mb-0">Transaction recorded. Commission will be processed.</p>
                            <?php elseif ($referral['status'] === 'settled'): ?>
                                <p class="text-muted small mt-3 mb-0">This referral has been fully settled.</p>
                            <?php else: ?>
                                <p class="text-muted small mt-3 mb-0">This referral has been <?php echo htmlspecialchars(referral_status_label($referral['status'])); ?>.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-7">
                    <div class="card shadow-sm mb-3">
                        <div class="card-body text-center">
                            <h2 class="h6 text-uppercase text-muted mb-3">QR code</h2>
                            <div id="qrcode" class="qr-box d-inline-block" style="min-height:200px;"></div>
                            <p class="text-muted small mt-3 mb-0">Scan to open this referral page on another device.</p>
                        </div>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h2 class="h6 text-uppercase text-muted mb-3">Share with partner</h2>
                            <label class="form-label small">Referral link</label>
                            <div class="input-group mb-3">
                                <input type="text" readonly class="form-control form-control-sm" id="referral-link" value="<?php echo htmlspecialchars($referral_link); ?>">
                                <button class="btn btn-outline-secondary btn-sm" type="button" id="copy-link">Copy</button>
                            </div>
                            <label class="form-label small">WhatsApp message</label>
                            <textarea id="whatsapp-message" class="form-control form-control-sm mb-3" rows="6" readonly><?php echo htmlspecialchars($whatsapp_message); ?></textarea>
                            <div class="d-flex flex-wrap gap-2">
                                <a class="btn btn-success btn-sm" href="<?php echo htmlspecialchars($partner_whatsapp_url); ?>" target="_blank" rel="noopener">
                                    Share on WhatsApp
                                </a>
                                <button class="btn btn-outline-secondary btn-sm" type="button" id="copy-message">Copy message</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p class="text-center mt-4 mb-0">
                <a href="/guestbridgerwanda/login.php" class="small text-muted">Hotel staff login</a>
            </p>
        </div>
    </div>
</div>
<script>
new QRCode(document.getElementById('qrcode'), {
    text: <?php echo json_encode($referral_link); ?>,
    width: 200,
    height: 200,
});

document.getElementById('copy-link').addEventListener('click', function () {
    navigator.clipboard.writeText(document.getElementById('referral-link').value);
    this.textContent = 'Copied!';
    var btn = this;
    setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
});

document.getElementById('copy-message').addEventListener('click', function () {
    navigator.clipboard.writeText(document.getElementById('whatsapp-message').value);
    this.textContent = 'Copied!';
    var btn = this;
    setTimeout(function () { btn.textContent = 'Copy message'; }, 1500);
});
</script>
</body>
</html>
