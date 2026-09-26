# GuestBridge Rwanda — Complete Product Transformation & Implementation Specification

## ROLE

You are the lead software engineer responsible for transforming the **existing GuestBridge Rwanda** project into a production-ready MVP based on the business model and workflow defined in this specification.

This is an **existing application**, not a greenfield project.

Your job is to:

1. Inspect the entire existing codebase before changing anything.
2. Understand the current architecture, database, authentication, routing, UI, business logic, and existing features.
3. Preserve useful functionality that already works.
4. Remove, replace, or refactor functionality that conflicts with this specification.
5. Implement the complete business model described below.
6. Ensure the database, backend, frontend, authentication, dashboards, financial logic, referral logic, and user workflows all work together.
7. Test the application end-to-end after implementation.
8. Do not merely describe changes. **Actually implement them in the existing project.**

Do not create a second parallel architecture unless the existing architecture is genuinely unusable.

---

# 1. EXISTING PROJECT CONTEXT

GuestBridge Rwanda is an existing PHP/MySQL hospitality referral and commission-tracking MVP.

The existing project already contains useful functionality including, where applicable:

* Business registration and authentication
* Staff-aware referrals
* Partner selection
* Hospitality business categories
* Hotels
* Restaurants
* Spas
* Transport
* Tourism
* Nightlife
* Referral history
* QR referral functionality
* WhatsApp referral sharing
* Commission ledger
* Analytics
* Wallet-style receivables/payables
* Manual settlement functionality
* Finance/invoice/receipt functionality
* Reputation scoring
* Rule-based partner recommendations
* Business onboarding
* Public partner/business information
* Referral codes
* Admin functionality

The existing project is a **PHP/MySQL MVP**, with a structure that includes concepts such as:

* `public/`
* `app/`
* `database/`
* `.htaccess`
* database installation/seeding

Do not assume every existing implementation is correct.

Inspect it first.

---

# 2. MOST IMPORTANT BUSINESS MODEL

GuestBridge Rwanda is a **hospitality referral network and referral-performance management platform**.

The primary value proposition is:

> GuestBridge helps hospitality businesses acquire customers through trusted referrals, rewards the employees who generate successful referrals, tracks the resulting customer spending and commissions, and provides a transparent system for managing referral earnings, settlements, and monthly platform fees.

GuestBridge is NOT primarily an analytics dashboard.

GuestBridge is NOT simply a WhatsApp referral generator.

GuestBridge is NOT an "AI referral platform."

The core product is:

> **Referral → Guest Visit → Transaction → Commission → Distribution → Settlement → Statement**

---

# 3. BUSINESS MODEL

Implement the following economic model.

## 3.1 Referral

Business A refers a guest to Business B.

Example:

* Hotel A refers a guest to Hotel B.
* Hotel B accepts the referral.
* Guest visits Hotel B.
* Guest receives an agreed GuestBridge benefit.
* Guest spends RWF 100,000.
* Hotel B records/validates the eligible transaction.
* A referral commission is calculated.

---

# 4. COMMISSION MODEL

The commission should be configurable per partnership/referral arrangement.

Do NOT hard-code one universal commission percentage.

The system must support:

* Percentage-based commission
* Optional fixed commission
* Minimum/maximum commission where needed
* Different commission rates between different businesses
* Different commission rules by partnership
* Effective dates
* Active/inactive commission arrangements

Example:

Guest spending:

> RWF 100,000

Referral commission:

> 10%

Total referral commission:

> RWF 10,000

That RWF 10,000 may be distributed according to the configured commission structure.

Example:

* Referring business: 70% = RWF 7,000
* Referring employee: 20% = RWF 2,000
* GuestBridge: 10% = RWF 1,000

These percentages must be configurable.

Do not hard-code 70/20/10.

The system should allow an administrator to configure:

* Referring business share
* Employee share
* GuestBridge share

The total allocation must equal 100%.

Prevent invalid configurations.

---

# 5. GUESTBRIDGE REVENUE MODEL

GuestBridge should have **one primary monetization model** for the MVP:

## Usage-based monthly platform subscription/service fee

The fee is determined by the value generated through GuestBridge referrals.

Do NOT implement four competing revenue models.

Do NOT create independent revenue streams for:

* Featured listings
* Analytics subscriptions
* Referral fees
* Random premium features

Those can remain architecturally possible in the future, but they must NOT be presented as active primary revenue models.

The current MVP should clearly communicate:

> GuestBridge charges businesses a monthly platform/service fee based on the referral value generated through GuestBridge.

---

# 6. PLATFORM FEE ENGINE

Create a configurable platform-fee system.

The admin must be able to define fee tiers.

Example:

| Monthly referral value  | Platform fee |
| ----------------------- | -----------: |
| RWF 0–500,000           | Configurable |
| RWF 500,001–2,000,000   | Configurable |
| RWF 2,000,001–5,000,000 | Configurable |
| RWF 5,000,001+          | Configurable |

Do NOT assume these exact prices are final.

Make the thresholds and amounts configurable from the admin panel.

The system must calculate:

```text
Monthly eligible referral value
        ↓
Applicable platform-fee tier
        ↓
Monthly GuestBridge fee
        ↓
Invoice
        ↓
Payment
        ↓
Receipt
```

Keep this separate from the referral commission.

---

# 7. IMPORTANT FINANCIAL DISTINCTION

The system must clearly distinguish:

### Referral commission

Money generated because a business successfully received a referred customer.

### Employee commission

The employee's portion of the referral commission.

