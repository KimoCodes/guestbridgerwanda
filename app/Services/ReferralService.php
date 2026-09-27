<?php
/**
 * ReferralService - Handles referral lifecycle, creation, validation, and status transitions.
 */

class ReferralService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Generate a unique referral code in format GBR-XXXXXXXX
     */
    public function generateReferralCode(): string
    {
        do {
            $code = 'GBR-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM referrals WHERE referral_code = ?');
            $stmt->execute([$code]);
        } while ((int)$stmt->fetchColumn() > 0);

        return $code;
    }

    /**
     * Create a new referral with full attribution
     */
    public function createReferral(array $data): int
    {
        // Server-side input validation
        $source_business_id = (int) ($data['source_business_id'] ?? 0);
        $target_business_id = (int) ($data['target_business_id'] ?? 0);
        $commission_percentage = (float) ($data['commission_percentage'] ?? 0);
        $estimated_value = (float) ($data['estimated_value'] ?? 0);

        if ($source_business_id <= 0) {
            throw new RuntimeException('Invalid source business.');
        }
        if ($target_business_id <= 0) {
            throw new RuntimeException('Invalid target business.');
        }
        if ($source_business_id === $target_business_id) {
            throw new RuntimeException('Source and target business cannot be the same.');
        }
        if ($commission_percentage < 0 || $commission_percentage > 100) {
            throw new RuntimeException('Commission percentage must be between 0 and 100.');
        }
        if ($estimated_value < 0) {
            throw new RuntimeException('Estimated value cannot be negative.');
        }

        // Validate guest email if provided
        $guest_email = trim($data['guest_email'] ?? '');
        if ($guest_email !== '' && !filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid guest email address.');
        }

        // Validate expiration
        $expires_at = $data['expires_at'] ?? $this->getExpiryDate();
        if ($expires_at && strtotime($expires_at) < time()) {
            throw new RuntimeException('Expiration date cannot be in the past.');
        }

        $this->pdo->beginTransaction();
        try {
            $referral_code = $this->generateReferralCode();
            $secure_token = bin2hex(random_bytes(32));
            $pin = (string) random_int(100000, 999999);
            $pin_hash = password_hash($pin, PASSWORD_DEFAULT);

            // Create or find guest record
            $guest_id = null;
            $guest_name = trim($data['guest_name'] ?? '');
            $guest_phone = trim($data['guest_phone'] ?? '');
            if ($guest_name !== '') {
                // Try to find existing guest by phone or email
                $guest_id = $this->findOrCreateGuest($guest_name, $guest_phone, $guest_email);
            }

            // Insert referral
            $stmt = $this->pdo->prepare('INSERT INTO referrals 
                (referral_code, secure_token, redemption_pin_hash, source_business_id, target_business_id, staff_id, staff_identity_id,
                 note, commission_percentage, estimated_value, guest_id, guest_name, guest_phone, guest_email,
                 guest_benefit_description, expires_at, issued_at, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
            $stmt->execute([
                $referral_code,
                $secure_token,
                $pin_hash,
                $data['source_business_id'],
                $data['target_business_id'],
                $data['staff_id'] ?? null,
                $data['staff_identity_id'] ?? null,
                $data['note'] ?? null,
                $data['commission_percentage'],
                $data['estimated_value'] ?? 0,
                $guest_id,
                $guest_name ?: null,
                $guest_phone ?: null,
                $guest_email ?: null,
                $data['guest_benefit_description'] ?? null,
                $expires_at,
                'created'
            ]);
            $referral_id = (int) $this->pdo->lastInsertId();

            // Create commission record
            $commission_amount = ($data['estimated_value'] * $data['commission_percentage']) / 100;
            $month_key = date('Y-m');
            
            $stmt = $this->pdo->prepare('INSERT INTO commissions 
                (referral_id, source_business_id, target_business_id, commission_percentage, 
                 estimated_value, amount, owed_to_business_id, month) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $referral_id,
                $data['source_business_id'],
                $data['target_business_id'],
                $data['commission_percentage'],
                $data['estimated_value'] ?? 0,
                $commission_amount,
                $data['source_business_id'],
                $month_key
            ]);

            // Log referral event
            $this->logReferralEvent($referral_id, 'created', null, 'created', $data['actor_user_id'] ?? null, $data['source_business_id']);

            // Create notification for target business
            $this->createNotification(
                $data['target_business_id'],
                'referral_received',
                'New Referral Received',
                "You have a new referral from business #{$data['source_business_id']}",
                'referral',
                $referral_id
            );

            $this->pdo->commit();
            return $referral_id;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Transition referral status with validation
     */
    public function updateStatus(int $referral_id, string $new_status, ?int $actor_user_id = null, ?string $note = null): bool
    {
        $referral = $this->getReferral($referral_id);
        if (!$referral) {
            throw new RuntimeException('Referral not found.');
        }

        $valid_transitions = [
            'created' => ['verified', 'accepted', 'cancelled', 'expired', 'rejected'],
            'verified' => ['accepted', 'cancelled', 'expired', 'rejected'],
            'accepted' => ['redeemed', 'visited', 'cancelled', 'expired'],
            'redeemed' => ['visited', 'converted', 'cancelled'],
            'visited' => ['converted', 'cancelled'],
            'converted' => ['settled'],
            // Terminal states
            'settled' => [],
            'cancelled' => [],
            'expired' => [],
            'rejected' => [],
            'disputed' => [],
        ];

        $current_status = $referral['status'];
        if (!in_array($new_status, $valid_transitions[$current_status] ?? [], true)) {
            throw new RuntimeException("Invalid status transition from '{$current_status}' to '{$new_status}'.");
        }

        $this->pdo->beginTransaction();
        try {
            $old_status = $current_status;
            
            // Build update query
            $update_fields = ['status' => $new_status];
            $timestamp_field = $new_status . '_at';
            if (in_array($timestamp_field, ['accepted_at', 'visited_at', 'converted_at', 'settled_at', 'cancelled_at', 'rejected_at', 'disputed_at', 'verified_at', 'redeemed_at'])) {
                $update_fields[$timestamp_field] = date('Y-m-d H:i:s');
            }
            if ($note) {
                $update_fields['status_note'] = $note;
            }
            if ($new_status === 'accepted' && $actor_user_id) {
                $update_fields['accepted_by_user_id'] = $actor_user_id;
            }

            $sets = [];
            $vals = [];
            foreach ($update_fields as $col => $val) {
                $sets[] = "{$col} = ?";
                $vals[] = $val;
            }
            $vals[] = $referral_id;

            $this->pdo->prepare('UPDATE referrals SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

            // Log event
            $this->logReferralEvent($referral_id, $new_status, $old_status, $new_status, $actor_user_id, $referral['source_business_id'], $note);

            // If converted, ensure commission is confirmed
            if ($new_status === 'converted') {
                $this->pdo->prepare('UPDATE commissions SET status = \'confirmed\' WHERE referral_id = ? AND status = \'pending\'')->execute([$referral_id]);
            }

            // If settled, mark commission as settled
            if ($new_status === 'settled') {
                $this->pdo->prepare('UPDATE commissions SET status = \'settled\', settled_at = NOW() WHERE referral_id = ? AND status IN (\'confirmed\',\'reconciled\')')->execute([$referral_id]);
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get referral by ID with tenant check
     */
    public function getReferral(int $id, ?int $business_id = null): ?array
    {
        $stmt = $this->pdo->prepare('SELECT r.*, 
            sb.name AS source_business_name, 
            tb.name AS target_business_name,
            s.name AS staff_name
            FROM referrals r 
            LEFT JOIN businesses sb ON sb.id = r.source_business_id 
            LEFT JOIN businesses tb ON tb.id = r.target_business_id
            LEFT JOIN staff s ON s.id = r.staff_id
            WHERE r.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || $business_id === null) {
            return $row ?: null;
        }
        // Tenant check: user must be source or target business
        $src = (int) $row['source_business_id'];
        $tgt = (int) $row['target_business_id'];
        if ($src !== $business_id && $tgt !== $business_id) {
            return null;
        }
        return $row;
    }

    /**
     * Get referral by code (public access)
     */
    public function getReferralByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT r.id, r.referral_code, r.secure_token, r.source_business_id, r.target_business_id,
            r.staff_id, r.staff_identity_id, r.guest_id, r.guest_name, r.guest_benefit_description,
            r.note, r.status, r.commission_percentage, r.estimated_value,
            r.created_at, r.accepted_at, r.verified_at, r.visited_at, r.converted_at, r.settled_at,
            r.used_at, r.redeemed_at, r.issued_at, r.printed_at, r.shared_at, r.expires_at,
            sb.name AS source_business_name, 
            tb.name AS target_business_name,
            s.name AS staff_name
            FROM referrals r 
            LEFT JOIN businesses sb ON sb.id = r.source_business_id 
            LEFT JOIN businesses tb ON tb.id = r.target_business_id
            LEFT JOIN staff s ON s.id = r.staff_id
            WHERE r.referral_code = ?');
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get referral by secure token (for QR verification)
     */
    public function getReferralByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT r.id, r.referral_code, r.secure_token, r.source_business_id, r.target_business_id,
            r.staff_id, r.staff_identity_id, r.guest_id, r.guest_name, r.guest_benefit_description,
            r.note, r.status, r.commission_percentage, r.estimated_value,
            r.created_at, r.accepted_at, r.verified_at, r.visited_at, r.converted_at, r.settled_at,
            r.used_at, r.redeemed_at, r.issued_at, r.printed_at, r.shared_at, r.expires_at,
            sb.name AS source_business_name, 
            tb.name AS target_business_name,
            s.name AS staff_name,
            sri.public_identity_code AS staff_identity_code
            FROM referrals r 
            LEFT JOIN businesses sb ON sb.id = r.source_business_id 
            LEFT JOIN businesses tb ON tb.id = r.target_business_id
            LEFT JOIN staff s ON s.id = r.staff_id
            LEFT JOIN staff_referral_identities sri ON sri.id = r.staff_identity_id
            WHERE r.secure_token = ?');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get referral with full details for verification (public-safe).
     * Returns only information safe for public display.
     */
    public function getReferralForVerification(string $codeOrToken): ?array
    {
        // Try as secure token first
        $referral = $this->getReferralByToken($codeOrToken);
        if (!$referral) {
            // Try as referral code
            $referral = $this->getReferralByCode($codeOrToken);
        }
        return $referral;
    }

    /**
     * Verify a referral (transitions to 'verified' status).
     * Only the target business can verify.
     */
    public function verifyReferral(int $referralId, int $businessId, ?int $actorUserId = null): bool
    {
        $referral = $this->getReferral($referralId, $businessId);
        if (!$referral) {
            throw new RuntimeException('Referral not found or access denied.');
        }

        if ($referral['status'] !== 'created') {
            throw new RuntimeException('This referral cannot be verified (current status: ' . $referral['status'] . ').');
        }

        // Check expiry
        if ($referral['expires_at'] && strtotime($referral['expires_at']) < time()) {
            $this->updateStatus($referralId, 'expired');
            throw new RuntimeException('This referral has expired.');
        }

        return $this->updateStatus($referralId, 'verified', $actorUserId, 'Referral verified by destination business');
    }

    /**
     * Accept a referral (transitions from 'verified' or 'created' to 'accepted').
     */
    public function acceptReferral(int $referralId, int $businessId, ?int $actorUserId = null): bool
    {
        $referral = $this->getReferral($referralId, $businessId);
        if (!$referral) {
            throw new RuntimeException('Referral not found or access denied.');
        }

        $validFrom = ['created', 'verified'];
        if (!in_array($referral['status'], $validFrom, true)) {
            throw new RuntimeException('This referral cannot be accepted (current status: ' . $referral['status'] . ').');
        }

        return $this->updateStatus($referralId, 'accepted', $actorUserId, 'Referral accepted by destination business');
    }

    /**
     * Calculate commission preview for a given referral and transaction amount.
     * Returns breakdown without persisting anything.
     */
    public function calculateCommissionPreview(int $referralId, float $transactionAmount): array
    {
        $referral = $this->getReferral($referralId);
        if (!$referral) {
            throw new RuntimeException('Referral not found.');
        }

        $commissionPct = (float) $referral['commission_percentage'];
        $totalCommission = $transactionAmount * ($commissionPct / 100);

        // GuestBridge platform fee: 20% of commission, minimum 500 RWF
        $platformFeePct = 20;
        $platformFee = max(500, $totalCommission * ($platformFeePct / 100));

        // Staff share: 30% of remaining commission after platform fee
        $staffSharePct = 30;
        $remainingAfterPlatform = $totalCommission - $platformFee;
        $staffShare = $remainingAfterPlatform * ($staffSharePct / 100);

        // Referring hotel gets the rest
        $hotelShare = $totalCommission - $platformFee - $staffShare;

        return [
            'transaction_amount' => $transactionAmount,
            'commission_percentage' => $commissionPct,
            'total_commission' => round($totalCommission, 2),
            'platform_fee' => round($platformFee, 2),
            'platform_fee_pct' => $platformFeePct,
            'staff_share' => round($staffShare, 2),
            'staff_share_pct' => $staffSharePct,
            'hotel_share' => round($hotelShare, 2),
            'source_business_id' => (int) $referral['source_business_id'],
            'target_business_id' => (int) $referral['target_business_id'],
            'staff_id' => $referral['staff_id'] ? (int) $referral['staff_id'] : null,
        ];
    }

    /**
     * Redeem a referral with transaction amount and receipt.
     * Transitions from 'accepted' to 'redeemed', creates commission records.
     */
    public function redeemReferral(int $referralId, int $businessId, int $actorUserId, ?string $notes = null, float $transactionAmount = 0, ?int $receiptId = null): bool
    {
        $referral = $this->getReferral($referralId, $businessId);
        if (!$referral) {
            throw new RuntimeException('Referral not found or access denied.');
        }

        if ($referral['status'] !== 'accepted') {
            throw new RuntimeException('Only accepted referrals can be redeemed.');
        }

        if ((int) $referral['target_business_id'] !== $businessId) {
            throw new RuntimeException('Only the destination business can redeem a referral.');
        }

        if ($transactionAmount <= 0) {
            throw new RuntimeException('Transaction amount must be a positive value.');
        }

        // Calculate commission server-side
        $calc = $this->calculateCommissionPreview($referralId, $transactionAmount);
        $month_key = date('Y-m');

        $this->pdo->beginTransaction();
        try {
            // 1. Record redemption with amount
            $stmt = $this->pdo->prepare('INSERT INTO referral_redemptions 
                (referral_id, redeemed_by_user_id, destination_business_id, notes, transaction_amount, receipt_path, commission_calculated) 
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $receiptPath = null;
            if ($receiptId) {
                $rStmt = $this->pdo->prepare('SELECT stored_path FROM receipts WHERE id = ?');
                $rStmt->execute([$receiptId]);
                $receiptPath = $rStmt->fetchColumn() ?: null;
            }
            $stmt->execute([
                $referralId, $actorUserId, $businessId, $notes,
                $transactionAmount, $receiptPath, json_encode($calc)
            ]);
            $redemptionId = (int) $this->pdo->lastInsertId();

            // 2. Update referral status and transaction_amount
            $old_status = $referral['status'];
            $this->pdo->prepare('UPDATE referrals SET status = ?, redeemed_at = ?, transaction_amount = ?, status_note = ? WHERE id = ?')
                ->execute(['redeemed', date('Y-m-d H:i:s'), $transactionAmount, $notes ?: 'Benefit redeemed', $referralId]);

            // 3. Link receipt to redemption
            if ($receiptId) {
                $this->pdo->prepare('UPDATE receipts SET redemption_id = ? WHERE id = ?')
                    ->execute([$redemptionId, $receiptId]);
            }

            // 4. Create commission record
            $this->pdo->prepare('INSERT INTO commissions 
                (referral_id, source_business_id, target_business_id, commission_percentage, estimated_value, amount, owed_to_business_id, status, month) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $referralId,
                    $calc['source_business_id'],
                    $calc['target_business_id'],
                    $calc['commission_percentage'],
                    $transactionAmount,
                    $calc['total_commission'],
                    $calc['source_business_id'],
                    'confirmed',
                    $month_key,
                ]);

            // 5. Create guest transaction record
            $this->pdo->prepare('INSERT INTO guest_transactions 
                (referral_id, business_id, gross_amount, eligible_amount, employee_id, notes, recorded_by_user_id, receipt_path) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $referralId,
                    $businessId,
                    $transactionAmount,
                    $transactionAmount,
                    $referral['staff_id'],
                    $notes,
                    $actorUserId,
                    $receiptPath,
                ]);

            // 6. Log event
            $this->logReferralEvent($referralId, 'redeemed', $old_status, 'redeemed', $actorUserId, $referral['source_business_id'], "Transaction: RWF " . number_format($transactionAmount));

            // 7. Update staff identity stats
            if ($referral['staff_identity_id']) {
                try {
                    $identityService = new StaffIdentityService($this->pdo);
                    $identityService->updateStats((int) $referral['staff_identity_id']);
                } catch (Exception $e) {
                    // Non-critical
                }
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get referral with full joins including staff identity.
     */
    public function getReferralFull(int $id, ?int $businessId = null): ?array
    {
        $sql = 'SELECT r.*, 
            sb.name AS source_business_name, 
            tb.name AS target_business_name,
            s.name AS staff_name,
            sri.public_identity_code AS staff_identity_code,
            sri.id AS staff_identity_id_ref
            FROM referrals r 
            LEFT JOIN businesses sb ON sb.id = r.source_business_id 
            LEFT JOIN businesses tb ON tb.id = r.target_business_id
            LEFT JOIN staff s ON s.id = r.staff_id
            LEFT JOIN staff_referral_identities sri ON sri.id = r.staff_identity_id
            WHERE r.id = ?';
        $params = [$id];

        if ($businessId !== null) {
            $sql .= ' AND (r.source_business_id = ? OR r.target_business_id = ?)';
            $params[] = $businessId;
            $params[] = $businessId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get referrals for a business (as source or target)
     */
    public function getBusinessReferrals(int $business_id, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT r.*, 
            sb.name AS source_business_name, 
            tb.name AS target_business_name,
            s.name AS staff_name
            FROM referrals r 
            LEFT JOIN businesses sb ON sb.id = r.source_business_id 
            LEFT JOIN businesses tb ON tb.id = r.target_business_id
            LEFT JOIN staff s ON s.id = r.staff_id
            WHERE (r.source_business_id = ? OR r.target_business_id = ?)';
        $params = [$business_id, $business_id];

        if ($status) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }

        $sql .= sprintf(' ORDER BY r.created_at DESC LIMIT %d OFFSET %d', (int)$limit, (int)$offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get pending actions for a business
     */
    public function getPendingActions(int $business_id): array
    {
        $actions = [];

        // Referrals awaiting acceptance (incoming)
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM referrals WHERE target_business_id = ? AND status = \'created\'');
        $stmt->execute([$business_id]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            $actions[] = [
                'type' => 'referrals_awaiting_acceptance',
                'count' => $count,
                'message' => "{$count} referral(s) awaiting acceptance",
                'url' => 'history.php?status=created',
                'priority' => 'high'
            ];
        }

        // Referrals awaiting visit confirmation
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM referrals WHERE target_business_id = ? AND status = \'accepted\'');
        $stmt->execute([$business_id]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            $actions[] = [
                'type' => 'referrals_awaiting_visit',
                'count' => $count,
                'message' => "{$count} referral(s) awaiting guest visit",
                'url' => 'history.php?status=accepted',
                'priority' => 'medium'
            ];
        }

        // Referrals awaiting transaction recording
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM referrals WHERE target_business_id = ? AND status = \'visited\'');
        $stmt->execute([$business_id]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            $actions[] = [
                'type' => 'referrals_awaiting_transaction',
                'count' => $count,
                'message' => "{$count} referral(s) need transaction recording",
                'url' => 'history.php?status=visited',
                'priority' => 'high'
            ];
        }

        // Pending settlements
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM settlements WHERE from_business_id = ? AND status IN (\'pending\',\'submitted\')');
            $stmt->execute([$business_id]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $actions[] = [
                    'type' => 'pending_settlements',
                    'count' => $count,
                    'message' => "{$count} settlement(s) pending",
                    'url' => 'settlement_summary.php',
                    'priority' => 'high'
                ];
            }
        } catch (PDOException $e) {
            // settlements table may not exist yet
        }

        // Pending partnership requests (incoming)
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM partnerships WHERE partner_business_id = ? AND status = \'pending\'');
        $stmt->execute([$business_id]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            $actions[] = [
                'type' => 'pending_partnerships',
                'count' => $count,
                'message' => "{$count} partnership request(s) to review",
                'url' => 'partnerships.php',
                'priority' => 'medium'
            ];
        }

        // Open disputes
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM disputes WHERE business_id = ? AND status IN (\'open\',\'under_review\')');
            $stmt->execute([$business_id]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $actions[] = [
                    'type' => 'open_disputes',
                    'count' => $count,
                    'message' => "{$count} dispute(s) require attention",
                    'url' => 'disputes.php',
                    'priority' => 'high'
                ];
            }
        } catch (PDOException $e) {
            // disputes table may not exist yet
        }

        // Sort by priority
        $priority_order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($actions, fn($a, $b) => ($priority_order[$a['priority']] ?? 2) <=> ($priority_order[$b['priority']] ?? 2));

        return $actions;
    }

    /**
     * Expire stale referrals
     */
    public function expireStaleReferrals(): int
    {
        $stmt = $this->pdo->prepare('UPDATE referrals SET status = \'expired\' WHERE status = \'created\' AND expires_at IS NOT NULL AND expires_at < NOW()');
        $stmt->execute([]);
        return $stmt->rowCount();
    }

    /**
     * Get referral timeline/history
     */
    public function getReferralTimeline(int $referral_id): array
    {
        $stmt = $this->pdo->prepare('SELECT re.*, u.name AS actor_name 
            FROM referral_events re 
            LEFT JOIN users u ON u.id = re.actor_user_id 
            WHERE re.referral_id = ? 
            ORDER BY re.created_at ASC');
        $stmt->execute([$referral_id]);
        return $stmt->fetchAll();
    }

    /**
     * Log a referral event
     */
    private function logReferralEvent(int $referral_id, string $event_type, ?string $old_status, string $new_status, ?int $actor_user_id, ?int $actor_business_id, ?string $notes = null): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO referral_events 
            (referral_id, event_type, old_status, new_status, actor_user_id, actor_business_id, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$referral_id, $event_type, $old_status, $new_status, $actor_user_id, $actor_business_id, $notes]);
    }

    /**
     * Create a notification
     */
    private function createNotification(int $business_id, string $type, string $title, string $message, ?string $entity_type = null, ?int $entity_id = null): void
    {
        // Get manager user for this business
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE business_id = ? AND role IN (\'manager\',\'super_admin\') ORDER BY id ASC LIMIT 1');
        $stmt->execute([$business_id]);
        $user = $stmt->fetch();
        if (!$user) return;

        $stmt = $this->pdo->prepare('INSERT INTO notifications 
            (user_id, business_id, notification_type, title, message, entity_type, entity_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$user['id'], $business_id, $type, $title, $message, $entity_type, $entity_id]);
    }

    private function getExpiryDate(): ?string
    {
        $days = (int) get_platform_config('referral_expiry_days', defined('REFERRAL_EXPIRY_DAYS') ? REFERRAL_EXPIRY_DAYS : 30);
        if ($days <= 0) return null;
        return date('Y-m-d H:i:s', strtotime("+{$days} days"));
    }

    /**
     * Find or create a guest record
     */
    private function findOrCreateGuest(string $name, string $phone = '', string $email = ''): int
    {
        // Try to find existing guest by phone or email
        if ($phone !== '') {
            $stmt = $this->pdo->prepare('SELECT id FROM guests WHERE phone = ? LIMIT 1');
            $stmt->execute([$phone]);
            $row = $stmt->fetch();
            if ($row) return (int) $row['id'];
        }
        if ($email !== '') {
            $stmt = $this->pdo->prepare('SELECT id FROM guests WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            if ($row) return (int) $row['id'];
        }

        // Create new guest
        $stmt = $this->pdo->prepare('INSERT INTO guests (name, phone, email) VALUES (?, ?, ?)');
        $stmt->execute([$name, $phone ?: null, $email ?: null]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Mark referral as printed
     */
    public function markPrinted(int $referral_id): void
    {
        $stmt = $this->pdo->prepare('UPDATE referrals SET printed_at = NOW() WHERE id = ? AND printed_at IS NULL');
        $stmt->execute([$referral_id]);
        if ($stmt->rowCount() > 0) {
            $this->logReferralEvent($referral_id, 'printed', null, 'printed', null, null, 'Referral voucher printed');
        }
    }

    /**
     * Mark referral as shared
     */
    public function markShared(int $referral_id): void
    {
        $stmt = $this->pdo->prepare('UPDATE referrals SET shared_at = NOW() WHERE id = ? AND shared_at IS NULL');
        $stmt->execute([$referral_id]);
        if ($stmt->rowCount() > 0) {
            $this->logReferralEvent($referral_id, 'shared', null, 'shared', null, null, 'Referral shared with guest');
        }
    }
}
