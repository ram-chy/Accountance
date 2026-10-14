# PHASE 13 — AUDIT & ACCOUNTING CONTROLS

## 1. ROLE

You are implementing **Phase 13 — Audit & Accounting Controls** for the existing
Accounting Web Application.

You are working inside:

```text
accounting-app/backend
```

This is an existing Laravel 13 accounting backend.

**This is NOT a greenfield implementation.**

Before changing anything, inspect the existing Phase 1–12 implementation and
especially:

- authentication and authorization;
- CompanyContext;
- users / roles / permissions;
- accounting periods;
- accounts;
- journals;
- journal lines;
- JournalService;
- JournalPostingService;
- LedgerService;
- document numbering;
- audit-related infrastructure already present;
- Sales Invoices;
- Purchase Bills;
- Customer Payments;
- Supplier Payments;
- Expenses;
- Cash & Banking;
- Bank Reconciliation;
- Tax Engine;
- Credit/Debit Notes;
- Fixed Assets;
- reports;
- existing database constraints;
- existing exception conventions;
- existing API/resource conventions;
- existing tests.

The Phase 12 report is authoritative for the current architecture.

Phase 12 confirms that the application already uses:

- one accounting journal path;
- immutable posted accounting records;
- fiscal-period enforcement;
- company-scoped accounting;
- exact `Money` arithmetic;
- server-side authorization;
- database constraints;
- row locking for irreversible asset operations;
- ledger-derived financial reporting;
- no stored accounting balance as a second source of truth.

Do not duplicate these systems.

---

# 2. PRIMARY OBJECTIVE

Implement a centralized **Audit & Accounting Controls** layer that makes the
accounting application more traceable, reviewable, and resistant to accidental
or unauthorized financial changes.

The phase must provide:

1. immutable audit history for important business/accounting actions;
2. accounting control checks;
3. posting integrity verification;
4. fiscal-period controls;
5. reversal/correction traceability;
6. user/action/timestamp traceability;
7. accounting data integrity checks;
8. audit/reporting APIs;
9. controlled administrative access;
10. automated integrity/security testing.

The implementation must strengthen the existing accounting architecture without
replacing it.

---

# 3. IMPORTANT ARCHITECTURAL RULE

Do NOT create:

- a second journal engine;
- a second ledger;
- a second accounting calculation engine;
- a second authorization system;
- a second company-context system;
- an independent financial-balance store;
- an uncontrolled generic event log;
- an event-sourcing architecture;
- CQRS merely for audit purposes;
- a complex workflow engine;
- a background audit system that can silently lose financial events.

The existing accounting engine remains the source of truth.

The audit layer records **what happened, who did it, when it happened, and what
financial/business object was affected**.

It must never become the source of accounting balances.

---

# 4. CURRENT BASELINE

Phase 12 completed fixed-asset accounting with:

- asset categories;
- asset registration;
- capitalization;
- straight-line depreciation;
- disposal;
- gain/loss;
- asset register;
- depreciation schedules;
- accounting integration.

Phase 12 specifically verified:

```text
947 tests
4622 assertions
0 failures
0 errors
```

and confirmed:

- posted records are immutable;
- fiscal periods are respected;
- company isolation works;
- authorization works;
- concurrent irreversible operations are protected;
- existing reports reflect accounting journals without modification;
- no second accounting engine exists;
- no stored accounting balance was introduced.

Treat this as the Phase 13 regression baseline.

---

# 5. SCOPE

Implement:

## A. Audit Trail

Track important application and accounting actions.

## B. Accounting Integrity Controls

Verify that accounting invariants remain true.

## C. Posting Controls

Verify journal/posting integrity.

## D. Period Controls

Strengthen controls around open/closed/locked periods.

## E. Financial Immutability Controls

Detect or prevent unauthorized changes to posted financial records.

## F. Reversal / Correction Traceability

Ensure corrections can be traced back to their original transactions where the
existing architecture supports reversal/correction.

## G. Audit APIs

Provide read-only audit access for authorized users.

## H. Accounting Control Reports

Provide read-only control/integrity reports.