### Referring business earnings

The originating business's portion of the referral commission.

### GuestBridge platform revenue

GuestBridge's configured share of the referral commission, where applicable, and/or the monthly platform/service fee.

### GuestBridge subscription/service invoice

The monthly amount the business owes GuestBridge for platform usage.

These must never be mixed together.

The UI must clearly show what money belongs to whom.

---

# 8. USER ROLES

Implement robust role-based access control.

At minimum:

## 8.1 Super Admin

The platform owner/administrator.

Full control over:

* Businesses
* Employees
* Referrals
* Transactions
* Commissions
* Commission structures
* Partnerships
* Benefits
* Settlements
* Disputes
* Platform fees
* Invoices
* Payments
* Reports
* Analytics
* Categories
* Public business profiles
* System settings
* Audit logs
* User management
* Suspension/activation
* Financial adjustments
* Configuration

The admin must be able to see everything across the platform.

---

## 8.2 Business Owner / Manager

A registered hospitality business.

Can:

* Manage business profile
* Manage employees
* Add/remove employees
* View referrals
* Create referrals
* View referral status
* View transaction results
* View commission earnings
* View employee performance
* View partner performance
* View settlements
* View outstanding balances
* View invoices
* View monthly statements
* View platform fees
* View disputes
* Manage referral partnerships
* Configure eligible guest benefits where permitted
* Generate referral links/QR codes
* Send referral links through WhatsApp
* Review employee commissions
* Approve relevant financial records

---

## 8.3 Employee / Staff

Employees belong to one business.

Examples:

* Receptionist
* Waiter
* Concierge
* Sales employee
* Front-desk employee
* Other configurable staff roles

Employees can:

* Create referrals
* Share referral links/QR codes
* View their own referrals
* View referral statuses
* View successful referrals
* View their commission earnings
* View monthly performance
* View their referral history
* View applicable guest benefits

Employees must NOT be able to:

* Modify business-wide financial settings
* Modify commission structures
* View unrelated businesses' private data
* Access admin functionality
* Modify other employees' earnings
* Change their own commission rate

---

## 8.4 Partner Business

A business receiving referrals.

Depending on the business relationship, a partner may also send referrals.

Do not artificially restrict the network to hotel-to-hotel referrals.

Support:

* Hotel → Restaurant
* Hotel → Spa
* Hotel → Transport
* Hotel → Tour operator
* Restaurant → Hotel
* Spa → Hotel
* Tour operator → Restaurant
* etc.

Any eligible business can potentially be a referrer or destination.

---

# 9. MULTI-BUSINESS ACCESS CONTROL

Every private object must be associated with the correct business/tenant.

Implement strict tenant isolation.

A business must NEVER be able to access:

* another business's private referrals
* another business's employee records
* another business's private financial data
* another business's transaction details
* another business's invoices
* another business's settlements

Employees must only see records permitted for their business and role.

Admin can see all.

Test this explicitly.

---

# 10. BUSINESS REGISTRATION

Improve business onboarding.

Registration should collect appropriate information such as:

* Business name
* Business type/category
* Contact person
* Phone
* Email
* Address
* City
* Description
* Operating information where relevant
* Logo/profile image where supported
* Referral preferences
* Payment information where necessary
* Terms acceptance

Business categories must remain configurable.

Initial categories:

* Hotel
* Restaurant
* Spa
* Transport
* Tourism
* Nightlife

Allow admin to add categories later.

---

# 11. BUSINESS APPROVAL

Do not automatically trust every registered business.

Support business status:

* Pending
* Approved
* Suspended
* Rejected
* Deactivated

Admin must be able to approve/suspend businesses.

Only approved businesses should participate in the referral network.

Clearly communicate status to the business.

---

# 12. EMPLOYEE MANAGEMENT

Business owners/managers must be able to:

* Invite employee
* Create employee account
* Assign employee role
* Activate/deactivate employee
* Reset employee access
* View employee performance
* View employee referrals
* View employee commissions

Every referral created by an employee must preserve:

```text
employee_id
business_id
referral_id
created_at
```

Do not lose attribution.

If an employee leaves, historical referrals must remain attributed to that employee.

Do not delete historical financial records when an employee is deactivated.

---

# 13. PARTNERSHIPS

Create a proper partnership model.

A partnership represents a relationship between two businesses.

Example:

```text
Hotel A
    ↕
Hotel B
```

or:

```text
Hotel A
    ↕
Restaurant B
```

A partnership should support:

* Referring business
* Receiving business
* Commission configuration
* Eligible benefit
* Effective start date
* Effective end date
* Status
* Notes
* Approval status

Possible statuses:

* Pending
* Active
* Paused
* Expired
* Rejected

Do not assume every business can automatically refer to every other business.

---

# 14. GUEST BENEFITS

GuestBridge must create value for the guest.

Each eligible referral can have a configured benefit.

Examples:

* Percentage discount
* Fixed discount
* Free item
* Complimentary service
* Upgrade
* Special package
* Other configurable benefit

Do not hard-code these.

A business should be able to define an eligible benefit for a partnership, subject to appropriate permissions.

The guest should be able to clearly see:

* Destination business
* Benefit
* Referral identifier
* Expiry if applicable
* Instructions

---

# 15. REFERRAL CREATION

Create a robust referral flow.

When an employee creates a referral:

1. Select destination business.
2. System verifies that the partnership is active.
3. Load applicable guest benefit.
4. Generate a unique referral ID.
5. Associate:

   * referring business
   * referring employee
   * destination business
