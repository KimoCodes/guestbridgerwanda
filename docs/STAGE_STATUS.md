# GuestBridge Rwanda Stage Status

## Current Position

The project has moved beyond the basic MVP and now covers the core pieces of Stages 1, 2, 3, 4, and 5:

- Stage 1: referral creation, referral history, QR/referral links, simulated commission ledger.
- Stage 2: verified partnerships, internal wallet view, WhatsApp sharing, analytics.
- Stage 3: partner discovery, multiple business categories, reputation scores.
- Stage 4: manual settlement, simulated monetization, featured listing experiments, staff incentives.
- Stage 5: rule-based recommendations, predictive insights, dynamic commission suggestions, seasonality controls, and pilot pricing packets.

## Current Stage

Stage 6 has started in a manual-first way.

Implemented so far:

- Manual settlement recording for confirmed commissions.
- Structured settlement methods: mobile money, bank transfer, internal credit, cash, and other.
- Settlement reference capture for MoMo, bank, or internal tracking references.
- Transaction audit table for future settlement reporting.
- Settlement review controls so recorded payments can be marked verified or disputed.
- Transaction reports by partner, month, and settlement method.
- Settlement export summaries for monthly partner reviews.
- Hotel debt records with outstanding balance, billing month, due date, and status.
- Persistent dashboard payment alert banners for unpaid hotel balances.
- Billing page with MTN MoMo, PayPal, Stripe, and bank transfer options.
- Provider payment attempt records and secure callback endpoint for future live integrations.
- Partial payment allocation and remaining balance tracking.
- Downloadable invoice and receipt documents.
- Admin finance dashboard for debt analytics and monitoring.
- Revenue model settings page for SaaS, referral fee, analytics tier, and featured listing experiments.
- Platform fee simulation with monthly partner-level forecasts and CSV export.
- Manual approval and waiver controls for simulated platform fees with review notes.
- Featured listing simulation in partner discovery with manager-controlled monthly placements.
- Monthly partner sign-off notes with review status, manager attribution, and CSV export fields.
- Staff incentive reward points with manager approval and transparent reward logs.
- Rule-based partner recommendation panel in referral creation using category fit, reputation, conversion history, featured placement, and optional guest budget.
- Dynamic commission suggestions for referral creation based on partner performance and current demand signals.
- Seasonality-based commission suggestions using historical monthly referral patterns, with visible adjustment reasons and confidence labels.
- Manager-controlled seasonality threshold review for referral history sample size, high/low season multipliers, and conversion adjustments.
- Pilot pricing packet page combining platform fee reviews, featured placements, settlement readiness, seasonality signals, and CSV export.
- Explicit manual-pilot guardrails that keep real AI calls, paid placements, subscription billing, and payment collection disabled until validation.
- Predictive analytics section with demand forecast, partner performance ranking, and expected revenue impact estimates.
- City support for businesses across Kigali, Kampala, Nairobi, and Dar es Salaam.
- City-aware registration, partner discovery, and partner business management.
- Regional scaling dashboard with city readiness, category mix, and partnership coverage.
- Contract template library for city/type-specific onboarding agreements.
- Contract templates can be applied directly when requesting a new partnership with server-side template merging.
- Onboarding checklist tracking by city and optional business, with manager status updates, blockers, due dates, and notes.
- Pilot feedback capture for pricing packet reviews and regional launch reviews.
- Read-only city cohort comparisons for businesses, partnerships, referrals, conversion, and commission signal.
- City deployment readiness scoring (0–100 scale) with stage classification: not ready, setup, onboarding, pilot ready.
- Suggested next onboarding steps per city based on current gaps in businesses, partnerships, checklist, and referral coverage.
- Plug-and-play deployment button that seeds checklists and detects applicable templates for a city.
- Contract template merging with placeholder substitution (partner name, city, commission rate, date).
- Apply contract template action on existing active partnerships.
- Template preview in the partnership request form (info box showing selected template text before submission).

- Real mobile money integration.
- Real bank transfer automation.
- Subscription billing.
- Platform referral fees.
- Paid featured listings.
- AI model integration.
- Automated seasonality-based commission enforcement.
- Automated city onboarding.
- Legal contract generation and signatures.
- PMS/POS integrations.
- Multi-currency settlement.
- Enterprise account hierarchy and centralized controls.

## Next Recommended Work

1. Connect automated onboarding triggers: once a city's readiness score hits 75+, surface a manager prompt to begin the automated rollout sequence (email/SMS invite, partnership auto-activation, first-referral nudge).
2. Move into Stage 7 with an enterprise account model for groups that manage multiple businesses.
3. Add PMS/POS integration registry records and manual integration status tracking.
4. Add enterprise-level reporting across businesses, cities, referrals, and settlement exposure.