## I. Security

Ensure audit records cannot be manipulated through ordinary APIs.

## J. Testing

Add comprehensive integrity, authorization, company-isolation, tampering, and
concurrency tests.

---

# 6. EXPLICIT NON-GOALS

Do NOT implement:

- external compliance frameworks;
- country-specific statutory audit systems;
- electronic signatures;
- blockchain;
- immutable external ledgers;
- external SIEM integration;
- email notification engine;
- accounting approval workflow;
- document workflow engine;
- full ERP approval chains;
- tax filing;
- statutory audit filing;
- GST/VAT audit compliance;
- inventory audit;
- payroll audit;
- frontend;
- dashboard redesign;
- PDF export;
- unrelated refactoring.

If a future requirement needs one of these, document it as deferred.

---

# 7. FIRST TASK — INSPECT EXISTING AUDIT INFRASTRUCTURE

Before creating anything, search the repository for existing:

```text
audit
audit_log
audit_logs
activity
activity_log
created_by
updated_by
posted_by
reversed_by
deleted_by
created_at
updated_at
posted_at
```

Also inspect:

- models;
- traits;
- observers;
- events;
- listeners;
- middleware;
- policies;
- service methods;
- migrations.

**Do not create `audit_logs` if an adequate existing audit implementation
already exists.**

If an existing audit table/service is present, extend it only where necessary.

---

# 8. AUDIT DESIGN PRINCIPLE

An audit record should answer:

```text
WHO
WHAT
WHEN
WHERE
WHICH COMPANY
WHICH RESOURCE
WHICH ACTION
WHAT CHANGED
```

At minimum, where technically available, capture:

- company_id;
- actor/user id;
- action;
- auditable/resource type;
- auditable/resource id;
- timestamp;
- request/correlation identifier if available;
- IP address if appropriate;
- user agent if appropriate;
- before state where appropriate;
- after state where appropriate;
- metadata/context;
- success/failure where appropriate.

Do not collect unnecessary personal information.

Do not store passwords, OTPs, tokens, authorization headers, or secrets.

---

# 9. AUDIT EVENT TYPES

Use a controlled action vocabulary.

Do not accept arbitrary action strings from clients.

Potential actions:

```text
CREATED
UPDATED
DELETED
POSTED
REVERSED
CANCELLED
CAPITALISED
DEPRECIATED
DISPOSED
RECONCILED
REOPENED
ACTIVATED
DEACTIVATED
LOGIN
LOGOUT
PASSWORD_CHANGED
PASSWORD_RESET
```

These are examples.

Inspect existing application terminology and create the smallest appropriate
enum.

Do not create duplicate action concepts if existing enums already represent
them.

---

# 10. FINANCIAL RESOURCE AUDITING

Prioritize financial resources.

At minimum evaluate audit coverage for:

- journals;
- journal lines;
- sales invoices;
- purchase bills;
- customer payments;
- supplier payments;
- expenses;
- cash/bank transactions;
- bank reconciliations;
- tax configuration;
- credit/debit notes;
- fixed assets;
- fixed-asset depreciation;
- fixed-asset disposal;
- fiscal periods;
- chart of accounts.

Do not blindly attach an observer to every model.

Financially significant actions should be explicitly traceable.

---

# 11. POSTED TRANSACTION IMMUTABILITY

Verify the existing application protects posted financial records.

A posted financial transaction must not be silently changed through:

- PUT;
- PATCH;
- mass assignment;
- direct model update;
- relationship manipulation;
- hidden API fields;
- route tampering.

Test all relevant financial modules.

Where the existing implementation has a gap, fix it.

Do not solve this by adding a generic global observer that silently rejects
every update without understanding the module.

Use the existing service/policy architecture.

---

# 12. POSTED JOURNAL IMMUTABILITY

The journal is central to the accounting system.

After a journal is posted:

- journal lines cannot be edited;
- debit/credit values cannot be changed;
- account cannot be changed;
- journal date cannot be changed;
- company cannot be changed;
- source cannot be changed;
- posted user/date cannot be changed.

