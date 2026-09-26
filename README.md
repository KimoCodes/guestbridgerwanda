# GuestBridge Rwanda

A PHP hospitality referral and commission tracking platform connecting hotels, restaurants, spas, transport, tourism, and nightlife businesses in Rwanda. Staff generate guest referrals to partner businesses and earn commissions when guests convert.

## Features

### Referral System
- **Full referral lifecycle**: Created → Verified → Accepted → Redeemed → Visited → Converted → Settled
- **QR codes and secure tokens** for each referral
- **WhatsApp sharing** with status-specific pre-formatted messages
- **Referral verification page** — public page for scanning QR codes or entering referral codes manually
- **6-digit PIN-based partner verification** for secure referral handoff
- **Configurable expiry** (default 30 days, auto-expiration)
- **Guest benefits** — percentage discount, fixed discount, free item, complimentary service, upgrade, special package

### Staff Referral Identity System
- **Permanent staff identity codes** (`STF-XXXXXXXX`) for tracking individual referral performance
- **One active identity per staff member** enforced at creation
- **Auto-association** — staff identity is automatically linked when creating referrals
- **Performance leaderboard** ranked by total revenue
- **Employee performance dashboard** with conversion rates, commission breakdown, referral history
- **Manager control** — create, deactivate, reactivate staff identities

### Partner Network
- **Partnership management** — send/accept/reject partnership requests with commission rate negotiation
- **Partner directory** — browse and manage partner businesses
- **Public partner places directory** — no authentication required, hero section, grid of active listings
- **Place detail pages** — gallery, video embed/upload, amenities, specialties, referral code generation
- **Business reputation scores** — reliability, payout compliance, guest satisfaction

### Financial System
- **3-way commission split** — referring business (70%), employee (20%), platform (10%) default
- **Configurable commission rules** — percentage or fixed, min/max caps, time-bound rules per partnership
- **Commission lifecycle** — created → confirmed → allocated → reconciled → settled
- **Wallet overview** — monthly earning/owing summary
- **Billing and invoices** — tiered platform fees, auto-generated invoices, partial payment support
- **Payment tracking** — Mobile money, MTN MoMo, PayPal, Stripe, bank transfer, internal credit, cash
- **Webhook-based payment callbacks** for automated confirmation
- **Debt management** — automatic aggregation, due date tracking, notification system
- **Settlements** — unique references, verification workflow, reversal support
- **Monthly financial statements** with print-optimized view

### Team Management
- **Staff records** — add/remove staff, assign roles (manager, receptionist, concierge)
- **Staff earnings** — view performance and commission earnings per period
- **Staff incentives** — points system with manager approval workflow
- **Guest benefits configuration** — set benefits for referred guests per business

### Analytics and Reporting
- **Conversion rates** — overall and per partner/staff
- **Top partners** ranked by referral count and commission
- **Staff performance** — referral counts, conversion rates, commission totals
- **Trend analysis** — 6-month referral and commission trends
- **Momentum forecasting** — weighted average prediction
- **Monthly filtering** on all reports

### Admin and Platform Management
- **Super admin dashboard** — platform-wide stats, recent activity, audit log
- **Business management** — approve, suspend, deactivate businesses
- **User management** — manage all accounts and roles
- **Platform finance** — network-wide debt monitoring, payment overview, risk assessment
- **Pricing configuration** — fee tiers, featured listings, seasonality settings
- **Regional scaling** — city readiness assessment, contract templates, onboarding checklists
- **Dispute management** — create, respond to, resolve disputes on referrals/commissions
- **Audit logging** — full trail across all businesses

### Settings (10 Tabs)
- **Account** — profile, image, contact info
- **Security** — password change, session management
- **Payments** — payout method, account details, currency
- **Referrals** — outbound count, place codes, referral link
- **Public listing** — full CMS for place content, images, video, gallery, banners
- **Team & roles** — staff management, role assignment
- **Analytics** — enable/disable, export format
- **Notifications** — email, SMS, booking, payment, marketing, system
- **Privacy** — GDPR consents, data export, account deletion
- **System** — theme, language, timezone, UI density

## Setup

1. Place this folder under your XAMPP `htdocs` directory.
2. Open `app/Config/config.php` and update MySQL credentials if needed.
3. Create the database:

