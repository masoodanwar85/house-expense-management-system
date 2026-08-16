# Architecture Decisions & Safe Defaults

This document records ambiguities found in the business requirements and the safe defaults chosen so financial behavior is never invented silently.

## Ambiguities and resolutions

### 1. Availability `status` vs “available days”
**Ambiguity:** Periods have `available` / `unavailable` status, while day math speaks of “availability periods”.

**Safe default:** Only periods with `status = available` contribute person-days. Periods with `status = unavailable` explicitly mark absence and never contribute days. Overlaps are rejected for *any* periods of the same user in the same house (regardless of status), so a day cannot be both available and unavailable.

`end_date` may be `null` for an ongoing period (still present). Overlap checks treat null as open-ended.

### 2. Membership vs availability
**Ambiguity:** Can a non-member have availability?

**Safe default:** No. Availability requires an active `house_members` row for that user/house. Membership (`joined_at` / `left_at`) is independent of presence days.

### 3. Who is a “member” for fixed allocation?
**Safe default:** Members whose membership interval overlaps the expense period:

`joined_at <= period_end` AND (`left_at` IS NULL OR `left_at >= period_start`)

Then `apply_to` filters further:

| apply_to | Meaning |
|----------|---------|
| `all_members` | All overlapping members above |
| `active_members` | Overlapping members with ≥ 1 available day in the expense period |
| `full_period_members` | Overlapping members available on every day of the expense period |

### 4. Expense → month settlement association
**Ambiguity:** Is an expense “in August” by `expense_date` or by period overlap? Multi-month coverage would double-count if every overlapping month included the full amount.

**Safe default:**
- **Month lock / edit impact:** coverage period overlaps the month (`period_start <= month_end` AND `period_end >= month_start`; coverage defaults to `[expense_date, expense_date]`).
- **Settlement balances:** confirmed expenses whose `expense_date` falls in the month. The full paid amount and stored allocations are counted once; coverage is not prorated across calendar months.

### 5. Recalculate on settlement vs stored allocations
**Ambiguity:** Spec both stores `expense_allocations` at confirmation and describes resolving/allocating again in monthly settlement.

**Safe default:** Settlement **reads stored** `expense_allocations` for confirmed expenses. It does **not** re-run the allocation engine with current rules. Editing a confirmed expense (while month open) recalculates and replaces that expense’s allocations inside a transaction.

### 5b. Settlement transfers
**Ambiguity:** Spec asks for minimal transfers but does not define persistence or a unique minimal algorithm.

**Safe default:** Transfers are **computed on read** (not stored). Algorithm: greedy match of largest remaining debtor to largest remaining creditor (`min(debt, credit)`), with ties broken by lower `user_id`. Direction is always debtor → creditor. Zero balances are omitted. No transfer table in v1.

### 5c. Overall (lifetime) owing
**Ambiguity:** Unpaid month transfers do not automatically appear in later months.

**Safe default:** Dashboard **Total owing** aggregates all confirmed expenses across months: lifetime `paid − share` per user, then the same transfer generator. Opposite monthly debts net correctly.

### 5d. Settlement payments (cash settlements)
**Ambiguity:** How do members record that money changed hands?

**Safe default:**
- Payer records a `settlement_payments` row (`pending`) to a house member, tagged with **year/month** (the debt month being reduced).
- Only the **recipient** can **confirm** or **reject**.
- Payer (or owner) may **cancel** while pending.
- Only **confirmed** payments adjust nets pairwise on suggested transfers:
  - paying A→B reduces A’s debt to B;
  - any **overpayment** becomes B→A credit (carries forward) and is **not** redistributed to other members;
  - applies to the tagged month’s settlement plan and to lifetime **Total owing**.
- Pending / rejected / cancelled payments do not affect owing.
- A payment for August does not change September’s settlement.

### 6. Hybrid component split modes
Hybrid rules support `configuration.mode`:

| Mode | Meaning |
|------|---------|
| `percentage` (default if omitted) | Components use `percentage`; must sum to exactly `100`. |
| `amount_remainder` | Absolute `amount` components + exactly one `share: "remainder"` component. |

**Safe defaults for `amount_remainder`:**
- At allocation time, if absolute amounts exceed the expense total → domain exception (no clamp).
- If absolute amounts equal the expense total → remainder is `0.00`.
- Historical percentage rules without `mode` still allocate as percentage.

### 7. Rule covering expense period
**Safe default:** Exactly one rule version must cover the full `[period_start, period_end]`. Missing/partial/overlapping coverage → domain exception (no silent guess). Multi-version spanning expenses are out of scope for v1.

### 8. Closed months
**Safe default:** Closed months block create/update/delete of expenses, availability, and rule versions that would affect that month. Owner may **reopen** explicitly; reopen is audited via `monthly_settlements.status`.

### 9. Money arithmetic
**Safe default:** `DECIMAL(15,2)` columns + BCMath string math in allocators. Round each participant to 2dp; assign remainder to the participant with the highest user id (deterministic).

### 10. Expense ownership edits
**Safe default:** Payer or house owner may edit draft expenses. Confirmed expenses: owner only, and only if month is open. Cancelled expenses cannot be edited directly; the owner may **reinstate** them to `draft` (month open) and confirm again. Reinstate does not restore old allocations — confirmation recalculates.

### 11. Authorization layers
**Safe default:** HTTP layer uses Laravel policies (`HousePolicy`, `ExpensePolicy`, `ExpenseCategoryPolicy`) returning **403** for forbidden access. Domain services keep `HouseAccessService` checks as defense in depth (422 `DomainException` if reached). Auth login/register are rate-limited.

| Action | Who |
|--------|-----|
| View house / expenses / settlement / availability | Active house member |
| Update house, manage members/categories/rules, close/reopen month, cancel/reinstate expense | House owner |
| Create expense / own availability | Active house member |
| Edit draft expense | Payer, creator, or owner |
| Edit confirmed expense | Owner only |
| Create availability for another user | Owner only (enforced in `AvailabilityService`) |

## Test coverage (Phase 11)

Spec matrix is covered across Unit + Feature suites (`composer test`, 86 tests). Focused financial subset: `composer test:domain`.

| Area | Primary tests |
|------|----------------|
| Availability (full/partial/zero/multi/overlap/unavailable) | `AvailabilityManagementTest`, `MembershipAvailabilityIntegrationTest` |
| Fixed / per-day / hybrid allocators | `AllocationEngineTest` |
| Rule versioning & spanning rejection | `AllocationRuleVersioningTest` |
| Expense confirm + stored allocations | `ExpenseConfirmationTest` |
| Monthly balances, close/reopen | `MonthlySettlementTest`, `AugustHouseholdScenarioTest` |
| Settlement transfers | `SettlementServiceTest`, `SettlementMultiPartyTest`, `SettlementPlanTest` |
| API + policies | `ApiFlowTest`, `AuthorizationTest` |
| Money / remainder | `MoneyTest`, `MoneyRemainderTest` |

Shared fixture: `Tests\Support\CreatesFamilyHouse` (A/B/C/D August house + standard categories).

## Extensibility

New allocation rule types are added by:

1. Adding an `AllocationRuleType` enum case
2. Implementing `AllocatorInterface`
3. Registering in the allocator factory / engine map

Expense, availability, and settlement pipelines stay unchanged.
