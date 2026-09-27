<?php
/**
 * BillingService - Handles platform fee calculation, invoicing, and billing periods.
 */

class BillingService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Calculate platform fee for a business based on tiered pricing
     */
    public function calculatePlatformFee(int $business_id, string $billing_month): array
    {
        // Get total referral value for the month
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(c.amount), 0) AS total_value
            FROM commissions c
            WHERE c.source_business_id = ? AND c.month = ? AND c.status IN (\'confirmed\',\'reconciled\',\'settled\')');
        $stmt->execute([$business_id, $billing_month]);
        $total_referral_value = (float) $stmt->fetchColumn();

        // Find applicable tier
        $tier = $this->getApplicableTier($total_referral_value);
        $platform_fee = $tier ? (float) $tier['fee_amount'] : 0;

        return [
            'business_id' => $business_id,
            'billing_month' => $billing_month,
            'total_referral_value' => $total_referral_value,
            'tier_name' => $tier['tier_name'] ?? 'None',
            'platform_fee' => $platform_fee,
        ];
    }

    /**
     * Get applicable fee tier for a value
     */
    private function getApplicableTier(float $value): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM platform_fee_tiers 
            WHERE status = \'active\' 
            AND min_value <= ? 
            AND (max_value IS NULL OR max_value >= ?)
            ORDER BY min_value DESC LIMIT 1');
        $stmt->execute([$value, $value]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Generate or update billing period
     */
    public function generateBillingPeriod(int $business_id, string $billing_month): array
    {
        $fee_calc = $this->calculatePlatformFee($business_id, $billing_month);

        // Get total commission earned
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE source_business_id = ? AND month = ?');
        $stmt->execute([$business_id, $billing_month]);
        $total_commission = (float) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('INSERT INTO billing_periods 
            (business_id, period_month, total_referral_value, total_commission_earned, platform_fee_amount, status) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT (business_id, period_month) DO UPDATE SET
                total_referral_value = EXCLUDED.total_referral_value,
                total_commission_earned = EXCLUDED.total_commission_earned,
                platform_fee_amount = EXCLUDED.platform_fee_amount,
                updated_at = NOW()');
        $stmt->execute([
            $business_id,
            $billing_month,
            $fee_calc['total_referral_value'],
            $total_commission,
            $fee_calc['platform_fee'],
            'open'
        ]);

        return $fee_calc;
    }

    /**
     * Create invoice for billing period
     */
    public function createInvoice(int $business_id, string $billing_month, ?int $created_by_user_id = null): ?int
    {
        // Get or create billing period
        $billing_period = $this->getBillingPeriod($business_id, $billing_month);
        if (!$billing_period) {
            $this->generateBillingPeriod($business_id, $billing_month);
            $billing_period = $this->getBillingPeriod($business_id, $billing_month);
        }

        if (!$billing_period || (float) $billing_period['platform_fee_amount'] <= 0) {
            return null;
        }

        // Check if invoice already exists
        $stmt = $this->pdo->prepare('SELECT id FROM invoices WHERE business_id = ? AND billing_period_id = ? AND status != \'cancelled\'');
        $stmt->execute([$business_id, $billing_period['id']]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int) $existing;
        }

        // Generate invoice number
        $invoice_number = 'INV-' . date('Ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $due_date = date('Y-m-d', strtotime($billing_month . '-01 +1 month +7 days'));

        $stmt = $this->pdo->prepare('INSERT INTO invoices 
            (business_id, invoice_number, amount, invoice_type, billing_period_id, status, due_date, notes, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $business_id,
            $invoice_number,
            $billing_period['platform_fee_amount'],
            'platform_fee',
            $billing_period['id'],
            'issued',
            $due_date,
            "Platform fee for {$billing_month}"
        ]);

        $invoice_id = (int) $this->pdo->lastInsertId();

        // Update billing period status
        $this->pdo->prepare('UPDATE billing_periods SET status = \'invoiced\' WHERE id = ?')->execute([$billing_period['id']]);

        return $invoice_id;
    }

    /**
     * Get billing period
     */
    public function getBillingPeriod(int $business_id, string $billing_month): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM billing_periods WHERE business_id = ? AND period_month = ?');
        $stmt->execute([$business_id, $billing_month]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get invoice by ID
     */
    public function getInvoice(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT i.*, b.name AS business_name
            FROM invoices i
            JOIN businesses b ON b.id = i.business_id
            WHERE i.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get invoices for a business
     */
    public function getBusinessInvoices(int $business_id, ?string $status = null, int $limit = 20): array
    {
        $sql = 'SELECT i.* FROM invoices i WHERE i.business_id = ?';
        $params = [$business_id];

        if ($status) {
            $sql .= ' AND i.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY i.created_at DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Record invoice payment
     */
    public function recordPayment(int $invoice_id, float $amount, string $method, ?string $reference, ?int $recorded_by_user_id = null): bool
    {
        // Server-side validation
        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }
        if ($amount > 999999999999.99) {
            throw new RuntimeException('Payment amount exceeds maximum allowed value.');
        }
        $valid_methods = ['mobile_money', 'mtn_momo', 'paypal', 'stripe', 'bank_transfer', 'internal_credit', 'cash', 'other'];
        if (!in_array($method, $valid_methods, true)) {
            throw new RuntimeException('Invalid payment method.');
        }

        $invoice = $this->getInvoice($invoice_id);
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        if (in_array($invoice['status'], ['paid', 'cancelled'])) {
            throw new RuntimeException('Invoice cannot be paid in current status.');
        }

        $this->pdo->beginTransaction();
        try {
            $new_paid_amount = (float) $invoice['paid_amount'] + $amount;
            $total_amount = (float) $invoice['amount'];

            if ($new_paid_amount >= $total_amount) {
                $new_status = 'paid';
                $new_paid_amount = $total_amount;
            } else {
                $new_status = 'partially_paid';
            }

            $stmt = $this->pdo->prepare('UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ?');
            $stmt->execute([$new_paid_amount, $new_status, $invoice_id]);

            // Persist payment record for audit trail
            $payment_ref = $reference ?: ('PAY-' . strtoupper(bin2hex(random_bytes(4))));
            $this->pdo->prepare('INSERT INTO payments (commission_id, amount, method, reference, status, note, paid_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())')
                ->execute([null, $amount, $method, $payment_ref, 'recorded',
                    "Payment for invoice {$invoice['invoice_number']}"]);

            // Update billing period if fully paid
            if ($new_status === 'paid' && $invoice['billing_period_id']) {
                $this->pdo->prepare('UPDATE billing_periods SET status = \'paid\' WHERE id = ?')->execute([$invoice['billing_period_id']]);
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Check for overdue invoices and update status
     */
    public function updateOverdueInvoices(): int
    {
        $stmt = $this->pdo->prepare('UPDATE invoices SET status = \'overdue\' 
            WHERE status IN (\'issued\',\'pending\',\'partially_paid\') 
            AND due_date < CURRENT_DATE');
        $stmt->execute([]);
        return $stmt->rowCount();
    }

    /**
     * Get all fee tiers
     */
    public function getFeeTiers(): array
    {
        return $this->pdo->query('SELECT * FROM platform_fee_tiers WHERE status = \'active\' ORDER BY min_value ASC')->fetchAll();
    }
}