6. Set expiration if configured.
7. Record timestamp.
8. Generate referral QR code.
9. Generate shareable referral URL.
10. Provide WhatsApp/share functionality where supported.

Referral IDs must be unique and non-guessable.

Example format:

```text
GBR-8F42K9
```

Do not expose sequential database IDs as referral identifiers.

---

# 16. REFERRAL LIFECYCLE

Implement these explicit statuses:

```text
CREATED
    ↓
ACCEPTED
    ↓
VISITED
    ↓
CONVERTED
    ↓
SETTLED
```

Also support exceptional statuses where necessary:

* Cancelled
* Expired
* Rejected
* Disputed

### Created

Referral has been generated.

### Accepted

Destination business recognizes/accepts the referral.

### Visited

Guest actually arrived.

### Converted

Guest made an eligible transaction.

### Settled

Commission has been confirmed as settled.

Do not allow arbitrary status changes.

Use controlled state transitions.

Record who changed the status and when.

---

# 17. REFERRAL VALIDATION

The receiving business should have a simple workflow:

```text
Scan QR / open referral
        ↓
Verify referral
        ↓
Accept
        ↓
Guest arrives
        ↓
Mark visited
        ↓
Record eligible purchase
```

Provide an easy mobile-friendly interface.

The destination employee should not need to navigate through a complicated dashboard just to validate a referral.

---

# 18. TRANSACTION RECORDING

When a referred guest makes a purchase, the destination business records:

* Referral ID
* Transaction amount
* Currency
* Eligible amount
* Transaction date/time
* Business
* Employee who processed the transaction
* Notes
* Optional receipt/reference
* Optional transaction category

Default currency:

> RWF

Do not assume every transaction amount is commissionable.

Support:

* Gross transaction amount
* Eligible referral amount

Example:

```text
Gross spend: RWF 120,000
Eligible referral amount: RWF 100,000
Commission rate: 10%
Commission: RWF 10,000
```

---

# 19. COMMISSION CALCULATION

Commission calculation must happen server-side.

Never trust frontend-submitted commission amounts.

The backend should calculate:

```text
eligible_amount
×
commission_rate
=
total_commission
```

Then distribute the result according to the active commission allocation.

Example:

```text
Eligible amount: RWF 100,000

Commission rate: 10%

Total commission:
RWF 10,000

Distribution:

Referring business:
RWF 7,000

Employee:
RWF 2,000

GuestBridge:
RWF 1,000
```

Store the calculated amounts as immutable financial records once finalized.

If a transaction is corrected, create an adjustment/reversal rather than silently changing historical financial records.

---

# 20. FINANCIAL LEDGER

Create a proper double-entry-inspired or transaction-based financial ledger.

At minimum, every commission should have:

* Source transaction
* Referral
* Destination business
* Referring business
* Employee
* Total commission
* Each allocation
* Status
* Created date
* Settlement date
* Reference
* Audit trail

Avoid relying on a simple mutable wallet balance.

Balances should be derived from financial transactions where practical.

If a cached balance exists for performance, it must reconcile against the ledger.

---

# 21. EMPLOYEE EARNINGS

Each employee should have a dedicated earnings view.

Display:

* Total referrals
* Successful referrals
* Total guest spending generated
* Total commission generated
* Pending commission
* Settled commission
* Current-month earnings
* Historical earnings
* Referral conversion rate

Example:

```text
Jean
────────────────────
Referrals: 37
Successful: 29
Guest value: RWF 1,850,000

Pending: RWF 12,000
Settled: RWF 25,000

August earnings:
RWF 37,000
```

Employee earnings must be attributable to actual successful referrals.

Do not reward simply creating referrals unless the configured business model explicitly says so.

---

# 22. BUSINESS EARNINGS

Business owners should see:

### Revenue generated through referrals

### Commission earned

### Commission pending

### Commission settled

### Commission payable to others

### Employee commission obligations

### GuestBridge platform fees

### Net position

Example:

```text
August

Referral value generated:
RWF 4,250,000

Commission earned:
RWF 425,000

Employee commissions:
RWF 85,000

Platform fee:
RWF 50,000

Settled:
RWF 300,000

Outstanding:
RWF 125,000
```

The exact presentation should distinguish revenue, commission, liabilities, and platform fees.

Do not label everything as "profit."

---

# 23. SETTLEMENT CENTER

Create a central settlement workflow.

The business should see:

```text
Pending settlements
Overdue settlements
Recently settled
Disputed settlements
```

For each settlement:

* Amount
* From business
* To business/employee/platform
* Referral
* Transaction
* Due date
* Status
* Payment method
* Payment reference
* Date
* Notes

Support existing manual methods where useful:

* Mobile Money
* Bank
* Cash
* Internal credit
* Other configured methods

Do not falsely represent a manual payment as automatically verified.

---

# 24. PAYMENT VERIFICATION

For manual settlement:

1. User records payment.
2. System creates a pending settlement/payment record.
3. Reference is recorded.
4. Authorized user verifies it.
5. Status becomes confirmed.
6. Ledger is updated.
7. Receipt/statement is generated.

Support:

* Pending
* Submitted
* Verified
* Rejected
* Reversed

Do not mark payments as confirmed simply because someone typed a reference number.

---

# 25. DISPUTES

Implement a dispute system.

A business should be able to dispute:

* Referral
* Guest visit
* Transaction
* Commission
* Settlement

A dispute should include:

* Related record
* Raised by
* Reason
* Description
* Evidence/attachment if supported
* Status
* Admin response
* Resolution
* Timestamps

