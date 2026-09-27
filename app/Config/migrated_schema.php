<?php
/**
 * AUTO-GENERATED from the migrated production schema (see /tmp/gb_gen_migrated.php).
 * Aligns a fresh install with production: InnoDB-era single-column indexes,
 * NOT NULL flags, and column defaults as migrated from MySQL.
 * Indexes are matched by structure so a differently-named equivalent is never duplicated.
 */
function ensure_migrated_schema(PDO $pdo): void
{
    $exists = [];
    foreach ($pdo->query("SELECT relname FROM pg_class WHERE relkind = 'r' AND relnamespace = current_schema()::regnamespace") as $row) {
        $exists[$row['relname']] = true;
    }
    $have = [];
    foreach ($pdo->query("SELECT tablename, indexdef FROM pg_indexes WHERE schemaname = current_schema()") as $row) {
        $have[$row['tablename'] . ' :: ' . preg_replace('/CREATE (UNIQUE )?INDEX \w+ ON [^.]+\.?/', '', $row['indexdef'])] = true;
    }

    $indexes = array (
  0 => 
  array (
    0 => 'account_deletion_requests',
    1 => 'account_deletion_requests USING btree (user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_account_deletion_requests_user_id ON account_deletion_requests USING btree (user_id)',
  ),
  1 => 
  array (
    0 => 'audit_logs',
    1 => 'audit_logs USING btree (user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_audit_logs_user_id ON audit_logs USING btree (user_id)',
  ),
  2 => 
  array (
    0 => 'businesses',
    1 => 'businesses USING btree (phone)',
    2 => 'CREATE UNIQUE INDEX IF NOT EXISTS uq_businesses_business_phone_unique ON businesses USING btree (phone)',
  ),
  3 => 
  array (
    0 => 'commission_allocations',
    1 => 'commission_allocations USING btree (commission_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_commission_allocations_commission_id ON commission_allocations USING btree (commission_id)',
  ),
  4 => 
  array (
    0 => 'commission_allocations',
    1 => 'commission_allocations USING btree (recipient_business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_commission_allocations_recipient_business_id ON commission_allocations USING btree (recipient_business_id)',
  ),
  5 => 
  array (
    0 => 'commission_allocations',
    1 => 'commission_allocations USING btree (recipient_employee_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_commission_allocations_recipient_employee_id ON commission_allocations USING btree (recipient_employee_id)',
  ),
  6 => 
  array (
    0 => 'commission_rules',
    1 => 'commission_rules USING btree (partnership_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_commission_rules_partnership_id ON commission_rules USING btree (partnership_id)',
  ),
  7 => 
  array (
    0 => 'contract_templates',
    1 => 'contract_templates USING btree (created_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_contract_templates_created_by_user_id ON contract_templates USING btree (created_by_user_id)',
  ),
  8 => 
  array (
    0 => 'data_export_requests',
    1 => 'data_export_requests USING btree (user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_data_export_requests_user_id ON data_export_requests USING btree (user_id)',
  ),
  9 => 
  array (
    0 => 'debt_notifications',
    1 => 'debt_notifications USING btree (business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_debt_notifications_business_id ON debt_notifications USING btree (business_id)',
  ),
  10 => 
  array (
    0 => 'employee_earnings',
    1 => 'employee_earnings USING btree (business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_employee_earnings_business_id ON employee_earnings USING btree (business_id)',
  ),
  11 => 
  array (
    0 => 'featured_listings',
    1 => 'featured_listings USING btree (created_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_featured_listings_created_by_user_id ON featured_listings USING btree (created_by_user_id)',
  ),
  12 => 
  array (
    0 => 'guest_benefits',
    1 => 'guest_benefits USING btree (partnership_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_guest_benefits_partnership_id ON guest_benefits USING btree (partnership_id)',
  ),
  13 => 
  array (
    0 => 'guest_transactions',
    1 => 'guest_transactions USING btree (business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_guest_transactions_business_id ON guest_transactions USING btree (business_id)',
  ),
  14 => 
  array (
    0 => 'guest_transactions',
    1 => 'guest_transactions USING btree (employee_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_guest_transactions_employee_id ON guest_transactions USING btree (employee_id)',
  ),
  15 => 
  array (
    0 => 'guest_transactions',
    1 => 'guest_transactions USING btree (recorded_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_guest_transactions_recorded_by_user_id ON guest_transactions USING btree (recorded_by_user_id)',
  ),
  16 => 
  array (
    0 => 'guest_transactions',
    1 => 'guest_transactions USING btree (referral_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_guest_transactions_referral_id ON guest_transactions USING btree (referral_id)',
  ),
  17 => 
  array (
    0 => 'hotel_debts',
    1 => 'hotel_debts USING btree (creditor_business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_hotel_debts_creditor_business_id ON hotel_debts USING btree (creditor_business_id)',
  ),
  18 => 
  array (
    0 => 'invoices',
    1 => 'invoices USING btree (invoice_number)',
    2 => 'CREATE UNIQUE INDEX IF NOT EXISTS uq_invoices_invoice_number_unique ON invoices USING btree (invoice_number)',
  ),
  19 => 
  array (
    0 => 'notifications',
    1 => 'notifications USING btree (business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_notifications_business_id ON notifications USING btree (business_id)',
  ),
  20 => 
  array (
    0 => 'notifications',
    1 => 'notifications USING btree (user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_notifications_user_id ON notifications USING btree (user_id)',
  ),
  21 => 
  array (
    0 => 'onboarding_checklists',
    1 => 'onboarding_checklists USING btree (business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_onboarding_checklists_business_id ON onboarding_checklists USING btree (business_id)',
  ),
  22 => 
  array (
    0 => 'onboarding_checklists',
    1 => 'onboarding_checklists USING btree (completed_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_onboarding_checklists_completed_by_user_id ON onboarding_checklists USING btree (completed_by_user_id)',
  ),
  23 => 
  array (
    0 => 'onboarding_checklists',
    1 => 'onboarding_checklists USING btree (created_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_onboarding_checklists_created_by_user_id ON onboarding_checklists USING btree (created_by_user_id)',
  ),
  24 => 
  array (
    0 => 'partner_places',
    1 => 'partner_places USING btree (business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_partner_places_business_id ON partner_places USING btree (business_id)',
  ),
  25 => 
  array (
    0 => 'password_resets',
    1 => 'password_resets USING btree (user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_password_resets_user_id ON password_resets USING btree (user_id)',
  ),
  26 => 
  array (
    0 => 'pilot_feedback',
    1 => 'pilot_feedback USING btree (business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_pilot_feedback_business_id ON pilot_feedback USING btree (business_id)',
  ),
  27 => 
  array (
    0 => 'pilot_feedback',
    1 => 'pilot_feedback USING btree (created_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_pilot_feedback_created_by_user_id ON pilot_feedback USING btree (created_by_user_id)',
  ),
  28 => 
  array (
    0 => 'pilot_notifications',
    1 => 'pilot_notifications USING btree (city, trigger_type)',
    2 => 'CREATE UNIQUE INDEX IF NOT EXISTS uq_pilot_notifications_unique_city_trigger ON pilot_notifications USING btree (city, trigger_type)',
  ),
  29 => 
  array (
    0 => 'pilot_notifications',
    1 => 'pilot_notifications USING btree (reviewed_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_pilot_notifications_reviewed_by_user_id ON pilot_notifications USING btree (reviewed_by_user_id)',
  ),
  30 => 
  array (
    0 => 'pilot_notifications',
    1 => 'pilot_notifications USING btree (triggered_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_pilot_notifications_triggered_by_user_id ON pilot_notifications USING btree (triggered_by_user_id)',
  ),
  31 => 
  array (
    0 => 'place_banners',
    1 => 'place_banners USING btree (place_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_place_banners_place_id ON place_banners USING btree (place_id)',
  ),
  32 => 
  array (
    0 => 'place_gallery',
    1 => 'place_gallery USING btree (place_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_place_gallery_place_id ON place_gallery USING btree (place_id)',
  ),
  33 => 
  array (
    0 => 'place_referrals',
    1 => 'place_referrals USING btree (place_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_place_referrals_place_id ON place_referrals USING btree (place_id)',
  ),
  34 => 
  array (
    0 => 'platform_config',
    1 => 'platform_config USING btree (config_key)',
    2 => 'CREATE UNIQUE INDEX IF NOT EXISTS platform_config_pkey ON platform_config USING btree (config_key)',
  ),
  35 => 
  array (
    0 => 'receipts',
    1 => 'receipts USING btree (business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_receipts_business_id ON receipts USING btree (business_id)',
  ),
  36 => 
  array (
    0 => 'receipts',
    1 => 'receipts USING btree (referral_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_receipts_referral_id ON receipts USING btree (referral_id)',
  ),
  37 => 
  array (
    0 => 'receipts',
    1 => 'receipts USING btree (uploaded_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_receipts_uploaded_by_user_id ON receipts USING btree (uploaded_by_user_id)',
  ),
  38 => 
  array (
    0 => 'referral_events',
    1 => 'referral_events USING btree (actor_business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_referral_events_actor_business_id ON referral_events USING btree (actor_business_id)',
  ),
  39 => 
  array (
    0 => 'referral_events',
    1 => 'referral_events USING btree (actor_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_referral_events_actor_user_id ON referral_events USING btree (actor_user_id)',
  ),
  40 => 
  array (
    0 => 'referral_redemptions',
    1 => 'referral_redemptions USING btree (destination_business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_referral_redemptions_destination_business_id ON referral_redemptions USING btree (destination_business_id)',
  ),
  41 => 
  array (
    0 => 'referral_redemptions',
    1 => 'referral_redemptions USING btree (redeemed_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_referral_redemptions_redeemed_by_user_id ON referral_redemptions USING btree (redeemed_by_user_id)',
  ),
  42 => 
  array (
    0 => 'referral_redemptions',
    1 => 'referral_redemptions USING btree (referral_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_referral_redemptions_referral_id ON referral_redemptions USING btree (referral_id)',
  ),
  43 => 
  array (
    0 => 'seasonality_settings',
    1 => 'seasonality_settings USING btree (updated_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_seasonality_settings_updated_by_user_id ON seasonality_settings USING btree (updated_by_user_id)',
  ),
  44 => 
  array (
    0 => 'settlements',
    1 => 'settlements USING btree (created_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_settlements_created_by_user_id ON settlements USING btree (created_by_user_id)',
  ),
  45 => 
  array (
    0 => 'settlements',
    1 => 'settlements USING btree (from_business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_settlements_from_business_id ON settlements USING btree (from_business_id)',
  ),
  46 => 
  array (
    0 => 'settlements',
    1 => 'settlements USING btree (to_business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_settlements_to_business_id ON settlements USING btree (to_business_id)',
  ),
  47 => 
  array (
    0 => 'settlements',
    1 => 'settlements USING btree (to_employee_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_settlements_to_employee_id ON settlements USING btree (to_employee_id)',
  ),
  48 => 
  array (
    0 => 'settlements',
    1 => 'settlements USING btree (verified_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_settlements_verified_by_user_id ON settlements USING btree (verified_by_user_id)',
  ),
  49 => 
  array (
    0 => 'settlement_items',
    1 => 'settlement_items USING btree (commission_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_settlement_items_commission_id ON settlement_items USING btree (commission_id)',
  ),
  50 => 
  array (
    0 => 'settlement_items',
    1 => 'settlement_items USING btree (settlement_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_settlement_items_settlement_id ON settlement_items USING btree (settlement_id)',
  ),
  51 => 
  array (
    0 => 'settlement_items',
    1 => 'settlement_items USING btree (transaction_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_settlement_items_transaction_id ON settlement_items USING btree (transaction_id)',
  ),
  52 => 
  array (
    0 => 'settlement_signoffs',
    1 => 'settlement_signoffs USING btree (partner_business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_settlement_signoffs_partner_business_id ON settlement_signoffs USING btree (partner_business_id)',
  ),
  53 => 
  array (
    0 => 'settlement_signoffs',
    1 => 'settlement_signoffs USING btree (signed_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_settlement_signoffs_signed_by_user_id ON settlement_signoffs USING btree (signed_by_user_id)',
  ),
  54 => 
  array (
    0 => 'staff_referral_identities',
    1 => 'staff_referral_identities USING btree (business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_staff_referral_identities_business_id ON staff_referral_identities USING btree (business_id)',
  ),
  55 => 
  array (
    0 => 'staff_rewards',
    1 => 'staff_rewards USING btree (approved_by_user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_staff_rewards_approved_by_user_id ON staff_rewards USING btree (approved_by_user_id)',
  ),
  56 => 
  array (
    0 => 'staff_rewards',
    1 => 'staff_rewards USING btree (business_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_staff_rewards_business_id ON staff_rewards USING btree (business_id)',
  ),
  57 => 
  array (
    0 => 'staff_rewards',
    1 => 'staff_rewards USING btree (staff_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_staff_rewards_staff_id ON staff_rewards USING btree (staff_id)',
  ),
  58 => 
  array (
    0 => 'transactions',
    1 => 'transactions USING btree (payment_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_transactions_payment_id ON transactions USING btree (payment_id)',
  ),
  59 => 
  array (
    0 => 'users',
    1 => 'users USING btree (phone)',
    2 => 'CREATE UNIQUE INDEX IF NOT EXISTS uq_users_user_phone_unique ON users USING btree (phone)',
  ),
  60 => 
  array (
    0 => 'users',
    1 => 'users USING btree (business_id, role, status)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_users_users_business_role_status ON users USING btree (business_id, role, status)',
  ),
  61 => 
  array (
    0 => 'user_2fa',
    1 => 'user_2fa USING btree (user_id)',
    2 => 'CREATE INDEX IF NOT EXISTS ix_user_2fa_user_id ON user_2fa USING btree (user_id)',
  ),
);
    foreach ($indexes as [$table, $key, $sql]) {
        if (!isset($exists[$table]) || isset($have[$table . ' :: ' . $key])) continue;
        $pdo->exec($sql);
    }

    $notNull = array (
  'account_deletion_requests' => 
  array (
    0 => 'requested_at',
  ),
  'audit_logs' => 
  array (
    0 => 'created_at',
  ),
  'billing_periods' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'businesses' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'commission_allocations' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'commission_rules' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'commissions' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'contract_templates' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'data_export_requests' => 
  array (
    0 => 'requested_at',
  ),
  'debt_notifications' => 
  array (
    0 => 'created_at',
  ),
  'disputes' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'earnings' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'employee_earnings' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'featured_listings' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'guest_benefits' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'guest_transactions' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'guests' => 
  array (
    0 => 'created_at',
  ),
  'hotel_debts' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'invoices' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'login_history' => 
  array (
    0 => 'logged_in_at',
  ),
  'login_sessions' => 
  array (
    0 => 'created_at',
    1 => 'last_active_at',
  ),
  'monthly_statements' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'notification_preferences' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'notifications' => 
  array (
    0 => 'created_at',
  ),
  'onboarding_checklists' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'partner_places' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'partnerships' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'password_resets' => 
  array (
    0 => 'created_at',
  ),
  'payment_attempts' => 
  array (
    0 => 'created_at',
  ),
  'payments' => 
  array (
    0 => 'created_at',
  ),
  'pilot_feedback' => 
  array (
    0 => 'created_at',
  ),
  'pilot_notifications' => 
  array (
    0 => 'created_at',
  ),
  'place_banners' => 
  array (
    0 => 'created_at',
  ),
  'place_gallery' => 
  array (
    0 => 'created_at',
  ),
  'place_referrals' => 
  array (
    0 => 'created_at',
  ),
  'platform_config' => 
  array (
    0 => 'config_key',
    1 => 'config_value',
    2 => 'updated_at',
  ),
  'platform_fee_tiers' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'platform_fees' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'platform_revenue_settings' => 
  array (
    0 => 'updated_at',
  ),
  'receipts' => 
  array (
    0 => 'created_at',
  ),
  'referral_events' => 
  array (
    0 => 'created_at',
    1 => 'new_status',
  ),
  'referral_redemptions' => 
  array (
    0 => 'redeemed_at',
  ),
  'referrals' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'seasonality_settings' => 
  array (
    0 => 'updated_at',
  ),
  'settlement_items' => 
  array (
    0 => 'created_at',
  ),
  'settlement_signoffs' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'settlements' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'staff' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'staff_permissions' => 
  array (
    0 => 'updated_at',
  ),
  'staff_referral_identities' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'staff_rewards' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'staff_roles' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
  'transactions' => 
  array (
    0 => 'created_at',
  ),
  'user_meta' => 
  array (
    0 => 'updated_at',
  ),
  'user_preferences' => 
  array (
    0 => 'updated_at',
  ),
  'users' => 
  array (
    0 => 'created_at',
    1 => 'updated_at',
  ),
);
    foreach ($notNull as $table => $cols) {
        if (!isset($exists[$table])) continue;
        $alter = [];
        foreach ($cols as $col) {
            if ($pdo->query(sprintf('SELECT 1 FROM %s WHERE %s IS NULL LIMIT 1', $table, $col))->fetch() !== false) continue;
            $alter[] = $col;
        }
        if ($alter) {
            $acts = [];
            foreach ($alter as $col) $acts[] = "ALTER COLUMN {$col} SET NOT NULL";
            $pdo->exec("ALTER TABLE {$table} " . implode(', ', $acts));
        }
    }

    $defaults = array (
  'account_deletion_requests' => 
  array (
    'requested_at' => 'LOCALTIMESTAMP',
  ),
  'audit_logs' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'billing_periods' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'platform_fee_amount' => '0.00',
    'total_commission_earned' => '0.00',
    'total_referral_value' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'businesses' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'guest_satisfaction_score' => '80.00',
    'payout_compliance_score' => '80.00',
    'reliability_score' => '80.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'commission_allocations' => 
  array (
    'amount' => '0.00',
    'created_at' => 'LOCALTIMESTAMP',
    'percentage' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'commission_rules' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'commissions' => 
  array (
    'amount' => '0.00',
    'commission_percentage' => '10.00',
    'created_at' => 'LOCALTIMESTAMP',
    'employee_share' => '0.00',
    'estimated_value' => '0.00',
    'platform_share' => '0.00',
    'referring_business_share' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'contract_templates' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'default_commission_rate' => '10.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'data_export_requests' => 
  array (
    'requested_at' => 'LOCALTIMESTAMP',
  ),
  'debt_notifications' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'disputes' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'earnings' => 
  array (
    'amount' => '0.00',
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'employee_earnings' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'pending_commission' => '0.00',
    'settled_commission' => '0.00',
    'total_commission_generated' => '0.00',
    'total_guest_value' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'featured_listings' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'guest_benefits' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'guest_transactions' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'eligible_amount' => '0.00',
    'gross_amount' => '0.00',
    'transaction_date' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'guests' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'hotel_debts' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'paid_amount' => '0.00',
    'remaining_amount' => '0.00',
    'total_amount' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'invoices' => 
  array (
    'amount' => '0.00',
    'created_at' => 'LOCALTIMESTAMP',
    'paid_amount' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'login_history' => 
  array (
    'logged_in_at' => 'LOCALTIMESTAMP',
  ),
  'login_sessions' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'last_active_at' => 'LOCALTIMESTAMP',
  ),
  'monthly_statements' => 
  array (
    'adjustments' => '0.00',
    'closing_balance' => '0.00',
    'commission_earned' => '0.00',
    'commission_payable' => '0.00',
    'created_at' => 'LOCALTIMESTAMP',
    'employee_commission' => '0.00',
    'opening_balance' => '0.00',
    'payments_made' => '0.00',
    'payments_received' => '0.00',
    'platform_fee' => '0.00',
    'referral_value_generated' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'notification_preferences' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'notifications' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'onboarding_checklists' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'partner_places' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'partnerships' => 
  array (
    'commission_rate' => '10.00',
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'password_resets' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'expires_at' => 'LOCALTIMESTAMP',
  ),
  'payment_attempts' => 
  array (
    'amount' => '0.00',
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'payments' => 
  array (
    'amount' => '0.00',
    'created_at' => 'LOCALTIMESTAMP',
    'method' => '\'manual\'::character varying',
    'paid_at' => 'LOCALTIMESTAMP',
  ),
  'pilot_feedback' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'pilot_notifications' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'status' => '\'pending\'::text',
  ),
  'place_banners' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'place_gallery' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'place_referrals' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'platform_fee_tiers' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'fee_amount' => '0.00',
    'min_value' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'platform_fees' => 
  array (
    'analytics_fee' => '0.00',
    'created_at' => 'LOCALTIMESTAMP',
    'featured_fee' => '0.00',
    'referral_fee' => '0.00',
    'subscription_fee' => '0.00',
    'total_amount' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'platform_revenue_settings' => 
  array (
    'analytics_tier_fee' => '0.00',
    'featured_listing_fee' => '0.00',
    'referral_fee_percentage' => '0.00',
    'subscription_fee' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'receipts' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'referral_events' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'referral_redemptions' => 
  array (
    'redeemed_at' => 'LOCALTIMESTAMP',
  ),
  'referrals' => 
  array (
    'commission_percentage' => '10.00',
    'created_at' => 'LOCALTIMESTAMP',
    'estimated_value' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'seasonality_settings' => 
  array (
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'settlement_items' => 
  array (
    'amount' => '0.00',
    'created_at' => 'LOCALTIMESTAMP',
  ),
  'settlement_signoffs' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'settlements' => 
  array (
    'amount' => '0.00',
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'staff' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'staff_permissions' => 
  array (
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'staff_referral_identities' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'total_commission' => '0.00',
    'total_revenue' => '0.00',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'staff_rewards' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'staff_roles' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'transactions' => 
  array (
    'amount' => '0.00',
    'created_at' => 'LOCALTIMESTAMP',
    'transaction_date' => 'LOCALTIMESTAMP',
  ),
  'user_meta' => 
  array (
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'user_preferences' => 
  array (
    'updated_at' => 'LOCALTIMESTAMP',
  ),
  'users' => 
  array (
    'created_at' => 'LOCALTIMESTAMP',
    'role' => '\'super_admin\'::text',
    'updated_at' => 'LOCALTIMESTAMP',
  ),
);
    foreach ($defaults as $table => $cols) {
        if (!isset($exists[$table])) continue;
        $acts = [];
        foreach ($cols as $col => $sql) $acts[] = "ALTER COLUMN {$col} SET DEFAULT {$sql}";
        $pdo->exec("ALTER TABLE {$table} " . implode(', ', $acts));
    }
}
