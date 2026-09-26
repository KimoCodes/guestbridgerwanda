<?php
require_once __DIR__ . '/config.php';

function db_init()
{
    $pdo = db_connect();

    // Ensure staff role column accepts all role values (fix ENUM mismatch from older installs)
    try {
        $col = $pdo->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'role'")->fetch();
        if ($col && $col['DATA_TYPE'] === 'enum') {
            $pdo->exec("ALTER TABLE staff MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'receptionist'");
        }
    } catch (Exception $e) {
        // Ignore — table may not exist yet
    }

    // === GUESTS TABLE ===
    $pdo->exec("CREATE TABLE IF NOT EXISTS guests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NULL,
        email VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_guests_phone (phone),
        INDEX idx_guests_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // === REFERRAL EVENTS TABLE ===
    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referral_id INT NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        old_status VARCHAR(50) NULL,
        new_status VARCHAR(50) NULL,
        actor_user_id INT NULL,
        actor_business_id INT NULL,
        notes TEXT NULL,
        metadata JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
        INDEX idx_referral_events_referral (referral_id),
        INDEX idx_referral_events_type (event_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Ensure referrals table has all required columns (for existing databases)
    $referral_new_cols = [
        'secure_token' => "VARCHAR(64) NULL AFTER referral_code",
        'guest_id' => "INT NULL AFTER staff_identity_id",
        'guest_email' => "VARCHAR(255) NULL AFTER guest_phone",
        'staff_identity_id' => "INT NULL AFTER staff_id",
        'guest_name' => "VARCHAR(255) NULL AFTER note",
        'guest_phone' => "VARCHAR(50) NULL AFTER guest_name",
        'guest_benefit_description' => "TEXT NULL AFTER guest_phone",
        'status_note' => "TEXT NULL AFTER status",
        'accepted_by_user_id' => "INT NULL AFTER status_note",
        'accepted_at' => "TIMESTAMP NULL DEFAULT NULL AFTER used_at",
        'verified_at' => "TIMESTAMP NULL DEFAULT NULL AFTER accepted_at",
        'visited_at' => "TIMESTAMP NULL DEFAULT NULL AFTER verified_at",
        'converted_at' => "TIMESTAMP NULL DEFAULT NULL AFTER visited_at",
        'settled_at' => "TIMESTAMP NULL DEFAULT NULL AFTER converted_at",
        'cancelled_at' => "TIMESTAMP NULL DEFAULT NULL AFTER settled_at",
        'rejected_at' => "TIMESTAMP NULL DEFAULT NULL AFTER cancelled_at",
        'disputed_at' => "TIMESTAMP NULL DEFAULT NULL AFTER rejected_at",
        'redeemed_at' => "TIMESTAMP NULL DEFAULT NULL AFTER disputed_at",
        'issued_at' => "TIMESTAMP NULL DEFAULT NULL AFTER redeemed_at",
        'printed_at' => "TIMESTAMP NULL DEFAULT NULL AFTER issued_at",
        'shared_at' => "TIMESTAMP NULL DEFAULT NULL AFTER printed_at",
        'transaction_amount' => "DECIMAL(12,2) NULL AFTER estimated_value",
    ];
    foreach ($referral_new_cols as $col => $def) {
        try { $pdo->exec("ALTER TABLE referrals ADD COLUMN $col $def"); } catch (Exception $e) {}
    }

    // Ensure referral_redemptions has transaction_amount and receipt_path
    $redemption_new_cols = [
        'transaction_amount' => "DECIMAL(12,2) NULL AFTER notes",
        'receipt_path' => "VARCHAR(500) NULL AFTER transaction_amount",
        'commission_calculated' => "TEXT NULL AFTER receipt_path",
    ];
    foreach ($redemption_new_cols as $col => $def) {
        try { $pdo->exec("ALTER TABLE referral_redemptions ADD COLUMN $col $def"); } catch (Exception $e) {}
    }
    try { $pdo->exec("ALTER TABLE referrals MODIFY status ENUM('created','verified','accepted','visited','converted','settled','cancelled','expired','rejected','disputed','redeemed') NOT NULL DEFAULT 'created'"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX idx_referrals_secure_token ON referrals(secure_token)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX idx_referrals_staff_identity ON referrals(staff_identity_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX idx_referrals_guest ON referrals(guest_id)"); } catch (Exception $e) {}

    // Skip expensive migration checks if already initialized this session
    if (!empty($_SESSION['db_initialized'])) {
        return;
    }

	    $pdo->exec("CREATE TABLE IF NOT EXISTS businesses (
	        id INT AUTO_INCREMENT PRIMARY KEY,
	        name VARCHAR(255) NOT NULL,
	        email VARCHAR(255) NOT NULL,
	        phone VARCHAR(50),
	        address VARCHAR(255),
	        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY (email)
	    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    ensure_business_type_column($pdo);
    ensure_business_city_column($pdo);
    ensure_business_reputation_columns($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('super_admin','manager','receptionist','concierge') NOT NULL DEFAULT 'receptionist',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        UNIQUE KEY (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        user_id INT NULL,
        name VARCHAR(255) NOT NULL,
        role ENUM('manager','receptionist','concierge') NOT NULL DEFAULT 'receptionist',
        phone VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS referrals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referral_code VARCHAR(32) NOT NULL,
        secure_token VARCHAR(64) NULL,
        source_business_id INT NOT NULL,
        target_business_id INT NOT NULL,
        staff_id INT NULL,
        staff_identity_id INT NULL,
        note TEXT,
        guest_name VARCHAR(255) NULL,
        guest_phone VARCHAR(50) NULL,
        guest_benefit_description TEXT NULL,
        commission_percentage DECIMAL(5,2) NOT NULL DEFAULT 10,
        estimated_value DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('created','verified','accepted','visited','converted','settled','cancelled','expired','rejected','disputed','redeemed') NOT NULL DEFAULT 'created',
        status_note TEXT NULL,
        accepted_by_user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        used_at TIMESTAMP NULL DEFAULT NULL,
        accepted_at TIMESTAMP NULL DEFAULT NULL,
        verified_at TIMESTAMP NULL DEFAULT NULL,
        visited_at TIMESTAMP NULL DEFAULT NULL,
        converted_at TIMESTAMP NULL DEFAULT NULL,
        settled_at TIMESTAMP NULL DEFAULT NULL,
        cancelled_at TIMESTAMP NULL DEFAULT NULL,
        rejected_at TIMESTAMP NULL DEFAULT NULL,
        disputed_at TIMESTAMP NULL DEFAULT NULL,
        redeemed_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (source_business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (target_business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL,
        FOREIGN KEY (staff_identity_id) REFERENCES staff_referral_identities(id) ON DELETE SET NULL,
        FOREIGN KEY (accepted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY (referral_code),
        UNIQUE KEY idx_referrals_secure_token (secure_token),
        INDEX idx_referrals_staff_identity (staff_identity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS commissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referral_id INT NOT NULL,
        source_business_id INT NOT NULL,
        target_business_id INT NOT NULL,
        commission_percentage DECIMAL(5,2) NOT NULL DEFAULT 10,
        estimated_value DECIMAL(10,2) NOT NULL DEFAULT 0,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        owed_to_business_id INT NOT NULL,
        status ENUM('pending','confirmed','reconciled') NOT NULL DEFAULT 'pending',
        month VARCHAR(7) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
        FOREIGN KEY (source_business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (target_business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (owed_to_business_id) REFERENCES businesses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commission_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        method VARCHAR(100) NOT NULL DEFAULT 'bank_transfer',
        reference VARCHAR(100),
        status ENUM('recorded','verified','disputed') NOT NULL DEFAULT 'recorded',
        note TEXT,
        paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (commission_id) REFERENCES commissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    ensure_payment_settlement_columns($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS partnerships (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        partner_business_id INT NOT NULL,
        status ENUM('pending','active','rejected') NOT NULL DEFAULT 'pending',
        commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10,
        agreement_text TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (partner_business_id) REFERENCES businesses(id) ON DELETE CASCADE
	    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payment_id INT NULL,
        from_business_id INT NOT NULL,
        to_business_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        method VARCHAR(100) NOT NULL DEFAULT 'bank_transfer',
        reference VARCHAR(100),
        status ENUM('recorded','verified','disputed') NOT NULL DEFAULT 'recorded',
        transaction_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
        FOREIGN KEY (from_business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (to_business_id) REFERENCES businesses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hotel_debts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        debtor_business_id INT NOT NULL,
        creditor_business_id INT NOT NULL,
        billing_month VARCHAR(7) NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        remaining_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        due_date DATE NOT NULL,
        status ENUM('unpaid','partial','paid','overdue') NOT NULL DEFAULT 'unpaid',
        last_notified_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (debtor_business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (creditor_business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        UNIQUE KEY debt_scope (debtor_business_id, creditor_business_id, billing_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS debt_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        debt_id INT NOT NULL,
        business_id INT NOT NULL,
        channel VARCHAR(50) NOT NULL DEFAULT 'dashboard',
        message TEXT NOT NULL,
        status ENUM('pending','shown','sent','failed') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (debt_id) REFERENCES hotel_debts(id) ON DELETE CASCADE,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        debt_id INT NOT NULL,
        business_id INT NOT NULL,
        provider VARCHAR(50) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        reference VARCHAR(100),
        status ENUM('pending','successful','failed','cancelled') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (debt_id) REFERENCES hotel_debts(id) ON DELETE CASCADE,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS earnings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('available','pending','paid') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_revenue_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subscription_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        referral_fee_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
        analytics_tier_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        featured_listing_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_fees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        billing_month VARCHAR(7) NOT NULL,
        subscription_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        referral_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        analytics_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        featured_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('simulated','approved','waived') NOT NULL DEFAULT 'simulated',
        review_note TEXT,
        reviewed_by_user_id INT NULL,
        reviewed_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY platform_fee_scope (business_id, billing_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    ensure_platform_fee_review_columns($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS settlement_signoffs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        partner_business_id INT NOT NULL,
        billing_month VARCHAR(7) NOT NULL,
        status ENUM('pending','signed','needs_followup','disputed') NOT NULL DEFAULT 'pending',
        note TEXT,
        signed_by_user_id INT NULL,
        signed_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (partner_business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (signed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY signoff_scope (business_id, partner_business_id, billing_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_rewards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        staff_id INT NOT NULL,
        referral_id INT NOT NULL,
        points INT NOT NULL DEFAULT 0,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        note TEXT,
        approved_by_user_id INT NULL,
        approved_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
        FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
        FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY reward_referral_staff (referral_id, staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // === STAFF REFERRAL IDENTITIES ===
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_referral_identities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        business_id INT NOT NULL,
        public_identity_code VARCHAR(20) NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        total_referrals INT NOT NULL DEFAULT 0,
        successful_referrals INT NOT NULL DEFAULT 0,
        total_revenue DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_commission DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        deactivated_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        UNIQUE KEY unique_identity_code (public_identity_code),
        UNIQUE KEY unique_staff_identity (staff_id, business_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // === REFERRAL REDEMPTIONS ===
    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_redemptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referral_id INT NOT NULL,
        redeemed_by_user_id INT NULL,
        destination_business_id INT NOT NULL,
        redeemed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
        FOREIGN KEY (redeemed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (destination_business_id) REFERENCES businesses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referral_id INT NOT NULL,
        transaction_id INT NULL,
        redemption_id INT NULL,
        business_id INT NOT NULL,
        uploaded_by_user_id INT NULL,
        original_filename VARCHAR(255) NOT NULL,
        stored_path VARCHAR(500) NOT NULL,
        file_type VARCHAR(50) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        receipt_total DECIMAL(12,2) NULL,
        status ENUM('uploaded','verified','disputed','flagged') NOT NULL DEFAULT 'uploaded',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS featured_listings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        featured_month VARCHAR(7) NOT NULL,
        status ENUM('active','paused') NOT NULL DEFAULT 'active',
        note TEXT,
        created_by_user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY featured_business_month (business_id, featured_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS seasonality_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        minimum_referrals INT NOT NULL DEFAULT 6,
        minimum_active_months INT NOT NULL DEFAULT 3,
        high_season_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.30,
        low_season_multiplier DECIMAL(5,2) NOT NULL DEFAULT 0.70,
        high_season_adjustment DECIMAL(5,2) NOT NULL DEFAULT 1.00,
        low_season_adjustment DECIMAL(5,2) NOT NULL DEFAULT -0.50,
        conversion_bonus_threshold DECIMAL(5,2) NOT NULL DEFAULT 15.00,
        conversion_penalty_threshold DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        conversion_adjustment DECIMAL(5,2) NOT NULL DEFAULT 0.50,
        updated_by_user_id INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS contract_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        city VARCHAR(100) NOT NULL DEFAULT 'Kigali',
        business_type VARCHAR(50) NOT NULL DEFAULT 'hotel',
        title VARCHAR(255) NOT NULL,
        default_commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10,
        template_text TEXT NOT NULL,
        status ENUM('active','archived') NOT NULL DEFAULT 'active',
        created_by_user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS onboarding_checklists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        city VARCHAR(100) NOT NULL DEFAULT 'Kigali',
        business_id INT NULL,
        title VARCHAR(255) NOT NULL,
        status ENUM('not_started','in_progress','blocked','done') NOT NULL DEFAULT 'not_started',
        due_date DATE NULL,
        note TEXT,
        created_by_user_id INT NULL,
        completed_by_user_id INT NULL,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pilot_feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        context VARCHAR(50) NOT NULL,
        feedback_month VARCHAR(7) NULL,
        city VARCHAR(100) NULL,
        business_id INT NULL,
        sentiment ENUM('positive','neutral','negative','follow_up') NOT NULL DEFAULT 'neutral',
        rating INT NOT NULL DEFAULT 3,
        note TEXT NOT NULL,
        created_by_user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    ensure_referral_trust_columns($pdo);
    expire_stale_referrals($pdo);
    ensure_super_admin_role($pdo);
    seed_super_admin_user($pdo);

    ensure_platform_revenue_settings($pdo);
    ensure_seasonality_settings($pdo);
    ensure_contract_templates($pdo);
    ensure_onboarding_checklists($pdo);
    refresh_hotel_debts($pdo);
    recalculate_business_reputation($pdo);
    ensure_settings_tables($pdo);
    ensure_password_resets_table($pdo);
    require_once __DIR__ . '/../Domain/places.php';
    ensure_partner_places_tables($pdo);

    // Run v2 migration for new schema
    if (!empty($_SESSION['db_v2_migrated'])) {
        // Already migrated this session
    } else {
        $migration_file = __DIR__ . '/../database/migration_v2.php';
        if (file_exists($migration_file)) {
            require_once $migration_file;
            run_migration_v2();
        }
        $_SESSION['db_v2_migrated'] = true;
    }

    // Add missing performance indexes
    ensure_performance_indexes($pdo);

    $_SESSION['db_initialized'] = true;
}

function ensure_password_resets_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        used_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function generate_password_reset_token(): string
{
    return bin2hex(random_bytes(32));
}

function recent_password_reset_request_exists(PDO $pdo, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)');
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn() > 0;
}

function create_password_reset_request(PDO $pdo, int $user_id): string
{
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$user_id]);

    $token = generate_password_reset_token();
    $ttl_minutes = defined('PASSWORD_RESET_TOKEN_TTL_MINUTES') ? (int) PASSWORD_RESET_TOKEN_TTL_MINUTES : 60;
    $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))');
    $stmt->execute([$user_id, hash('sha256', $token), $ttl_minutes]);

    return $token;
}

function find_valid_password_reset(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('SELECT pr.*, u.email, u.name FROM password_resets pr
        INNER JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
        LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function consume_password_reset(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$id]);
}

function password_reset_url(string $token): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $host;
    return rtrim($base . BASE_URL, '/') . '/reset_password.php?token=' . urlencode($token);
}

function ensure_business_type_column(PDO $pdo)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['businesses', 'business_type']);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE businesses ADD business_type VARCHAR(50) NOT NULL DEFAULT 'hotel' AFTER address");
    }
}

function ensure_business_city_column(PDO $pdo)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['businesses', 'city']);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE businesses ADD city VARCHAR(100) NOT NULL DEFAULT 'Kigali' AFTER address");
    }
}

function ensure_business_reputation_columns(PDO $pdo)
{
    $columns = [
        'reliability_score' => "ALTER TABLE businesses ADD reliability_score DECIMAL(5,2) NOT NULL DEFAULT 80 AFTER status",
        'payout_compliance_score' => "ALTER TABLE businesses ADD payout_compliance_score DECIMAL(5,2) NOT NULL DEFAULT 80 AFTER reliability_score",
        'guest_satisfaction_score' => "ALTER TABLE businesses ADD guest_satisfaction_score DECIMAL(5,2) NOT NULL DEFAULT 80 AFTER payout_compliance_score",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['businesses', $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function ensure_payment_settlement_columns(PDO $pdo)
{
    $columns = [
        'reference' => "ALTER TABLE payments ADD reference VARCHAR(100) NULL AFTER method",
        'status' => "ALTER TABLE payments ADD status ENUM('recorded','verified','disputed') NOT NULL DEFAULT 'recorded' AFTER reference",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['payments', $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function ensure_platform_fee_review_columns(PDO $pdo)
{
    $columns = [
        'review_note' => "ALTER TABLE platform_fees ADD review_note TEXT NULL AFTER status",
        'reviewed_by_user_id' => "ALTER TABLE platform_fees ADD reviewed_by_user_id INT NULL AFTER review_note",
        'reviewed_at' => "ALTER TABLE platform_fees ADD reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER reviewed_by_user_id",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['platform_fees', $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function ensure_super_admin_role(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['users', 'role']);
    $row = $stmt->fetch();
    if ($row && strpos($row['COLUMN_TYPE'], 'super_admin') === false) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','manager','receptionist','concierge') NOT NULL DEFAULT 'receptionist'");
    }
}

function seed_super_admin_user(PDO $pdo): void
{
    $admin_emails = array_filter(array_map('strtolower', array_map('trim', explode(',', PLATFORM_ADMIN_EMAILS))));
    if (empty($admin_emails)) {
        return;
    }

    $admin_email = $admin_emails[0];

    $check = $pdo->prepare('SELECT id, role FROM users WHERE LOWER(email) = ? LIMIT 1');
    $check->execute([$admin_email]);
    $existing = $check->fetch();

    if ($existing) {
        if ($existing['role'] !== 'super_admin') {
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['super_admin', $existing['id']]);
        }
        return;
    }

    $business_check = $pdo->prepare('SELECT id FROM businesses WHERE LOWER(email) = ? LIMIT 1');
    $business_check->execute([$admin_email]);
    $business = $business_check->fetch();

    if (!$business) {
        $pdo->prepare('INSERT INTO businesses (name, email, phone, address, business_type) VALUES (?, ?, ?, ?, ?)')
            ->execute(['GuestBridge Admin', $admin_email, '+250788000000', 'Kigali, Rwanda', 'other']);
        $business_id = (int) $pdo->lastInsertId();
    } else {
        $business_id = (int) $business['id'];
    }

    $password = bin2hex(random_bytes(12));
    $pdo->prepare('INSERT INTO users (business_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)')
        ->execute([$business_id, 'Super Admin', $admin_email, password_hash($password, PASSWORD_DEFAULT), 'super_admin']);
    error_log("[GuestBridge] Super admin seeded for {$admin_email} with password: {$password}");
}

function calculate_business_reputation(PDO $pdo, int $business_id): array
{
    $metrics = business_reputation_metrics($pdo, $business_id);
    $reliability_score = $metrics['incoming_referrals'] > 0 ? round(($metrics['used_referrals'] / $metrics['incoming_referrals']) * 100, 2) : 80;
    $payout_compliance_score = $metrics['payable_commissions'] > 0 ? round(($metrics['paid_commissions'] / $metrics['payable_commissions']) * 100, 2) : 80;
    $guest_satisfaction_score = $metrics['used_referrals'] > 0 ? round(($metrics['completed_referrals'] / $metrics['used_referrals']) * 100, 2) : 80;

    return [
        'reliability_score' => $reliability_score,
        'payout_compliance_score' => $payout_compliance_score,
        'guest_satisfaction_score' => $guest_satisfaction_score,
    ];
}

function business_reputation_metrics(PDO $pdo, int $business_id): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(status = "used") AS used_total FROM referrals WHERE target_business_id = ?');
    $stmt->execute([$business_id]);
    $referrals = $stmt->fetch();
    $incoming_total = (int)($referrals['total'] ?? 0);
    $used_total = (int)($referrals['used_total'] ?? 0);

    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN paid.amount_paid >= c.amount THEN 1 ELSE 0 END) AS paid_total
        FROM commissions c
        LEFT JOIN (
            SELECT commission_id, SUM(amount) AS amount_paid
            FROM payments
            GROUP BY commission_id
        ) paid ON paid.commission_id = c.id
        WHERE c.target_business_id = ?');
    $stmt->execute([$business_id]);
    $payouts = $stmt->fetch();
    $payable_total = (int)($payouts['total'] ?? 0);
    $paid_total = (int)($payouts['paid_total'] ?? 0);

    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN paid.amount_paid >= c.amount THEN 1 ELSE 0 END) AS completed_total
        FROM referrals r
        LEFT JOIN commissions c ON c.referral_id = r.id
        LEFT JOIN (
            SELECT commission_id, SUM(amount) AS amount_paid
            FROM payments
            GROUP BY commission_id
        ) paid ON paid.commission_id = c.id
        WHERE r.target_business_id = ? AND r.status = "used"');
    $stmt->execute([$business_id]);
    $completed = $stmt->fetch();
    $used_referrals = (int)($completed['total'] ?? 0);
    $completed_total = (int)($completed['completed_total'] ?? 0);

    return [
        'incoming_referrals' => $incoming_total,
        'used_referrals' => $used_total,
        'payable_commissions' => $payable_total,
        'paid_commissions' => $paid_total,
        'completed_referrals' => $completed_total,
    ];
}

function recalculate_business_reputation(PDO $pdo, ?int $business_id = null): void
{
    if ($business_id !== null) {
        $scores = calculate_business_reputation($pdo, $business_id);
        $stmt = $pdo->prepare('UPDATE businesses SET reliability_score = ?, payout_compliance_score = ?, guest_satisfaction_score = ? WHERE id = ?');
        $stmt->execute([
            $scores['reliability_score'],
            $scores['payout_compliance_score'],
            $scores['guest_satisfaction_score'],
            $business_id,
        ]);
        return;
    }

    $stmt = $pdo->query('SELECT id FROM businesses');
    foreach ($stmt->fetchAll() as $business) {
        recalculate_business_reputation($pdo, (int)$business['id']);
    }
}

function debt_due_date(string $billing_month): string
{
    return date('Y-m-d', strtotime($billing_month . '-01 +1 month +6 days'));
}

function refresh_hotel_debts(PDO $pdo, ?int $business_id = null, ?string $billing_month = null): void
{
    $sql = 'SELECT c.target_business_id AS debtor_business_id,
            c.owed_to_business_id AS creditor_business_id,
            c.month AS billing_month,
            SUM(c.amount) AS total_amount,
            IFNULL(SUM(paid.amount_paid), 0) AS paid_amount
        FROM commissions c
        LEFT JOIN (
            SELECT commission_id, SUM(amount) AS amount_paid
            FROM payments
            WHERE status != "disputed"
            GROUP BY commission_id
        ) paid ON paid.commission_id = c.id
        WHERE c.status IN ("confirmed","reconciled")';
    $params = [];

    if ($business_id !== null) {
        $sql .= ' AND (c.target_business_id = ? OR c.owed_to_business_id = ?)';
        $params[] = $business_id;
        $params[] = $business_id;
    }
    if ($billing_month !== null) {
        $sql .= ' AND c.month = ?';
        $params[] = $billing_month;
    }

    $sql .= ' GROUP BY c.target_business_id, c.owed_to_business_id, c.month';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $total = (float)$row['total_amount'];
        $paid = min((float)$row['paid_amount'], $total);
        $remaining = max(0, $total - $paid);
        $due_date = debt_due_date($row['billing_month']);
        $status = 'unpaid';
        if ($remaining <= 0) {
            $status = 'paid';
        } elseif ($paid > 0) {
            $status = 'partial';
        } elseif (date('Y-m-d') > $due_date) {
            $status = 'overdue';
        }

        $upsert = $pdo->prepare('INSERT INTO hotel_debts
            (debtor_business_id, creditor_business_id, billing_month, total_amount, paid_amount, remaining_amount, due_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_amount = VALUES(total_amount),
                paid_amount = VALUES(paid_amount),
                remaining_amount = VALUES(remaining_amount),
                due_date = VALUES(due_date),
                status = VALUES(status)');
        $upsert->execute([
            $row['debtor_business_id'],
            $row['creditor_business_id'],
            $row['billing_month'],
            $total,
            $paid,
            $remaining,
            $due_date,
            $status,
        ]);
    }

    queue_debt_notifications($pdo);
}

function queue_debt_notifications(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT d.*, b.name AS creditor_name
        FROM hotel_debts d
        JOIN businesses b ON b.id = d.creditor_business_id
        WHERE d.remaining_amount > 0 AND d.status IN ("unpaid","partial","overdue")');

    foreach ($stmt->fetchAll() as $debt) {
        $recent = $pdo->prepare('SELECT COUNT(*) FROM debt_notifications WHERE debt_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $recent->execute([$debt['id']]);
        if ((int)$recent->fetchColumn() > 0) {
            continue;
        }

        $message = 'Outstanding balance of RWF ' . format_money($debt['remaining_amount']) . ' for ' . $debt['billing_month'] . ' is due by ' . $debt['due_date'] . '.';
        $insert = $pdo->prepare('INSERT INTO debt_notifications (debt_id, business_id, channel, message, status) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$debt['id'], $debt['debtor_business_id'], 'dashboard', $message, 'pending']);

        $update = $pdo->prepare('UPDATE hotel_debts SET last_notified_at = NOW() WHERE id = ?');
        $update->execute([$debt['id']]);
    }
}

function outstanding_debts(PDO $pdo, int $business_id): array
{
    refresh_hotel_debts($pdo, $business_id);
    $stmt = $pdo->prepare('SELECT d.*, b.name AS creditor_name
        FROM hotel_debts d
        JOIN businesses b ON b.id = d.creditor_business_id
        WHERE d.debtor_business_id = ? AND d.remaining_amount > 0
        ORDER BY d.due_date ASC, d.remaining_amount DESC');
    $stmt->execute([$business_id]);
    return $stmt->fetchAll();
}

function record_debt_payment(PDO $pdo, int $debt_id, float $amount, string $method, ?string $reference, string $note, string $payment_status = 'recorded'): void
{
    $debt_stmt = $pdo->prepare('SELECT * FROM hotel_debts WHERE id = ?');
    $debt_stmt->execute([$debt_id]);
    $debt = $debt_stmt->fetch();
    if (!$debt) {
        throw new RuntimeException('Debt record not found.');
    }
    if ($amount <= 0 || $amount > (float)$debt['remaining_amount']) {
        throw new RuntimeException('Payment amount must be greater than zero and no more than the remaining balance.');
    }

    $remaining_to_allocate = $amount;
    $commission_stmt = $pdo->prepare('SELECT c.*, IFNULL(paid.amount_paid, 0) AS amount_paid
        FROM commissions c
        LEFT JOIN (
            SELECT commission_id, SUM(amount) AS amount_paid
            FROM payments
            WHERE status != "disputed"
            GROUP BY commission_id
        ) paid ON paid.commission_id = c.id
        WHERE c.target_business_id = ?
            AND c.owed_to_business_id = ?
            AND c.month = ?
            AND c.status IN ("confirmed","reconciled")
            AND c.amount > IFNULL(paid.amount_paid, 0)
        ORDER BY c.created_at ASC');
    $commission_stmt->execute([$debt['debtor_business_id'], $debt['creditor_business_id'], $debt['billing_month']]);

    foreach ($commission_stmt->fetchAll() as $commission) {
        if ($remaining_to_allocate <= 0) {
            break;
        }

        $commission_remaining = (float)$commission['amount'] - (float)$commission['amount_paid'];
        $payment_amount = min($commission_remaining, $remaining_to_allocate);

        $stmt = $pdo->prepare('INSERT INTO payments (commission_id, amount, paid_at, method, reference, status, note) VALUES (?, ?, NOW(), ?, ?, ?, ?)');
        $stmt->execute([$commission['id'], $payment_amount, $method, $reference, $payment_status, $note]);
        $payment_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO transactions (payment_id, from_business_id, to_business_id, amount, method, reference, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$payment_id, $debt['debtor_business_id'], $debt['creditor_business_id'], $payment_amount, $method, $reference, $payment_status]);

        $new_paid_total = (float)$commission['amount_paid'] + $payment_amount;
        $new_status = $new_paid_total >= (float)$commission['amount'] ? 'reconciled' : 'confirmed';
        $stmt = $pdo->prepare('UPDATE commissions SET status = ? WHERE id = ?');
        $stmt->execute([$new_status, $commission['id']]);

        $remaining_to_allocate -= $payment_amount;
    }

    refresh_hotel_debts($pdo, (int)$debt['debtor_business_id'], $debt['billing_month']);
    recalculate_business_reputation($pdo, (int)$debt['debtor_business_id']);
}

function ensure_platform_revenue_settings(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM platform_revenue_settings');
    if ((int)$stmt->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO platform_revenue_settings (subscription_fee, referral_fee_percentage, analytics_tier_fee, featured_listing_fee) VALUES (?, ?, ?, ?)');
        $insert->execute([0, 0, 0, 0]);
    }
}

function platform_revenue_settings(PDO $pdo): array
{
    ensure_platform_revenue_settings($pdo);
    $stmt = $pdo->query('SELECT * FROM platform_revenue_settings ORDER BY id ASC LIMIT 1');
    return $stmt->fetch();
}

function ensure_seasonality_settings(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM seasonality_settings');
    if ((int)$stmt->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO seasonality_settings
            (minimum_referrals, minimum_active_months, high_season_multiplier, low_season_multiplier, high_season_adjustment, low_season_adjustment, conversion_bonus_threshold, conversion_penalty_threshold, conversion_adjustment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([6, 3, 1.30, 0.70, 1.00, -0.50, 15.00, 20.00, 0.50]);
    }
}

function seasonality_settings(PDO $pdo): array
{
    ensure_seasonality_settings($pdo);
    $stmt = $pdo->query('SELECT * FROM seasonality_settings ORDER BY id ASC LIMIT 1');
    return $stmt->fetch();
}

function ensure_contract_templates(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM contract_templates');
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $templates = [
        ['Kigali', 'hotel', 'Kigali hotel referral agreement', 10.00, 'Partner agrees to accept verified GuestBridge referrals, confirm usage manually, and settle approved commissions during monthly review.'],
        ['Kampala', 'hotel', 'Kampala hotel launch template', 10.00, 'Pilot template for hotel-to-hotel referrals during Kampala onboarding. Commission rates remain advisory until both partners approve.'],
        ['Nairobi', 'tourism', 'Nairobi tourism partner template', 8.50, 'Tourism partner accepts referral notes, confirms guest usage, and joins monthly manual settlement review before paid automation.'],
        ['Dar es Salaam', 'transport', 'Dar es Salaam transport referral template', 7.50, 'Transport partner template for airport, hotel, and tourism referrals with manual confirmation and settlement tracking.'],
    ];

    $insert = $pdo->prepare('INSERT INTO contract_templates (city, business_type, title, default_commission_rate, template_text, status) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($templates as $template) {
        $insert->execute([$template[0], $template[1], $template[2], $template[3], $template[4], 'active']);
    }
}

function onboarding_status_options(): array
{
    return [
        'not_started' => 'Not started',
        'in_progress' => 'In progress',
        'blocked' => 'Blocked',
        'done' => 'Done',
    ];
}

function onboarding_status_label(string $status): string
{
    $options = onboarding_status_options();
    return $options[$status] ?? $options['not_started'];
}

function feedback_sentiment_options(): array
{
    return [
        'positive' => 'Positive',
        'neutral' => 'Neutral',
        'negative' => 'Negative',
        'follow_up' => 'Needs follow-up',
    ];
}

function feedback_sentiment_label(string $sentiment): string
{
    $options = feedback_sentiment_options();
    return $options[$sentiment] ?? $options['neutral'];
}

function ensure_onboarding_checklists(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM onboarding_checklists');
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $items = [
        'Confirm pilot manager and primary contact',
        'Add at least three launch partners',
        'Create one active partnership path',
        'Review contract template and commission range',
        'Run first referral and settlement walkthrough',
    ];
    $insert = $pdo->prepare('INSERT INTO onboarding_checklists (city, title, status) VALUES (?, ?, ?)');
    foreach (array_keys(city_options()) as $city) {
        foreach ($items as $title) {
            $insert->execute([$city, $title, 'not_started']);
        }
    }
}

function city_deployment_status(PDO $pdo, string $city): array
{
    $stmt = $pdo->prepare('SELECT
            COUNT(DISTINCT b.id) AS business_count,
            COUNT(DISTINCT CASE WHEN b.status = "active" THEN b.id END) AS active_businesses,
            COUNT(DISTINCT p.id) AS partnership_count,
            COUNT(DISTINCT CASE WHEN p.status = "active" THEN p.id END) AS active_partnerships,
            COUNT(DISTINCT r.id) AS referral_count,
            COUNT(DISTINCT CASE WHEN r.status = "used" THEN r.id END) AS used_referrals,
            COUNT(DISTINCT oc.id) AS checklist_items,
            COUNT(DISTINCT CASE WHEN oc.status = "done" THEN oc.id END) AS checklist_done,
            COUNT(DISTINCT CASE WHEN oc.status = "blocked" THEN oc.id END) AS checklist_blocked
        FROM businesses b
        LEFT JOIN partnerships p ON p.business_id = b.id
        LEFT JOIN referrals r ON r.source_business_id = b.id
        LEFT JOIN onboarding_checklists oc ON oc.city = b.city AND (oc.business_id IS NULL OR oc.business_id = b.id)
        WHERE b.city = ?');
    $stmt->execute([$city]);
    $row = $stmt->fetch();

    $business_count = max(1, (int)($row['business_count'] ?? 0));
    $active_partnerships = (int)($row['active_partnerships'] ?? 0);
    $checklist_total = max(1, (int)($row['checklist_items'] ?? 0));
    $checklist_done = (int)($row['checklist_done'] ?? 0);
    $checklist_blocked = (int)($row['checklist_blocked'] ?? 0);
    $checklist_percent = (int)round(($checklist_done / $checklist_total) * 100);

    $score = 0;
    if ((int)($row['active_businesses'] ?? 0) >= 3) { $score += 25; }
    if ($active_partnerships >= 1) { $score += 25; }
    if ($checklist_percent >= 60) { $score += 25; }
    if ((int)($row['used_referrals'] ?? 0) >= 1) { $score += 25; }

    $stage = 'not_ready';
    $next_action = 'Add at least 3 active businesses and complete onboarding checklist';
    if ($score >= 75) {
        $stage = 'pilot_ready';
        $next_action = 'Approve pilot and begin automated onboarding sequence';
    } elseif ($score >= 50) {
        $stage = 'onboarding';
        $next_action = 'Complete remaining checklist items before automation';
    } elseif ((int)($row['business_count'] ?? 0) >= 1) {
        $stage = 'setup';
        $next_action = 'Activate partnerships and track first referrals';
    }

    return [
        'city' => $city,
        'score' => $score,
        'stage' => $stage,
        'next_action' => $next_action,
        'business_count' => (int)($row['business_count'] ?? 0),
        'active_businesses' => (int)($row['active_businesses'] ?? 0),
        'active_partnerships' => $active_partnerships,
        'referral_count' => (int)($row['referral_count'] ?? 0),
        'used_referrals' => (int)($row['used_referrals'] ?? 0),
        'checklist_percent' => $checklist_percent,
        'checklist_blocked' => $checklist_blocked,
    ];
}

function suggest_next_onboarding_steps(PDO $pdo, string $city): array
{
    $status = city_deployment_status($pdo, $city);
    $steps = [];

    if ($status['active_businesses'] < 3) {
        $steps[] = [
            'priority' => 1,
            'action' => 'Register at least 3 active businesses in ' . $city,
            'widget' => 'register',
            'gap' => 3 - $status['active_businesses'],
        ];
    }
    if ($status['active_partnerships'] < 1) {
        $steps[] = [
            'priority' => 2,
            'action' => 'Activate at least 1 partnership in ' . $city,
            'widget' => 'partnerships',
            'gap' => 1 - $status['active_partnerships'],
        ];
    }
    if ($status['checklist_percent'] < 60) {
        $steps[] = [
            'priority' => 3,
            'action' => 'Complete onboarding checklist (currently ' . $status['checklist_percent'] . '% done)',
            'widget' => 'checklist',
            'gap' => $status['checklist_percent'],
        ];
    }
    if ($status['referral_count'] < 1) {
        $steps[] = [
            'priority' => 4,
            'action' => 'Create first referral to validate the workflow',
            'widget' => 'referral',
            'gap' => 0,
        ];
    }

    usort($steps, fn($a, $b) => $a['priority'] <=> $b['priority']);
    return $steps;
}

function merge_contract_template(string $template_text, array $vars): string
{
    $replacements = [
        '{{PARTNER_NAME}}' => $vars['partner_name'] ?? '',
        '{{CITY}}' => $vars['city'] ?? '',
        '{{COMMISSION_RATE}}' => isset($vars['commission_rate']) ? number_format((float)$vars['commission_rate'], 1) . '%' : '',
        '{{PLATFORM_NAME}}' => APP_NAME,
        '{{DATE}}' => date('Y-m-d'),
        '{{EXPIRY_DATE}}' => date('Y-m-d', strtotime('+30 days')),
    ];

    $text = $template_text;
    foreach ($replacements as $placeholder => $value) {
        $text = str_replace($placeholder, $value, $text);
    }
    return $text;
}

function apply_contract_template_to_partnership(PDO $pdo, int $partnership_id, int $template_id, int $user_id): void
{
    $stmt = $pdo->prepare('SELECT * FROM contract_templates WHERE id = ? AND status = "active"');
    $stmt->execute([$template_id]);
    $template = $stmt->fetch();
    if (!$template) {
        return;
    }

    $stmt = $pdo->prepare('SELECT p.*, sb.name AS source_name, pb.name AS partner_name, pb.city
        FROM partnerships p
        JOIN businesses sb ON sb.id = p.business_id
        JOIN businesses pb ON pb.id = p.partner_business_id
        WHERE p.id = ?');
    $stmt->execute([$partnership_id]);
    $partnership = $stmt->fetch();
    if (!$partnership) {
        return;
    }

    $merged = merge_contract_template($template['template_text'], [
        'partner_name' => $partnership['partner_name'],
        'city' => $partnership['city'],
        'commission_rate' => $partnership['commission_rate'],
    ]);

    $stmt = $pdo->prepare('UPDATE partnerships SET agreement_text = ?, commission_rate = COALESCE(NULLIF(?, 0), commission_rate) WHERE id = ?');
    $stmt->execute([$merged, $template['default_commission_rate'], $partnership_id]);
}

function seed_default_checklist_for_city(PDO $pdo, string $city): void
{
    $items = [
        'Confirm pilot manager and primary contact',
        'Add at least three launch partners',
        'Create one active partnership path',
        'Review contract template and commission range',
        'Run first referral and settlement walkthrough',
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO onboarding_checklists (city, title, status) VALUES (?, ?, ?)');
    foreach ($items as $title) {
        $insert->execute([$city, $title, 'not_started']);
    }
}

function deploy_city_checklist(PDO $pdo, string $city, int $created_by_user_id): array
{
    seed_default_checklist_for_city($pdo, $city);

    $template_stmt = $pdo->prepare('SELECT * FROM contract_templates WHERE city = ? AND status = "active" ORDER BY business_type LIMIT 1');
    $template_stmt->execute([$city]);
    $template = $template_stmt->fetch();

    $businesses_stmt = $pdo->prepare('SELECT id, name FROM businesses WHERE city = ? AND status = "active"');
    $businesses_stmt->execute([$city]);
    $businesses = $businesses_stmt->fetchAll();

    $summary = [
        'checklist_seed' => true,
        'template_found' => (bool)$template,
        'businesses_found' => count($businesses),
    ];

    if ($template) {
        $summary['template_title'] = $template['title'];
    }

    return $summary;
}

function refresh_platform_fees(PDO $pdo, string $billing_month): void
{
    $settings = platform_revenue_settings($pdo);
    $businesses = $pdo->query('SELECT id FROM businesses WHERE status = "active"')->fetchAll();

    foreach ($businesses as $business) {
        $business_id = (int)$business['id'];
        $stmt = $pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM commissions WHERE owed_to_business_id = ? AND month = ?');
        $stmt->execute([$business_id, $billing_month]);
        $earned_commissions = (float)$stmt->fetchColumn();

        $subscription_fee = (float)$settings['subscription_fee'];
        $referral_fee = $earned_commissions * ((float)$settings['referral_fee_percentage'] / 100);
        $analytics_fee = (float)$settings['analytics_tier_fee'];
        $featured_fee = (float)$settings['featured_listing_fee'];
        $total = $subscription_fee + $referral_fee + $analytics_fee + $featured_fee;

        $stmt = $pdo->prepare('INSERT INTO platform_fees
            (business_id, billing_month, subscription_fee, referral_fee, analytics_fee, featured_fee, total_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                subscription_fee = VALUES(subscription_fee),
                referral_fee = VALUES(referral_fee),
                analytics_fee = VALUES(analytics_fee),
                featured_fee = VALUES(featured_fee),
                total_amount = VALUES(total_amount)');
        $stmt->execute([$business_id, $billing_month, $subscription_fee, $referral_fee, $analytics_fee, $featured_fee, $total]);
    }
}

function commission_seasonality_signal(PDO $pdo, int $source_business_id, int $target_business_id, ?string $month = null): array
{
    $settings = seasonality_settings($pdo);
    $month = $month ?: date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $target_month_number = (int)date('n', strtotime($month . '-01'));
    $month_name = date('F', strtotime($month . '-01'));

    $stmt = $pdo->prepare('SELECT
            MONTH(created_at) AS month_number,
            COUNT(*) AS referrals_count,
            SUM(status = "used") AS used_count,
            AVG(commission_percentage) AS average_rate
        FROM referrals
        WHERE source_business_id = ?
            AND target_business_id = ?
            AND created_at >= DATE_SUB(STR_TO_DATE(CONCAT(?, "-01"), "%Y-%m-%d"), INTERVAL 24 MONTH)
            AND created_at < DATE_ADD(STR_TO_DATE(CONCAT(?, "-01"), "%Y-%m-%d"), INTERVAL 1 MONTH)
        GROUP BY MONTH(created_at)');
    $stmt->execute([$source_business_id, $target_business_id, $month, $month]);
    $rows = $stmt->fetchAll();

    $total_referrals = 0;
    $total_used = 0;
    $active_months = count($rows);
    $seasonal_referrals = 0;
    $seasonal_used = 0;

    foreach ($rows as $row) {
        $count = (int)$row['referrals_count'];
        $used = (int)$row['used_count'];
        $total_referrals += $count;
        $total_used += $used;
        if ((int)$row['month_number'] === $target_month_number) {
            $seasonal_referrals = $count;
            $seasonal_used = $used;
        }
    }

    if ($total_referrals < (int)$settings['minimum_referrals'] || $active_months < (int)$settings['minimum_active_months']) {
        return [
            'adjustment' => 0.0,
            'label' => 'Building history',
            'description' => $month_name . ' needs more referral history before seasonality changes the rate.',
            'confidence' => 'low',
            'sample_size' => $total_referrals,
        ];
    }

    $baseline_referrals = $active_months > 0 ? $total_referrals / $active_months : 0;
    $baseline_conversion = $total_referrals > 0 ? ($total_used / $total_referrals) * 100 : 0;
    $seasonal_conversion = $seasonal_referrals > 0 ? ($seasonal_used / $seasonal_referrals) * 100 : $baseline_conversion;
    $adjustment = 0.0;
    $label = 'Steady season';

    if ($seasonal_referrals >= max(2, $baseline_referrals * (float)$settings['high_season_multiplier'])) {
        $adjustment += (float)$settings['high_season_adjustment'];
        $label = 'High season';
    } elseif ($seasonal_referrals > 0 && $seasonal_referrals <= $baseline_referrals * (float)$settings['low_season_multiplier']) {
        $adjustment += (float)$settings['low_season_adjustment'];
        $label = 'Low season';
    }

    if ($seasonal_referrals >= 2 && $seasonal_conversion >= $baseline_conversion + (float)$settings['conversion_bonus_threshold']) {
        $adjustment += (float)$settings['conversion_adjustment'];
    } elseif ($seasonal_referrals >= 2 && $seasonal_conversion <= $baseline_conversion - (float)$settings['conversion_penalty_threshold']) {
        $adjustment -= (float)$settings['conversion_adjustment'];
    }

    $adjustment = max(-1.5, min(2.0, $adjustment));
    $confidence = $total_referrals >= 12 && $active_months >= 5 ? 'medium' : 'low';

    return [
        'adjustment' => round($adjustment, 1),
        'label' => $label,
        'description' => $month_name . ' history: ' . $seasonal_referrals . ' referrals vs ' . number_format($baseline_referrals, 1) . ' monthly baseline.',
        'confidence' => $confidence,
        'sample_size' => $total_referrals,
        'seasonal_referrals' => $seasonal_referrals,
        'baseline_referrals' => round($baseline_referrals, 1),
        'seasonal_conversion' => round($seasonal_conversion, 1),
        'baseline_conversion' => round($baseline_conversion, 1),
    ];
}

function reward_points_for_referral(float $estimated_value, float $commission_amount): int
{
    $base_points = 5;
    $value_points = (int)floor(max(0, $estimated_value) / 50000);
    $commission_points = (int)floor(max(0, $commission_amount) / 10000);
    return min(50, $base_points + $value_points + $commission_points);
}

function create_staff_reward(PDO $pdo, int $business_id, ?int $staff_id, int $referral_id, float $estimated_value, float $commission_amount): void
{
    if (!$staff_id) {
        return;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE id = ? AND business_id = ?');
    $stmt->execute([$staff_id, $business_id]);
    if ((int)$stmt->fetchColumn() === 0) {
        return;
    }

    $points = reward_points_for_referral($estimated_value, $commission_amount);
    $stmt = $pdo->prepare('INSERT INTO staff_rewards (business_id, staff_id, referral_id, points, status)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE points = VALUES(points)');
    $stmt->execute([$business_id, $staff_id, $referral_id, $points, 'pending']);
}

function generate_referral_code()
{
    return bin2hex(random_bytes(6));
}

function ensure_referral_trust_columns(PDO $pdo): void
{
    $columns = [
        'redemption_pin_hash' => "ALTER TABLE referrals ADD redemption_pin_hash VARCHAR(255) NULL AFTER referral_code",
        'expires_at' => "ALTER TABLE referrals ADD expires_at TIMESTAMP NULL DEFAULT NULL AFTER used_at",
    ];
    foreach ($columns as $column => $sql) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['referrals', $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function generate_redemption_pin(): string
{
    return (string) random_int(100000, 999999);
}

function hash_redemption_pin(string $pin): string
{
    return password_hash($pin, PASSWORD_DEFAULT);
}

function verify_redemption_pin(string $pin, ?string $hash): bool
{
    if ($hash === null || $hash === '') {
        return false;
    }
    return password_verify($pin, $hash);
}

function referral_expires_at_sql(): ?string
{
    $days = defined('REFERRAL_EXPIRY_DAYS') ? (int) REFERRAL_EXPIRY_DAYS : 30;
    if ($days <= 0) {
        return null;
    }
    return date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
}

function referral_is_expired(array $referral): bool
{
    if (($referral['status'] ?? '') === 'expired') {
        return true;
    }
    if (($referral['status'] ?? '') !== 'created') {
        return false;
    }
    if (!empty($referral['expires_at']) && strtotime($referral['expires_at']) < time()) {
        return true;
    }
    return false;
}

function expire_stale_referrals(PDO $pdo): int
{
    $stmt = $pdo->prepare('UPDATE referrals SET status = ? WHERE status = ? AND expires_at IS NOT NULL AND expires_at < NOW()');
    $stmt->execute(['expired', 'created']);
    return $stmt->rowCount();
}

function audit_actor_user_id(PDO $pdo, int $business_id): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE business_id = ? ORDER BY FIELD(role, "manager", "concierge", "receptionist"), id ASC LIMIT 1');
    $stmt->execute([$business_id]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function log_referral_redemption_audit(PDO $pdo, array $referral, string $channel, ?int $actor_user_id = null): void
{
    $business_id = (int) $referral['target_business_id'];
    $user_id = $actor_user_id > 0 ? $actor_user_id : audit_actor_user_id($pdo, $business_id);
    if ($user_id <= 0) {
        return;
    }
    log_audit(
        $pdo,
        $business_id,
        $user_id,
        'referral_used',
        'referral',
        (int) $referral['id'],
        ['status' => 'created'],
        ['status' => 'used', 'channel' => $channel],
        $_SERVER['REMOTE_ADDR'] ?? null
    );
}

function mark_referral_used(PDO $pdo, array $referral, string $channel = 'public', ?int $actor_user_id = null): bool
{
    expire_stale_referrals($pdo);
    $stmt = $pdo->prepare('SELECT * FROM referrals WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $referral['id']]);
    $fresh = $stmt->fetch();
    if (!$fresh) {
        return false;
    }
    if (referral_is_expired($fresh)) {
        if ($fresh['status'] === 'created') {
            $pdo->prepare('UPDATE referrals SET status = ? WHERE id = ?')->execute(['expired', $fresh['id']]);
        }
        return false;
    }
    if ($fresh['status'] !== 'created') {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE referrals SET status = ?, used_at = NOW() WHERE id = ?')->execute(['used', $fresh['id']]);
        $pdo->prepare('UPDATE commissions SET status = ? WHERE referral_id = ?')->execute(['confirmed', $fresh['id']]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }

    recalculate_business_reputation($pdo, (int) $fresh['target_business_id']);
    log_referral_redemption_audit($pdo, $fresh, $channel, $actor_user_id);
    return true;
}

function ensure_initial_data()
{
    $pdo = db_connect();
    $stmt = $pdo->query('SELECT COUNT(*) as count FROM businesses');
    $row = $stmt->fetch();
    if ($row['count'] == 0) {
        $stmt = $pdo->prepare('INSERT INTO businesses (name, email, phone, address, business_type) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(['Pilot Hotel Kigali', 'admin@pilot.kig', '+250788000000', 'Kigali, Rwanda', 'hotel']);
        $business_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO users (business_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$business_id, 'Admin User', 'admin@pilot.kig', password_hash('password', PASSWORD_DEFAULT), 'manager']);

	    $stmt = $pdo->prepare('INSERT INTO staff (business_id, user_id, name, role, phone) VALUES (?, ?, ?, ?, ?)');
	    $stmt->execute([$business_id, $pdo->lastInsertId(), 'Admin User', 'manager', '+250788000000']);

        seed_default_staff_roles($pdo, $business_id);
	}

    recalculate_business_reputation($pdo);
}

// ─── SETTINGS TABLES ─────────────────────────────────────────────────────────

function ensure_settings_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        device VARCHAR(100),
        browser VARCHAR(100),
        os VARCHAR(100),
        logged_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        user_id INT NOT NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(100) NOT NULL,
        entity_id INT,
        old_value JSON,
        new_value JSON,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_2fa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        secret VARCHAR(255),
        backup_codes JSON,
        enabled_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        pref_key VARCHAR(100) NOT NULL,
        pref_value INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY user_pref_key (user_id, pref_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_sessions (
        id VARCHAR(128) PRIMARY KEY,
        user_id INT NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pilot_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        city VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('active','dismissed') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        role_name VARCHAR(50) NOT NULL,
        can_view_bookings TINYINT(1) NOT NULL DEFAULT 1,
        can_manage_payments TINYINT(1) NOT NULL DEFAULT 0,
        can_edit_profile TINYINT(1) NOT NULL DEFAULT 1,
        can_create_referrals TINYINT(1) NOT NULL DEFAULT 1,
        can_manage_staff TINYINT(1) NOT NULL DEFAULT 0,
        can_view_analytics TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        UNIQUE KEY role_scope (business_id, role_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        can_view_bookings TINYINT(1) NOT NULL DEFAULT 1,
        can_manage_payments TINYINT(1) NOT NULL DEFAULT 0,
        can_edit_profile TINYINT(1) NOT NULL DEFAULT 1,
        can_create_referrals TINYINT(1) NOT NULL DEFAULT 1,
        can_manage_staff TINYINT(1) NOT NULL DEFAULT 0,
        can_view_analytics TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_meta (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        meta_key VARCHAR(100) NOT NULL,
        meta_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY user_meta_key (user_id, meta_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS consents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        consent_type VARCHAR(50) NOT NULL,
        granted TINYINT(1) NOT NULL DEFAULT 0,
        granted_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY user_consent (user_id, consent_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        invoice_number VARCHAR(100),
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('draft','sent','paid','cancelled') NOT NULL DEFAULT 'draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS disputes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_id INT NOT NULL,
        subject VARCHAR(255),
        description TEXT,
        status ENUM('open','pending','closed','rejected') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS data_export_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending','processing','ready','completed') NOT NULL DEFAULT 'pending',
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS account_deletion_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // user_sessions table removed - consolidated into login_sessions (line ~1438)

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        pref_key VARCHAR(100) NOT NULL,
        pref_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY user_pref_key (user_id, pref_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    ensure_user_settings_columns($pdo);
    ensure_business_settings_columns($pdo);
    ensure_notification_preferences_schema($pdo);
}

function ensure_notification_preferences_schema(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['notification_preferences', 'pref_key']);
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $stmt->execute(['notification_preferences', 'email_notifications']);
    if ((int)$stmt->fetchColumn() === 0) {
        return;
    }

    $pdo->exec('CREATE TABLE notification_preferences_kv (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        pref_key VARCHAR(100) NOT NULL,
        pref_value INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY user_pref_key (user_id, pref_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $keys = ['email_notifications', 'sms_alerts', 'booking_alerts', 'payment_alerts', 'marketing_emails', 'system_announcements'];
    $rows = $pdo->query('SELECT * FROM notification_preferences')->fetchAll();
    $insert = $pdo->prepare('INSERT INTO notification_preferences_kv (user_id, pref_key, pref_value) VALUES (?, ?, ?)');
    foreach ($rows as $row) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $insert->execute([(int)$row['user_id'], $key, (int)$row[$key]]);
            }
        }
    }

    $pdo->exec('DROP TABLE notification_preferences');
    $pdo->exec('RENAME TABLE notification_preferences_kv TO notification_preferences');
}

function ensure_user_settings_columns(PDO $pdo): void
{
    $columns = [
        'phone' => "ALTER TABLE users ADD phone VARCHAR(50) NULL AFTER email",
        'profile_image' => "ALTER TABLE users ADD profile_image VARCHAR(500) NULL AFTER phone",
        'language' => "ALTER TABLE users ADD language VARCHAR(10) NOT NULL DEFAULT 'en' AFTER profile_image",
        'timezone' => "ALTER TABLE users ADD timezone VARCHAR(50) NOT NULL DEFAULT 'Africa/Kigali' AFTER language",
        'theme' => "ALTER TABLE users ADD theme VARCHAR(20) NOT NULL DEFAULT 'light' AFTER timezone",
        'ui_density' => "ALTER TABLE users ADD ui_density VARCHAR(20) NOT NULL DEFAULT 'default' AFTER theme",
        'default_dashboard_view' => "ALTER TABLE users ADD default_dashboard_view VARCHAR(50) NOT NULL DEFAULT 'overview' AFTER ui_density",
        'deleted_at' => "ALTER TABLE users ADD deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at",
    ];

    foreach ($columns as $col => $sql) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['users', $col]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function ensure_business_settings_columns(PDO $pdo): void
{
    $columns = [
        'payout_method' => "ALTER TABLE businesses ADD payout_method VARCHAR(50) NULL AFTER city",
        'payment_account' => "ALTER TABLE businesses ADD payment_account VARCHAR(255) NULL AFTER payout_method",
        'currency_pref' => "ALTER TABLE businesses ADD currency_pref VARCHAR(10) NOT NULL DEFAULT 'RWF' AFTER payment_account",
        'public_profile' => "ALTER TABLE businesses ADD public_profile TINYINT(1) NOT NULL DEFAULT 1 AFTER currency_pref",
        'marketing_consent' => "ALTER TABLE businesses ADD marketing_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER public_profile",
        'commission_rate' => "ALTER TABLE businesses ADD commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER marketing_consent",
        'analytics_enabled' => "ALTER TABLE businesses ADD analytics_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER commission_rate",
        'report_export_format' => "ALTER TABLE businesses ADD report_export_format VARCHAR(10) NOT NULL DEFAULT 'csv' AFTER analytics_enabled",
    ];

    foreach ($columns as $col => $sql) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['businesses', $col]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

// ─── SETTINGS HELPER FUNCTIONS ───────────────────────────────────────────────

function get_user_settings(int $user_id): array
{
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: [];
}

function get_business_settings(int $business_id): array
{
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT * FROM businesses WHERE id = ?');
    $stmt->execute([$business_id]);
    return $stmt->fetch() ?: [];
}

function get_login_history(int $user_id, int $limit = 20): array
{
    $pdo = db_connect();
    $limit = max(1, min(100, (int)$limit));
    $stmt = $pdo->prepare('SELECT * FROM login_history WHERE user_id = ? ORDER BY logged_in_at DESC LIMIT ' . $limit);
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function log_login(PDO $pdo, int $user_id, ?string $ip = null, ?string $ua = null, ?string $device = null, ?string $browser = null, ?string $os = null): void
{
    $stmt = $pdo->prepare('INSERT INTO login_history (user_id, ip_address, user_agent, device, browser, os) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$user_id, $ip ?? '', $ua ?? '', $device ?? '', $browser ?? '', $os ?? '']);

    $session_id = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO login_sessions (id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_active_at = NOW()')->execute([$session_id, $user_id, $ip ?? '', $ua ?? '']);
}

function get_active_sessions(int $user_id): array
{
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT id, ip_address, user_agent, created_at, last_active_at FROM login_sessions WHERE user_id = ? ORDER BY last_active_at DESC');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function invalidate_session(string $session_id): void
{
    $pdo = db_connect();
    $pdo->prepare('DELETE FROM login_sessions WHERE id = ?')->execute([$session_id]);
}

function invalidate_all_other_sessions(int $user_id, string $current_session_id): void
{
    $pdo = db_connect();
    $pdo->prepare('DELETE FROM login_sessions WHERE user_id = ? AND id != ?')->execute([$user_id, $current_session_id]);
}

function log_audit(PDO $pdo, int $business_id, int $user_id, string $action, string $entity_type, ?int $entity_id = null, ?array $old_value = null, ?array $new_value = null, ?string $ip = null): void
{
    $stmt = $pdo->prepare('INSERT INTO audit_logs (business_id, user_id, action, entity_type, entity_id, old_value, new_value, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $business_id, $user_id, $action, $entity_type, $entity_id,
        $old_value ? json_encode($old_value) : null,
        $new_value ? json_encode($new_value) : null,
        $ip ?? ''
    ]);
}

function get_audit_logs(PDO $pdo, int $business_id, int $limit = 50): array
{
    $limit = max(1, min(200, (int)$limit));
    $stmt = $pdo->prepare('SELECT al.*, u.name AS actor_name FROM audit_logs al JOIN users u ON u.id = al.user_id WHERE al.business_id = ? ORDER BY al.created_at DESC LIMIT ' . $limit);
    $stmt->execute([$business_id]);
    return $stmt->fetchAll();
}

function get_user_2fa_status(int $user_id): array
{
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT enabled, enabled_at FROM user_2fa WHERE user_id = ?');
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: ['enabled' => 0, 'enabled_at' => null];
}

function toggle_user_2fa(int $user_id, bool $enable, ?string $secret = null): void
{
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT id FROM user_2fa WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare('UPDATE user_2fa SET enabled = ?, secret = COALESCE(?, secret) WHERE user_id = ?')->execute([$enable ? 1 : 0, $secret, $user_id]);
    } else {
        $pdo->prepare('INSERT INTO user_2fa (user_id, enabled, secret, enabled_at) VALUES (?, ?, ?, NOW())')->execute([$user_id, $enable ? 1 : 0, $secret]);
    }
}

function get_2fa_backup_codes(int $user_id): array
{
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT backup_codes FROM user_2fa WHERE user_id = ? AND backup_codes IS NOT NULL');
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result ? json_decode($result['backup_codes'], true) ?: [] : [];
}

function get_notification_preferences(int $user_id): array
{
    $pdo = db_connect();
    $defaults = [
        'email_notifications' => 1,
        'sms_alerts' => 0,
        'booking_alerts' => 1,
        'payment_alerts' => 1,
        'marketing_emails' => 0,
        'system_announcements' => 1,
    ];
    $stmt = $pdo->prepare('SELECT pref_key, pref_value FROM notification_preferences WHERE user_id = ?');
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll() as $row) {
        $defaults[$row['pref_key']] = (int)$row['pref_value'];
    }
    return $defaults;
}

function save_notification_preferences(int $user_id, array $prefs): void
{
    $pdo = db_connect();
    $allowed = ['email_notifications', 'sms_alerts', 'booking_alerts', 'payment_alerts', 'marketing_emails', 'system_announcements'];
    $stmt = $pdo->prepare('INSERT INTO notification_preferences (user_id, pref_key, pref_value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)');
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $prefs)) {
            continue;
        }
        $stmt->execute([$user_id, $key, (int)$prefs[$key]]);
    }
}

function export_user_data(int $user_id): array
{
    $pdo = db_connect();
    $user = get_user_settings($user_id);
    unset($user['password_hash']);

    $stmt = $pdo->prepare('SELECT * FROM referrals WHERE source_business_id = (SELECT business_id FROM users WHERE id = ?) OR target_business_id = (SELECT business_id FROM users WHERE id = ?)');
    $stmt->execute([$user_id, $user_id]);
    $referrals = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM commissions WHERE source_business_id = (SELECT business_id FROM users WHERE id = ?) OR target_business_id = (SELECT business_id FROM users WHERE id = ?)');
    $stmt->execute([$user_id, $user_id]);
    $commissions = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM login_history WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $logins = $stmt->fetchAll();

    return [
        'user' => $user,
        'referrals' => $referrals,
        'commissions' => $commissions,
        'login_history' => $logins,
        'exported_at' => date('Y-m-d H:i:s'),
    ];
}

function detect_device(string $ua): array
{
    $device = 'Desktop';
    $browser = 'Unknown';
    $os = 'Unknown';

    if (preg_match('/mobile|android|iphone|ipad/i', $ua)) {
        $device = preg_match('/tablet|ipad/i', $ua) ? 'Tablet' : 'Mobile';
    }
    if (preg_match('/Chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Safari/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/Edge/i', $ua)) $browser = 'Edge';

    if (preg_match('/Windows/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Mac/i', $ua)) $os = 'macOS';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
    elseif (preg_match('/Android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iOS|iPhone/i', $ua)) $os = 'iOS';

    return ['device' => $device, 'browser' => $browser, 'os' => $os];
}

function get_staff_members(PDO $pdo, int $business_id): array
{
    $stmt = $pdo->prepare("SELECT s.*, sp.can_view_bookings, sp.can_manage_payments, sp.can_edit_profile, sp.can_create_referrals, sp.can_manage_staff, sp.can_view_analytics
        FROM staff s
        LEFT JOIN staff_permissions sp ON sp.staff_id = s.id
        WHERE s.business_id = ?
        ORDER BY s.name");
    $stmt->execute([$business_id]);
    return $stmt->fetchAll();
}

function get_staff_roles(PDO $pdo, int $business_id): array
{
    $stmt = $pdo->prepare('SELECT * FROM staff_roles WHERE business_id = ? ORDER BY role_name');
    $stmt->execute([$business_id]);
    return $stmt->fetchAll();
}

function update_staff_permissions(PDO $pdo, int $staff_id, array $perms): void
{
    $cols = ['can_view_bookings', 'can_manage_payments', 'can_edit_profile', 'can_create_referrals', 'can_manage_staff', 'can_view_analytics'];
    $sets = [];
    $vals = [];
    foreach ($cols as $col) {
        if (array_key_exists($col, $perms)) {
            $sets[] = "$col = ?";
            $vals[] = (int)$perms[$col];
        }
    }
    if (empty($sets)) return;
    $vals[] = $staff_id;

    $pdo->prepare('UPDATE staff_permissions SET ' . implode(', ', $sets) . ' WHERE staff_id = ?')->execute($vals);
}

function seed_default_staff_roles(PDO $pdo, int $business_id): void
{
    $roles = [
        ['manager', 1, 1, 1, 1, 1, 1, 1],
        ['receptionist', 1, 0, 1, 1, 0, 0, 0],
        ['concierge', 1, 0, 1, 1, 0, 0, 0],
    ];

    foreach ($roles as $role) {
        [$role_name, $v, $mp, $ep, $cr, $ms, $va] = $role;
        $check = $pdo->prepare('SELECT id FROM staff_roles WHERE business_id = ? AND role_name = ?');
        $check->execute([$business_id, $role_name]);
        if (!$check->fetch()) {
            $pdo->prepare('INSERT INTO staff_roles (business_id, role_name, can_view_bookings, can_manage_payments, can_edit_profile, can_create_referrals, can_manage_staff, can_view_analytics) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$business_id, $role_name, $v, $mp, $ep, $cr, $ms, $va]);
        }
    }
}

/**
 * Add performance indexes on commonly queried columns.
 * Uses IF NOT EXISTS pattern via information_schema check.
 */
function ensure_performance_indexes(PDO $pdo): void
{
    $indexes = [
        ['users', 'idx_users_business_id', 'business_id'],
        ['staff', 'idx_staff_business_id', 'business_id'],
        ['staff', 'idx_staff_user_id', 'user_id'],
        ['referrals', 'idx_referrals_source_business', 'source_business_id'],
        ['referrals', 'idx_referrals_target_business', 'target_business_id'],
        ['referrals', 'idx_referrals_staff_id', 'staff_id'],
        ['referrals', 'idx_referrals_status', 'status'],
        ['commissions', 'idx_commissions_source_business', 'source_business_id'],
        ['commissions', 'idx_commissions_target_business', 'target_business_id'],
        ['commissions', 'idx_commissions_owed_to_business', 'owed_to_business_id'],
        ['commissions', 'idx_commissions_referral_id', 'referral_id'],
        ['commissions', 'idx_commissions_month', 'month'],
        ['commissions', 'idx_commissions_status', 'status'],
        ['payments', 'idx_payments_commission_id', 'commission_id'],
        ['partnerships', 'idx_partnerships_business_id', 'business_id'],
        ['partnerships', 'idx_partnerships_partner_business', 'partner_business_id'],
        ['partnerships', 'idx_partnerships_status', 'status'],
        ['transactions', 'idx_transactions_from_business', 'from_business_id'],
        ['transactions', 'idx_transactions_to_business', 'to_business_id'],
        ['debt_notifications', 'idx_debt_notifications_debt_id', 'debt_id'],
        ['payment_attempts', 'idx_payment_attempts_debt_id', 'debt_id'],
        ['payment_attempts', 'idx_payment_attempts_business_id', 'business_id'],
        ['earnings', 'idx_earnings_business_id', 'business_id'],
        ['earnings', 'idx_earnings_status', 'status'],
        ['login_history', 'idx_login_history_user_id', 'user_id'],
        ['login_history', 'idx_login_history_logged_in_at', 'logged_in_at'],
        ['audit_logs', 'idx_audit_logs_business_id', 'business_id'],
        ['audit_logs', 'idx_audit_logs_created_at', 'created_at'],
        ['invoices', 'idx_invoices_business_id', 'business_id'],
        ['invoices', 'idx_invoices_status', 'status'],
        ['disputes', 'idx_disputes_business_id', 'business_id'],
        ['disputes', 'idx_disputes_status', 'status'],
        ['login_sessions', 'idx_login_sessions_user_id', 'user_id'],
        ['staff_permissions', 'idx_staff_permissions_staff_id', 'staff_id'],
        ['contract_templates', 'idx_contract_templates_city', 'city'],
        ['contract_templates', 'idx_contract_templates_status', 'status'],
    ];

    // Get existing indexes
    $existing = [];
    $stmt = $pdo->query("SELECT TABLE_NAME, INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE()");
    foreach ($stmt->fetchAll() as $row) {
        $existing[$row['TABLE_NAME'] . '.' . $row['INDEX_NAME']] = true;
    }

    foreach ($indexes as [$table, $index_name, $column]) {
        if (!isset($existing[$table . '.' . $index_name])) {
            try {
                $pdo->exec("CREATE INDEX {$index_name} ON {$table} ({$column})");
            } catch (PDOException $e) {
                // Index may already exist or table missing - skip silently
            }
        }
    }
}
