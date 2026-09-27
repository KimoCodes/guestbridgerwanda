<?php
/**
 * Staff Referral Identity Service
 * Manages permanent staff referral identities (STF-XXXXXX codes).
 */

class StaffIdentityService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Generate a unique staff identity code.
     * Format: STF-XXXXXXXX (8 random alphanumeric chars, uppercase, no ambiguous chars)
     */
    public function generateIdentityCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = 'STF-';
            for ($i = 0; $i < 8; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM staff_referral_identities WHERE public_identity_code = ?');
            $stmt->execute([$code]);
        } while ((int) $stmt->fetchColumn() > 0);

        return $code;
    }

    /**
     * Create a staff referral identity.
     */
    public function createIdentity(int $staffId, int $businessId): int
    {
        // Check if staff already has an active identity
        $existing = $this->getActiveIdentity($staffId, $businessId);
        if ($existing) {
            throw new RuntimeException('This staff member already has an active referral identity.');
        }

        // Verify staff belongs to business
        $stmt = $this->pdo->prepare('SELECT id FROM staff WHERE id = ? AND business_id = ?');
        $stmt->execute([$staffId, $businessId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('Staff member not found in this business.');
        }

        $code = $this->generateIdentityCode();

        $stmt = $this->pdo->prepare('INSERT INTO staff_referral_identities 
            (staff_id, business_id, public_identity_code, status) 
            VALUES (?, ?, ?, ?)');
        $stmt->execute([$staffId, $businessId, $code, 'active']);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Get active identity for a staff member at a business.
     */
    public function getActiveIdentity(int $staffId, int $businessId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT sri.*, s.name AS staff_name, s.role AS staff_role
            FROM staff_referral_identities sri
            JOIN staff s ON s.id = sri.staff_id
            WHERE sri.staff_id = ? AND sri.business_id = ? AND sri.status = ?
            LIMIT 1');
        $stmt->execute([$staffId, $businessId, 'active']);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get identity by ID with tenant check.
     */
    public function getIdentity(int $id, ?int $businessId = null): ?array
    {
        $sql = 'SELECT sri.*, s.name AS staff_name, s.role AS staff_role, s.phone AS staff_phone
            FROM staff_referral_identities sri
            JOIN staff s ON s.id = sri.staff_id
            WHERE sri.id = ?';
        $params = [$id];

        if ($businessId !== null) {
            $sql .= ' AND sri.business_id = ?';
            $params[] = $businessId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get identity by public code (for lookup).
     */
    public function getIdentityByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT sri.*, s.name AS staff_name, s.role AS staff_role,
            b.name AS business_name
            FROM staff_referral_identities sri
            JOIN staff s ON s.id = sri.staff_id
            JOIN businesses b ON b.id = sri.business_id
            WHERE sri.public_identity_code = ?');
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all identities for a business.
     */
    public function getBusinessIdentities(int $businessId, ?string $status = null): array
    {
        $sql = 'SELECT sri.*, s.name AS staff_name, s.role AS staff_role
            FROM staff_referral_identities sri
            JOIN staff s ON s.id = sri.staff_id
            WHERE sri.business_id = ?';
        $params = [$businessId];

        if ($status) {
            $sql .= ' AND sri.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY sri.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Deactivate a staff identity.
     */
    public function deactivateIdentity(int $id, int $businessId): bool
    {
        $identity = $this->getIdentity($id, $businessId);
        if (!$identity || $identity['status'] !== 'active') {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE staff_referral_identities 
            SET status = \'inactive\', deactivated_at = NOW() 
            WHERE id = ? AND business_id = ?');
        $stmt->execute([$id, $businessId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reactivate a staff identity.
     */
    public function reactivateIdentity(int $id, int $businessId): bool
    {
        $identity = $this->getIdentity($id, $businessId);
        if (!$identity || $identity['status'] !== 'inactive') {
            return false;
        }

        // Check if there's already an active identity for this staff
        $active = $this->getActiveIdentity($identity['staff_id'], $businessId);
        if ($active) {
            throw new RuntimeException('This staff member already has an active identity. Deactivate it first.');
        }

        $stmt = $this->pdo->prepare('UPDATE staff_referral_identities 
            SET status = \'active\', deactivated_at = NULL 
            WHERE id = ? AND business_id = ?');
        $stmt->execute([$id, $businessId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update identity performance stats (called after referral events).
     */
    public function updateStats(int $identityId): void
    {
        $stmt = $this->pdo->prepare('SELECT 
            COUNT(r.id) AS total_referrals,
            SUM(CASE WHEN r.status IN (\'converted\',\'settled\') THEN 1 ELSE 0 END) AS successful_referrals,
            COALESCE(SUM(CASE WHEN r.status IN (\'converted\',\'settled\') THEN g.gross_amount ELSE 0 END), 0) AS total_revenue,
            COALESCE(SUM(CASE WHEN r.status IN (\'converted\',\'settled\') THEN c.amount ELSE 0 END), 0) AS total_commission
            FROM referrals r
            LEFT JOIN guest_transactions g ON g.referral_id = r.id
            LEFT JOIN commissions c ON c.referral_id = r.id
            WHERE r.staff_identity_id = ?');
        $stmt->execute([$identityId]);
        $stats = $stmt->fetch();

        if ($stats) {
            $this->pdo->prepare('UPDATE staff_referral_identities 
                SET total_referrals = ?, successful_referrals = ?, total_revenue = ?, total_commission = ?
                WHERE id = ?')
                ->execute([
                    (int) $stats['total_referrals'],
                    (int) $stats['successful_referrals'],
                    (float) $stats['total_revenue'],
                    (float) $stats['total_commission'],
                    $identityId
                ]);
        }
    }

    /**
     * Get staff performance leaderboard for a business.
     */
    public function getLeaderboard(int $businessId, ?string $period = null, int $limit = 20): array
    {
        $sql = 'SELECT sri.*, s.name AS staff_name, s.role AS staff_role
            FROM staff_referral_identities sri
            JOIN staff s ON s.id = sri.staff_id
            WHERE sri.business_id = ? AND sri.status = \'active\'';

        $params = [$businessId];

        if ($period) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM referrals r 
                WHERE r.staff_identity_id = sri.id 
                AND to_char(r.created_at, \'YYYY-MM\') = ?
            )';
            $params[] = $period;
        }

        $sql .= ' ORDER BY sri.total_revenue DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get employee performance details.
     */
    public function getEmployeePerformance(int $identityId, ?string $period = null): array
    {
        $identity = $this->getIdentity($identityId);
        if (!$identity) {
            return [];
        }

        $sql = 'SELECT 
            COUNT(r.id) AS total_referrals,
            SUM(CASE WHEN r.status = \'verified\' THEN 1 ELSE 0 END) AS verified_count,
            SUM(CASE WHEN r.status = \'accepted\' THEN 1 ELSE 0 END) AS accepted_count,
            SUM(CASE WHEN r.status = \'redeemed\' THEN 1 ELSE 0 END) AS redeemed_count,
            SUM(CASE WHEN r.status IN (\'converted\',\'settled\') THEN 1 ELSE 0 END) AS converted_count,
            COALESCE(SUM(CASE WHEN r.status IN (\'converted\',\'settled\') THEN g.gross_amount ELSE 0 END), 0) AS total_guest_value,
            COALESCE(SUM(CASE WHEN r.status IN (\'converted\',\'settled\') THEN c.amount ELSE 0 END), 0) AS total_commission,
            COALESCE(SUM(CASE WHEN c.status = \'settled\' THEN c.amount ELSE 0 END), 0) AS settled_commission,
            COALESCE(SUM(CASE WHEN c.status IN (\'pending\',\'confirmed\') THEN c.amount ELSE 0 END), 0) AS pending_commission
            FROM referrals r
            LEFT JOIN guest_transactions g ON g.referral_id = r.id
            LEFT JOIN commissions c ON c.referral_id = r.id
            WHERE r.staff_identity_id = ?';

        $params = [$identityId];

        if ($period) {
            $sql .= ' AND to_char(r.created_at, \'YYYY-MM\') = ?';
            $params[] = $period;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $stats = $stmt->fetch();

        $conversionRate = (int) $stats['total_referrals'] > 0
            ? round(((int) $stats['converted_count'] / (int) $stats['total_referrals']) * 100, 1)
            : 0;

        return array_merge($stats, [
            'identity' => $identity,
            'conversion_rate' => $conversionRate,
        ]);
    }
}
