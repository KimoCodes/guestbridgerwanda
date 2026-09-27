<?php
require_once __DIR__ . '/../app/Config/config.php';
require_once __DIR__ . '/../app/Config/database.php';

// Security: Only super admins can run migrations
$user = require_super_admin();

header('Content-Type: text/plain');

try {
    $pdo = db_connect();
    echo "Connected to database.\n";
    
    // Check which v2 tables are missing
    $required_tables = [
        'settlements', 'settlement_items', 'commission_rules', 'commission_allocations',
        'platform_fee_tiers', 'monthly_statements', 'statement_line_items',
        'payment_attempts', 'referral_timeline', 'partner_benefits',
        'employee_earnings_ledger', 'business_earnings_ledger', 'audit_log',
        'disputes', 'dispute_messages', 'notifications'
    ];
    
    $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema()");
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $missing = array_diff($required_tables, $existing);
    
    if (empty($missing)) {
        echo "All v2 tables exist. Migration already complete.\n";
        exit;
    }
    
    echo "Missing tables: " . implode(', ', $missing) . "\n";
    echo "The legacy migration_v2.php installer is MySQL-only; on PostgreSQL the schema is managed by database/migrate_to_postgres.php.\n";
    exit;
    
    echo "\nDone! Check above for details.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
