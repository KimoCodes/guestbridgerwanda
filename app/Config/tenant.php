<?php
/**
 * Multi-tenant data access guards — every query should scope to the logged-in business
 * unless the user is a platform operator (PLATFORM_ADMIN_EMAILS).
 */

function tenant_business_id(array $user): int
{
    return (int) ($user['business_id'] ?? 0);
}

/** Require a logged-in user with a valid business assignment. */
function require_tenant_user(): array
{
    require_login();
    $user = current_user();
    if (!$user || tenant_business_id($user) <= 0 || empty($user['business_name'])) {
        unset($_SESSION['user_id'], $_SESSION['business_id']);
        flash_set('Your account is not linked to a business. Please contact your manager or register a new business.');
        gb_redirect('login.php');
    }
    return $user;
}

/** Staff row for the logged-in user at their business (for "my referrals" filters). */
function tenant_staff_id_for_user(PDO $pdo, array $user): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM staff WHERE business_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([tenant_business_id($user), (int) $user['id']]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/** Ensure every login account has a staff record tied to their hotel. */
function tenant_ensure_user_staff_link(PDO $pdo, array $user): void
{
    $business_id = tenant_business_id($user);
    $user_id = (int) $user['id'];
    if ($business_id <= 0 || $user_id <= 0) {
        return;
    }
    $stmt = $pdo->prepare('SELECT id FROM staff WHERE business_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$business_id, $user_id]);
    if ($stmt->fetch()) {
        return;
    }
    $role = in_array($user['role'] ?? '', ['manager', 'receptionist', 'concierge'], true)
        ? $user['role']
        : 'receptionist';
    $pdo->prepare('INSERT INTO staff (business_id, user_id, name, role) VALUES (?, ?, ?, ?)')
        ->execute([$business_id, $user_id, $user['name'] ?? 'Staff', $role]);
}

function tenant_can_view_network(array $user): bool
{
    return is_platform_admin($user);
}

function tenant_is_super_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'super_admin';
}

/** Businesses linked by partnership or referral/commission activity. */
function tenant_partner_linked(PDO $pdo, int $business_id, int $other_business_id): bool
{
    if ($business_id <= 0 || $other_business_id <= 0 || $business_id === $other_business_id) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM partnerships
        WHERE status IN ("active", "pending")
          AND ((business_id = ? AND partner_business_id = ?) OR (business_id = ? AND partner_business_id = ?))');
    $stmt->execute([$business_id, $other_business_id, $other_business_id, $business_id]);
    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM referrals
        WHERE (source_business_id = ? AND target_business_id = ?)
           OR (source_business_id = ? AND target_business_id = ?)
        LIMIT 1');
    $stmt->execute([$business_id, $other_business_id, $other_business_id, $business_id]);
    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM commissions
        WHERE (source_business_id = ? AND target_business_id = ?)
           OR (source_business_id = ? AND target_business_id = ?)
           OR (owed_to_business_id = ? AND target_business_id = ?)
           OR (owed_to_business_id = ? AND target_business_id = ?)
        LIMIT 1');
    $stmt->execute([
        $business_id, $other_business_id,
        $other_business_id, $business_id,
        $business_id, $other_business_id,
        $other_business_id, $business_id,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function tenant_assert_partner_filter(PDO $pdo, int $business_id, int $partner_id): void
{
    if ($partner_id <= 0) {
        return;
    }
    if (!tenant_partner_linked($pdo, $business_id, $partner_id)) {
        http_response_code(403);
        die('You do not have access to that partner\'s data.');
    }
}

function tenant_fetch_referral(PDO $pdo, int $referral_id, int $business_id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM referrals WHERE id = ? LIMIT 1');
    $stmt->execute([$referral_id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $user = current_user();
    if (tenant_is_super_admin($user ?? [])) {
        return $row;
    }
    $src = (int) $row['source_business_id'];
    $tgt = (int) $row['target_business_id'];
    if ($src !== $business_id && $tgt !== $business_id) {
        return null;
    }
    return $row;
}

function tenant_fetch_commission(PDO $pdo, int $commission_id, int $business_id): ?array
{
    $user = current_user();
    if (tenant_is_super_admin($user ?? [])) {
        $stmt = $pdo->prepare('SELECT * FROM commissions WHERE id = ? LIMIT 1');
        $stmt->execute([$commission_id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
    $stmt = $pdo->prepare('SELECT * FROM commissions WHERE id = ?
        AND (owed_to_business_id = ? OR target_business_id = ? OR source_business_id = ?)
        LIMIT 1');
    $stmt->execute([$commission_id, $business_id, $business_id, $business_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tenant_fetch_partnership(PDO $pdo, int $partnership_id, int $business_id): ?array
{
    $user = current_user();
    if (tenant_is_super_admin($user ?? [])) {
        $stmt = $pdo->prepare('SELECT * FROM partnerships WHERE id = ? LIMIT 1');
        $stmt->execute([$partnership_id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
    $stmt = $pdo->prepare('SELECT * FROM partnerships WHERE id = ?
        AND (business_id = ? OR partner_business_id = ?)
        LIMIT 1');
    $stmt->execute([$partnership_id, $business_id, $business_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tenant_staff_belongs(PDO $pdo, int $staff_id, int $business_id): bool
{
    if ($staff_id <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE id = ? AND business_id = ?');
    $stmt->execute([$staff_id, $business_id]);
    return (int) $stmt->fetchColumn() > 0;
}

/** SQL fragment + params for platform_fees queries (alias pf). */
function tenant_platform_fee_scope(array $user, string $alias = 'pf'): array
{
    if (tenant_can_view_network($user) || tenant_is_super_admin($user)) {
        return ['', []];
    }
    $col = $alias . '.business_id';
    return [' AND ' . $col . ' = ?', [tenant_business_id($user)]];
}

function tenant_assert_platform_fee_row(PDO $pdo, int $fee_id, array $user): void
{
    if (tenant_can_view_network($user) || tenant_is_super_admin($user)) {
        return;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM platform_fees WHERE id = ? AND business_id = ?');
    $stmt->execute([$fee_id, tenant_business_id($user)]);
    if ((int) $stmt->fetchColumn() === 0) {
        http_response_code(403);
        die('You do not have access to that platform fee record.');
    }
}

function tenant_commission_involves_business(array $commission, int $business_id): bool
{
    return in_array($business_id, [
        (int) ($commission['source_business_id'] ?? 0),
        (int) ($commission['target_business_id'] ?? 0),
        (int) ($commission['owed_to_business_id'] ?? 0),
    ], true);
}