If the existing lifecycle supports:

```text
DRAFT → POSTED → REVERSED
```

then:

- Draft may be modified according to existing rules;
- Posted is immutable;
- Reversed remains historical;
- correction happens through the established reversal/correction mechanism.

Do not add direct update endpoints for posted journals.

---

# 13. JOURNAL BALANCE CONTROL

Create a reusable accounting integrity control for:

```text
SUM(debits) == SUM(credits)
```

for every posted journal.

Use exact decimal/Money logic.

Never use floating-point comparison.

The control should detect:

- unbalanced journal;
- negative debit;
- negative credit;
- debit and credit both non-zero on a line;
- missing account;
- inactive account where prohibited;
- cross-company account;
- invalid company relation.

Do not change valid historical accounting data merely because a control report
finds an issue.

Controls should identify problems safely.

---

# 14. JOURNAL LINE CONTROL

Verify every journal line satisfies the application's existing accounting rules.

At minimum:

```text
debit >= 0
credit >= 0
not (debit > 0 and credit > 0)
```

and:

```text
debit > 0 OR credit > 0
```

unless the existing schema intentionally permits another state.

Verify:

- account belongs to same company;
- journal belongs to same company;
- currency context is valid;
- base amounts are consistent where applicable.

Use existing constraints first.

Add missing database constraints only when safe and compatible with existing
data.

---

# 15. COMPANY ISOLATION CONTROL

Audit records themselves must be company scoped where the underlying resource is
company scoped.

A user from Company A must not be able to:

- read Company B audit records;
- infer Company B resource IDs through audit endpoints;
- create audit records for Company B;
- alter Company B audit records.

Use the established:

```text
CompanyContext
```

architecture.

Do not accept `company_id` from the client.

---

# 16. AUDIT RECORD IMMUTABILITY

Audit records are historical records.

Ordinary users must never be able to:

```text
PUT /audit-logs/{id}
PATCH /audit-logs/{id}
DELETE /audit-logs/{id}
```

Do not provide ordinary CRUD.

Prefer:

```text
CREATE internally
READ through authorized endpoints
NO UPDATE
NO DELETE
```

If retention/deletion is ever required, document it as a future
administrative/compliance feature rather than implementing arbitrary deletion in
Phase 13.

---

# 17. AUDIT CREATION

Audit entries must be generated by trusted server-side code.

Never accept:

```text
actor_id
company_id
action
before
after
```

as trusted client payload.

The server determines these values.

Prefer recording audit entries inside the same database transaction as the
business action where practical.

For example:

```text
DB transaction
    ↓
validate
    ↓
perform financial/business action
    ↓
write audit event
    ↓
commit
```

The audit record must not claim that an action succeeded if the underlying
transaction rolled back.

---

# 18. FAILED ACTIONS

Determine carefully whether failed actions should be audited.

For security-sensitive actions such as:

- unauthorized access attempts;
- login failures;
- password reset attempts;
- permission failures;

an audit/security event may be useful.

However, do not create misleading financial audit events.

For a financial transaction:

```text
validation fails
```

must not produce:

```text
POSTED
```

or:

```text
CREATED
```

audit history.

Use appropriate action/result semantics.

---

# 19. BEFORE / AFTER DATA

For update actions, capture meaningful changed fields.

Example:

```text
before:
{
    "name": "Old Name",
    "description": "Old description"
}

after:
{
    "name": "New Name",
    "description": "New description"
}
```

Do not blindly serialize complete Eloquent models.

Exclude:

- password;
- password hash;
- OTP;
- access token;
- refresh token;
- secrets;
- sensitive authentication data.

For large financial objects, prefer concise structured metadata over massive
JSON snapshots.

---

# 20. AUDIT DATA SIZE

Do not create an audit system that stores enormous copies of every model.

Use a practical policy.

For example:

- business identity fields;
- accounting-relevant changed fields;
- status changes;
- amounts where necessary;
- references;
- actor;
- timestamp.

Document what is intentionally omitted.

---

# 21. REQUEST / CORRELATION ID

Inspect whether the application already has a request/correlation ID mechanism.

