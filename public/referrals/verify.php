<?php
/**
 * Referral Verification Page
 * Public page for verifying guest referrals via QR code or manual code entry.
 * Authenticated destination businesses can accept and redeem referrals here.
 */
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Services/ServiceContainer.php';
db_init();

$pdo = db_connect();
$services = ServiceContainer::getInstance($pdo);

$code = trim($_GET['code'] ?? $_POST['referral_code'] ?? '');
$action = $_POST['action'] ?? '';
$referral = null;
$error = null;
$success = null;
$verified_referral = null;

// Check if user is logged in
$user = current_user();
$is_logged_in = !!$user;
$business_id = $user ? tenant_business_id($user) : 0;

// Handle POST actions (accept/redeem)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_logged_in) {
    require_csrf_post('verify.php');
    $referral_id = intval($_POST['referral_id'] ?? 0);

    if ($action === 'accept' && $referral_id > 0) {
        try {
            $services->referrals()->acceptReferral($referral_id, $business_id, (int) $user['id']);
            $success = 'Referral accepted successfully.';
            log_audit($pdo, $business_id, (int) $user['id'], 'referral_accepted', 'referral', $referral_id);
            $code = $_POST['referral_code'] ?? '';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'redeem' && $referral_id > 0) {
        try {
            $transaction_amount = floatval($_POST['transaction_amount'] ?? 0);
            $receipt_id = intval($_POST['receipt_id'] ?? 0) ?: null;
            $notes = trim($_POST['redeem_notes'] ?? '');

            if ($transaction_amount <= 0) {
                throw new RuntimeException('Please enter a valid transaction amount.');
            }

            $services->referrals()->redeemReferral(
                $referral_id, $business_id, (int) $user['id'], $notes,
                $transaction_amount, $receipt_id
            );
            $success = 'Referral redeemed. Transaction of RWF ' . number_format($transaction_amount) . ' recorded.';
            log_audit($pdo, $business_id, (int) $user['id'], 'referral_redeemed', 'referral', $referral_id, null, [
                'transaction_amount' => $transaction_amount,
                'receipt_id' => $receipt_id,
            ]);
            $code = $_POST['referral_code'] ?? '';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Look up referral
if ($code !== '') {
    $referral = $services->referrals()->getReferralForVerification($code);
    if (!$referral) {
        $error = 'Referral not found. Please check the code and try again.';
    } elseif ($referral['expires_at'] && strtotime($referral['expires_at']) < time()) {
        // Auto-expire only if logged in as target business
        if ($is_logged_in && (int) $referral['target_business_id'] === $business_id && in_array($referral['status'], ['created', 'verified'], true)) {
            $services->referrals()->updateStatus((int) $referral['id'], 'expired');
            $referral['status'] = 'expired';
        }
    }
}

// If redirected after action, re-lookup
if ($success && $code) {
    $referral = $services->referrals()->getReferralForVerification($code);
}

// Status helpers
function verify_status_color(string $status): string
{
    return match($status) {
        'created' => 'warning',
        'verified' => 'info',
        'accepted' => 'primary',
        'redeemed' => 'success',
        'converted' => 'success',
        'settled' => 'success',
        'expired' => 'secondary',
        'cancelled' => 'danger',
        'rejected' => 'danger',
        'disputed' => 'danger',
        default => 'secondary',
    };
}

function verify_status_label(string $status): string
{
    return match($status) {
        'created' => 'Pending Verification',
        'verified' => 'Verified',
        'accepted' => 'Accepted',
        'redeemed' => 'Benefit Redeemed',
        'converted' => 'Converted',
        'settled' => 'Settled',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
        'disputed' => 'Disputed',
        default => ucfirst($status),
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Referral - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/5.3.1/flatly/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/guestbridgerwanda/assets/style.css?v=20260522">
    <link rel="icon" href="/guestbridgerwanda/assets/guestbridge-mark.svg">
    <style>
        body { background: #f8f9fa; min-height: 100vh; }
        .verify-container { max-width: 480px; margin: 2rem auto; }
        .verify-hero { text-align: center; padding: 2rem 1rem; }
        .verify-hero h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .verify-result { margin-top: 1.5rem; }
        .result-valid { border-left: 4px solid #28a745; background: #d4edda; }
        .result-invalid { border-left: 4px solid #dc3545; background: #f8d7da; }
        .result-expired { border-left: 4px solid #6c757d; background: #e2e3e5; }
        .result-used { border-left: 4px solid #ffc107; background: #fff3cd; }
        .ref-code { font-family: 'Courier New', monospace; font-size: 1.4rem; font-weight: bold; letter-spacing: 2px; }
        .qr-scan-area { width: 100%; max-width: 300px; height: 300px; margin: 1rem auto; border: 3px dashed #dee2e6; border-radius: 12px; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; background: #000; }
        .qr-scan-area video { width: 100%; height: 100%; object-fit: cover; }
        .divider { text-align: center; margin: 1.5rem 0; position: relative; }
        .divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: #dee2e6; }
        .divider span { background: #f8f9fa; padding: 0 1rem; position: relative; color: #6c757d; font-size: 0.9rem; }
        .btn-verify { width: 100%; padding: 0.8rem; font-size: 1.1rem; }
        .detail-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
        .detail-label { color: #6c757d; }
        .detail-value { font-weight: 500; }
        #cameraError { display: none; }
    </style>
</head>
<body>
<div class="verify-container">
    <div class="verify-hero">
        <img src="/guestbridgerwanda/assets/guestbridge-mark.svg" alt="GuestBridge" width="48" height="48" class="mb-3">
        <h1>Verify GuestBridge Referral</h1>
        <p class="text-muted">Scan the guest's QR code or enter the referral code.</p>
        <a href="/guestbridgerwanda/dashboard.php" class="btn btn-outline-secondary btn-sm mt-2">Back to Dashboard</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error && !$referral): ?>
        <div class="card result-invalid mb-3">
            <div class="card-body text-center py-4">
                <div class="fs-1 mb-2">&#10005;</div>
                <h5>Referral Not Valid</h5>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($error); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$referral): ?>
    <!-- Verification Form -->
    <div class="card mb-3">
        <div class="card-body">
            <!-- QR Scanner -->
            <div class="qr-scan-area" id="qr-reader">
                <div class="text-center text-white" id="qr-placeholder">
                    <div class="fs-1 mb-2">&#128247;</div>
                    <small>Tap to scan QR code</small>
                </div>
                <video id="camera-video" autoplay playsinline style="display:none;"></video>
            </div>
            <div id="cameraError" class="alert alert-warning text-center py-2">
                Camera not available. Please enter the code manually.
            </div>
            <button class="btn btn-outline-primary btn-sm w-100 mb-3" id="startCamera" type="button">Start Camera</button>

            <div class="divider"><span>or enter code manually</span></div>

            <form method="get" action="">
                <div class="mb-3">
                    <input type="text" name="code" class="form-control form-control-lg text-center ref-code"
                           placeholder="GBR-XXXXXXXX" maxlength="50"
                           value="<?php echo htmlspecialchars($code); ?>" autofocus
                           style="text-transform: uppercase;">
                </div>
                <button type="submit" class="btn btn-primary btn-verify">Verify Referral</button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- Referral Result -->
    <?php
    $isValid = in_array($referral['status'], ['created', 'verified', 'accepted']);
    $isExpired = $referral['status'] === 'expired';
    $isUsed = in_array($referral['status'], ['redeemed', 'converted', 'settled']);
    ?>

    <div class="card verify-result mb-3 <?php echo $isValid ? 'result-valid' : ($isExpired ? 'result-expired' : ($isUsed ? 'result-used' : 'result-invalid')); ?>">
        <div class="card-body text-center py-4">
            <?php if ($isValid): ?>
                <div class="fs-1 mb-2">&#10003;</div>
                <h5 class="text-success">Valid Referral</h5>
            <?php elseif ($isExpired): ?>
                <div class="fs-1 mb-2">&#9200;</div>
                <h5 class="text-secondary">Referral Expired</h5>
            <?php elseif ($isUsed): ?>
                <div class="fs-1 mb-2">&#10003;</div>
                <h5 class="text-warning">Already <?php echo htmlspecialchars(verify_status_label($referral['status'])); ?></h5>
            <?php else: ?>
                <div class="fs-1 mb-2">&#10005;</div>
                <h5 class="text-danger">Invalid Referral</h5>
            <?php endif; ?>

            <div class="ref-code text-primary my-3"><?php echo htmlspecialchars($referral['referral_code']); ?></div>

            <div class="text-start">
                <div class="detail-row">
                    <span class="detail-label">Status</span>
                    <span class="badge bg-<?php echo verify_status_color($referral['status']); ?>">
                        <?php echo htmlspecialchars(verify_status_label($referral['status'])); ?>
                    </span>
                </div>
                <?php if ($referral['source_business_name']): ?>
                <div class="detail-row">
                    <span class="detail-label">Referred From</span>
                    <span class="detail-value"><?php echo htmlspecialchars($referral['source_business_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($referral['target_business_name']): ?>
                <div class="detail-row">
                    <span class="detail-label">Destination</span>
                    <span class="detail-value"><?php echo htmlspecialchars($referral['target_business_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($referral['staff_name'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Referral Employee</span>
                    <span class="detail-value"><?php echo htmlspecialchars($referral['staff_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($referral['guest_benefit_description']): ?>
                <div class="detail-row">
                    <span class="detail-label">Guest Benefit</span>
                    <span class="detail-value text-success fw-bold"><?php echo htmlspecialchars($referral['guest_benefit_description']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($referral['expires_at']): ?>
                <div class="detail-row">
                    <span class="detail-label">Expires</span>
                    <span class="detail-value"><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($referral['expires_at']))); ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-row border-0">
                    <span class="detail-label">Created</span>
                    <span class="detail-value"><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($referral['created_at']))); ?></span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isValid && $is_logged_in): ?>
    <!-- Accept Action -->
    <?php if ($referral['status'] === 'created' || $referral['status'] === 'verified'): ?>
        <?php if ((int) $referral['target_business_id'] === $business_id): ?>
        <div class="card mb-3">
            <div class="card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="referral_id" value="<?php echo $referral['id']; ?>">
                    <input type="hidden" name="referral_code" value="<?php echo htmlspecialchars($referral['referral_code']); ?>">
                    <input type="hidden" name="action" value="accept">
                    <button class="btn btn-primary btn-verify">Accept Referral</button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-3"><div class="card-body text-center"><p class="text-muted mb-0">Log in as the destination business to accept this referral.</p></div></div>
        <?php endif; ?>

    <?php elseif ($referral['status'] === 'accepted'): ?>
        <?php if ((int) $referral['target_business_id'] === $business_id): ?>
        <!-- Full Redemption Flow -->
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="mb-3">Redeem Referral</h5>

                <!-- Step 1: Transaction Amount -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Client Consumption Amount (RWF) <span class="text-danger">*</span></label>
                    <input type="number" id="transaction-amount" name="transaction_amount" class="form-control form-control-lg"
                        min="1" step="1" placeholder="e.g. 100000" required>
                    <div class="form-text">Enter the actual amount spent by the guest at your establishment.</div>
                </div>

                <!-- Step 2: EBM Receipt Upload -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Official EBM Receipt</label>
                    <div id="receipt-upload-area" class="border rounded p-3 text-center" style="border-style: dashed !important; cursor: pointer;">
                        <input type="file" id="receipt-file" accept=".jpg,.jpeg,.png,.pdf" style="display:none;">
                        <div id="receipt-placeholder">
                            <div class="text-muted mb-2">Click to upload or drag and drop</div>
                            <small class="text-muted">JPG, PNG, or PDF — Max 10MB</small>
                        </div>
                        <div id="receipt-preview" class="d-none">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-2">
                                    <span id="receipt-icon" class="fs-3"></span>
                                    <div class="text-start">
                                        <div id="receipt-name" class="fw-semibold"></div>
                                        <small id="receipt-size" class="text-muted"></small>
                                    </div>
                                </div>
                                <button type="button" id="receipt-remove" class="btn btn-outline-danger btn-sm">Remove</button>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" id="receipt-id" name="receipt_id" value="">
                    <div id="receipt-error" class="text-danger small mt-1 d-none"></div>
                </div>

                <!-- Commission Preview -->
                <div id="commission-preview" class="d-none mb-3">
                    <h6 class="border-bottom pb-2">Commission Summary</h6>
                    <div class="bg-light rounded p-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Transaction amount</span>
                            <strong id="preview-amount">RWF 0</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Commission rate</span>
                            <span id="preview-rate">0%</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Total commission</span>
                            <strong id="preview-total">RWF 0</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Referring hotel (<?php echo htmlspecialchars($referral['source_business_name'] ?? ''); ?>)</span>
                            <span id="preview-hotel" class="text-success fw-semibold">RWF 0</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Referring staff</span>
                            <span id="preview-staff" class="text-success">RWF 0</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">GuestBridge service fee</span>
                            <span id="preview-platform" class="text-muted">RWF 0</span>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Notes -->
                <div class="mb-3">
                    <label class="form-label small">Notes (optional)</label>
                    <input type="text" name="redeem_notes" class="form-control" placeholder="e.g. Room upgrade provided">
                </div>

                <!-- Confirm Button -->
                <form method="post" id="redeem-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="referral_id" value="<?php echo $referral['id']; ?>">
                    <input type="hidden" name="referral_code" value="<?php echo htmlspecialchars($referral['referral_code']); ?>">
                    <input type="hidden" name="action" value="redeem">
                    <input type="hidden" id="form-transaction-amount" name="transaction_amount" value="">
                    <input type="hidden" id="form-receipt-id" name="receipt_id" value="">
                    <input type="hidden" id="form-redeem-notes" name="redeem_notes" value="">
                    <button type="submit" id="redeem-btn" class="btn btn-success btn-lg w-100" disabled onclick="return confirm('Confirm the transaction details and redeem this referral?')">
                        Confirm & Redeem Referral
                    </button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-3"><div class="card-body text-center"><p class="text-muted mb-0">Log in as the destination business to redeem this referral.</p></div></div>
        <?php endif; ?>
    <?php endif; ?>
    <?php elseif (!$is_logged_in && $isValid): ?>
    <div class="card mb-3">
        <div class="card-body text-center">
            <a href="/guestbridgerwanda/login.php" class="btn btn-primary btn-verify">Log In to Accept Referral</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="text-center">
        <a href="?code=<?php echo htmlspecialchars($code); ?>" class="btn btn-outline-secondary">Verify Another</a>
    </div>
    <?php endif; ?>

    <div class="text-center mt-4 mb-3">
        <small class="text-muted">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(APP_NAME); ?></small>
    </div>
</div>

<script>
// Simple QR scanner using camera
document.getElementById('startCamera')?.addEventListener('click', async function() {
    var video = document.getElementById('camera-video');
    var placeholder = document.getElementById('qr-placeholder');
    var errorDiv = document.getElementById('cameraError');

    try {
        var stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' }
        });
        video.srcObject = stream;
        video.style.display = 'block';
        placeholder.style.display = 'none';
        this.style.display = 'none';

        // Use BarcodeDetector if available
        if ('BarcodeDetector' in window) {
            var detector = new BarcodeDetector({ formats: ['qr_code'] });
            var detectInterval = setInterval(async function() {
                try {
                    var barcodes = await detector.detect(video);
                    if (barcodes.length > 0) {
                        clearInterval(detectInterval);
                        stream.getTracks().forEach(function(t) { t.stop(); });
                        var value = barcodes[0].rawValue;
                        // Navigate to verify with the scanned code
                        window.location.href = '?code=' + encodeURIComponent(value);
                    }
                } catch(e) {}
            }, 500);
        } else {
            // Fallback: just show camera, user manually enters code
            placeholder.innerHTML = '<small>Camera active. Scan the QR code or enter the code below.</small>';
            placeholder.style.display = 'block';
            placeholder.className = 'text-center text-white';
        }
    } catch(e) {
        errorDiv.style.display = 'block';
        this.style.display = 'none';
    }
});

// Auto-uppercase code input
document.querySelector('input[name="code"]')?.addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});

// === Receipt Upload & Commission Preview ===
(function() {
    var amountInput = document.getElementById('transaction-amount');
    var receiptFile = document.getElementById('receipt-file');
    var receiptUploadArea = document.getElementById('receipt-upload-area');
    var receiptPlaceholder = document.getElementById('receipt-placeholder');
    var receiptPreview = document.getElementById('receipt-preview');
    var receiptName = document.getElementById('receipt-name');
    var receiptSize = document.getElementById('receipt-size');
    var receiptIcon = document.getElementById('receipt-icon');
    var receiptRemove = document.getElementById('receipt-remove');
    var receiptIdInput = document.getElementById('receipt-id');
    var receiptError = document.getElementById('receipt-error');
    var commissionPreview = document.getElementById('commission-preview');
    var redeemBtn = document.getElementById('redeem-btn');
    var formAmount = document.getElementById('form-transaction-amount');
    var formReceiptId = document.getElementById('form-receipt-id');
    var formNotes = document.getElementById('form-redeem-notes');
    var notesInput = document.querySelector('input[name="redeem_notes"]');
    var csrfToken = document.querySelector('#redeem-form input[name="csrf_token"]')?.value || '';

    var currentReceiptId = null;
    var commissionRate = <?php echo floatval($referral['commission_percentage'] ?? 10); ?>;
    var referralId = <?php echo (int) $referral['id']; ?>;
    var debounceTimer = null;

    function formatMoney(n) { return 'RWF ' + Math.round(n).toLocaleString(); }

    function updateCommissionPreview() {
        var amount = parseFloat(amountInput.value) || 0;
        if (amount <= 0) {
            commissionPreview.classList.add('d-none');
            redeemBtn.disabled = true;
            return;
        }

        // Client-side preview (server validates on submit)
        var totalCommission = amount * (commissionRate / 100);
        var platformFee = Math.max(500, totalCommission * 0.20);
        var staffShare = (totalCommission - platformFee) * 0.30;
        var hotelShare = totalCommission - platformFee - staffShare;

        document.getElementById('preview-amount').textContent = formatMoney(amount);
        document.getElementById('preview-rate').textContent = commissionRate + '%';
        document.getElementById('preview-total').textContent = formatMoney(totalCommission);
        document.getElementById('preview-hotel').textContent = formatMoney(hotelShare);
        document.getElementById('preview-staff').textContent = formatMoney(staffShare);
        document.getElementById('preview-platform').textContent = formatMoney(platformFee);
        commissionPreview.classList.remove('d-none');

        formAmount.value = amount;
        redeemBtn.disabled = false;
    }

    // Amount input
    if (amountInput) {
        amountInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(updateCommissionPreview, 300);
        });
    }

    // Notes sync
    if (notesInput) {
        notesInput.addEventListener('input', function() {
            if (formNotes) formNotes.value = this.value;
        });
    }

    // Receipt upload click
    if (receiptUploadArea) {
        receiptUploadArea.addEventListener('click', function(e) {
            if (e.target === receiptRemove || e.target.closest('#receipt-remove')) return;
            receiptFile.click();
        });
    }

    // Receipt file change
    if (receiptFile) {
        receiptFile.addEventListener('change', function() {
            if (!this.files || !this.files[0]) return;
            receiptError.classList.add('d-none');

            var formData = new FormData();
            formData.append('receipt', this.files[0]);
            formData.append('referral_id', referralId);
            formData.append('csrf_token', csrfToken);

            receiptPlaceholder.innerHTML = '<small class="text-primary">Uploading...</small>';

            fetch('/guestbridgerwanda/referrals/upload-receipt.php', {
                method: 'POST',
                body: formData
            })
            .then(function(r) {
                if (!r.ok) {
                    return r.json().catch(function() { return { error: 'Server error (' + r.status + ')' }; }).then(function(d) { throw new Error(d.error || 'Upload failed'); });
                }
                return r.json();
            })
            .then(function(data) {
                if (data.error) {
                    receiptError.textContent = data.error;
                    receiptError.classList.remove('d-none');
                    receiptPlaceholder.innerHTML = '<div class="text-muted mb-2">Click to upload or drag and drop</div><small class="text-muted">JPG, PNG, or PDF — Max 10MB</small>';
                    return;
                }
                currentReceiptId = data.receipt_id;
                receiptIdInput.value = data.receipt_id;
                formReceiptId.value = data.receipt_id;

                var icon = data.file_type === 'application/pdf' ? '&#128196;' : '&#128247;';
                receiptIcon.innerHTML = icon;
                receiptName.textContent = data.filename;
                receiptSize.textContent = (data.file_size / 1024).toFixed(1) + ' KB';
                receiptPlaceholder.classList.add('d-none');
                receiptPreview.classList.remove('d-none');
            })
            .catch(function(err) {
                receiptError.textContent = err.message || 'Upload failed. Please try again.';
                receiptError.classList.remove('d-none');
                receiptPlaceholder.innerHTML = '<div class="text-muted mb-2">Click to upload or drag and drop</div><small class="text-muted">JPG, PNG, or PDF — Max 10MB</small>';
            });

            receiptFile.value = '';
        });
    }

    // Receipt remove
    if (receiptRemove) {
        receiptRemove.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!currentReceiptId) return;

            fetch('/guestbridgerwanda/referrals/delete-receipt.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'receipt_id=' + currentReceiptId + '&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(function(r) { return r.json(); })
            .then(function() {
                currentReceiptId = null;
                receiptIdInput.value = '';
                formReceiptId.value = '';
                receiptPreview.classList.add('d-none');
                receiptPlaceholder.classList.remove('d-none');
                receiptPlaceholder.innerHTML = '<div class="text-muted mb-2">Click to upload or drag and drop</div><small class="text-muted">JPG, PNG, or PDF — Max 10MB</small>';
            });
        });
    }
})();
</script>
</body>
</html>
