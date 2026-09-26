<?php
/**
 * Professional Referral Voucher — printable, branded GuestBridge referral document.
 */
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Services/ServiceContainer.php';
require_once __DIR__ . '/../../app/HTTP/helpers.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$services = ServiceContainer::getInstance($pdo);
$business_id = tenant_business_id($user);

$referral_id = intval($_GET['id'] ?? 0);
if ($referral_id <= 0) {
    header('Location: /guestbridgerwanda/history.php');
    exit;
}

// Fetch full referral with all joins
$stmt = $pdo->prepare('SELECT r.*,
    sb.name AS source_name, sb.city AS source_city, sb.phone AS source_phone, sb.email AS source_email, sb.address AS source_address,
    tb.name AS target_name, tb.city AS target_city, tb.phone AS target_phone, tb.email AS target_email, tb.address AS target_address, tb.business_type AS target_type,
    s.name AS staff_name, s.role AS staff_role,
    sri.public_identity_code AS staff_identity_code,
    g.name AS guest_name_full, g.phone AS guest_phone_full, g.email AS guest_email_full
FROM referrals r
LEFT JOIN businesses sb ON sb.id = r.source_business_id
LEFT JOIN businesses tb ON tb.id = r.target_business_id
LEFT JOIN staff s ON s.id = r.staff_id
LEFT JOIN staff_referral_identities sri ON sri.id = r.staff_identity_id
LEFT JOIN guests g ON g.id = r.guest_id
WHERE r.id = ?');
$stmt->execute([$referral_id]);
$ref = $stmt->fetch();

if (!$ref) {
    header('Location: /guestbridgerwanda/history.php');
    exit;
}

// Access check
$is_source = (int) $ref['source_business_id'] === $business_id;
$is_target = (int) $ref['target_business_id'] === $business_id;
$is_super = is_super_admin($user);
if (!$is_source && !$is_target && !$is_super) {
    header('Location: /guestbridgerwanda/dashboard.php');
    exit;
}

// Mark as printed
if (($_GET['action'] ?? '') === 'print') {
    $services->referrals()->markPrinted($referral_id);
}

// Build verification URL
$verify_url = '/guestbridgerwanda/referral/verify/' . urlencode($ref['secure_token']);
$full_verify_url = ($_SERVER['HTTP_HOST'] ?? 'localhost') . $verify_url;

// Status helpers
function v_status_color(string $s): string {
    return match($s) {
        'created' => '#f59e0b', 'verified' => '#3b82f6', 'accepted' => '#10b981',
        'redeemed' => '#6366f1', 'converted' => '#8b5cf6', 'settled' => '#059669',
        'expired' => '#9ca3af', 'cancelled' => '#ef4444', 'rejected' => '#ef4444',
        'disputed' => '#f97316', default => '#6b7280',
    };
}
function v_status_label(string $s): string {
    return match($s) {
        'created' => 'ACTIVE', 'verified' => 'VERIFIED', 'accepted' => 'ACCEPTED',
        'redeemed' => 'REDEEMED', 'converted' => 'CONVERTED', 'settled' => 'SETTLED',
        'expired' => 'EXPIRED', 'cancelled' => 'CANCELLED', 'rejected' => 'REJECTED',
        'disputed' => 'DISPUTED', default => ucfirst($s),
    };
}

$guest_display = $ref['guest_name'] ?: $ref['guest_name_full'] ?: 'Guest';
$phone_display = $ref['guest_phone'] ?: $ref['guest_phone_full'] ?: '';
$email_display = $ref['guest_email'] ?: $ref['guest_email_full'] ?: '';
$benefit = $ref['guest_benefit_description'] ?: 'Guest benefit';
$issued = $ref['issued_at'] ?: $ref['created_at'];
$expires = $ref['expires_at'];
$status = $ref['status'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Voucher - <?php echo htmlspecialchars($ref['referral_code']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/5.3.1/flatly/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/guestbridgerwanda/assets/style.css?v=20260522">
    <link rel="icon" href="/guestbridgerwanda/assets/guestbridge-mark.svg">
    <style>
        :root {
            --gb-primary: #1a56db;
            --gb-primary-light: #e8eefb;
            --gb-accent: #059669;
            --gb-dark: #111827;
            --gb-gray: #6b7280;
            --gb-light: #f9fafb;
            --gb-border: #e5e7eb;
        }
        body { background: #f3f4f6; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }

        /* ===== TOOLBAR (hidden on print) ===== */
        .voucher-toolbar {
            max-width: 800px; margin: 1.5rem auto; padding: 0 1rem;
            display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;
        }
        .voucher-toolbar .btn { font-size: 0.9rem; }
        .voucher-toolbar .status-badge {
            margin-left: auto; font-weight: 600; font-size: 0.85rem;
            padding: 0.4rem 1rem; border-radius: 999px; color: #fff;
        }

        /* ===== VOUCHER CARD ===== */
        .voucher {
            max-width: 800px; margin: 0 auto 2rem; background: #fff;
            border: 1px solid var(--gb-border); border-radius: 12px;
            overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        /* Header */
        .voucher-header {
            background: var(--gb-primary); color: #fff; text-align: center;
            padding: 2.5rem 2rem 2rem; position: relative;
        }
        .voucher-header::after {
            content: ''; position: absolute; bottom: -1px; left: 0; right: 0;
            height: 4px; background: linear-gradient(90deg, var(--gb-accent), #f59e0b, var(--gb-accent));
        }
        .voucher-logo { width: 56px; height: 56px; margin: 0 auto 0.75rem; }
        .voucher-brand { font-size: 1.5rem; font-weight: 700; letter-spacing: 1px; margin-bottom: 0.15rem; }
        .voucher-tagline { font-size: 0.8rem; opacity: 0.85; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 1.25rem; }
        .voucher-title { font-size: 1.1rem; font-weight: 600; letter-spacing: 2px; text-transform: uppercase; background: rgba(255,255,255,0.15); display: inline-block; padding: 0.4rem 1.5rem; border-radius: 6px; }
        .voucher-id { font-family: 'Courier New', monospace; font-size: 1.05rem; font-weight: 700; margin-top: 1rem; letter-spacing: 1.5px; opacity: 0.95; }

        /* Body */
        .voucher-body { padding: 0; }

        /* Section */
        .v-section { padding: 1.25rem 2rem; border-bottom: 1px solid var(--gb-border); }
        .v-section:last-child { border-bottom: none; }
        .v-section-label {
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1.5px; color: var(--gb-primary); margin-bottom: 0.6rem;
        }
        .v-section-value { font-size: 1rem; color: var(--gb-dark); line-height: 1.5; }
        .v-section-value strong { font-weight: 600; }
        .v-section-value small { color: var(--gb-gray); font-size: 0.85rem; }

        /* Benefit highlight */
        .v-benefit {
            text-align: center; padding: 2rem; background: linear-gradient(135deg, #ecfdf5, #f0fdf4);
            border-bottom: 1px solid var(--gb-border);
        }
        .v-benefit-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; color: var(--gb-accent); margin-bottom: 0.5rem; }
        .v-benefit-text { font-size: 1.6rem; font-weight: 700; color: var(--gb-dark); margin-bottom: 0.75rem; }
        .v-benefit-instruction { font-size: 0.85rem; color: var(--gb-gray); max-width: 500px; margin: 0 auto; line-height: 1.5; }

        /* QR Section */
        .v-qr { text-align: center; padding: 2rem; border-bottom: 1px solid var(--gb-border); }
        .v-qr canvas, .v-qr img { margin: 0 auto; display: block; }
        .v-qr-code { font-family: 'Courier New', monospace; font-size: 1rem; font-weight: 700; margin-top: 1rem; letter-spacing: 1px; color: var(--gb-dark); }
        .v-qr-instruction { font-size: 0.8rem; color: var(--gb-gray); margin-top: 0.5rem; }

        /* Footer */
        .v-footer { padding: 1.25rem 2rem; background: var(--gb-light); display: flex; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
        .v-footer-item { font-size: 0.8rem; color: var(--gb-gray); }
        .v-footer-item strong { color: var(--gb-dark); font-weight: 600; }

        /* Terms */
        .v-terms { padding: 1rem 2rem; background: var(--gb-light); border-top: 1px solid var(--gb-border); font-size: 0.7rem; color: var(--gb-gray); line-height: 1.5; }

        /* ===== COMPACT / RECEIPT FORMAT ===== */
        .voucher-compact {
            max-width: 400px; margin: 2rem auto; background: #fff;
            border: 1px solid var(--gb-border); border-radius: 8px;
            font-size: 0.85rem; overflow: hidden;
        }
        .voucher-compact .vc-header { background: var(--gb-primary); color: #fff; text-align: center; padding: 1rem; }
        .voucher-compact .vc-header h6 { margin: 0; font-size: 0.9rem; letter-spacing: 1px; }
        .voucher-compact .vc-body { padding: 1rem; }
        .voucher-compact .vc-row { display: flex; justify-content: space-between; padding: 0.3rem 0; border-bottom: 1px dotted var(--gb-border); }
        .voucher-compact .vc-row:last-child { border-bottom: none; }
        .voucher-compact .vc-label { color: var(--gb-gray); font-size: 0.75rem; }
        .voucher-compact .vc-value { font-weight: 600; text-align: right; }
        .voucher-compact .vc-benefit { text-align: center; padding: 0.75rem; background: #ecfdf5; margin: 0.5rem 0; border-radius: 6px; }
        .voucher-compact .vc-benefit strong { font-size: 1.1rem; color: var(--gb-accent); }
        .voucher-compact .vc-qr { text-align: center; padding: 0.75rem; }
        .voucher-compact .vc-footer { text-align: center; padding: 0.5rem; font-size: 0.7rem; color: var(--gb-gray); background: var(--gb-light); }

        /* ===== FORMAT TABS ===== */
        .format-tabs { max-width: 800px; margin: 0 auto; padding: 0 1rem; }
        .format-tabs .nav-pills .nav-link { font-size: 0.85rem; }

        /* ===== PRINT STYLES ===== */
        @media print {
            body { background: #fff !important; margin: 0; padding: 0; }
            .voucher-toolbar, .format-tabs, .no-print, nav, .navbar, footer, .btn { display: none !important; }
            .voucher, .voucher-compact {
                box-shadow: none !important; border: none !important;
                border-radius: 0 !important; margin: 0 !important;
                max-width: 100% !important; page-break-inside: avoid;
            }
            .voucher-header { padding: 1.5rem 1.5rem 1.25rem !important; }
            .v-section { padding: 1rem 1.5rem !important; }
            .v-benefit { padding: 1.5rem !important; }
            .v-qr { padding: 1.5rem !important; }
            .v-footer { padding: 1rem 1.5rem !important; }
            .v-terms { padding: 0.75rem 1.5rem !important; }
        }
        @page { margin: 1cm; size: A4; }
    </style>
</head>
<body>

<!-- Toolbar -->
<div class="voucher-toolbar no-print">
    <a href="/guestbridgerwanda/view_referral.php?id=<?php echo $referral_id; ?>" class="btn btn-outline-secondary btn-sm">Back to Referral</a>
    <button onclick="window.print()" class="btn btn-primary btn-sm">Print Referral</button>
    <button onclick="downloadPDF()" class="btn btn-outline-primary btn-sm">Download PDF</button>
    <button onclick="shareReferral()" class="btn btn-outline-success btn-sm">Share</button>
    <span class="status-badge" style="background:<?php echo v_status_color($status); ?>"><?php echo v_status_label($status); ?></span>
</div>

<!-- Format tabs -->
<div class="format-tabs no-print">
    <ul class="nav nav-pills mb-3" id="formatTab" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#fullFormat" role="tab">Full Voucher (A4)</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#compactFormat" role="tab">Compact / Receipt</a></li>
    </ul>
</div>

<div class="tab-content">
<!-- ===== FULL A4 VOUCHER ===== -->
<div class="tab-pane fade show active" id="fullFormat" role="tabpanel">
<div class="voucher" id="voucher-full">

    <!-- Header -->
    <div class="voucher-header">
        <img src="/guestbridgerwanda/assets/guestbridge-mark.svg" alt="GuestBridge" class="voucher-logo" width="56" height="56">
        <div class="voucher-brand">GUESTBRIDGE RWANDA</div>
        <div class="voucher-tagline">Trusted Hospitality Referral Network</div>
        <div class="voucher-title">Referral Voucher</div>
        <div class="voucher-id"><?php echo htmlspecialchars($ref['referral_code']); ?></div>
    </div>

    <div class="voucher-body">
        <!-- Guest -->
        <div class="v-section">
            <div class="v-section-label">Guest</div>
            <div class="v-section-value">
                <strong><?php echo htmlspecialchars($guest_display); ?></strong>
                <?php if ($phone_display): ?><br><small><?php echo htmlspecialchars($phone_display); ?></small><?php endif; ?>
                <?php if ($email_display): ?><br><small><?php echo htmlspecialchars($email_display); ?></small><?php endif; ?>
            </div>
        </div>

        <!-- Referred By -->
        <div class="v-section">
            <div class="v-section-label">Referred By</div>
            <div class="v-section-value">
                <strong><?php echo htmlspecialchars($ref['staff_name'] ?: $user['name']); ?></strong><br>
                <small><?php echo htmlspecialchars(ucfirst($ref['staff_role'] ?: 'Staff')); ?></small><br>
                <small><?php echo htmlspecialchars($ref['source_name']); ?></small>
                <?php if ($ref['source_city']): ?><br><small><?php echo htmlspecialchars(city_label($ref['source_city'])); ?></small><?php endif; ?>
            </div>
        </div>

        <!-- Destination -->
        <div class="v-section">
            <div class="v-section-label">Destination</div>
            <div class="v-section-value">
                <strong><?php echo htmlspecialchars($ref['target_name']); ?></strong><br>
                <small><?php echo htmlspecialchars(business_type_label($ref['target_type'])); ?></small>
                <?php if ($ref['target_city']): ?> &middot; <small><?php echo htmlspecialchars(city_label($ref['target_city'])); ?></small><?php endif; ?>
                <?php if ($ref['target_phone']): ?><br><small><?php echo htmlspecialchars($ref['target_phone']); ?></small><?php endif; ?>
            </div>
        </div>

        <!-- Benefit -->
        <div class="v-benefit">
            <div class="v-benefit-label">Your Guest Benefit</div>
            <div class="v-benefit-text"><?php echo htmlspecialchars(strtoupper($benefit)); ?></div>
            <div class="v-benefit-instruction">Present this referral at the destination business to receive the stated benefit.</div>
        </div>

        <!-- QR Code -->
        <div class="v-qr">
            <div id="qrcode"></div>
            <div class="v-qr-code"><?php echo htmlspecialchars($ref['referral_code']); ?></div>
            <div class="v-qr-instruction">Scan to verify this referral</div>
        </div>

        <!-- Footer -->
        <div class="v-footer">
            <div class="v-footer-item">
                <strong>Issued:</strong> <?php echo htmlspecialchars(date('d M Y, H:i', strtotime($issued))); ?>
            </div>
            <div class="v-footer-item">
                <?php if ($expires): ?>
                    <strong>Valid until:</strong> <?php echo htmlspecialchars(date('d M Y, H:i', strtotime($expires))); ?>
                <?php else: ?>
                    <strong>No expiration</strong>
                <?php endif; ?>
            </div>
            <div class="v-footer-item">
                <strong>Status:</strong> <?php echo v_status_label($status); ?>
            </div>
        </div>

        <!-- Terms -->
        <div class="v-terms">
            <strong>Terms & Conditions:</strong> This referral is valid only at the destination business specified above. The benefit is subject to the partner's terms and availability. This referral may only be redeemed once unless otherwise specified. GuestBridge Rwanda acts as a referral facilitator and is not a party to the transaction between the referring and destination businesses.
        </div>
    </div>
</div>
</div>

<!-- ===== COMPACT / RECEIPT FORMAT ===== -->
<div class="tab-pane fade" id="compactFormat" role="tabpanel">
<div class="voucher-compact" id="voucher-compact">
    <div class="vc-header">
        <h6>GUESTBRIDGE RWANDA</h6>
        <small>Referral Voucher</small>
    </div>
    <div class="vc-body">
        <div class="vc-row"><span class="vc-label">Referral ID</span><span class="vc-value"><?php echo htmlspecialchars($ref['referral_code']); ?></span></div>
        <div class="vc-row"><span class="vc-label">Guest</span><span class="vc-value"><?php echo htmlspecialchars($guest_display); ?></span></div>
        <div class="vc-row"><span class="vc-label">From</span><span class="vc-value"><?php echo htmlspecialchars($ref['source_name']); ?></span></div>
        <div class="vc-row"><span class="vc-label">Staff</span><span class="vc-value"><?php echo htmlspecialchars($ref['staff_name'] ?: 'N/A'); ?></span></div>
        <div class="vc-row"><span class="vc-label">To</span><span class="vc-value"><?php echo htmlspecialchars($ref['target_name']); ?></span></div>
        <div class="vc-benefit">
            <div class="v-section-label">Benefit</div>
            <strong><?php echo htmlspecialchars($benefit); ?></strong>
        </div>
        <div class="vc-qr" id="qr-compact"></div>
        <div class="vc-row"><span class="vc-label">Issued</span><span class="vc-value"><?php echo htmlspecialchars(date('d M Y', strtotime($issued))); ?></span></div>
        <?php if ($expires): ?>
        <div class="vc-row"><span class="vc-label">Valid until</span><span class="vc-value"><?php echo htmlspecialchars(date('d M Y', strtotime($expires))); ?></span></div>
        <?php endif; ?>
    </div>
    <div class="vc-footer">Scan QR or present code at destination &middot; guestbridgerwanda.com</div>
</div>
</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// Full QR
new QRCode(document.getElementById('qrcode'), {
    text: <?php echo json_encode($full_verify_url); ?>,
    width: 160, height: 160,
    correctLevel: QRCode.CorrectLevel.M
});

// Compact QR
new QRCode(document.getElementById('qr-compact'), {
    text: <?php echo json_encode($full_verify_url); ?>,
    width: 100, height: 100,
    correctLevel: QRCode.CorrectLevel.M
});

function downloadPDF() {
    window.print();
}

function shareReferral() {
    var url = <?php echo json_encode('https://' . $full_verify_url); ?>;
    var text = 'GuestBridge Referral\n\n' +
        'You have been referred to:\n<?php echo addslashes($ref["target_name"]); ?>\n\n' +
        'Benefit: <?php echo addslashes($benefit); ?>\n' +
        'Referral ID: <?php echo addslashes($ref["referral_code"]); ?>\n\n' +
        'Valid until: <?php echo $expires ? addslashes(date("d M Y", strtotime($expires))) : "No expiration"; ?>\n\n' +
        'Scan the QR code or present this code at the destination.';

    if (navigator.share) {
        navigator.share({ title: 'GuestBridge Referral', text: text, url: url });
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(text + '\n\n' + url);
        alert('Referral details copied to clipboard!');
    } else {
        prompt('Copy this referral info:', text + '\n\n' + url);
    }
}
</script>
</body>
</html>
