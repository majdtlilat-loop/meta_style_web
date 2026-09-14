# Meta Style

Multi-tenant SaaS operating platform for barbershops, hair salons, beauty
centers, laser centers, spas, hammams and similar appointment- and
service-based businesses.

This repository is the canonical home of the Laravel backend, Meta Style Web
(center administration, host/reception, POS) and the REST APIs. Flutter clients
live in their own repositories.

**Status: Phases 0–9 complete** — tenancy, identity and roles, entitlements,
catalog, customers, booking, service journeys and resources, the queue, and
sales with the POS till, immutable invoices, cashier shifts and printing.
No payments or finance yet. See [`docs/13-ROADMAP.md`](docs/13-ROADMAP.md).

## Stack

| | |
|---|---|
| Framework | Laravel 12 |
| Language | PHP `^8.2` |
| Frontend | Blade + Livewire 3 |
| Database | MySQL 8 (production/CI), MySQL or MariaDB 10.4+ (local) |
| Tests | Pest 3 against a real MySQL-compatible server |
| Analysis | PHPStan + Larastan · Laravel Pint |

Multi-tenancy is **one database per tenant**, plus one control-plane database.

## Getting started

Requirements: PHP `^8.2` with `pdo_mysql`, Composer 2, and a running MySQL 8 or
MariaDB 10.4+ server. No Docker, Redis, or Node required.

```bash
composer install
composer setup
```

Then create the control database and migrate it:

```bash
mysql -u root -e "CREATE DATABASE meta_style_control CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

```bash
php artisan metastyle:control:migrate
```

```bash
php artisan serve
```

## Commands

```bash
composer check
```

Runs formatting, static analysis, and the full test suite — run this before
finishing any change.

| Command | Does |
|---|---|
| `composer check` | `lint` + `stan` + `test` |
| `composer fix` | Apply Pint formatting |
| `composer test` | Pest |
| `composer stan` | PHPStan / Larastan |
| `php artisan metastyle:control:migrate` | Control-plane migrations |

Tests create and drop their own databases, all prefixed `meta_style_test_`.
They need a running MySQL/MariaDB server and will not touch anything outside
that prefix.

## Migrations

Two sets, never mixed:

```
database/migrations/control/   → the control-plane database
database/migrations/tenant/    → every tenant database
```

Do not use plain `php artisan migrate` — it targets the default connection and
the default path, which is neither set. See
[`docs/03-DATABASE-MIGRATIONS.md`](docs/03-DATABASE-MIGRATIONS.md).

## Documentation

Architecture, tenancy, entitlements, security and the phase roadmap are in
[`docs/`](docs/), starting with
[`docs/00-PRODUCT-OVERVIEW.md`](docs/00-PRODUCT-OVERVIEW.md).

Working rules for every change are in [`CLAUDE.md`](CLAUDE.md).
Decisions and their trade-offs are in [`docs/DECISIONS.md`](docs/DECISIONS.md).