If it exists:

- reuse it.

If it does not:

- introduce the smallest middleware needed to assign a request identifier.

Do not introduce distributed tracing infrastructure.

The ID should help connect:

```text
HTTP request
→ business action
→ journal
→ audit event
```

where possible.

---

# 22. IP ADDRESS AND USER AGENT

If the existing application captures these values, reuse the convention.

If not, determine whether they materially improve auditability.

If implemented:

- capture only appropriate request metadata;
- do not store authorization headers;
- do not store cookies;
- do not store passwords or tokens.

Document the decision.

---

# 23. FINANCIAL POSTING AUDIT

Ensure major financial posting actions have audit traceability.

Examples:

### Sales Invoice

```text
invoice created
invoice issued/posted
payment recorded
```

### Purchase Bill

```text
bill created
bill posted
payment recorded
```

### Cash/Bank

```text
cash/bank transaction posted
```

### Bank Reconciliation

```text
reconciliation completed
reconciliation reopened
```

### Tax

```text
tax configuration created/updated
tax activated/deactivated
```

### Credit/Debit Note

```text
note posted
```

### Fixed Assets

```text
asset capitalized
depreciation posted
asset disposed
```

Do not create duplicate audit events if existing services already generate them
correctly.

---

# 24. REVERSAL / CORRECTION TRACEABILITY

Inspect the existing application for journal reversal/correction support.

If reversal already exists:

- verify it is auditable;
- verify the reversal references the original journal;
- verify the original remains immutable;
- verify the reversal itself is immutable once posted;
- verify the relationship is company scoped.

If reversal does not yet exist:

**Do not build a full reversal engine automatically unless it is already part of
the existing architecture.**

Instead:

- identify the limitation;
- document it;
- implement only the audit/control portion that can be safely supported.

Do not invent a new correction workflow in Phase 13.

---

# 25. FISCAL PERIOD CONTROLS

Review:

```text
OPEN
CLOSED
LOCKED
```

period behavior.

Verify:

### Open

Allowed financial posting according to existing rules.

### Closed

No new financial posting.

### Locked

No posting and no financial modification.

The exact semantics must follow the current implementation.

Do not create a competing period-state system.

---

# 26. PERIOD CONTROL AUDIT

Audit important fiscal-period actions:

- created;
- opened;
- closed;
- locked;
- reopened if supported.

A period state change must record:

- actor;
- timestamp;
- company;
- period;
- old state;
- new state.

If reopening is currently supported, verify appropriate authorization.

If reopening is not supported, do not add it merely for this phase.

---

# 27. CHART OF ACCOUNTS CONTROLS

Verify accounting accounts cannot be altered in ways that corrupt posted
history.

Test:

- account deletion while referenced;
- account type changes while referenced;
- company changes;
- code changes where prohibited;
- system-account deletion;
- inactive account posting;
- account hierarchy integrity.

Do not break legitimate existing account-management behavior.

Fix only concrete integrity gaps.

---

# 28. MASTER DATA CONTROLS

Review financially referenced master data:

- customers;
- suppliers;
- accounts;
- tax rates;
- tax rules;
- asset categories.

A referenced record must not be destructively deleted if doing so would
compromise historical accounting.

Prefer:

```text
active → inactive
```

where appropriate.

Follow existing module conventions.

---

# 29. CONTROL CHECK SERVICE

Create a reusable accounting-control service only if the existing architecture
has no suitable equivalent.

Potential responsibility:

```text
AccountingControlService
```

It may expose checks such as:

```text
checkPostedJournalBalance()
checkJournalLineIntegrity()
checkCompanyIsolation()
checkFinancialPeriodIntegrity()
checkPostedDocumentIntegrity()
checkReferenceIntegrity()
```

Keep it focused.

Do not build a generic rules engine.

---

# 30. CONTROL RESULT MODEL

A control result should be easy to understand.

Conceptually:

```text
status:
    PASS
    WARNING
    FAIL

control_code
description
company_id
resource_type
resource_id
financial_date
details
```

Do not store these results as permanent accounting balances.