```sql
CREATE DATABASE guestbridgerwanda CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

4. Run the installer:

```
http://localhost/guestbridgerwanda/database/install.php
```

5. Login with the seeded admin:

- Email: `lemigomanager@gmail.com`
- Password: `Keynes@123`

6. (Optional) Seed the public partner places:

```bash
php database/seed_places.php
```

7. (Optional) Run the v2 migration for full feature set:

```
http://localhost/guestbridgerwanda/run_migration.php
```

## Project Structure

```
guestbridgerwanda/
├── .htaccess                    # URL rewrites and security
├── app/
│   ├── bootstrap.php            # Central include entry point
│   ├── Config/
│   │   ├── config.php           # Core config, auth, helpers, constants
│   │   ├── database.php         # Schema (55+ tables), db_init(), migrations
│   │   ├── security.php         # CSRF token generation/verification
│   │   └── tenant.php           # Multi-tenant data isolation
│   ├── Domain/
│   │   └── places.php           # Partner places domain logic
│   ├── HTTP/
│   │   ├── helpers.php          # Navigation, layout rendering
│   │   └── middleware.php       # Input sanitization, session management
│   ├── Services/
│   │   ├── ServiceContainer.php # DI container for all services
│   │   ├── ReferralService.php  # Referral lifecycle management
│   │   ├── CommissionService.php # Commission calculation/distribution
│   │   ├── BillingService.php   # Platform fees and invoicing
│   │   ├── SettlementService.php # Settlement creation/verification
│   │   └── StaffIdentityService.php # Staff referral identities
│   ├── Settings/
│   │   └── hub.php              # Settings control panel
│   └── Views/
│       └── settings/            # 12 settings tab views
├── database/
│   ├── install.php              # Fresh installation
│   ├── seed.php                 # Seed test data
│   ├── seed_places.php          # Seed partner places
│   └── migration_v2.php         # Schema migration (16 new tables)
├── public/
│   ├── assets/                  # CSS, JS, images, SVG logos
│   ├── auth/                    # Login, register, logout, password reset
│   ├── referrals/               # Create, view, history, landing, verify
│   ├── financial/               # Commissions, wallet, payments, billing, statements
│   ├── partners/                # Directory, place detail, partnerships
│   ├── team/                    # Staff, earnings, identities, performance, incentives
│   ├── admin/                   # Super admin dashboard, businesses, users, settings
│   └── dashboard.php            # Main authenticated dashboard
├── vendor/                      # Composer dependencies
└── composer.json
```

## URL Structure

| URL | Page |
|-----|------|
| `/login.php` | Login |
| `/register.php` | Business registration |
| `/dashboard.php` | Main dashboard |
| `/create_referral.php` | Create new referral |
| `/view_referral.php?id=N` | Referral detail with QR code |
| `/verify_referral.php?code=XXX` | Verify/accept/redeem referral |
| `/referral/verify/XXX` | QR code scan landing |
| `/referral.php?code=XXX` | Public referral landing page |
| `/history.php` | Referral history |
| `/partnerships.php` | Partnership management |
| `/staff.php` | Staff management |
| `/staff_identities.php` | Staff identity management |
| `/performance.php` | Referral performance |
| `/my_identity.php` | My referral identity |
| `/commissions.php` | Commission ledger |
| `/wallet.php` | Wallet overview |
| `/billing.php` | Billing and invoices |
| `/analytics.php` | Analytics dashboard |
| `/partner_places.php` | Public partner directory |
| `/place.php?slug=XXX` | Place detail page |
| `/settings.php` | Account settings |
| `/admin/` | Super admin dashboard |

## Security

- **CSRF protection** on all forms with timing-safe token verification
- **Multi-tenant data isolation** — every query filters by `business_id`
- **Role-based access** — super_admin, manager, receptionist, concierge, employee
- **Rate limiting** — login (5 attempts/15 min), password reset (1 req/2 min)
- **Session security** — httponly, strict mode, same-site, secure cookies
- **Security headers** — X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, CSP
- **Input sanitization** — email, phone, text, username validation
- **Password hashing** — bcrypt via `password_hash()`
- **Business approval workflow** — new businesses start pending, admin approval required
- **Invite-only registration** — optional invite code gate
- **Audit logging** — all actions recorded with user, business, entity, old/new values
- **Webhook authentication** — payment callbacks validated with secret key
- **.htaccess** — blocks access to private directories (`app/`, `database/`, `vendor/`)

## Database

55+ tables covering:

| Category | Tables |
|----------|--------|
| Core | `businesses`, `users`, `staff`, `referrals`, `partnerships` |
| Financial | `commissions`, `payments`, `earnings`, `settlements`, `settlement_items` |
| Billing | `platform_fees`, `invoices`, `billing_periods`, `platform_fee_tiers`, `hotel_debts` |
| Staff | `staff_referral_identities`, `staff_rewards`, `employee_earnings` |
| Referral | `referral_redemptions`, `referral_events`, `guest_transactions`, `commission_allocations` |
| Places | `partner_places`, `place_gallery`, `place_referrals`, `place_banners` |
| Admin | `audit_logs`, `notifications`, `commission_rules`, `guest_benefits` |
| Platform | `platform_revenue_settings`, `seasonality_settings`, `contract_templates` |
| Regional | `onboarding_checklists`, `pilot_feedback`, `featured_listings` |
| Auth | `password_resets`, `login_sessions`, `login_history`, `user_meta`, `user_preferences` |
| Privacy | `consents`, `data_export_requests`, `account_deletion_requests` |

Schema is created automatically on first request via `db_init()`. The v2 migration adds 16 additional tables for the full feature set.

## Configuration

| Constant | Default | Purpose |
|----------|---------|---------|
| `APP_NAME` | `GuestBridge Rwanda` | Application name |
| `BASE_URL` | `/guestbridgerwanda` | Base URL path |
| `REFERRAL_EXPIRY_DAYS` | `30` | Referral expiry |
| `INVITE_ONLY_REGISTRATION` | `false` | Registration gate |
| `REGISTRATION_INVITE_CODE` | `pilot-kigali-2026` | Pilot invite code |
| `PLATFORM_ADMIN_EMAILS` | `admin@pilot.kig` | Platform admin emails |
| `PAYMENT_WEBHOOK_SECRET` | env | Webhook authentication |
| `PASSWORD_RESET_TOKEN_TTL_MINUTES` | `60` | Reset link validity |

## Business Types

Hotel, Restaurant, Spa, Transport, Tourism, Nightlife, Other

## Payment Methods

Mobile money, MTN MoMo, PayPal, Stripe, Bank transfer, Internal credit, Cash

## Notes

- No real payments are implemented in this MVP
- Subscription billing, paid featured listings, real AI calls, and automatic payment collection are intentionally disabled during the manual pilot
- MTN MoMo, PayPal, and Stripe are prepared as provider payment attempts; live automatic settlement requires provider credentials and a configured `PAYMENT_WEBHOOK_SECRET`
- Use the WhatsApp sharing button on the referral detail page to send the referral code, offer details, and link to the partner
- If Apache rewrite rules are disabled, use `http://localhost/guestbridgerwanda/public/` directly
