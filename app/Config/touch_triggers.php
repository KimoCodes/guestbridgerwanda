<?php
/**
 * AUTO-GENERATED from the migrated PostgreSQL schema (see /tmp/gb_gen_triggers.php).
 * Recreates the MySQL 'ON UPDATE CURRENT_TIMESTAMP' touch triggers that the
 * migrated production schema has, so fresh installs behave identically.
 */
function ensure_touch_triggers(PDO $pdo): void
{
    $trigger_ready = $pdo->prepare("SELECT 1 FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ? AND t.tgname = ?");
    $table_ready = $pdo->prepare('SELECT to_regclass(?) IS NOT NULL');
    $col_exists = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?');
    $items = [
        ['billing_periods', 'trg_touch_billing_periods_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_billing_periods_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_billing_periods_updated_at BEFORE UPDATE ON billing_periods FOR EACH ROW EXECUTE FUNCTION gb_touch_billing_periods_updated_at()'],
        ['businesses', 'trg_touch_businesses_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_businesses_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_businesses_updated_at BEFORE UPDATE ON businesses FOR EACH ROW EXECUTE FUNCTION gb_touch_businesses_updated_at()'],
        ['commission_allocations', 'trg_touch_commission_allocations_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_commission_allocations_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_commission_allocations_updated_at BEFORE UPDATE ON commission_allocations FOR EACH ROW EXECUTE FUNCTION gb_touch_commission_allocations_updated_at()'],
        ['commission_rules', 'trg_touch_commission_rules_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_commission_rules_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_commission_rules_updated_at BEFORE UPDATE ON commission_rules FOR EACH ROW EXECUTE FUNCTION gb_touch_commission_rules_updated_at()'],
        ['commissions', 'trg_touch_commissions_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_commissions_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_commissions_updated_at BEFORE UPDATE ON commissions FOR EACH ROW EXECUTE FUNCTION gb_touch_commissions_updated_at()'],
        ['company_invitations', 'trg_touch_company_invitations_expires_at', 'expires_at',
            'CREATE OR REPLACE FUNCTION gb_touch_company_invitations_expires_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."expires_at" IS NOT DISTINCT FROM OLD."expires_at" THEN NEW."expires_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_company_invitations_expires_at BEFORE UPDATE ON company_invitations FOR EACH ROW EXECUTE FUNCTION gb_touch_company_invitations_expires_at()'],
        ['company_invitations', 'trg_touch_company_invitations_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_company_invitations_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_company_invitations_updated_at BEFORE UPDATE ON company_invitations FOR EACH ROW EXECUTE FUNCTION gb_touch_company_invitations_updated_at()'],
        ['contract_templates', 'trg_touch_contract_templates_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_contract_templates_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_contract_templates_updated_at BEFORE UPDATE ON contract_templates FOR EACH ROW EXECUTE FUNCTION gb_touch_contract_templates_updated_at()'],
        ['disputes', 'trg_touch_disputes_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_disputes_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_disputes_updated_at BEFORE UPDATE ON disputes FOR EACH ROW EXECUTE FUNCTION gb_touch_disputes_updated_at()'],
        ['earnings', 'trg_touch_earnings_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_earnings_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_earnings_updated_at BEFORE UPDATE ON earnings FOR EACH ROW EXECUTE FUNCTION gb_touch_earnings_updated_at()'],
        ['employee_earnings', 'trg_touch_employee_earnings_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_employee_earnings_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_employee_earnings_updated_at BEFORE UPDATE ON employee_earnings FOR EACH ROW EXECUTE FUNCTION gb_touch_employee_earnings_updated_at()'],
        ['enterprise_accounts', 'trg_touch_enterprise_accounts_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_enterprise_accounts_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_enterprise_accounts_updated_at BEFORE UPDATE ON enterprise_accounts FOR EACH ROW EXECUTE FUNCTION gb_touch_enterprise_accounts_updated_at()'],
        ['featured_listings', 'trg_touch_featured_listings_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_featured_listings_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_featured_listings_updated_at BEFORE UPDATE ON featured_listings FOR EACH ROW EXECUTE FUNCTION gb_touch_featured_listings_updated_at()'],
        ['guest_benefits', 'trg_touch_guest_benefits_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_guest_benefits_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_guest_benefits_updated_at BEFORE UPDATE ON guest_benefits FOR EACH ROW EXECUTE FUNCTION gb_touch_guest_benefits_updated_at()'],
        ['guest_transactions', 'trg_touch_guest_transactions_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_guest_transactions_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_guest_transactions_updated_at BEFORE UPDATE ON guest_transactions FOR EACH ROW EXECUTE FUNCTION gb_touch_guest_transactions_updated_at()'],
        ['hotel_debts', 'trg_touch_hotel_debts_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_hotel_debts_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_hotel_debts_updated_at BEFORE UPDATE ON hotel_debts FOR EACH ROW EXECUTE FUNCTION gb_touch_hotel_debts_updated_at()'],
        ['invoices', 'trg_touch_invoices_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_invoices_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_invoices_updated_at BEFORE UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION gb_touch_invoices_updated_at()'],
        ['login_sessions', 'trg_touch_login_sessions_last_active_at', 'last_active_at',
            'CREATE OR REPLACE FUNCTION gb_touch_login_sessions_last_active_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."last_active_at" IS NOT DISTINCT FROM OLD."last_active_at" THEN NEW."last_active_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_login_sessions_last_active_at BEFORE UPDATE ON login_sessions FOR EACH ROW EXECUTE FUNCTION gb_touch_login_sessions_last_active_at()'],
        ['monthly_billings', 'trg_touch_monthly_billings_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_monthly_billings_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_monthly_billings_updated_at BEFORE UPDATE ON monthly_billings FOR EACH ROW EXECUTE FUNCTION gb_touch_monthly_billings_updated_at()'],
        ['monthly_statements', 'trg_touch_monthly_statements_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_monthly_statements_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_monthly_statements_updated_at BEFORE UPDATE ON monthly_statements FOR EACH ROW EXECUTE FUNCTION gb_touch_monthly_statements_updated_at()'],
        ['notification_preferences', 'trg_touch_notification_preferences_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_notification_preferences_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_notification_preferences_updated_at BEFORE UPDATE ON notification_preferences FOR EACH ROW EXECUTE FUNCTION gb_touch_notification_preferences_updated_at()'],
        ['onboarding_checklists', 'trg_touch_onboarding_checklists_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_onboarding_checklists_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_onboarding_checklists_updated_at BEFORE UPDATE ON onboarding_checklists FOR EACH ROW EXECUTE FUNCTION gb_touch_onboarding_checklists_updated_at()'],
        ['outstanding_balances', 'trg_touch_outstanding_balances_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_outstanding_balances_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_outstanding_balances_updated_at BEFORE UPDATE ON outstanding_balances FOR EACH ROW EXECUTE FUNCTION gb_touch_outstanding_balances_updated_at()'],
        ['partner_places', 'trg_touch_partner_places_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_partner_places_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_partner_places_updated_at BEFORE UPDATE ON partner_places FOR EACH ROW EXECUTE FUNCTION gb_touch_partner_places_updated_at()'],
        ['partnerships', 'trg_touch_partnerships_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_partnerships_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_partnerships_updated_at BEFORE UPDATE ON partnerships FOR EACH ROW EXECUTE FUNCTION gb_touch_partnerships_updated_at()'],
        ['password_resets', 'trg_touch_password_resets_expires_at', 'expires_at',
            'CREATE OR REPLACE FUNCTION gb_touch_password_resets_expires_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."expires_at" IS NOT DISTINCT FROM OLD."expires_at" THEN NEW."expires_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_password_resets_expires_at BEFORE UPDATE ON password_resets FOR EACH ROW EXECUTE FUNCTION gb_touch_password_resets_expires_at()'],
        ['platform_commissions', 'trg_touch_platform_commissions_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_platform_commissions_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_platform_commissions_updated_at BEFORE UPDATE ON platform_commissions FOR EACH ROW EXECUTE FUNCTION gb_touch_platform_commissions_updated_at()'],
        ['platform_config', 'trg_touch_platform_config_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_platform_config_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_platform_config_updated_at BEFORE UPDATE ON platform_config FOR EACH ROW EXECUTE FUNCTION gb_touch_platform_config_updated_at()'],
        ['platform_fee_tiers', 'trg_touch_platform_fee_tiers_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_platform_fee_tiers_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_platform_fee_tiers_updated_at BEFORE UPDATE ON platform_fee_tiers FOR EACH ROW EXECUTE FUNCTION gb_touch_platform_fee_tiers_updated_at()'],
        ['platform_fees', 'trg_touch_platform_fees_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_platform_fees_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_platform_fees_updated_at BEFORE UPDATE ON platform_fees FOR EACH ROW EXECUTE FUNCTION gb_touch_platform_fees_updated_at()'],
        ['platform_revenue_settings', 'trg_touch_platform_revenue_settings_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_platform_revenue_settings_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_platform_revenue_settings_updated_at BEFORE UPDATE ON platform_revenue_settings FOR EACH ROW EXECUTE FUNCTION gb_touch_platform_revenue_settings_updated_at()'],
        ['pms_integrations', 'trg_touch_pms_integrations_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_pms_integrations_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_pms_integrations_updated_at BEFORE UPDATE ON pms_integrations FOR EACH ROW EXECUTE FUNCTION gb_touch_pms_integrations_updated_at()'],
        ['referrals', 'trg_touch_referrals_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_referrals_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_referrals_updated_at BEFORE UPDATE ON referrals FOR EACH ROW EXECUTE FUNCTION gb_touch_referrals_updated_at()'],
        ['seasonality_settings', 'trg_touch_seasonality_settings_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_seasonality_settings_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_seasonality_settings_updated_at BEFORE UPDATE ON seasonality_settings FOR EACH ROW EXECUTE FUNCTION gb_touch_seasonality_settings_updated_at()'],
        ['settlement_signoffs', 'trg_touch_settlement_signoffs_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_settlement_signoffs_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_settlement_signoffs_updated_at BEFORE UPDATE ON settlement_signoffs FOR EACH ROW EXECUTE FUNCTION gb_touch_settlement_signoffs_updated_at()'],
        ['settlements', 'trg_touch_settlements_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_settlements_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_settlements_updated_at BEFORE UPDATE ON settlements FOR EACH ROW EXECUTE FUNCTION gb_touch_settlements_updated_at()'],
        ['staff', 'trg_touch_staff_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_staff_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_staff_updated_at BEFORE UPDATE ON staff FOR EACH ROW EXECUTE FUNCTION gb_touch_staff_updated_at()'],
        ['staff_permissions', 'trg_touch_staff_permissions_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_staff_permissions_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_staff_permissions_updated_at BEFORE UPDATE ON staff_permissions FOR EACH ROW EXECUTE FUNCTION gb_touch_staff_permissions_updated_at()'],
        ['staff_referral_identities', 'trg_touch_staff_referral_identities_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_staff_referral_identities_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_staff_referral_identities_updated_at BEFORE UPDATE ON staff_referral_identities FOR EACH ROW EXECUTE FUNCTION gb_touch_staff_referral_identities_updated_at()'],
        ['staff_rewards', 'trg_touch_staff_rewards_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_staff_rewards_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_staff_rewards_updated_at BEFORE UPDATE ON staff_rewards FOR EACH ROW EXECUTE FUNCTION gb_touch_staff_rewards_updated_at()'],
        ['staff_roles', 'trg_touch_staff_roles_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_staff_roles_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_staff_roles_updated_at BEFORE UPDATE ON staff_roles FOR EACH ROW EXECUTE FUNCTION gb_touch_staff_roles_updated_at()'],
        ['user_meta', 'trg_touch_user_meta_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_user_meta_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_user_meta_updated_at BEFORE UPDATE ON user_meta FOR EACH ROW EXECUTE FUNCTION gb_touch_user_meta_updated_at()'],
        ['user_preferences', 'trg_touch_user_preferences_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_user_preferences_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_user_preferences_updated_at BEFORE UPDATE ON user_preferences FOR EACH ROW EXECUTE FUNCTION gb_touch_user_preferences_updated_at()'],
        ['user_sessions', 'trg_touch_user_sessions_last_active_at', 'last_active_at',
            'CREATE OR REPLACE FUNCTION gb_touch_user_sessions_last_active_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."last_active_at" IS NOT DISTINCT FROM OLD."last_active_at" THEN NEW."last_active_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_user_sessions_last_active_at BEFORE UPDATE ON user_sessions FOR EACH ROW EXECUTE FUNCTION gb_touch_user_sessions_last_active_at()'],
        ['users', 'trg_touch_users_updated_at', 'updated_at',
            'CREATE OR REPLACE FUNCTION gb_touch_users_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN NEW."updated_at" := LOCALTIMESTAMP; END IF; RETURN NEW; END; $function$
',
            'CREATE TRIGGER trg_touch_users_updated_at BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION gb_touch_users_updated_at()'],
    ];
    foreach ($items as [$table, $trigger, $column, $fn_sql, $trigger_sql]) {
        $table_ready->execute([$table]);
        if (!$table_ready->fetchColumn()) {
            continue;
        }
        $col_exists->execute([$table, $column]);
        if (!$col_exists->fetchColumn()) {
            continue;
        }
        $pdo->exec($fn_sql);
        $trigger_ready->execute([$table, $trigger]);
        if (!$trigger_ready->fetchColumn()) {
            $pdo->exec($trigger_sql);
        }
    }
}