Statuses:

* Open
* Under review
* Resolved
* Rejected
* Escalated

Never silently modify disputed financial records.

---

# 26. MONTHLY STATEMENTS

Generate monthly statements for businesses.

Statement should contain:

* Opening balance
* Referral transactions
* Guest spending
* Commissions earned
* Commissions payable
* Employee commissions
* GuestBridge platform fees
* Payments/settlements
* Adjustments
* Closing balance

Provide:

* On-screen statement
* Printable version
* PDF if the existing application already supports PDF generation

Statements must be reproducible from the ledger.

---

# 27. PLATFORM BILLING

At the end of every billing period:

1. Calculate referral-generated value.
2. Determine applicable platform-fee tier.
3. Create platform invoice.
4. Show invoice in business dashboard.
5. Track payment status.
6. Generate receipt after payment verification.

Invoice statuses:

* Draft
* Issued
* Pending
* Partially paid
* Paid
* Overdue
* Cancelled

Do not allow normal businesses to edit financial invoices after issuance.

---

# 28. ADMIN FINANCE DASHBOARD

Admin needs a complete financial overview.

Display:

* Total referral value
* Total commissions
* GuestBridge revenue
* Platform fees billed
* Platform fees collected
* Outstanding platform invoices
* Pending settlements
* Overdue settlements
* Disputes
* Number of active businesses
* Number of active employees
* Referral conversion rate

Allow filtering by:

* Date
* Business
* Category
* Referral status
* Settlement status

---

# 29. DAILY ACTIONS / STICKINESS

This is a critical requirement.

The dashboard must not merely show charts.

Create a prominent **Pending Actions** section.

Examples:

> 3 referrals awaiting confirmation

> 2 transactions need verification

> RWF 450,000 awaiting settlement

> 1 disputed referral requires attention

> Your August GuestBridge invoice is due

> 4 employee commissions are pending

Each item must link directly to the action.

The dashboard should answer:

> **"What do I need to do today?"**

---

# 30. BUSINESS DASHBOARD

The main business dashboard should include:

### Today

* Referrals today
* Successful referrals today
* Guest value today
* Pending actions

### Current month

* Total referrals
* Successful referrals
* Conversion rate
* Guest spending generated
* Commission earned
* Commission payable
* Employee earnings
* Platform fee
* Outstanding balance

### Partner performance

Show:

* Top referring partners
* Top destination partners
* Referral volume
* Conversion rate
* Guest value
* Commission generated

### Pending Actions

Make this visually prominent.

---

# 31. EMPLOYEE DASHBOARD

Employee dashboard should focus on:

* Create referral
* Scan/create QR
* Share referral
* My active referrals
* My successful referrals
* My earnings
* Pending earnings
* Monthly performance

Keep the interface extremely simple.

An employee should be able to create a referral in seconds.

---

# 32. PARTNER DASHBOARD

Businesses should be able to see partner performance.

Show:

* Referrals received
* Referrals sent
* Conversion
* Guest value
* Commission
* Settlement status
* Last activity

Do not expose private financial data that belongs only to another business.

Only show information appropriate to the relationship.

---

# 33. SMART PARTNER RECOMMENDATIONS

Keep the existing rule-based recommendation engine if it is useful.

However:

**Do not call it AI.**

Use labels such as:

* Smart Recommendations
* Recommended Partners
* Partner Suggestions

The system may rank partners based on factors such as:

```text
Reputation
+
Conversion rate
+
Referral volume
+
Recent activity
+
Category compatibility
+
Commission attractiveness
```

If the existing formula is useful, preserve/refactor it.

Clearly communicate that these are **smart/rule-based recommendations**, not machine-learning predictions.

---

# 34. PUBLIC BUSINESS DIRECTORY

Preserve and improve useful existing public partner functionality.

Businesses may have public profiles containing:

* Business name
* Category
* Description
* Location
* Contact information where appropriate
* Images
* Gallery
* Video where supported
* GuestBridge benefits
* Referral options

Do not expose private financial data.

Public profiles should encourage guests/businesses to use the referral network.

---

# 35. REFERRAL SHARING

Preserve:

* QR codes
* Referral URLs
* WhatsApp sharing
* Mobile sharing

But redesign them around the new referral lifecycle.

A shared referral should clearly communicate:

```text
GuestBridge Rwanda

You're being referred to:
Hotel B

Your benefit:
10% discount

Referral code:
GBR-8F42K9

Valid until:
[date]
```

Do not expose unnecessary internal information.

---

# 36. REFERRAL HISTORY

Create a complete referral timeline.

Example:

```text
GBR-8F42K9

Created
11 Aug 09:42
by Jean / Hotel A

Accepted
11 Aug 10:03
by Hotel B

Visited
11 Aug 14:21

Transaction
RWF 100,000

Commission
RWF 10,000

Distribution
Hotel A: RWF 7,000
Jean: RWF 2,000
GuestBridge: RWF 1,000

Settlement
Verified
12 Aug
```

This should be auditable.

---

# 37. NOTIFICATIONS

Implement useful notifications.

Examples:

* Referral received
* Referral accepted
* Referral expired
* Guest visited
* Transaction recorded
* Commission generated
* Commission disputed
* Settlement submitted
* Settlement verified
* Payment overdue
* Platform invoice issued
* Platform invoice overdue

Use the existing notification architecture if available.

Do not build unnecessary notification complexity.

---

# 38. SEARCH AND FILTERING

Provide search/filter functionality for:

