<?php
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Services/ServiceContainer.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = $user['business_id'];
$is_manager = in_array(($user['role'] ?? ''), ['manager', 'super_admin'], true);
$current_page = 'create_referral.php';

$services = ServiceContainer::getInstance($pdo);

$stmt = $pdo->prepare('SELECT COUNT(*) AS pending FROM partnerships WHERE partner_business_id = ? AND status = ?');
$stmt->execute([$business_id, 'pending']);
$pending_requests = $stmt->fetch()['pending'];

require_once __DIR__ . '/../../app/HTTP/helpers.php';

$errors = [];
$recommendation_need = $_GET['need'] ?? '';
$recommendation_budget = trim($_GET['budget'] ?? '');
$recommendation_budget_value = $recommendation_budget !== '' ? max(0, floatval($recommendation_budget)) : 0;
if ($recommendation_need !== '' && !array_key_exists($recommendation_need, business_type_options())) {
    $recommendation_need = '';
}

$partners = [];
$current_month = date('Y-m');
$partner_stmt = $pdo->prepare('SELECT b.id, b.name, b.business_type, b.reliability_score, b.payout_compliance_score, b.guest_satisfaction_score,
        p.id AS partnership_id, p.commission_rate, p.agreement_text,
        IFNULL(perf.referrals_count, 0) AS referrals_count,
        IFNULL(perf.used_count, 0) AS used_count,
        IFNULL(perf.month_count, 0) AS month_count,
        fl.status AS featured_status
    FROM partnerships p
    JOIN businesses b ON b.id = CASE WHEN p.business_id = ? THEN p.partner_business_id ELSE p.business_id END
    LEFT JOIN (
        SELECT target_business_id,
            COUNT(*) AS referrals_count,
            SUM(status = "used") AS used_count,
            SUM(DATE_FORMAT(created_at, "%Y-%m") = ?) AS month_count
        FROM referrals
        WHERE source_business_id = ?
        GROUP BY target_business_id
    ) perf ON perf.target_business_id = b.id
    LEFT JOIN featured_listings fl ON fl.business_id = b.id AND fl.featured_month = ? AND fl.status = "active"
    WHERE (p.business_id = ? OR p.partner_business_id = ?) AND p.status = ?
    ORDER BY b.name');
$partner_stmt->execute([$business_id, $current_month, $business_id, $current_month, $business_id, $business_id, 'active']);
$partners = $partner_stmt->fetchAll();

foreach ($partners as &$partner) {
    $average_score = reputation_average($partner);
    $conversion_rate = (int)$partner['referrals_count'] > 0 ? (((int)$partner['used_count'] / (int)$partner['referrals_count']) * 100) : 50;
    $seasonality = commission_seasonality_signal($pdo, $business_id, (int)$partner['id'], $current_month);
    $type_bonus = $recommendation_need !== '' && $partner['business_type'] === $recommendation_need ? 18 : 0;
    $featured_bonus = $partner['featured_status'] === 'active' ? 4 : 0;
    $budget_bonus = 0;
    if ($recommendation_budget_value > 0) {
        $expected_commission = $recommendation_budget_value * ((float)$partner['commission_rate'] / 100);
        $budget_bonus = $expected_commission <= 50000 ? 6 : ($expected_commission <= 100000 ? 3 : 0);
    }
    $recent_activity_bonus = min(8, (int)$partner['month_count'] * 2);
    $partner['recommendation_score'] = round(($average_score * 0.55) + ($conversion_rate * 0.25) + $type_bonus + $featured_bonus + $budget_bonus + $recent_activity_bonus, 1);
    $suggested_rate = (float)$partner['commission_rate'];
    if ((int)$partner['month_count'] >= 5 || $conversion_rate >= 75) {
        $suggested_rate += 1.5;
    }
    if ($average_score < 65 || $conversion_rate < 35) {
        $suggested_rate -= 1;
    }
    if ($recommendation_budget_value > 0 && $recommendation_budget_value < 50000) {
        $suggested_rate = min($suggested_rate, (float)$partner['commission_rate']);
    }
    $suggested_rate += (float)$seasonality['adjustment'];
    $partner['suggested_rate'] = max(1, min(30, round($suggested_rate, 1)));
    $partner['seasonality_label'] = $seasonality['label'];
    $partner['seasonality_description'] = $seasonality['description'];
    $partner['seasonality_adjustment'] = (float)$seasonality['adjustment'];
    $partner['seasonality_confidence'] = $seasonality['confidence'];
    $partner['recommendation_reason'] = [];
    if ($type_bonus > 0) {
        $partner['recommendation_reason'][] = 'category match';
    }
    if ($conversion_rate >= 60) {
        $partner['recommendation_reason'][] = 'strong conversion';
    }
    if ($average_score >= 80) {
        $partner['recommendation_reason'][] = 'high reputation';
    }
    if ($partner['featured_status'] === 'active') {
        $partner['recommendation_reason'][] = 'featured pilot';
    }
    if ((float)$seasonality['adjustment'] > 0) {
        $partner['recommendation_reason'][] = 'seasonal demand';
    } elseif ((float)$seasonality['adjustment'] < 0) {
        $partner['recommendation_reason'][] = 'seasonal softness';
    }
    if (empty($partner['recommendation_reason'])) {
        $partner['recommendation_reason'][] = 'balanced option';
    }
}
unset($partner);

// Fetch active guest benefits for each partnership
$benefit_stmt = $pdo->prepare('SELECT gb.partnership_id, gb.benefit_type, gb.benefit_value, gb.benefit_description, gb.min_spend, gb.valid_until
    FROM guest_benefits gb
    JOIN partnerships p ON p.id = gb.partnership_id
    WHERE (p.business_id = ? OR p.partner_business_id = ?) AND p.status = "active" AND gb.status = "active"
    ORDER BY gb.created_at DESC');
$benefit_stmt->execute([$business_id, $business_id]);
$all_benefits = $benefit_stmt->fetchAll();

// Index benefits by partnership_id (use first/active benefit per partnership)
$benefits_by_partnership = [];
foreach ($all_benefits as $b) {
    $pid = (int) $b['partnership_id'];
    if (!isset($benefits_by_partnership[$pid])) {
        $benefits_by_partnership[$pid] = $b;
    }
}

// Attach benefit to each partner
foreach ($partners as &$partner) {
    $partner['guest_benefit'] = $benefits_by_partnership[(int) $partner['partnership_id']] ?? null;
}
unset($partner);

$recommended_partners = $partners;
usort($recommended_partners, function ($a, $b) {
    return $b['recommendation_score'] <=> $a['recommendation_score'];
});
$recommended_partners = array_slice($recommended_partners, 0, 3);

if (empty($partners)) {
    flash_set('No active partnership exists yet. Please request a partnership first so you can create referrals.');
    header('Location: /guestbridgerwanda/partnerships.php');
    exit;
}

$staff_stmt = $pdo->prepare('SELECT id, name, role FROM staff WHERE business_id = ? ORDER BY name');
$staff_stmt->execute([$business_id]);
$staff = $staff_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('create_referral.php');
    $target_business_id = intval($_POST['target_business_id'] ?? 0);
    $staff_id = intval($_POST['staff_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $commission_percentage = floatval($_POST['commission_percentage'] ?? 0);
    $estimated_value = floatval($_POST['estimated_value'] ?? 0);
    $guest_name = trim($_POST['guest_name'] ?? '');
    $guest_phone = trim($_POST['guest_phone'] ?? '');
    $guest_email = trim($_POST['guest_email'] ?? '');
    $guest_benefit_description = trim($_POST['guest_benefit_description'] ?? '');
    $expires_at = trim($_POST['expires_at'] ?? '');

    if ($staff_id <= 0) {
        $stmt = $pdo->prepare('SELECT id FROM staff WHERE business_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$business_id, $user['id']]);
        $staff_id = (int)($stmt->fetchColumn() ?: 0);
    } elseif (!tenant_staff_belongs($pdo, $staff_id, $business_id)) {
        $errors[] = 'Selected staff member is not part of your business.';
        $staff_id = 0;
    }

    if ($target_business_id <= 0) {
        $errors[] = 'Choose a partner business.';
    } else {
        $stmt = $pdo->prepare('SELECT commission_rate FROM partnerships WHERE ((business_id = ? AND partner_business_id = ?) OR (business_id = ? AND partner_business_id = ?)) AND status = ?');
        $stmt->execute([$business_id, $target_business_id, $target_business_id, $business_id, 'active']);
        $active_rate = $stmt->fetchColumn();
        if ($active_rate === false) {
            $errors[] = 'Selected partner is not an active partnership.';
        } elseif ($commission_percentage <= 0) {
            $commission_percentage = floatval($active_rate);
        }
    }

    if ($commission_percentage <= 0) {
        $errors[] = 'Enter a valid commission percentage.';
    }
    if ($estimated_value < 0) {
        $errors[] = 'Estimated value cannot be negative.';
    }

    if (empty($errors)) {
        try {
            // Auto-lookup staff identity for the staff member
            $staff_identity_id = null;
            if ($staff_id > 0) {
                $identity = $services->staffIdentities()->getActiveIdentity($staff_id, $business_id);
                if ($identity) {
                    $staff_identity_id = (int) $identity['id'];
                }
            }

            $referral_id = $services->referrals()->createReferral([
                'source_business_id' => $business_id,
                'target_business_id' => $target_business_id,
                'staff_id' => $staff_id ?: null,
                'staff_identity_id' => $staff_identity_id,
                'note' => $note,
                'commission_percentage' => $commission_percentage,
                'estimated_value' => $estimated_value,
                'guest_name' => $guest_name,
                'guest_phone' => $guest_phone,
                'guest_email' => $guest_email,
                'guest_benefit_description' => $guest_benefit_description,
                'expires_at' => $expires_at ?: null,
                'actor_user_id' => (int)$user['id'],
            ]);

            log_audit($pdo, $business_id, (int)$user['id'], 'referral_created', 'referral', (int)$referral_id, null, [
                'target_business_id' => $target_business_id,
                'commission_percentage' => $commission_percentage,
            ]);

            // Get the referral code for display
            $referral = $services->referrals()->getReferral((int)$referral_id);
            $_SESSION['referral_flash'] = [
                'referral_id' => (int)$referral_id,
                'referral_code' => $referral['referral_code'] ?? '',
            ];
            header('Location: /guestbridgerwanda/view_referral.php?id=' . intval($referral_id));
            exit;
        } catch (Exception $e) {
            $errors[] = 'Unable to save referral: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Referral - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php gb_render_stylesheets(); ?>
</head>
<body>
<?php echo render_app_shell_start($user, $pending_requests, $current_page); ?>
            <?php echo render_breadcrumbs(['Referrals' => 'history.php', 'Create' => false]); ?>
            <?php echo render_page_header(
                'Create Referral',
                'Generate a referral link for a partner business with commission tracking and instant sharing.',
                []
            ); ?>
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3">Create referral</h2>
                    <p class="text-muted">Select a partner, add optional notes, and generate a referral link with commission tracking.</p>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <div class="recommendation-panel mb-4">
                        <div class="d-flex justify-content-between align-items-start flex-column flex-lg-row gap-3">
                            <div>
                                <h3 class="h6 mb-1">Partner recommendations</h3>
                                <p class="text-muted mb-0">Pilot intelligence based on category fit, reputation, conversion history, and budget.</p>
                            </div>
                            <form method="get" class="row gx-2 gy-2 align-items-end">
                                <div class="col-12 col-sm-auto">
                                    <label class="visually-hidden" for="need">Need</label>
                                    <select id="need" name="need" class="form-select form-select-sm">
                                        <option value="">Any category</option>
                                        <?php foreach (business_type_options() as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $recommendation_need === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-auto">
                                    <label class="visually-hidden" for="budget">Budget</label>
                                    <input id="budget" type="number" min="0" step="1000" name="budget" class="form-control form-control-sm" placeholder="Budget RWF" value="<?php echo htmlspecialchars($recommendation_budget); ?>">
                                </div>
                                <div class="col-12 col-sm-auto d-grid">
                                    <button class="btn btn-sm btn-primary">Suggest</button>
                                </div>
                            </form>
                        </div>
                        <div class="recommendation-grid mt-3">
                            <?php foreach ($recommended_partners as $index => $partner): ?>
                                <button type="button" class="recommendation-card" data-partner-id="<?php echo intval($partner['id']); ?>" data-suggested-rate="<?php echo htmlspecialchars($partner['suggested_rate']); ?>">
                                    <span class="recommendation-rank">#<?php echo htmlspecialchars($index + 1); ?></span>
                                    <span class="recommendation-title"><?php echo htmlspecialchars($partner['name']); ?></span>
                                    <span class="recommendation-meta">
                                        <?php echo htmlspecialchars(business_type_label($partner['business_type'])); ?> · score <?php echo htmlspecialchars($partner['recommendation_score']); ?> · suggested <?php echo htmlspecialchars($partner['suggested_rate']); ?>%
                                    </span>
                                    <span class="recommendation-reason"><?php echo htmlspecialchars(implode(', ', $partner['recommendation_reason'])); ?></span>
                                    <span class="recommendation-reason"><?php echo htmlspecialchars($partner['seasonality_label']); ?> · <?php echo htmlspecialchars($partner['seasonality_description']); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Destination</label>
                            <select name="target_business_id" id="target_business_id" class="form-select" required>
                                <option value="">Select partner</option>
                                <?php foreach ($partners as $partner): ?>
                                    <?php
                                    $benefit_text = '';
                                    if ($partner['guest_benefit']) {
                                        $benefit_text = $partner['guest_benefit']['benefit_description'];
                                        if (!empty($partner['guest_benefit']['benefit_value'])) {
                                            $benefit_text = $partner['guest_benefit']['benefit_value'] . ' - ' . $benefit_text;
                                        }
                                    }
                                    ?>
                                    <option value="<?php echo $partner['id']; ?>"
                                        data-rate="<?php echo htmlspecialchars($partner['commission_rate']); ?>"
                                        data-suggested-rate="<?php echo htmlspecialchars($partner['suggested_rate']); ?>"
                                        data-seasonality="<?php echo htmlspecialchars($partner['seasonality_label'] . ': ' . $partner['seasonality_description']); ?>"
                                        data-seasonality-adjustment="<?php echo htmlspecialchars($partner['seasonality_adjustment']); ?>"
                                        data-agreement="<?php echo htmlspecialchars($partner['agreement_text']); ?>"
                                        data-benefit="<?php echo htmlspecialchars($benefit_text); ?>"
                                        <?php echo (isset($_POST['target_business_id']) && $_POST['target_business_id'] == $partner['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($partner['name']); ?> (<?php echo htmlspecialchars(business_type_label($partner['business_type'])); ?>) - reputation <?php echo htmlspecialchars(format_score(reputation_average($partner))); ?>/100
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="partner-agreement" class="alert alert-info mt-3 d-none small"></div>
                            <div id="commission-suggestion" class="small text-muted mt-2"></div>
                        </div>

                        <h5 class="mt-4 mb-3 border-bottom pb-2">Guest Information</h5>
                        <p class="text-muted small mb-3">Guest details are used for referral verification. Contact information is kept private.</p>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Full name</label>
                                <input type="text" name="guest_name" class="form-control" placeholder="Guest name" value="<?php echo htmlspecialchars($_POST['guest_name'] ?? ''); ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="guest_phone" class="form-control" placeholder="+250 7XX XXX XXX" value="<?php echo htmlspecialchars($_POST['guest_phone'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Email</label>
                                <input type="email" name="guest_email" class="form-control" placeholder="guest@example.com" value="<?php echo htmlspecialchars($_POST['guest_email'] ?? ''); ?>">
                            </div>
                        </div>

                        <h5 class="mt-4 mb-3 border-bottom pb-2">Guest Benefit</h5>
                        <p class="text-muted small mb-3">Auto-filled from the destination hotel's configured benefit. You may adjust if needed.</p>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Benefit description</label>
                                <input type="text" name="guest_benefit_description" class="form-control" placeholder="e.g. 10% discount, Complimentary welcome drink, Room upgrade" value="<?php echo htmlspecialchars($_POST['guest_benefit_description'] ?? ''); ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Valid until</label>
                                <input type="date" name="expires_at" class="form-control" value="<?php echo htmlspecialchars($_POST['expires_at'] ?? date('Y-m-d', strtotime('+30 days'))); ?>">
                            </div>
                        </div>

                        <h5 class="mt-4 mb-3 border-bottom pb-2">Referral Details</h5>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Commission %</label>
                                <input type="number" step="0.1" min="0" name="commission_percentage" class="form-control" value="<?php echo htmlspecialchars($_POST['commission_percentage'] ?? '10'); ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Estimated value (RWF)</label>
                                <input type="number" step="0.01" min="0" name="estimated_value" class="form-control" value="<?php echo htmlspecialchars($_POST['estimated_value'] ?? '0'); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea name="note" class="form-control" rows="2" placeholder="Optional special instructions for the destination business"><?php echo htmlspecialchars($_POST['note'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="mt-4 d-flex gap-2">
                            <button class="btn btn-success btn-lg" <?php echo empty($partners) ? 'disabled' : ''; ?>>Create Guest Referral</button>
                            <a href="/guestbridgerwanda/dashboard.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
<?php echo render_app_shell_end(); ?>
<script>
document.getElementById('target_business_id')?.addEventListener('change', function() {
    var selected = this.options[this.selectedIndex];
    var benefitField = document.querySelector('input[name="guest_benefit_description"]');
    if (!benefitField) return;

    var benefit = selected?.getAttribute('data-benefit') || '';

    // Only auto-fill if field is empty or matches a previous auto-fill
    if (!benefitField.value || benefitField.dataset.autoFilled === '1') {
        benefitField.value = benefit;
        benefitField.dataset.autoFilled = benefit ? '1' : '0';
    }
});

// On page load, if a partner is already selected, auto-fill
(function() {
    var sel = document.getElementById('target_business_id');
    if (!sel || sel.value === '') return;
    var selected = sel.options[sel.selectedIndex];
    var benefit = selected?.getAttribute('data-benefit') || '';
    var benefitField = document.querySelector('input[name="guest_benefit_description"]');
    if (benefitField && benefit && !benefitField.value) {
        benefitField.value = benefit;
        benefitField.dataset.autoFilled = '1';
    }
})();
</script>
</body>
</html>
