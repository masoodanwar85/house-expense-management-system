# House Expense Management System

API-first Laravel app for **rule-driven household accounting**.

Expenses are allocated by **versioned category rules** (`per_day`, `fixed`, `hybrid`), using member availability. Allocations are stored at confirmation time. Month-end settlement uses those stored amounts — it does not re-run the allocation engine.

## Stack

- Laravel 13 (12+ compatible)
- PHP 8.3+
- MySQL 8+ (SQLite for local/tests)
- Laravel Sanctum
- Blade + Livewire 4
- Service layer under `app/Services`

## Core model

1. Configure **categories** with **allocation rules** (versioned).
2. Members record **availability** (available / unavailable periods).
3. Members create expenses as **draft**, then **confirm** → allocations persist with the rule version used.
4. Month summary: `balance = actual_paid − responsibility`.
5. **Settlement** generates minimal debtor → creditor transfers.
6. Owner may **close** a month (locks edits) or **reopen**.

### Balance

```
actual_paid     = SUM(confirmed expenses paid by user)   // by expense_date month
responsibility  = SUM(stored expense_allocations)
balance         = actual_paid − responsibility
```

- Positive → creditor (should receive)
- Negative → debtor (owes)
- Zero → settled

### Rule types

| Type | Behavior |
|------|----------|
| `per_day` | Split by available person-days in the expense coverage period |
| `fixed` | Equal split among `all_members` / `active_members` / `full_period_members` |
| `hybrid` | Mix of fixed + per_day via `%` split, or fixed **amount** + remainder per day |

Architecture decisions and safe defaults: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).  
Database tables and relationships: [`docs/DATABASE.md`](docs/DATABASE.md).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

**MySQL** (default in `.env.example`):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=house_expenses
DB_USERNAME=root
DB_PASSWORD=
```

**SQLite** (local/quick start):

```bash
touch database/database.sqlite
# set DB_CONNECTION=sqlite in .env
```

```bash
php artisan migrate:fresh --seed
php artisan serve
```

Open `http://localhost:8000` — register/login, or use demo users below.

### Demo logins

Password for all: `password`

| Email | Role |
|-------|------|
| `masood@example.com` | Owner (Family House) |
| `maqsood@example.com` | Member |
| `munawar@example.com` | Member |
| `fakhar@example.com` | Member |

Seed data includes August 2026 availability, five categories with rules, and confirmed sample expenses.

## Architecture

```
app/Services/
  House/           HouseService, HouseMemberService, HouseAccessService
  Availability/    AvailabilityService, AvailableDaysCalculator
  Expense/         ExpenseService, ExpenseCategoryService
  Allocation/      AllocationEngine, rule resolvers & allocators
  Monthly/         MonthlySettlementService, MonthLockService
  Settlement/      SettlementService (transfer generation)

app/Policies/      HousePolicy, ExpensePolicy, ExpenseCategoryPolicy
app/Http/          API Controllers, Form Requests, Resources
```

Business logic lives in services. Controllers authorize and delegate. Controllers do not allocate money.

## API (`/api/v1`)

Auth: `Authorization: Bearer {token}`  
Envelope: `{ "success": true, "data": ... }` or `{ "success": false, "message": "...", "errors": {} }`

| Method | Path | Description |
|--------|------|-------------|
| POST | `/auth/register` | Register + token |
| POST | `/auth/login` | Login + token |
| POST | `/auth/logout` | Revoke token |
| GET | `/auth/me` | Current user |
| GET/POST | `/houses` | List / create |
| GET/PUT | `/houses/{house}` | Show / update |
| GET/POST | `/houses/{house}/members` | List / add member |
| POST | `/houses/{house}/members/{user}/leave` | Leave / remove |
| GET/POST | `/houses/{house}/members/{user}/availability` | List / add period |
| GET/POST | `/houses/{house}/categories` | List / create category |
| PUT | `/categories/{category}` | Update category |
| GET/POST | `/categories/{category}/rules` | List / create rule |
| POST | `/categories/{category}/rules/versions` | New rule version |
| GET/POST | `/houses/{house}/expenses` | List / create draft |
| GET/PUT | `/expenses/{expense}` | Show / update |
| DELETE | `/expenses/{expense}` | Cancel (owner) |
| POST | `/expenses/{expense}/confirm` | Confirm + allocate |
| POST | `/expenses/{expense}/reinstate` | Cancelled → draft (owner) |
| GET | `/expenses/{expense}/allocations` | Stored allocations |
| GET | `/houses/{house}/settlement?month=2026-08` | Balances + transfers |
| GET | `/houses/{house}/months/2026-08` | Month summary + transfers |
| POST | `/houses/{house}/months/2026-08/close` | Close month (owner) |
| POST | `/houses/{house}/months/2026-08/reopen` | Reopen month (owner) |

### Auth example

```bash
curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"email":"masood@example.com","password":"password","device_name":"cli"}'
```

### Confirm expense

```bash
curl -s -X POST http://localhost:8000/api/v1/expenses/1/confirm \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json'
```

### Settlement

```bash
curl -s "http://localhost:8000/api/v1/houses/1/settlement?month=2026-08" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json'
```

## Canonical August example

Availability: A = 10 days, B/C/D = 30 days.

**Electricity ₹20,000** — hybrid 10% fixed + 90% per_day:

| User | Fixed | Per day | Total |
|------|-------|---------|-------|
| A | 500 | 1,800 | **2,300** |
| B | 500 | 5,400 | **5,900** |
| C | 500 | 5,400 | **5,900** |
| D | 500 | 5,400 | **5,900** |

**Water ₹10,000** — 100% per_day → A 1,000 / B,C,D 3,000 each.  
**Security ₹4,000** — fixed among available members only (0-day / unavailable members pay `0`).

## Web UI

- Home, register, login
- Dashboard: house + month picker
- Overview: balances, transfers, close/reopen
- Expenses: draft, confirm, cancel
- Availability: periods
- Manage: members, categories + rules (owner)

## Testing

```bash
composer test            # full Unit + Feature suite
composer test:domain     # financial/domain subset
```

Coverage includes availability edge cases, all rule types, rule versioning, confirmation persistence, monthly close/reopen, settlement transfers, API envelopes, and policies. See `docs/ARCHITECTURE.md` for the matrix.

## Important rules

**Do**
- Store allocations at expense confirmation
- Use versioned rules (never mutate historical rule rows)
- Lock closed months against normal edits
- Keep money math in services (`App\Support\Money` / BCMath)

**Do not**
- Recalculate allocations from current rules at settlement time
- Put allocation logic in controllers
- Silently invent financial behavior when a rule is missing — fail with a domain error
