# Atlas 3.0

> **Your money. On autopilot.**

Atlas is a Nigerian AI-powered financial advisor and automation engine. It connects to users' bank accounts via Mono, analyses financial behaviour in real time, generates intelligent insights, and executes automated money rules — saving, sending, investing, paying bills — all without manual intervention.

---

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Architecture Overview](#architecture-overview)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Environment Variables](#environment-variables)
- [Database](#database)
- [Running the Application](#running-the-application)
- [Scheduler](#scheduler)
- [Queue Workers](#queue-workers)
- [API Overview](#api-overview)
- [Key Concepts](#key-concepts)
- [Project Structure](#project-structure)
- [Testing](#testing)
- [Sandbox Mode](#sandbox-mode)
- [Admin Access](#admin-access)
- [External Services](#external-services)

---

## Features

| Module | What it does |
|--------|-------------|
| **Bank Accounts** | Link accounts via Mono Connect, sync balances and transactions |
| **Financial Intelligence** | Detects salary patterns, projects end-of-month cashflow, tracks personal inflation |
| **Advisory Engine** | Generates contextual insights (low balance, spending spike, idle cash, etc.) with smart cooldowns |
| **Rules Engine** | Create automated rules in plain English — triggers (deposit, schedule, balance) + multi-step actions |
| **Execution Engine** | Executes rules with pre-flight checks, fee calculation, DB-transacted step execution, rollback, and receipt generation |
| **NLP Chat** | Conversational AI (Claude) with live financial context injected automatically |
| **Salary Advance** | Lend up to 50% of expected salary; auto-repaid on next salary arrival |
| **Bill Payments** | Airtime, data, electricity, and cable TV via VTpass |
| **Crypto Wallet** | Atlas-managed USDT wallet; funded via `convert_crypto` rule actions |
| **Disputes** | Full dispute lifecycle — open, evidence, admin review, resolution with optional refund |
| **Push Notifications** | Firebase FCM for execution, salary, advance, dispute, and low-balance events |
| **Admin Panel** | Dashboard, user management, dispute resolution, dynamic system settings |

---

## Tech Stack

- **Framework** — Laravel 12 (PHP 8.2+)
- **Auth** — JWT via `php-open-source-saver/jwt-auth`
- **Database** — MySQL 8+ (or MariaDB 10.6+)
- **Cache / Queue** — Redis (recommended) or database driver
- **Open Banking** — [Mono](https://mono.co) Connect + Transactions API
- **AI / NLP** — [Anthropic Claude](https://anthropic.com) (`claude-sonnet-4-5`)
- **Bill Payments** — [VTpass](https://vtpass.com)
- **Push Notifications** — Firebase Cloud Messaging (FCM v1)
- **Savings Rails** — PiggyVest API, Cowrywise API
- **HTTP Client** — Guzzle 7

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                        Atlas API                         │
│                   Laravel 12  /api/*                     │
├──────────────┬──────────────┬───────────────────────────┤
│  Auth + JWT  │  User Routes │  Admin Routes (/admin/*)  │
├──────────────┴──────────────┴───────────────────────────┤
│                     Service Layer                        │
│  FinancialIntelligence  │  AdvisoryEngine               │
│  RulesEngine            │  ExecutionEngine              │
│  SalaryAdvanceService   │  ChatService (Claude)         │
│  BillPaymentService     │  DisputeService               │
│  FcmService             │  LedgerService                │
├─────────────────────────────────────────────────────────┤
│                    Rails / Adapters                      │
│  BankTransferRail  PiggyvestRail  CowrywiseRail         │
│  CryptoRail        BillPaymentRail  VTpassService       │
├─────────────────────────────────────────────────────────┤
│               External Services                          │
│  Mono API  │  Anthropic API  │  VTpass  │  FCM          │
└─────────────────────────────────────────────────────────┘
```

All monetary values are stored and transmitted in **kobo** (1 NGN = 100 kobo). Every API response includes a human-readable formatted equivalent where relevant.

---

## Prerequisites

- PHP 8.2+
- Composer 2+
- MySQL 8+ or MariaDB 10.6+
- Redis (recommended for cache and queues)
- Node.js 18+ (optional — only needed to regenerate the API reference doc)

---

## Installation

```bash
# 1. Clone the repository
git clone https://github.com/your-org/atlas3.git
cd atlas3

# 2. Install PHP dependencies
composer install

# 3. Copy environment file
cp .env.example .env

# 4. Generate application key
php artisan key:generate

# 5. Generate JWT secret
php artisan jwt:secret

# 6. Configure your .env (see Environment Variables below)

# 7. Run migrations and seed system settings
php artisan migrate
php artisan db:seed --class=SystemSettingSeeder

# 8. Link storage
php artisan storage:link
```

---

## Environment Variables

```env
# ── Application ──────────────────────────────────────────
APP_NAME=Atlas
APP_ENV=local           # local | production
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_TIMEZONE=Africa/Lagos

# ── Database ─────────────────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=atlas3
DB_USERNAME=root
DB_PASSWORD=

# ── Cache & Queue ────────────────────────────────────────
CACHE_DRIVER=redis      # redis recommended in production
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# ── JWT ──────────────────────────────────────────────────
JWT_SECRET=             # generated by php artisan jwt:secret
JWT_TTL=60              # access token TTL in minutes
JWT_REFRESH_TTL=20160   # refresh token TTL (14 days)

# ── Mono (Open Banking) ──────────────────────────────────
MONO_SECRET_KEY=        # live_sk_... or test_sk_...
MONO_BASE_URL=https://api.withmono.com
MONO_WEBHOOK_SECRET=    # for verifying webhook signatures

# ── Anthropic (Claude NLP + Chat) ────────────────────────
ANTHROPIC_API_KEY=      # sk-ant-...

# ── VTpass (Bill Payments) ───────────────────────────────
VTPASS_BASE_URL=https://sandbox.vtpass.com/api   # or https://vtpass.com/api
VTPASS_API_KEY=
VTPASS_SECRET_KEY=
VTPASS_PUBLIC_KEY=

# ── Firebase FCM (Push Notifications) ───────────────────
FCM_PROJECT_ID=
FCM_CREDENTIALS_PATH=/path/to/firebase-credentials.json

# ── PiggyVest ────────────────────────────────────────────
PIGGYVEST_API_KEY=

# ── Cowrywise ────────────────────────────────────────────
COWRYWISE_CLIENT_ID=
COWRYWISE_CLIENT_SECRET=
```

---

## Database

Atlas uses 25 migrations. Run them all with:

```bash
php artisan migrate
```

Key tables:

| Table | Purpose |
|-------|---------|
| `users` | Core user accounts with JWT, PIN, admin flag |
| `connected_accounts` | Mono-linked bank accounts |
| `transactions` | Synced transaction history |
| `financial_profiles` | AI-derived salary, cashflow, health score |
| `advisory_insights` | Generated insights with cooldown tracking |
| `rules` | Automation rules |
| `rule_executions` | Execution runs with status and totals |
| `execution_steps` | Individual action steps per execution |
| `ledger_entries` | Immutable financial ledger |
| `receipts` | Execution receipts |
| `disputes` | Dispute lifecycle with evidence timeline |
| `salary_advances` | Advance requests and repayment tracking |
| `bill_payments` | VTpass payment records |
| `atlas_wallets` | USDT wallet balances per network |
| `system_settings` | Dynamic key-value configuration |

---

## Running the Application

```bash
# Development server
php artisan serve

# The API is available at:
# http://localhost:8000/api
```

Health check:
```bash
curl http://localhost:8000/api/health
```

---

## Scheduler

Add one cron entry to your server. Laravel handles all scheduling internally:

```cron
* * * * * cd /path-to-atlas && php artisan schedule:run >> /dev/null 2>&1
```

| Job | Frequency | Description |
|-----|-----------|-------------|
| `atlas:run-rules` | Every minute | Execute due scheduled rules |
| `atlas:sync-balances` | Hourly | Sync balances from Mono |
| `atlas:run-advisory` | Every 6 hours | Generate insights for all active users |
| Profile rebuild | Daily 6am | Rebuild stale financial profiles |
| Key cleanup | Daily midnight | Purge expired tokens and idempotency keys |
| Advance default check | Daily 9am | Mark overdue advances as defaulted |

---

## Queue Workers

Push notifications and advisory jobs run via queued listeners. Start workers with:

```bash
# Run all queues
php artisan queue:work

# Run notification queue specifically
php artisan queue:work --queue=notifications

# In production (supervisor recommended)
php artisan queue:work redis --queue=notifications,default --tries=3 --timeout=60
```

---

## API Overview

All endpoints are prefixed with `/api`. Full documentation is in `Atlas_3.0_API_Reference.docx`.

| Group | Prefix | Auth |
|-------|--------|------|
| Health | `/health` | No |
| Auth | `/auth` | No (login/register) |
| Profile | `/profile` | Yes |
| Accounts | `/accounts` | Yes |
| Transactions | `/transactions` | Yes |
| Financial Profile | `/financial-profile` | Yes |
| Insights | `/insights` | Yes |
| Rules | `/rules` | Yes |
| Executions | `/executions` | Yes |
| Receipts | `/receipts` | Yes |
| Disputes | `/disputes` | Yes |
| Contacts | `/contacts` | Yes |
| Wallet | `/wallet` | Yes |
| Chat | `/chat` | Yes |
| Salary Advance | `/advance` | Yes |
| Bill Payments | `/bills` | Yes |
| Notifications | `/notifications` | Yes |
| Admin | `/admin/*` | Yes + `is_admin` |

**Rate limits:**

| Limiter | Limit |
|---------|-------|
| Auth (login/register) | 10 / min |
| General API (authenticated) | 120 / min |
| Execution triggers | 10 / min |
| Chat messages | 30 / min |
| Bill payments | 20 / min |
| Salary advance requests | 3 / day |

---

## Key Concepts

### Rules

A rule is a **trigger** + one or more **actions**. When the trigger fires, Atlas executes all actions in order, deducts the total from the user's account, charges a small fee, and generates a receipt.

**Trigger types:** `schedule`, `deposit`, `balance`, `manual`

**Action types:** `send_bank`, `save_piggvest`, `save_cowrywise`, `convert_crypto`, `pay_bill`

**NLP parsing** — users can write rules in plain English:
```
POST /api/rules/parse
{ "text": "When salary arrives save 20% to PiggyVest and pay my DSTV" }
```

### Execution Lifecycle

```
pending → running → completed
                 ↘ failed → rolled_back
```

Pre-flight checks (balance, rule validity) run before any money moves. If any step fails mid-execution, completed steps are rolled back in reverse order.

### Financial Health Score

Scored 0–100, recalculated every 6 hours:

| Component | Weight |
|-----------|--------|
| Savings rate ≥ 20% | 30 pts |
| Salary consistency | 25 pts |
| Cashflow stability | 25 pts |
| No projected shortfall | 20 pts |

### Salary Advance Eligibility

- At least 2 months of detected salary history
- Consistency score ≥ 60
- No existing active advance or previous defaults
- Within the 7-day window before expected salary day
- Salary has not yet arrived this month

Maximum advance: `avg_salary × advance_max_percent / 100` (default 50%)

---

## Project Structure

```
app/
├── Console/Commands/          # Artisan commands (run-rules, sync-balances, run-advisory)
├── Enums/                     # ActionType, TriggerType, ExecutionStatus, etc.
├── Events/                    # ExecutionCompleted, SalaryDetected, AdvanceDisbursed, etc.
├── Http/
│   ├── Controllers/
│   │   ├── Admin/             # AdminDashboard, AdminUser, AdminDispute, AdminSettings
│   │   └── Api/               # All user-facing API controllers
│   └── Middleware/            # JWT, Admin, Idempotency, Velocity, Security, Sanitize
├── Listeners/                 # SendPushNotification (handles all events)
├── Models/                    # 20 Eloquent models
├── Providers/                 # AppServiceProvider, EventServiceProvider, RateLimitServiceProvider
└── Services/
    ├── Advisory/              # AdvisoryEngine, InsightGenerators
    ├── Auth/                  # JwtService, TokenService
    ├── Bills/                 # BillPaymentService, VTpassService
    ├── Chat/                  # ChatService (Claude integration)
    ├── Execution/             # ExecutionEngine, StepExecutor, FeeCalculator, Rollback, Scheduler
    ├── Finance/               # SalaryAdvanceService
    ├── Financial/             # FinancialIntelligenceService, CashflowService, InflationTracker
    ├── Ledger/                # LedgerService
    ├── Mono/                  # MonoService, WebhookProcessor
    ├── Notifications/         # FcmService
    └── Rails/                 # BankTransferRail, PiggyvestRail, CowrywiseRail, CryptoRail, BillPaymentRail
```

---

## Testing

```bash
# Run all tests
php artisan test

# Run a specific test file
php artisan test tests/Feature/ExecutionEngineTest.php

# With coverage
php artisan test --coverage
```

In sandbox mode (`APP_ENV=local`), all rails return simulated success responses — no real money moves. See [Sandbox Mode](#sandbox-mode).

---

## Sandbox Mode

When `APP_ENV` is not `production`, all execution rails simulate success:

- `BankTransferRail` — returns a fake NIBSS reference
- `PiggyvestRail` — returns a fake savings reference
- `CowrywiseRail` — returns a fake investment reference
- `CryptoRail` — returns a fake on-chain hash
- `BillPaymentRail` — routes through `VTpassService` which hits `sandbox.vtpass.com`
- `FcmService` — logs notifications to `storage/logs/laravel.log` instead of sending

To confirm sandbox notifications are firing:
```bash
tail -f storage/logs/laravel.log | grep "FCM sandbox"
```

---

## Admin Access

Grant admin access via tinker:

```bash
php artisan tinker
```

```php
// Grant admin to first user
App\Models\User::first()->update(['is_admin' => true]);

// Grant by email
App\Models\User::where('email', 'admin@example.com')->update(['is_admin' => true]);
```

Admin endpoints live under `/api/admin/*` and require a valid bearer token belonging to an admin user.

---

## External Services

| Service | Purpose | Docs |
|---------|---------|------|
| [Mono](https://mono.co/docs) | Bank account linking and transaction data | sandbox available |
| [Anthropic Claude](https://docs.anthropic.com) | NLP rule parsing and chat | pay-per-token |
| [VTpass](https://vtpass.com/documentation) | Airtime, data, electricity, cable | sandbox available |
| [Firebase FCM](https://firebase.google.com/docs/cloud-messaging) | Push notifications | free tier |
| [PiggyVest](https://piggyvest.com) | Automated savings rail | partner API |
| [Cowrywise](https://cowrywise.com) | Investment savings rail | partner API |

---

## License

Proprietary. All rights reserved.