They are control findings.

If persistence is useful, use a clearly named control-finding table.

Do not store duplicate accounting data.

---

# 31. CONTROL EXECUTION

Controls should be callable:

- from authorized API endpoints;
- from automated tests;
- from internal services.

Potential endpoint:

```text
GET /api/accounting/controls
```

or a more focused route consistent with the existing API.

Do not expose arbitrary SQL or model inspection through the API.

---

# 32. CONTROL REPORTS

Provide read-only reports for authorized users.

Potential reports:

### Journal Integrity

- total posted journals checked;
- balanced;
- unbalanced;
- invalid lines;
- invalid account/company references.

### Period Integrity

- financial records outside valid periods;
- posted transactions in closed/locked periods.

### Audit Coverage

- recent financial actions;
- actor;
- action;
- resource;
- timestamp.

### Reference Integrity

- financial records pointing to inactive/deleted master data;
- orphaned references.

Do not invent findings simply because a record is inactive.

For example:

An inactive customer with an existing historical invoice may be perfectly valid.

Distinguish:

```text
historically valid inactive reference
```

from:

```text
broken/orphaned reference
```

---

# 33. CONTROL REPORTING MUST BE READ-ONLY

Control endpoints must never:

- modify journals;
- repair financial records automatically;
- delete records;
- reopen periods;
- reverse transactions.

A finding should be:

```text
identified
reported
investigated
corrected through the appropriate existing process
```

Do not implement automatic financial "repair."

---

# 34. AUDIT API

Provide a read-only API consistent with existing report conventions.

Potential endpoints:

```text
GET /api/accounting/audit-logs
GET /api/accounting/audit-logs/{auditLog}
GET /api/accounting/audit-logs/financial
GET /api/accounting/audit-logs/security
```

These are illustrative.

Inspect the existing route architecture first.

Useful filters may include:

- action;
- actor;
- resource type;
- resource ID;
- date range;
- company;
- request/correlation ID.

All filtering must be server-side.

---

# 35. PAGINATION

Audit logs can grow significantly.

Do not return an unbounded collection.

Use the application's existing pagination convention.

Support:

```text
page
per_page
```

or the existing equivalent.

Apply reasonable maximum page sizes.

Do not allow clients to request millions of audit rows in one response.

---

# 36. AUDIT SEARCH SECURITY

An authorized user must not be able to use filters to discover another
company's:

- user IDs;
- invoice IDs;
- journal IDs;
- customer IDs;
- supplier IDs;
- asset IDs;
- audit events.

Company context must be applied before filtering.

---

# 37. ROLE / PERMISSION MODEL

Inspect the current permission architecture.

If suitable permissions do not exist, introduce:

```text
accounting.audit.view
accounting.controls.view
```

Potentially:

```text
accounting.controls.run
```

only if execution should be separately protected.

Do not create unnecessary permissions.

Recommended principle:

- Admin: audit + controls;
- Accountant: audit + controls according to accounting responsibilities;
- Manager: read controls/audit according to existing financial visibility;
- Staff: no financial audit/control access unless existing policy explicitly
  permits it.

The actual matrix must follow the application's established authorization
philosophy.

---

# 38. AUDIT POLICY

Register an explicit policy for audit records if the application uses policy
registration.

Do not rely on accidental route protection.

Test:

- Admin;
- Accountant;
- Manager;
- Staff;
- cross-company access.

---

# 39. AUDIT LOG MODEL

If a new model is necessary, it should be deliberately narrow.

Potential fields:

```text
id
company_id
actor_id
action
auditable_type
auditable_id
request_id
ip_address
user_agent
before_data
after_data
metadata
created_at
```

Do not blindly implement all fields.

Inspect the existing system first.

Possible indexes:

```text
(company_id, created_at)
(company_id, action, created_at)
(company_id, auditable_type, auditable_id)
(company_id, actor_id, created_at)
(request_id)
```

Choose only useful indexes.

---

# 40. AUDIT RETENTION

Do not implement automatic deletion in Phase 13.

Document:

- audit records are intended to be historical;
- retention policy is a future administrative/compliance concern;
- ordinary users cannot delete audit history.

---

# 41. AUDIT TRANSACTION CONSISTENCY

For financial actions, audit creation should normally happen inside the same
transaction.

Example:

```text
BEGIN
    validate
    create/update/post business record
    create journal
    post journal
    write audit event
COMMIT
```

If any operation fails:

```text
ROLLBACK
```

The audit record must not falsely report a successful financial event.

---

# 42. AUDIT FAILURE POLICY

Do not silently swallow audit failures for financial operations.

If an audit record is mandatory for a financial event and cannot be written:

```text
financial transaction should fail/rollback
```

unless the existing architecture already has a documented alternative.

Do not allow:

```text
journal posted
audit missing
```

without an explicit architectural reason.

---

# 43. CONTROL PERFORMANCE

Do not make normal accounting transactions run every expensive control query.

For example:

```text
POST invoice
```

should not scan the entire company's journal history just to verify global
integrity.

Normal transactions should perform local invariants.

Global control reports may perform broader scans.

Keep:

```text
transaction-time validation
```

separate from:

```text
periodic/global integrity checks
```

---

# 44. DATABASE CONSTRAINT REVIEW

Review existing accounting tables for important database-level guarantees.

Do not rewrite migrations unnecessarily.

Look for:

- foreign keys;
- unique constraints;
- CHECK constraints;
- nullable financial fields;
- company scoping;
- delete behavior.

Where a missing constraint is clearly dangerous and compatible with existing
data:

- add it.

Where adding one could break legitimate historical records:

- do not blindly add it;
- report the limitation.

---

# 45. ORPHAN CHECKS

Create control checks for possible orphan conditions.

Examples:

- journal line without valid journal;
- journal line account missing;
- journal referencing invalid company;
- financial document referencing wrong company;
- audit record referencing inaccessible company;
- depreciation row without journal where the architecture requires one;
- disposal without corresponding asset;
- asset journal mismatch.

Do not treat intentionally nullable relationships as orphans.

Respect existing delete semantics.

---

# 46. FINANCIAL DATE CONTROLS

Verify important accounting dates.

Examples:

- posted journal date;
- invoice date;
- payment date;
- credit/debit note date;
- depreciation period end;
- asset disposal date;
- bank reconciliation period.

Ensure existing rules prevent impossible backdating.

Do not introduce a universal date rule if modules legitimately have different
requirements.

Document module-specific rules.

---

# 47. CROSS-MODULE INTEGRITY

Verify accounting relationships across modules.

Examples:

```text
Invoice
    ↓
Journal
```

```text
Payment
    ↓
Journal
```

```text
Credit/Debit Note
    ↓
Journal
```

```text
Fixed Asset
    ↓
Capitalization Journal
```

```text
Fixed Asset Depreciation
    ↓
Depreciation Journal
```

```text
Fixed Asset Disposal
    ↓
Disposal Journal
```

The control layer should identify missing or inconsistent relationships without
rewriting financial history.

---

# 48. DOCUMENT-TO-JOURNAL CONTROL

For every financial document type that is supposed to have a journal:

Verify:

```text
document posted
→ journal exists
→ journal belongs to same company
→ journal is posted
→ journal is balanced
```

Do not apply this to draft documents.

Do not assume every business record is a financial document.

---

# 49. JOURNAL-TO-SOURCE CONTROL

For journals generated by business modules, verify the source relationship.

Examples:

```text
JournalSource::FixedAsset
JournalSource::FixedAssetDepreciation
JournalSource::FixedAssetDisposal
```

must correspond to the correct resource.

Do not require a source relationship for legitimate manual journals if the
current accounting design supports them.

---

# 50. AUDIT RESOURCE SERIALIZATION

Audit API responses must not expose:

- passwords;
- password hashes;
- tokens;
- OTPs;
- secrets;
- internal authentication details.

Resource serialization should be explicit.

Do not return raw model JSON blindly.

---

# 51. SECURITY TESTING

Test at minimum:

### Authorization

