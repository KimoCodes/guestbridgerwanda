<?php
/**
 * GuestBridge Rwanda - Database Migration v2
 * Transforms schema to support full referral lifecycle, commission distribution,
 * guest benefits, settlements, platform fee tiers, and disputes.
 */

require_once __DIR__ . '/../app/Config/config.php';

function run_migration_v2(): void
{
    $pdo = db_connect();

    // 1. Modify businesses table - add approval workflow
    migrate_add_column_if_missing($pdo, 'businesses', 'approval_status', "ALTER TABLE businesses ADD approval_status ENUM('pending','approved','suspended','rejected','deactivated') NOT NULL DEFAULT 'pending' AFTER status");
    migrate_add_column_if_missing($pdo, 'businesses', 'description', "ALTER TABLE businesses ADD description TEXT NULL AFTER business_type");
    migrate_add_column_if_missing($pdo, 'businesses', 'logo_path', "ALTER TABLE businesses ADD logo_path VARCHAR(500) NULL AFTER description");
    migrate_add_column_if_missing($pdo, 'businesses', 'website', "ALTER TABLE businesses ADD website VARCHAR(255) NULL AFTER logo_path");
    migrate_add_column_if_missing($pdo, 'businesses', 'contact_person', "ALTER TABLE businesses ADD contact_person VARCHAR(255) NULL AFTER phone");

    // Set existing active businesses to approved
    $pdo->exec("UPDATE businesses SET approval_status = 'approved' WHERE status = 'active' AND approval_status = 'pending'");

    // 2. Modify users table - add employee role
    migrate_modify_enum_if_needed($pdo, 'users', 'role', "super_admin,manager,receptionist,concierge,employee");

    // 3. Modify referrals table - full lifecycle
    migrate_modify_enum_if_needed($pdo, 'referrals', 'status', "created,verified,accepted,visited,converted,settled,cancelled,expired,rejected,disputed,redeemed");
    migrate_add_column_if_missing($pdo, 'referrals', 'guest_name', "ALTER TABLE referrals ADD guest_name VARCHAR(255) NULL AFTER note");
    migrate_add_column_if_missing($pdo, 'referrals', 'guest_phone', "ALTER TABLE referrals ADD guest_phone VARCHAR(50) NULL AFTER guest_name");
    migrate_add_column_if_missing($pdo, 'referrals', 'guest_benefit_description', "ALTER TABLE referrals ADD guest_benefit_description TEXT NULL AFTER guest_phone");
    migrate_add_column_if_missing($pdo, 'referrals', 'secure_token', "ALTER TABLE referrals ADD secure_token VARCHAR(64) NULL AFTER referral_code");
    migrate_add_column_if_missing($pdo, 'referrals', 'staff_identity_id', "ALTER TABLE referrals ADD staff_identity_id INT NULL AFTER staff_id");
    migrate_add_column_if_missing($pdo, 'referrals', 'accepted_at', "ALTER TABLE referrals ADD accepted_at TIMESTAMP NULL DEFAULT NULL AFTER used_at");
    migrate_add_column_if_missing($pdo, 'referrals', 'visited_at', "ALTER TABLE referrals ADD visited_at TIMESTAMP NULL DEFAULT NULL AFTER accepted_at");
    migrate_add_column_if_missing($pdo, 'referrals', 'converted_at', "ALTER TABLE referrals ADD converted_at TIMESTAMP NULL DEFAULT NULL AFTER visited_at");
    migrate_add_column_if_missing($pdo, 'referrals', 'settled_at', "ALTER TABLE referrals ADD settled_at TIMESTAMP NULL DEFAULT NULL AFTER converted_at");
    migrate_add_column_if_missing($pdo, 'referrals', 'cancelled_at', "ALTER TABLE referrals ADD cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER settled_at");
    migrate_add_column_if_missing($pdo, 'referrals', 'rejected_at', "ALTER TABLE referrals ADD rejected_at TIMESTAMP NULL DEFAULT NULL AFTER cancelled_at");
    migrate_add_column_if_missing($pdo, 'referrals', 'disputed_at', "ALTER TABLE referrals ADD disputed_at TIMESTAMP NULL DEFAULT NULL AFTER rejected_at");
    migrate_add_column_if_missing($pdo, 'referrals', 'accepted_by_user_id', "ALTER TABLE referrals ADD accepted_by_user_id INT NULL AFTER disputed_at");
    migrate_add_column_if_missing($pdo, 'referrals', 'status_note', "ALTER TABLE referrals ADD status_note TEXT NULL AFTER accepted_by_user_id");
    // Ensure secure_token index exists
    try { $pdo->exec("CREATE UNIQUE INDEX idx_referrals_secure_token ON referrals(secure_token)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX idx_referrals_staff_identity ON referrals(staff_identity_id)"); } catch (Exception $e) {}

    // 4. Modify commissions table - support distribution
    migrate_add_column_if_missing($pdo, 'commissions', 'referring_business_share', "ALTER TABLE commissions ADD referring_business_share DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER amount");
    migrate_add_column_if_missing($pdo, 'commissions', 'employee_share', "ALTER TABLE commissions ADD employee_share DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER referring_business_share");
    migrate_add_column_if_missing($pdo, 'commissions', 'platform_share', "ALTER TABLE commissions ADD platform_share DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER employee_share");
    migrate_add_column_if_missing($pdo, 'commissions', 'employee_id', "ALTER TABLE commissions ADD employee_id INT NULL AFTER owed_to_business_id");
    migrate_add_column_if_missing($pdo, 'commissions', 'settled_at', "ALTER TABLE commissions ADD settled_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    migrate_modify_enum_if_needed($pdo, 'commissions', 'status', "pending,confirmed,reconciled,settled,adjusted");

    // 5. Modify partnerships table - add more statuses and benefits
    migrate_modify_enum_if_needed($pdo, 'partnerships', 'status', "pending,active,paused,expired,rejected");
    migrate_add_column_if_missing($pdo, 'partnerships', 'effective_start_date', "ALTER TABLE partnerships ADD effective_start_date DATE NULL AFTER agreement_text");
    migrate_add_column_if_missing($pdo, 'partnerships', 'effective_end_date', "ALTER TABLE partnerships ADD effective_end_date DATE NULL AFTER effective_start_date");
    migrate_add_column_if_missing($pdo, 'partnerships', 'notes', "ALTER TABLE partnerships ADD notes TEXT NULL AFTER effective_end_date");
    migrate_add_column_if_missing($pdo, 'partnerships', 'approved_by_user_id', "ALTER TABLE partnerships ADD approved_by_user_id INT NULL AFTER notes");
    migrate_add_column_if_missing($pdo, 'partnerships', 'approved_at', "ALTER TABLE partnerships ADD approved_at TIMESTAMP NULL DEFAULT NULL AFTER approved_by_user_id");

    // 6. Create commission_rules table
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partnership_id INT NOT NULL,
        rule_name VARCHAR(255) NOT NULL DEFAULT 'Default',
        commission_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
        commission_value DECIMAL(10,2) NOT NULL DEFAULT 10.00,
        min_commission DECIMAL(10,2) NULL DEFAULT NULL,
        max_commission DECIMAL(10,2) NULL DEFAULT NULL,
        referring_business_pct DECIMAL(5,2) NOT NULL DEFAULT 70.00,
        employee_pct DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        platform_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00,
        effective_start_date DATE NULL,
        effective_end_date DATE NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (partnership_id) REFERENCES partnerships(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 7. Create guest_benefits table
    $pdo->exec("CREATE TABLE IF NOT EXISTS guest_benefits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partnership_id INT NOT NULL,
        benefit_type ENUM('percentage_discount','fixed_discount','free_item','complimentary_service','upgrade','special_package','other') NOT NULL DEFAULT 'percentage_discount',
        benefit_value VARCHAR(255) NOT NULL,
        benefit_description TEXT NOT NULL,
        min_spend DECIMAL(10,2) NULL DEFAULT NULL,
        max_uses INT NULL DEFAULT NULL,
        current_uses INT NOT NULL DEFAULT 0,
        valid_from DATE NULL,
        valid_until DATE NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (partnership_id) REFERENCES partnerships(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 8. Create referral_events table (audit trail)
    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referral_id INT NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        old_status VARCHAR(50) NULL,
        new_status VARCHAR(50) NOT NULL,
        actor_user_id INT NULL,
        actor_business_id INT NULL,
        notes TEXT,
        metadata JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (actor_business_id) REFERENCES businesses(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 9. Create guest_transactions table (guest spending)
    $pdo->exec("CREATE TABLE IF NOT EXISTS guest_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referral_id INT NOT NULL,
        business_id INT NOT NULL,
        transaction_ref VARCHAR(100) NULL,
        gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        eligible_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL DEFAULT 'RWF',
        transaction_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        employee_id INT NULL,
        notes TEXT,
        receipt_path VARCHAR(500) NULL,
        category VARCHAR(100) NULL,
        status ENUM('recorded','verified','disputed','adjusted') NOT NULL DEFAULT 'recorded',
        recorded_by_user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES staff(id) ON DELETE SET NULL,
        FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 10. Create commission_allocations table
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_allocations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commission_id INT NOT NULL,
        allocation_type ENUM('business','employee','platform') NOT NULL,
        recipient_business_id INT NULL,
        recipient_employee_id INT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
        status ENUM('pending','settled','adjusted') NOT NULL DEFAULT 'pending',
        settled_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (commission_id) REFERENCES commissions(id) ON DELETE CASCADE,
        FOREIGN KEY (recipient_business_id) REFERENCES businesses(id) ON DELETE SET NULL,
        FOREIGN KEY (recipient_employee_id) REFERENCES staff(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 11. Create settlements table
    $pdo->exec("CREATE TABLE IF NOT EXISTS settlements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        settlement_ref VARCHAR(32) NOT NULL,
        from_business_id INT NOT NULL,
        to_business_id INT NULL,
        to_employee_id INT NULL,
        to_platform TINYINT(1) NOT NULL DEFAULT 0,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        method VARCHAR(100) NOT NULL DEFAULT 'bank_transfer',
        reference VARCHAR(255) NULL,
        status ENUM('pending','submitted','verified','rejected','reversed') NOT NULL DEFAULT 'pending',
        due_date DATE NULL,
        notes TEXT,
        verified_by_user_id INT NULL,
        verified_at TIMESTAMP NULL DEFAULT NULL,
        created_by_user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (from_business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (to_business_id) REFERENCES businesses(id) ON DELETE SET NULL,
        FOREIGN KEY (to_employee_id) REFERENCES staff(id) ON DELETE SET NULL,
        FOREIGN KEY (verified_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY settlement_ref_unique (settlement_ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 12. Create settlement_items table
    $pdo->exec("CREATE TABLE IF NOT EXISTS settlement_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        settlement_id INT NOT NULL,
        commission_id INT NULL,
        transaction_id INT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        description TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
        FOREIGN KEY (commission_id) REFERENCES commissions(id) ON DELETE SET NULL,
        FOREIGN KEY (transaction_id) REFERENCES guest_transactions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 13. Create platform_fee_tiers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_fee_tiers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tier_name VARCHAR(100) NOT NULL,
        min_value DECIMAL(12,2) NOT NULL DEFAULT 0,
        max_value DECIMAL(12,2) NULL DEFAULT NULL,
        fee_type ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
        fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 14. Create billing_periods table
    $pdo->exec("CREATE TABLE IF NOT EXISTS billing_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        period_month VARCHAR(7) NOT NULL,
        total_referral_value DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_commission_earned DECIMAL(12,2) NOT NULL DEFAULT 0,
        platform_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('open','closed','invoiced','paid') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        UNIQUE KEY billing_period_scope (business_id, period_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 15. Enhance invoices table
    migrate_modify_enum_if_needed($pdo, 'invoices', 'status', "draft,issued,pending,partially_paid,paid,overdue,cancelled");
    migrate_add_column_if_missing($pdo, 'invoices', 'invoice_type', "ALTER TABLE invoices ADD invoice_type ENUM('platform_fee','commission','other') NOT NULL DEFAULT 'platform_fee' AFTER amount");
    migrate_add_column_if_missing($pdo, 'invoices', 'billing_period_id', "ALTER TABLE invoices ADD billing_period_id INT NULL AFTER invoice_type");
    migrate_add_column_if_missing($pdo, 'invoices', 'due_date', "ALTER TABLE invoices ADD due_date DATE NULL AFTER status");
    migrate_add_column_if_missing($pdo, 'invoices', 'issued_at', "ALTER TABLE invoices ADD issued_at TIMESTAMP NULL DEFAULT NULL AFTER due_date");
    migrate_add_column_if_missing($pdo, 'invoices', 'paid_amount', "ALTER TABLE invoices ADD paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER issued_at");
    migrate_add_column_if_missing($pdo, 'invoices', 'notes', "ALTER TABLE invoices ADD notes TEXT NULL AFTER paid_amount");

    // 16. Enhance disputes table
    migrate_modify_enum_if_needed($pdo, 'disputes', 'status', "open,under_review,resolved,rejected,escalated");
    migrate_add_column_if_missing($pdo, 'disputes', 'entity_type', "ALTER TABLE disputes ADD entity_type VARCHAR(50) NULL AFTER description");
    migrate_add_column_if_missing($pdo, 'disputes', 'entity_id', "ALTER TABLE disputes ADD entity_id INT NULL AFTER entity_type");
    migrate_add_column_if_missing($pdo, 'disputes', 'raised_by_user_id', "ALTER TABLE disputes ADD raised_by_user_id INT NULL AFTER entity_id");
    migrate_add_column_if_missing($pdo, 'disputes', 'admin_response', "ALTER TABLE disputes ADD admin_response TEXT NULL AFTER status");
    migrate_add_column_if_missing($pdo, 'disputes', 'resolution', "ALTER TABLE disputes ADD resolution TEXT NULL AFTER admin_response");
    migrate_add_column_if_missing($pdo, 'disputes', 'resolved_by_user_id', "ALTER TABLE disputes ADD resolved_by_user_id INT NULL AFTER resolution");
    migrate_add_column_if_missing($pdo, 'disputes', 'resolved_at', "ALTER TABLE disputes ADD resolved_at TIMESTAMP NULL DEFAULT NULL AFTER resolved_by_user_id");

    // 17. Create monthly_statements table
    $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_statements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        statement_month VARCHAR(7) NOT NULL,
        opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        referral_value_generated DECIMAL(12,2) NOT NULL DEFAULT 0,
        commission_earned DECIMAL(12,2) NOT NULL DEFAULT 0,
        commission_payable DECIMAL(12,2) NOT NULL DEFAULT 0,
        employee_commission DECIMAL(12,2) NOT NULL DEFAULT 0,
        platform_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
        payments_made DECIMAL(12,2) NOT NULL DEFAULT 0,
        payments_received DECIMAL(12,2) NOT NULL DEFAULT 0,
        adjustments DECIMAL(12,2) NOT NULL DEFAULT 0,
        closing_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('draft','final') NOT NULL DEFAULT 'draft',
        generated_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        UNIQUE KEY statement_scope (business_id, statement_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 18. Create notifications table
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        business_id INT NOT NULL,
        notification_type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        entity_type VARCHAR(50) NULL,
        entity_id INT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        action_url VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 19. Create employee_earnings table (materialized view for performance)
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_earnings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        business_id INT NOT NULL,
        period_month VARCHAR(7) NOT NULL,
        total_referrals INT NOT NULL DEFAULT 0,
        successful_referrals INT NOT NULL DEFAULT 0,
        total_guest_value DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_commission_generated DECIMAL(12,2) NOT NULL DEFAULT 0,
        pending_commission DECIMAL(12,2) NOT NULL DEFAULT 0,
        settled_commission DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES staff(id) ON DELETE CASCADE,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        UNIQUE KEY employee_earnings_scope (employee_id, period_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 20. Seed default platform fee tiers
    $tier_count = $pdo->query('SELECT COUNT(*) FROM platform_fee_tiers')->fetchColumn();
    if ((int)$tier_count === 0) {
        $tiers = [
            ['Starter', 0, 500000, 'fixed', 0],
            ['Growth', 500001, 2000000, 'fixed', 25000],
            ['Business', 2000001, 5000000, 'fixed', 75000],
            ['Enterprise', 5000001, null, 'fixed', 150000],
        ];
        $insert = $pdo->prepare('INSERT INTO platform_fee_tiers (tier_name, min_value, max_value, fee_type, fee_amount, status) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($tiers as $tier) {
            $insert->execute([$tier[0], $tier[1], $tier[2], $tier[3], $tier[4], 'active']);
        }
    }

    // 21. Seed default commission rule for existing partnerships
    $partnerships = $pdo->query('SELECT id, commission_rate FROM partnerships WHERE status = "active"')->fetchAll();
    foreach ($partnerships as $p) {
        $rule_count = $pdo->prepare('SELECT COUNT(*) FROM commission_rules WHERE partnership_id = ?');
        $rule_count->execute([$p['id']]);
        if ((int)$rule_count->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO commission_rules (partnership_id, rule_name, commission_type, commission_value, referring_business_pct, employee_pct, platform_pct, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$p['id'], 'Default Rule', 'percentage', $p['commission_rate'], 70.00, 20.00, 10.00, 'active']);
        }
    }

    // 22. Create settlement_ref generator function (used in code)
    // Format: SET-XXXXXXXX (8 char hex)

    echo "Migration v2 completed successfully.\n";
}

function migrate_add_column_if_missing(PDO $pdo, string $table, string $column, string $sql): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec($sql);
    }
}

function migrate_modify_enum_if_needed(PDO $pdo, string $table, string $column, string $new_values): void
{
    $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch();
    if (!$row) return;

    $current = str_replace(["enum('", "')", "'"], '', $row['COLUMN_TYPE']);
    $current_values = array_map('trim', explode(',', $current));
    $new_values_arr = array_map('trim', explode(',', $new_values));

    $missing = array_diff($new_values_arr, $current_values);
    if (!empty($missing)) {
        $all_values = array_unique(array_merge($current_values, $new_values_arr));
        $quoted = implode(',', array_map(function($v) { return "'" . addslashes($v) . "'"; }, $all_values));
        $pdo->exec("ALTER TABLE {$table} MODIFY COLUMN {$column} ENUM({$quoted}) NOT NULL DEFAULT '" . $new_values_arr[0] . "'");
    }
}

function generate_settlement_ref(): string
{
    return 'SET-' . strtoupper(bin2hex(random_bytes(4)));
}
