<?php
/**
 * CommissionService - Handles commission calculation, distribution, and allocation.
 */

class CommissionService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Calculate and allocate commission for a transaction
     * This is the core commission engine - all calculations server-side
     */
    public function calculateCommission(int $referral_id, float $eligible_amount): array
    {
        // Server-side validation
        if ($eligible_amount <= 0) {
            throw new RuntimeException('Eligible amount must be greater than zero.');
        }
        if ($eligible_amount > 999999999999.99) {
            throw new RuntimeException('Eligible amount exceeds maximum allowed value.');
        }

        $referral = $this->getReferralWithPartnership($referral_id);
        if (!$referral) {
            throw new RuntimeException('Referral not found.');
        }

        // Get the commission rule for this partnership
        $rule = $this->getActiveCommissionRule($referral['partnership_id']);
        if (!$rule) {
            // Fallback to referral's commission percentage
            $commission_rate = (float) $referral['commission_percentage'];
            $referring_business_pct = 70.00;
            $employee_pct = 20.00;
            $platform_pct = 10.00;
        } else {
            $commission_rate = (float) $rule['commission_value'];
            $referring_business_pct = (float) $rule['referring_business_pct'];
            $employee_pct = (float) $rule['employee_pct'];
            $platform_pct = (float) $rule['platform_pct'];
        }

        // Validate allocation totals 100%
        $total_pct = $referring_business_pct + $employee_pct + $platform_pct;
        if (abs($total_pct - 100) > 0.01) {
            throw new RuntimeException("Commission allocation must equal 100%. Current: {$total_pct}%");
        }

        // Calculate total commission
        if (isset($rule) && $rule['commission_type'] === 'fixed') {
            $total_commission = $commission_rate;
        } else {
            $total_commission = $eligible_amount * ($commission_rate / 100);
        }

        // Apply min/max if set
        if (isset($rule)) {
            if ($rule['min_commission'] !== null && $total_commission < (float) $rule['min_commission']) {
                $total_commission = (float) $rule['min_commission'];
            }
            if ($rule['max_commission'] !== null && $total_commission > (float) $rule['max_commission']) {
                $total_commission = (float) $rule['max_commission'];
            }
        }

        // Calculate shares
        $referring_business_share = round($total_commission * ($referring_business_pct / 100), 2);
        $employee_share = round($total_commission * ($employee_pct / 100), 2);
        $platform_share = round($total_commission - $referring_business_share - $employee_share, 2);

        return [
            'referral_id' => $referral_id,
            'eligible_amount' => $eligible_amount,
            'commission_rate' => $commission_rate,
            'total_commission' => $total_commission,
            'referring_business_share' => $referring_business_share,
            'employee_share' => $employee_share,
            'platform_share' => $platform_share,
            'referring_business_pct' => $referring_business_pct,
            'employee_pct' => $employee_pct,
            'platform_pct' => $platform_pct,
            'source_business_id' => $referral['source_business_id'],
            'target_business_id' => $referral['target_business_id'],
            'staff_id' => $referral['staff_id'],
        ];
    }

    /**
     * Create commission allocation records
     */
    public function allocateCommission(int $commission_id, array $calculation): void
    {
        // Prevent duplicate allocations
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM commission_allocations WHERE commission_id = ?');
        $stmt->execute([$commission_id]);
        if ((int) $stmt->fetchColumn() > 0) {
            return; // Allocations already exist, skip
        }

        $this->pdo->beginTransaction();
        try {
            // Update commission record with shares
            $stmt = $this->pdo->prepare('UPDATE commissions SET 
                referring_business_share = ?,
                employee_share = ?,
                platform_share = ?,
                employee_id = ?
                WHERE id = ?');
            $stmt->execute([
                $calculation['referring_business_share'],
                $calculation['employee_share'],
                $calculation['platform_share'],
                $calculation['staff_id'] ?? null,
                $commission_id
            ]);

            // Create allocation records
            // 1. Referring business allocation
            $stmt = $this->pdo->prepare('INSERT INTO commission_allocations 
                (commission_id, allocation_type, recipient_business_id, amount, percentage, status) 
                VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $commission_id,
                'business',
                $calculation['source_business_id'],
                $calculation['referring_business_share'],
                $calculation['referring_business_pct'],
                'pending'
            ]);

            // 2. Employee allocation (if staff_id exists)
            if ($calculation['staff_id'] && $calculation['employee_share'] > 0) {
                $stmt = $this->pdo->prepare('INSERT INTO commission_allocations 
                    (commission_id, allocation_type, recipient_employee_id, amount, percentage, status) 
                    VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $commission_id,
                    'employee',
                    $calculation['staff_id'],
                    $calculation['employee_share'],
                    $calculation['employee_pct'],
                    'pending'
                ]);
            }

            // 3. Platform allocation
            if ($calculation['platform_share'] > 0) {
                $stmt = $this->pdo->prepare('INSERT INTO commission_allocations 
                    (commission_id, allocation_type, amount, percentage, status) 
                    VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    $commission_id,
                    'platform',
                    $calculation['platform_share'],
                    $calculation['platform_pct'],
                    'pending'
                ]);
            }

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Process a guest transaction and create commission
     */
    public function processTransaction(int $referral_id, float $gross_amount, float $eligible_amount, ?int $employee_id = null, ?string $notes = null): array
    {
        $referral = $this->getReferralWithPartnership($referral_id);
        if (!$referral) {
            throw new RuntimeException('Referral not found.');
        }

        if (!in_array($referral['status'], ['accepted', 'visited', 'redeemed'], true)) {
            throw new RuntimeException('Referral must be accepted or visited before recording a transaction.');
        }

        $this->pdo->beginTransaction();
        try {
            // Create guest transaction
            $stmt = $this->pdo->prepare('INSERT INTO guest_transactions 
                (referral_id, business_id, gross_amount, eligible_amount, employee_id, notes, recorded_by_user_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $referral_id,
                $referral['target_business_id'],
                $gross_amount,
                $eligible_amount,
                $employee_id,
                $notes,
                $employee_id // Using employee_id as recorder for now
            ]);
            $transaction_id = (int) $this->pdo->lastInsertId();

            // Calculate commission
            $calculation = $this->calculateCommission($referral_id, $eligible_amount);

            // Update commission record
            $stmt = $this->pdo->prepare('UPDATE commissions SET 
                estimated_value = ?,
                amount = ?,
                commission_percentage = ?,
                status = \'confirmed\'
                WHERE referral_id = ? AND status IN (\'pending\',\'confirmed\')');
            $stmt->execute([
                $eligible_amount,
                $calculation['total_commission'],
                $calculation['commission_rate'],
                $referral_id
            ]);

            $commission_id = $this->getCommissionId($referral_id);

            // Allocate commission
            if ($commission_id) {
                $this->allocateCommission($commission_id, $calculation);
            }

            // Update referral status to converted
            $stmt = $this->pdo->prepare('UPDATE referrals SET status = \'converted\', converted_at = NOW() WHERE id = ? AND status IN (\'accepted\',\'visited\')');
            $stmt->execute([$referral_id]);

            // Log event
            $this->logReferralEvent($referral_id, 'converted', $referral['status'], 'converted', $employee_id, $referral['target_business_id'], "Transaction: RWF " . number_format($eligible_amount));

            $this->pdo->commit();

            return [
                'transaction_id' => $transaction_id,
                'commission' => $calculation,
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get commission by ID
     */
    public function getCommission(int $id, ?int $business_id = null): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.*, 
            sb.name AS source_business_name, 
            tb.name AS target_business_name
            FROM commissions c 
            LEFT JOIN businesses sb ON sb.id = c.source_business_id 
            LEFT JOIN businesses tb ON tb.id = c.target_business_id
            WHERE c.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || $business_id === null) {
            return $row ?: null;
        }
        // Tenant check: user must be source, target, or owed_to business
        $src = (int) $row['source_business_id'];
        $tgt = (int) $row['target_business_id'];
        $owed = (int) $row['owed_to_business_id'];
        if ($src !== $business_id && $tgt !== $business_id && $owed !== $business_id) {
            return null;
        }
        return $row;
    }

    /**
     * Get commissions for a business
     */
    public function getBusinessCommissions(int $business_id, ?string $status = null, ?string $month = null, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT c.*, 
            sb.name AS source_business_name, 
            tb.name AS target_business_name
            FROM commissions c 
            LEFT JOIN businesses sb ON sb.id = c.source_business_id 
            LEFT JOIN businesses tb ON tb.id = c.target_business_id
            WHERE (c.owed_to_business_id = ? OR c.target_business_id = ? OR c.source_business_id = ?)';
        $params = [$business_id, $business_id, $business_id];

        if ($status) {
            $sql .= ' AND c.status = ?';
            $params[] = $status;
        }
        if ($month) {
            $sql .= ' AND c.month = ?';
            $params[] = $month;
        }

        $sql .= sprintf(' ORDER BY c.created_at DESC LIMIT %d OFFSET %d', (int)$limit, (int)$offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get commission allocations for a commission
     */
    public function getAllocations(int $commission_id): array
    {
        $stmt = $this->pdo->prepare('SELECT ca.*, 
            b.name AS business_name,
            s.name AS employee_name
            FROM commission_allocations ca 
            LEFT JOIN businesses b ON b.id = ca.recipient_business_id
            LEFT JOIN staff s ON s.id = ca.recipient_employee_id
            WHERE ca.commission_id = ?');
        $stmt->execute([$commission_id]);
        return $stmt->fetchAll();
    }

    /**
     * Get employee earnings summary
     */
    public function getEmployeeEarnings(int $employee_id, ?string $month = null): array
    {
        $month = $month ?: date('Y-m');

        $stmt = $this->pdo->prepare('SELECT 
            COUNT(DISTINCT r.id) AS total_referrals,
            SUM(CASE WHEN r.status IN (\'converted\',\'settled\') THEN 1 ELSE 0 END) AS successful_referrals,
            COALESCE(SUM(CASE WHEN r.status IN (\'converted\',\'settled\') THEN gt.eligible_amount ELSE 0 END), 0) AS total_guest_value,
            COALESCE(SUM(ca.amount), 0) AS total_commission,
            COALESCE(SUM(CASE WHEN ca.status = \'pending\' THEN ca.amount ELSE 0 END), 0) AS pending_commission,
            COALESCE(SUM(CASE WHEN ca.status = \'settled\' THEN ca.amount ELSE 0 END), 0) AS settled_commission
            FROM referrals r
            LEFT JOIN guest_transactions gt ON gt.referral_id = r.id
            LEFT JOIN commission_allocations ca ON ca.commission_id = (
                SELECT id FROM commissions WHERE referral_id = r.id LIMIT 1
            ) AND ca.allocation_type = \'employee\' AND ca.recipient_employee_id = ?
            WHERE r.staff_id = ? AND to_char(r.created_at, \'YYYY-MM\') = ?');
        $stmt->execute([$employee_id, $employee_id, $month]);
        return $stmt->fetch() ?: [];
    }

    /**
     * Get business earnings summary
     */
    public function getBusinessEarnings(int $business_id, ?string $month = null): array
    {
        $month = $month ?: date('Y-m');

        // Commission earned (as source)
        $stmt = $this->pdo->prepare('SELECT 
            COALESCE(SUM(amount), 0) AS total_earned,
            COALESCE(SUM(CASE WHEN status = \'confirmed\' THEN amount ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status IN (\'reconciled\',\'settled\') THEN amount ELSE 0 END), 0) AS settled
            FROM commissions WHERE source_business_id = ? AND month = ?');
        $stmt->execute([$business_id, $month]);
        $earned = $stmt->fetch();

        // Commission payable (as target)
        $stmt = $this->pdo->prepare('SELECT 
            COALESCE(SUM(amount), 0) AS total_owed,
            COALESCE(SUM(CASE WHEN status = \'confirmed\' THEN amount ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status IN (\'reconciled\',\'settled\') THEN amount ELSE 0 END), 0) AS settled
            FROM commissions WHERE target_business_id = ? AND month = ?');
        $stmt->execute([$business_id, $month]);
        $owed = $stmt->fetch();

        // Employee commissions payable
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(ca.amount), 0) AS total
            FROM commission_allocations ca
            JOIN commissions c ON c.id = ca.commission_id
            WHERE ca.allocation_type = \'employee\' 
            AND c.source_business_id = ?
            AND to_char(c.created_at, \'YYYY-MM\') = ?
            AND ca.status = \'pending\'');
        $stmt->execute([$business_id, $month]);
        $employee_commissions = (float) $stmt->fetchColumn();

        // GuestBridge platform share
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(ca.amount), 0) AS total
            FROM commission_allocations ca
            JOIN commissions c ON c.id = ca.commission_id
            WHERE ca.allocation_type = \'platform\' 
            AND c.source_business_id = ?
            AND to_char(c.created_at, \'YYYY-MM\') = ?');
        $stmt->execute([$business_id, $month]);
        $platform_share = (float) $stmt->fetchColumn();

        return [
            'referral_value_generated' => (float) ($earned['total_earned'] ?? 0),
            'commission_earned' => (float) ($earned['total_earned'] ?? 0),
            'commission_pending' => (float) ($earned['pending'] ?? 0),
            'commission_settled' => (float) ($earned['settled'] ?? 0),
            'commission_payable' => (float) ($owed['total_owed'] ?? 0),
            'employee_commissions' => $employee_commissions,
            'platform_fee' => $platform_share,
        ];
    }

    /**
     * Get active commission rule for a partnership
     */
    private function getActiveCommissionRule(int $partnership_id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commission_rules 
            WHERE partnership_id = ? AND status = \'active\'
            AND (effective_start_date IS NULL OR effective_start_date <= CURRENT_DATE)
            AND (effective_end_date IS NULL OR effective_end_date >= CURRENT_DATE)
            ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$partnership_id]);
        return $stmt->fetch() ?: null;
    }

    private function getReferralWithPartnership(int $referral_id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT r.*, p.id AS partnership_id, p.commission_rate AS partnership_rate
            FROM referrals r
            LEFT JOIN partnerships p ON p.business_id = r.source_business_id AND p.partner_business_id = r.target_business_id AND p.status = \'active\'
            WHERE r.id = ?');
        $stmt->execute([$referral_id]);
        return $stmt->fetch() ?: null;
    }

    private function getCommissionId(int $referral_id): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM commissions WHERE referral_id = ? LIMIT 1');
        $stmt->execute([$referral_id]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    private function logReferralEvent(int $referral_id, string $event_type, ?string $old_status, string $new_status, ?int $actor_user_id, ?int $actor_business_id, ?string $notes = null): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO referral_events 
            (referral_id, event_type, old_status, new_status, actor_user_id, actor_business_id, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$referral_id, $event_type, $old_status, $new_status, $actor_user_id, $actor_business_id, $notes]);
    }
}