### Referrals

* Referral ID
* Business
* Employee
* Partner
* Status
* Date

### Transactions

* Referral
* Amount
* Business
* Date
* Status

### Settlements

* Business
* Amount
* Status
* Payment method
* Date

### Businesses

* Name
* Category
* City
* Status

---

# 39. ANALYTICS

Preserve useful analytics but make them operational.

Track:

### Referral metrics

* Created
* Accepted
* Visited
* Converted
* Settled

### Financial metrics

* Guest value
* Commission
* Employee earnings
* Business earnings
* Platform fees
* Settlement value

### Performance metrics

* Conversion rate
* Average transaction value
* Average commission
* Top partners
* Top employees

Do not create meaningless vanity metrics.

---

# 40. CITY STRATEGY

The current MVP should be focused on:

> **Kigali, Rwanda**

Do NOT actively build multi-city operational complexity yet.

Do not present Kampala, Nairobi, Dar es Salaam, etc. as active markets unless required for future-ready architecture.

The database may support cities for future expansion, but the UI/product positioning should focus on Kigali.

The success milestone is:

> 50 active Kigali businesses and RWF 5M+ monthly referral value.

Do not build significant expansion features before the core Kigali workflow is stable.

---

# 41. REMOVE OR REPLACE OUTDATED BUSINESS LOGIC

Audit the entire existing codebase for logic related to:

* Multiple competing revenue models
* Unvalidated pricing
* Fake AI/predictive claims
* Seed/demo financial data
* Fake transaction history
* Fake referral performance
* Multi-city marketing
* Simulated balances that do not reconcile
* Hard-coded commission assumptions
* Hard-coded platform pricing
* Unclear wallet logic
* Referral records without attribution
* Commission records without transaction source
* Payments marked as completed without verification

Replace conflicting implementations.

Do not simply hide them in the UI.

Remove obsolete backend logic where appropriate.

---

# 42. DEMO / SEED DATA

Do not leave fake financial performance presented as real.

If seed data is required for development:

* Clearly identify it as demo/test data.
* Make it easy to reset.
* Never mix it with production financial records.
* Do not show demo revenue as actual platform revenue.

The production application should start with zero real financial activity.

---

# 43. DATABASE DESIGN

Audit the current schema before modifying it.

Create or refactor the schema around entities such as:

```text
users
businesses
business_categories
business_employees
partnerships
commission_rules
guest_benefits
referrals
referral_events
transactions
commissions
commission_allocations
settlements
settlement_items
disputes
platform_fee_tiers
billing_periods
invoices
invoice_items
payments
monthly_statements
notifications
audit_logs
```

Reuse existing tables where appropriate instead of unnecessarily duplicating them.

Every financial record must have appropriate foreign keys.

Use indexes for:

* referral IDs
* business IDs
* employee IDs
* status
* timestamps
* transaction IDs
* settlement IDs
* invoice IDs

Use database constraints where appropriate.

---

# 44. FINANCIAL DATA INTEGRITY

Financial records are sensitive.

Implement:

* Decimal/fixed-precision monetary storage
* No floating-point money calculations
* Database transactions for multi-record financial operations
* Foreign keys
* Unique constraints
* Idempotency where applicable
* Immutable finalized records
* Adjustment/reversal records
* Audit logs

Never use JavaScript calculations as the authoritative source for commissions.

Frontend calculations are for display only.

Backend/database calculations are authoritative.

---

# 45. IDEMPOTENCY

Important financial operations must be idempotent.

For example, if a request to finalize a transaction is accidentally submitted twice, it must NOT generate two commissions.

Likewise:

* settlement confirmation
* invoice payment
* commission generation

must not duplicate financial records.

Use appropriate unique constraints/idempotency keys.

---

# 46. AUTHENTICATION

Audit the existing authentication system completely.

Verify:

* Registration
* Login
* Logout
* Password hashing
* Password reset if implemented
* Session handling
* Session expiration
* Role enforcement
* Authorization
* Account activation
* Suspended users
* Employee access

Do not trust user-submitted:

* business_id
* role
* employee_id
* commission amount
* transaction amount
* permissions

Resolve these from authenticated server-side context.

---

# 47. AUTHORIZATION

Every sensitive endpoint must verify:

1. Authentication
2. User status
3. Role
4. Business ownership/tenant
5. Resource ownership
6. Allowed action

Do not rely only on hiding buttons in the frontend.

Authorization must be enforced server-side.

---

# 48. SECURITY

Audit and improve:

* SQL injection protection
* Prepared statements
* XSS protection
* CSRF protection
* Session security
* Password hashing
* Authorization
* Rate limiting where appropriate
* Input validation
* Output escaping
* File upload validation
* MIME/type validation
* File size limits
* Secure headers
* Error handling
* Logging
* Sensitive-data exposure

Never display database errors, passwords, secrets, stack traces, or internal filesystem paths to users.

---

# 49. AUDIT LOGGING

Create an audit trail for sensitive actions.

Examples:

* Business approval
* Business suspension
* Employee creation
* Employee deactivation
* Referral status change
* Transaction creation
* Transaction correction
* Commission calculation
* Commission adjustment
* Settlement creation
* Settlement verification
* Payment verification
* Invoice creation
* Dispute resolution
* Platform-fee configuration changes
* Admin financial adjustments

Audit records should include:

* Actor
* Action
* Entity
* Entity ID
* Timestamp
* Relevant metadata
* IP where appropriate

---

# 50. ADMIN OVERRIDES