- Staff cannot access audit logs.
- Unauthorized users cannot run accounting controls.
- Manager/Accountant behavior matches the intended matrix.

### Company isolation

- Company A cannot see Company B audit events.
- Company A cannot query Company B by resource ID.
- Company A cannot use filters to infer Company B records.

### Tampering

Client cannot set:

- actor_id;
- company_id;
- action;
- created_at;
- before_data;
- after_data;
- journal ownership.

### Immutability

Audit records cannot be:

- updated;
- deleted;
- replaced through mass assignment.

---

# 52. CONCURRENCY TESTING

Test important control-sensitive operations concurrently where applicable.

At minimum verify that concurrent:

- posting;
- reversal/correction;
- period changes;
- financial status changes;

cannot leave contradictory audit history.

If the existing service already locks the relevant record, preserve that
mechanism.

Do not introduce unnecessary locking.

---

# 53. TEST SUITE

Create focused tests for:

## Audit

- creation;
- update;
- posting;
- financial events;
- actor attribution;
- company attribution;
- timestamps;
- before/after data;
- sensitive-field filtering;
- immutability.

## Journal Controls

- balanced journal;
- unbalanced journal detection;
- invalid line detection;
- invalid account detection;
- company mismatch.

## Period Controls

- valid posting;
- closed period rejection;
- locked period rejection;
- period-state audit.

## Cross-Module Controls

- document/journal relationship;
- fixed asset/journal relationship;
- payment/journal relationship;
- note/journal relationship.

## Security

- role permissions;
- cross-company;
- tampered IDs;
- tampered audit payload;
- unauthorized audit access.

## Regression

All existing tests must continue to pass.

---

# 54. FULL SUITE REQUIREMENT

Before completion, run:

```bash
php artisan test
```

The Phase 12 baseline is:

```text
947 tests
4622 assertions
0 failures
0 errors
```

The final count should increase appropriately.

Do not claim success based only on Phase 13 tests.

The entire application suite must pass.

---

# 55. CODE QUALITY

Run:

```bash
./vendor/bin/pint --test
```

Also perform PHP syntax verification for changed files.

Do not leave debugging code.

Do not leave temporary logging.

Do not leave commented-out experimental code.

---

# 56. MIGRATION VERIFICATION

If migrations are added:

```bash
php artisan migrate
```

and verify rollback/re-run using the project's existing migration test.

Also verify:

```bash
php artisan migrate:fresh --force
```

against the project's supported MySQL environment if appropriate.

Do not run seeders.

Do not create fake accounting data.

---

# 57. ROUTE VERIFICATION

Verify the final routes using the existing route-list conventions.

Check:

- authentication;
- fresh-auth requirements where appropriate;
- company context;
- throttling;
- policies;
- route model binding;
- pagination;
- read-only restrictions.

---

# 58. DOCUMENTATION

Create:

```text
docs/report/PHASE_13_REPORT.md
```

Follow the repository's actual report convention.

The report must contain:

1. Phase objectives;
2. architecture reviewed;
3. existing audit infrastructure;
4. audit schema;
5. audit actions;
6. financial-resource coverage;
7. journal controls;
8. fiscal-period controls;
9. cross-module controls;
10. permissions;
11. authorization;
12. company isolation;
13. security verification;
14. concurrency;
15. API endpoints;
16. tests;
17. migration verification;
18. commands executed;
19. known limitations;
20. deferred features;
21. requirements traceability;
22. final status.

Do not claim verification unless the command/test actually ran.

---

# 59. HARD STOPS

STOP implementation and document the issue if:

1. Existing audit infrastructure conflicts with the proposed design.
2. Audit history cannot be made transactionally consistent with financial
   posting.
3. Existing posted journals can be modified through an architectural path that
   cannot safely be protected.
4. Company isolation cannot be guaranteed for audit data.
5. Existing financial modules lack enough source references to establish
   meaningful audit relationships.
6. Fixing an integrity issue would require rewriting historical accounting data.
7. A proposed control would require a second accounting source of truth.
8. Existing fiscal-period behavior conflicts fundamentally with accounting
   integrity.
