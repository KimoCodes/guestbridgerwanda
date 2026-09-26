<?php
/**
 * SettlementService - Handles settlement creation, verification, and payment tracking.
 */

class SettlementService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Generate a unique settlement reference
     */
    public function generateSettlementRef(): string
    {
        do {
            $ref = 'SET-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM settlements WHERE settlement_ref = ?');
            $stmt->execute([$ref]);
        } while ((int)$stmt->fetchColumn() > 0);

        return $ref;
    }

    /**
     * Create a new settlement
     */
    public function createSettlement(array $data): int
    {
        // Server-side input validation
        $from_business_id = (int) ($data['from_business_id'] ?? 0);
        $amount = (float) ($data['amount'] ?? 0);
        $to_business_id = !empty($data['to_business_id']) ? (int) $data['to_business_id'] : null;
        $to_employee_id = !empty($data['to_employee_id']) ? (int) $data['to_employee_id'] : null;

        if ($from_business_id <= 0) {
            throw new RuntimeException('Invalid source business.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Settlement amount must be greater than zero.');
        }
        if ($amount > 999999999999.99) {
            throw new RuntimeException('Settlement amount exceeds maximum allowed value.');
        }
        if (!$to_business_id && !$to_employee_id && empty($data['to_platform'])) {
            throw new RuntimeException('Settlement must have a recipient (business, employee, or platform).');
        }

        $this->pdo->beginTransaction();
        try {
            $settlement_ref = $this->generateSettlementRef();

            $stmt = $this->pdo->prepare('INSERT INTO settlements 
                (settlement_ref, from_business_id, to_business_id, to_employee_id, to_platform, 
                 amount, method, reference, status, due_date, notes, created_by_user_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $settlement_ref,
                $data['from_business_id'],
                $data['to_business_id'] ?? null,
                $data['to_employee_id'] ?? null,
                $data['to_platform'] ?? 0,
                $data['amount'],
                $data['method'],
                $data['reference'] ?? null,
                'pending',
                $data['due_date'] ?? null,
                $data['notes'] ?? null,
                $data['created_by_user_id'] ?? null,
            ]);
            $settlement_id = (int) $this->pdo->lastInsertId();

            // Add settlement items if provided
            if (!empty($data['items'])) {
                $item_stmt = $this->pdo->prepare('INSERT INTO settlement_items 
                    (settlement_id, commission_id, transaction_id, amount, description) 
                    VALUES (?, ?, ?, ?, ?)');
                foreach ($data['items'] as $item) {
                    $item_stmt->execute([
                        $settlement_id,
                        $item['commission_id'] ?? null,
                        $item['transaction_id'] ?? null,
                        $item['amount'],
                        $item['description'] ?? null,
                    ]);
                }
            }

            $this->pdo->commit();
            return $settlement_id;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Update settlement status
     */
    public function updateStatus(int $settlement_id, string $new_status, ?int $verified_by_user_id = null, ?string $notes = null): bool
    {
        $settlement = $this->getSettlement($settlement_id);
        if (!$settlement) {
            throw new RuntimeException('Settlement not found.');
        }

        $valid_transitions = [
            'pending' => ['submitted', 'rejected'],
            'submitted' => ['verified', 'rejected'],
            'verified' => ['reversed'],
            'rejected' => [],
            'reversed' => [],
        ];

        if (!in_array($new_status, $valid_transitions[$settlement['status']] ?? [], true)) {
            throw new RuntimeException("Invalid status transition from '{$settlement['status']}' to '{$new_status}'.");
        }

        $this->pdo->beginTransaction();
        try {
            $update_fields = ['status' => $new_status];
            if ($notes) {
                $update_fields['notes'] = $notes;
            }
            if ($new_status === 'verified') {
                $update_fields['verified_by_user_id'] = $verified_by_user_id;
                $update_fields['verified_at'] = date('Y-m-d H:i:s');
            }

            $sets = [];
            $vals = [];
            foreach ($update_fields as $col => $val) {
                $sets[] = "{$col} = ?";
                $vals[] = $val;
            }
            $vals[] = $settlement_id;

            $this->pdo->prepare('UPDATE settlements SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

            // If verified, update related commission allocations
            if ($new_status === 'verified') {
                $this->settleRelatedCommissions($settlement_id);
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get settlement by ID
     */
    public function getSettlement(int $id, ?int $business_id = null): ?array
    {
        $stmt = $this->pdo->prepare('SELECT s.*, 
            fb.name AS from_business_name, 
            tb.name AS to_business_name,
            se.name AS employee_name
            FROM settlements s 
            LEFT JOIN businesses fb ON fb.id = s.from_business_id 
            LEFT JOIN businesses tb ON tb.id = s.to_business_id
            LEFT JOIN staff se ON se.id = s.to_employee_id
            WHERE s.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || $business_id === null) {
            return $row ?: null;
        }
        // Tenant check: user must be from or to business
        $from = (int) $row['from_business_id'];
        $to = (int) $row['to_business_id'];
        if ($from !== $business_id && $to !== $business_id) {
            return null;
        }
        return $row;
    }

    /**
     * Get settlements for a business
     */
    public function getBusinessSettlements(int $business_id, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT s.*, 
            fb.name AS from_business_name, 
            tb.name AS to_business_name
            FROM settlements s 
            LEFT JOIN businesses fb ON fb.id = s.from_business_id 
            LEFT JOIN businesses tb ON tb.id = s.to_business_id
            WHERE (s.from_business_id = ? OR s.to_business_id = ?)';
        $params = [$business_id, $business_id];

        if ($status) {
            $sql .= ' AND s.status = ?';
            $params[] = $status;
        }

        $sql .= sprintf(' ORDER BY s.created_at DESC LIMIT %d OFFSET %d', (int)$limit, (int)$offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get settlement items
     */
    public function getSettlementItems(int $settlement_id): array
    {
        $stmt = $this->pdo->prepare('SELECT si.*, 
            c.amount AS commission_amount,
            gt.gross_amount AS transaction_amount
            FROM settlement_items si 
            LEFT JOIN commissions c ON c.id = si.commission_id
            LEFT JOIN guest_transactions gt ON gt.id = si.transaction_id
            WHERE si.settlement_id = ?');
        $stmt->execute([$settlement_id]);
        return $stmt->fetchAll();
    }

    /**
     * Settle related commissions when settlement is verified
     */
    private function settleRelatedCommissions(int $settlement_id): void
    {
        $items = $this->getSettlementItems($settlement_id);
        foreach ($items as $item) {
            if ($item['commission_id']) {
                $this->pdo->prepare('UPDATE commission_allocations SET status = "settled", settled_at = NOW() WHERE commission_id = ? AND status = "pending"')->execute([$item['commission_id']]);
            }
        }
    }
}