Admin may correct errors, but corrections must be auditable.

Do NOT let admin simply overwrite historical financial values.

Instead use:

```text
Original transaction
       ↓
Adjustment
       ↓
Reason
       ↓
Audit log
```

The system should preserve financial history.

---

# 51. FRONTEND DESIGN

Redesign the frontend around the actual workflow.

The interface should feel like a professional hospitality business management system.

Prioritize:

* Clarity
* Speed
* Mobile responsiveness
* Financial transparency
* Action-oriented dashboards
* Simple referral creation
* Easy QR scanning
* Clear statuses
* Clear money ownership

Avoid:

* Excessive charts
* Fake futuristic AI styling
* Unnecessary animations
* Complicated navigation
* Vanity metrics
* Confusing financial terminology

---

# 52. MOBILE-FIRST REFERRAL EXPERIENCE

The referral workflow is frequently used by employees on phones.

Optimize specifically for mobile.

An employee should be able to:

```text
Open GuestBridge
      ↓
Create Referral
      ↓
Select Partner
      ↓
Show Benefit
      ↓
Generate QR/link
      ↓
Share
```

with minimal steps.

The receiving business should be able to:

```text
Open/scan referral
      ↓
Accept
      ↓
Mark visited
      ↓
Record transaction
```

quickly.

---

# 53. DASHBOARD INFORMATION HIERARCHY

Every dashboard should prioritize:

### 1. Actions

What needs attention?

### 2. Money

What has been earned/owed?

### 3. Referrals

What is happening?

### 4. Performance

Who/what is performing?

### 5. Analytics

Why is it happening?

Do not reverse this order.

---

# 54. EMPTY STATES

Build proper empty states.

For a new business:

> "No referrals yet."

Then immediately provide:

> "Create your first referral"

For no partners:

> "You don't have active referral partners yet."

Then:

> "Find partners"

For no transactions:

> "No transactions have been recorded."

Do not show fake numbers.

---

# 55. ERROR HANDLING

Every important workflow needs clear errors.

Examples:

* Partnership inactive
* Referral expired
* Referral already converted
* Transaction already recorded
* Commission configuration invalid
* Settlement already verified
* Invoice already paid
* Unauthorized access
* Business suspended

Errors should be understandable to business users.

---

# 56. API / BACKEND STRUCTURE

If the existing application uses controllers/services/routes, organize business logic appropriately.

Do not put all business logic inside templates/pages.

Use clear services for:

* ReferralService
* CommissionService
* SettlementService
* BillingService
* StatementService
* PartnershipService
* NotificationService

Names may differ according to the existing architecture.

The important requirement is separation of concerns.

---

# 57. TRANSACTIONAL WORKFLOW

The following operation should be atomic:

```text
Record transaction
      ↓
Calculate commission
      ↓
Create commission allocations
      ↓
Update referral status
      ↓
Create financial ledger entries
      ↓
Create notifications
```

If one critical step fails, rollback the transaction.

Do not leave half-created financial records.

---

# 58. REPORTING

Admin should be able to generate reports for:

* Referral activity
* Business performance
* Employee performance
* Commission activity
* Settlements
* Platform revenue
* Platform invoices
* Outstanding balances
* Disputes

Where existing export functionality exists, preserve it and update it for the new data model.

---

# 59. BUSINESS STATEMENT

Each business should have a monthly statement.

Example:

```text
GUESTBRIDGE RWANDA
August 2026 Statement

Referral value generated
RWF 4,250,000

Commission earned
RWF 425,000

Commission payable
RWF 110,000

Employee commission
RWF 85,000

GuestBridge platform fee
RWF 50,000

Payments made
RWF 300,000

Outstanding
RWF 125,000
```

The numbers must be calculated from actual ledger data.

---

# 60. NO FAKE "AI"

Search the entire project for terminology such as:

* AI
* AI-powered
* predictive
* machine learning
* intelligent prediction

If the underlying implementation is deterministic rules, replace the marketing terminology with:

* Smart Recommendations
* Partner Suggestions
* Recommendation Score
* Performance-based ranking

Do not make unsupported AI claims.

---

# 61. NO PREMATURE MULTI-CITY MARKETING

The product should currently communicate:

> GuestBridge Rwanda — Kigali

Do not make the primary UI look like an already-established East African network.

Keep the architecture extensible, but focus the actual MVP on Kigali.

---

# 62. ADMIN CONFIGURATION

Create centralized configuration for:

* Business categories
* Commission rules
* Commission allocation
* Guest benefits
* Platform-fee tiers
* Billing period
* Currency
* Settlement methods
* Referral expiration
* Referral statuses
* Business statuses

Do not scatter business rules across PHP files.

---

# 63. CURRENCY

Use:

> RWF

as the default currency.

Store monetary values using precise decimal types.

Format consistently throughout the UI.

Example:

> RWF 100,000

Do not display inconsistent currency formats.

---

# 64. DATABASE MIGRATION STRATEGY

Before modifying the schema:

1. Back up/document the existing schema.
2. Inspect all existing tables.
3. Identify dependencies.
4. Map old fields to new fields.
5. Create migrations.
6. Preserve useful historical records where possible.
7. Avoid destructive migrations unless necessary.
8. Clearly document any data migration.
9. Verify foreign keys.
10. Verify indexes.
11. Verify financial integrity.

Do not simply drop the existing database and recreate it unless absolutely necessary.

---

# 65. EXISTING DATA MIGRATION

If existing records correspond to the new architecture:

* Preserve them.
* Map them into the new entities.
* Mark legacy records appropriately if necessary.

If existing records are clearly fake/demo data:

* Do not represent them as real business activity.
* Provide a safe development reset/seed mechanism.

---

# 66. TESTING REQUIREMENTS

Do not consider the project complete because the pages load.

Test the complete business workflow.

## Test Case 1 — Business registration

```text
Register business
→ Pending
→ Admin approves
→ Business can access platform
```

## Test Case 2 — Employee

```text
Owner creates employee
→ Employee logs in
→ Employee creates referral
→ Referral belongs to employee
```

## Test Case 3 — Referral

```text
Hotel A
→ Employee creates referral
→ Hotel B receives it
→ Hotel B accepts
→ Guest visits
→ Hotel B records transaction
```

## Test Case 4 — Commission

```text
Transaction = RWF 100,000
Commission = 10%

Total commission = RWF 10,000

Distribution is calculated correctly.
```

## Test Case 5 — Settlement

```text
Settlement submitted
→ Pending
→ Verified
→ Ledger updated
→ Statement updated
```

## Test Case 6 — Platform billing

```text
Monthly referral value calculated
→ Correct fee tier selected
→ Invoice created
→ Payment recorded
→ Invoice becomes paid
```

## Test Case 7 — Dispute

```text
Business disputes transaction
→ Dispute created
→ Admin reviews
→ Resolution recorded
→ Audit trail preserved
```

## Test Case 8 — Authorization

Verify:

```text
Business A cannot access Business B's private data.
Employee cannot access owner/admin functions.
Suspended business cannot create referrals.
Unauthenticated users cannot access protected pages.
```

## Test Case 9 — Duplicate protection

Submit the same transaction/settlement twice.

Verify:

> No duplicate commission or payment is created.

---

# 67. ACCEPTANCE CRITERIA

The transformation is NOT complete until all of the following are true.

### Business model

* [ ] GuestBridge is positioned as a hospitality referral network.
* [ ] The primary monetization model is usage-based monthly platform/service fees.
* [ ] Competing monetization models are removed from the primary product.
* [ ] Platform fees are configurable.
* [ ] Commission logic is configurable.

### Referral

* [ ] Referrals have unique IDs.
* [ ] Referrals preserve business and employee attribution.
* [ ] Referral lifecycle is implemented.
* [ ] Referral expiry is supported.
* [ ] Referral validation works.
* [ ] QR and sharing functionality works.

### Guest

* [ ] Guest benefit is clearly displayed.
* [ ] Benefits can be configured.
* [ ] Referral instructions are clear.

### Employee

* [ ] Employees can create referrals.
* [ ] Employees see their own performance.
* [ ] Employee commissions are calculated correctly.
* [ ] Employees cannot access unauthorized data.

### Business

* [ ] Businesses can manage employees.
* [ ] Businesses can manage partnerships.
* [ ] Businesses can create referrals.
* [ ] Businesses can see earnings.
* [ ] Businesses can see obligations.
* [ ] Businesses can see statements.
* [ ] Businesses can see platform invoices.

### Commission

* [ ] Commission is calculated server-side.
* [ ] Commission allocations are configurable.
* [ ] Financial values use precise decimal storage.
* [ ] Duplicate commission creation is prevented.
* [ ] Adjustments preserve history.

### Settlement

* [ ] Manual settlement is supported.
* [ ] Settlement verification is supported.
* [ ] Payment references are recorded.
* [ ] Settlement status is auditable.

### Billing

* [ ] Monthly referral value is calculated.
* [ ] Correct platform fee tier is selected.
* [ ] Invoice is generated.
* [ ] Payment is tracked.
* [ ] Receipt is generated where applicable.

### Disputes

* [ ] Referral disputes are supported.
* [ ] Transaction disputes are supported.
* [ ] Commission disputes are supported.
* [ ] Admin resolution is auditable.

### Dashboards

* [ ] Business dashboard exists.
* [ ] Employee dashboard exists.
* [ ] Admin dashboard exists.
* [ ] Pending actions are prominent.
* [ ] Financial metrics are accurate.
* [ ] No fake data is presented as real.

### Security

* [ ] Authentication is secure.
* [ ] Authorization is enforced server-side.
* [ ] Tenant isolation works.
* [ ] CSRF protection is implemented where applicable.
* [ ] SQL injection protections are present.
* [ ] XSS protections are present.
* [ ] Sensitive information is protected.
* [ ] Financial operations are transactional.
* [ ] Audit logging exists.

---

# 68. PERFORMANCE AND UX ACCEPTANCE

The application should:

* Load efficiently.
* Work on mobile.
* Work on desktop.
* Avoid unnecessary database queries.
* Use pagination for large datasets.
* Use indexes appropriately.
* Avoid duplicated business logic.
* Avoid unnecessary API requests.
* Provide clear loading states.
* Provide clear success/error feedback.

---

# 69. DO NOT BREAK EXISTING USEFUL FEATURES

During the transformation:

Preserve useful existing functionality such as:

* QR referral generation
* WhatsApp referral sharing
* Business profiles
* Partner discovery
* Existing analytics where relevant
* Existing settlement methods
* Existing invoice/receipt capabilities
* Existing reputation scoring
* Existing onboarding
* Existing admin capabilities

But refactor them so they conform to the new business model.

Do not preserve obsolete logic merely because it already exists.

---

# 70. IMPLEMENTATION PROCESS

Follow this exact sequence.

## STEP 1 — FULL CODEBASE AUDIT

Before editing:

* Inspect directory structure.
* Inspect PHP files.
* Inspect database schema.
* Inspect authentication.
* Inspect routing.
* Inspect APIs/endpoints.
* Inspect frontend pages.
* Inspect JavaScript.
* Inspect CSS.
* Inspect seed data.
* Inspect configuration.
* Inspect financial logic.
* Inspect referral logic.
* Inspect commission logic.
* Inspect admin functionality.

Produce an internal architecture map.

Do not immediately start rewriting.

---

## STEP 2 — CURRENT VS TARGET GAP ANALYSIS

Map:

```text
Existing feature
→ Keep
→ Refactor
→ Replace
→ Remove
→ New feature
```

Identify conflicts with this specification.

Do not delete useful functionality without understanding its dependencies.

---

## STEP 3 — DATABASE TRANSFORMATION

Implement the required schema changes through proper migrations.

Preserve useful data.

Add:

* partnerships
* benefits
* referral events
* transactions
* commission allocations
* settlements
* disputes
* billing periods
* platform invoices
* payments
* statements
* audit records

Reuse existing structures where appropriate.

---

## STEP 4 — CORE DOMAIN LOGIC

Implement/refactor:

1. Partnership service
2. Referral service
3. Transaction service
4. Commission service
5. Settlement service
6. Billing service
7. Statement service
8. Dispute service
9. Notification service

Ensure each service follows the rules in this specification.

---

## STEP 5 — AUTHORIZATION

Implement strict role and tenant access control.

Test it before moving forward.

---

## STEP 6 — REFERRAL WORKFLOW

Complete:

```text
Create
→ Accept
→ Visit
→ Convert
→ Settle
```

with audit history.

---

## STEP 7 — COMMISSION WORKFLOW

Complete:

```text
Transaction
→ Commission
→ Allocation
→ Ledger
→ Settlement
```

Ensure all calculations are server-side.

---

## STEP 8 — BILLING WORKFLOW

Complete:

```text
Monthly referral value
→ Fee tier
→ Invoice
→ Payment
→ Receipt
```

---

## STEP 9 — DASHBOARDS

Build/refactor:

* Admin dashboard
* Business dashboard
* Employee dashboard

Prioritize pending actions and financial information.

---

## STEP 10 — MOBILE UX

Optimize referral creation, referral validation, QR scanning, transaction recording, and settlement actions for mobile screens.

---

## STEP 11 — REMOVE OUTDATED FEATURES/CLAIMS

Remove or refactor:

* Fake AI claims
* Conflicting revenue models
* Fake financial data
* Hard-coded financial rules
* Premature multi-city positioning
* Broken wallet calculations

---

## STEP 12 — END-TO-END TESTING

Run the complete workflow using realistic test data.

Verify:

```text
Business registration
→ Approval
→ Employee creation
→ Partnership
→ Referral
→ Guest benefit
→ Referral acceptance
→ Visit
→ Transaction
→ Commission
→ Employee earnings
→ Business earnings
→ Settlement
→ Platform fee
→ Invoice
→ Payment
→ Statement
```

---

# 71. FINAL QUALITY CHECK

Before declaring completion, inspect the entire application again.

Search for:

* Broken links
* Broken routes
* Undefined variables
* SQL errors
* Missing database fields
* Authorization holes
* Hard-coded commission values
* Hard-coded platform prices
* Fake metrics
* Demo data
* Inconsistent status values
* Duplicate financial records
* Incorrect balance calculations
* Missing audit logs
* UI pages still using old business terminology

Fix all issues discovered.

---

# 72. FINAL DELIVERABLE

When finished, provide a concise implementation report containing:

### 1. Architecture changes

What was changed and why.

### 2. Database changes

Tables created/modified and migrations performed.

### 3. Business logic

How referrals, transactions, commissions, settlements, and billing now work.

### 4. User roles

What each role can do.

### 5. Financial model

Explain exactly how:

```text
Guest spending
→ referral commission
→ employee allocation
→ business allocation
→ GuestBridge allocation
→ settlement
→ monthly platform fee
```

works.

### 6. Security

List major security improvements.

### 7. Testing

List the end-to-end workflows tested and their results.

### 8. Remaining issues

Only report genuine unresolved issues.

Do not claim functionality is complete if it was not actually implemented and tested.

---

# FINAL PRODUCT PRINCIPLE

The finished GuestBridge Rwanda application must embody this principle:

> **GuestBridge is the trusted system of record for hospitality referrals and the money generated from them.**

The product should make the complete journey transparent:

```text
REFERRAL
   ↓
GUEST BENEFIT
   ↓
VISIT
   ↓
TRANSACTION
   ↓
COMMISSION
   ↓
EMPLOYEE REWARD
   ↓
BUSINESS EARNINGS
   ↓
SETTLEMENT
   ↓
MONTHLY STATEMENT
   ↓
GUESTBRIDGE PLATFORM FEE
```

The ultimate MVP validation target is:

> **Prove the model in Kigali before expanding geographically.**

The product should be capable of supporting the milestone of:

> **50 active Kigali businesses and RWF 5M+ in tracked referral value per month.**

Do not build features merely because they sound impressive.

Every feature should strengthen one or more of these outcomes:

1. More successful referrals.
2. More value generated for participating businesses.
3. More transparent commission attribution.
4. More employee participation.
5. More reliable settlement.
6. More recurring business usage.
7. More sustainable GuestBridge revenue.

**Inspect first. Plan second. Implement third. Test fourth. Do not rewrite blindly.**