9. A global observer would be required to make the system correct but would
   create unsafe side effects.
10. The implementation would require an unrelated architectural rewrite.

If a hard stop occurs:

- do not invent a workaround;
- identify the exact class/table/service;
- explain the conflict;
- document the smallest safe architectural change;
- stop before introducing speculative infrastructure.

---

# 60. DO NOT AUTO-REPAIR ACCOUNTING

This is critical.

Control reports may identify:

```text
FAIL
```

but must NOT automatically:

- modify journals;
- change balances;
- rewrite documents;
- move transactions;
- reopen periods;
- delete records;
- create corrective journals.

Financial correction must use the application's legitimate accounting process.

---

# 61. NO SILENT HISTORICAL MODIFICATION

Never "fix" historical accounting records by directly updating them.

If a historical inconsistency is discovered:

```text
detect
→ report
→ document
→ correct through approved accounting mechanism
```

Do not silently modify history.

---

# 62. NO FRONTEND

Do not create:

- Next.js;
- React;
- Tailwind;
- frontend components;
- frontend API clients.

Phase 13 is backend-only.

The API must nevertheless be designed cleanly for the later frontend.

---

# 63. NO SEEDERS

Do not:

```text
php artisan db:seed
```

Do not create seeders.

Do not create default audit data.

Do not create fake control findings.

---

# 64. NO UNRELATED CHANGES

Do not:

- refactor working accounting services for style;
- rename unrelated models;
- change existing report behavior without a demonstrated control requirement;
- redesign authentication;
- redesign company context;
- redesign the journal engine;
- redesign the Tax Engine;
- redesign fixed assets.

If an existing defect is discovered that is outside Phase 13:

- document it;
- fix it only if it directly compromises Phase 13 correctness/security;
- otherwise defer it.

---

# 65. FINAL ACCEPTANCE CRITERIA

Phase 13 is complete only when:

- important financial actions have reliable audit traceability;
- audit records are server-generated;
- audit records are immutable;
- sensitive authentication data is never stored in audit records;
- company isolation applies to audit data;
- authorized users can query audit history;
- audit endpoints are read-only;
- posted journals are immutable;
- posted financial records are protected;
- journal balance integrity can be verified;
- journal-line integrity can be verified;
- cross-company accounting references can be detected;
- document-to-journal relationships can be checked;
- fiscal-period integrity can be checked;
- period state changes are auditable;
- accounting control reports are read-only;
- controls never automatically modify financial history;
- concurrency does not produce contradictory audit history;
- authorization is tested;
- cross-company isolation is tested;
- migrations pass;
- Pint passes;
- PHP syntax checks pass;
- focused Phase 13 tests pass;
- the complete application test suite passes;
- no seeders were created or executed;
- no frontend was created;
- no unrelated architecture was rewritten;
- `docs/report/PHASE_13_REPORT.md` exists;
- the report contains exact test results and an honest PASS / PASS WITH NOTES /
  BLOCKED status.

---

# 66. FINAL INSTRUCTION TO OPENCODE

**Inspect first. Implement second.**

The purpose of this phase is to make the existing accounting system more
auditable and more difficult to corrupt—not to create another accounting
framework.

Reuse:

- CompanyContext;
- existing authorization;
- existing policies;
- JournalService;
- JournalPostingService;
- LedgerService;
- AccountingPeriodService;
- Money;
- existing document numbering;
- existing audit infrastructure if present;
- existing exception conventions;
- existing API/resource conventions.

Do not create duplicate infrastructure.

Do not use observers blindly.

Do not automatically repair accounting history.

Do not store financial balances merely for audit purposes.

Do not create frontend code.

Do not run seeders.

Do not hurry.

Run focused tests while implementing.

Then run the complete application test suite.

If an existing architectural conflict prevents safe implementation, use the
hard-stop rule instead of inventing a workaround.

At completion, create:

```text
docs/report/PHASE_13_REPORT.md
```

and provide an accurate implementation report with:

```text
PASS
PASS WITH NOTES
```

or:

```text
BLOCKED
```

based strictly on what was actually implemented and verified.
