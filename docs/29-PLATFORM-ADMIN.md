# 29 — Platform Administration and Corporate Web

> Status: **Phase 15 implemented; local closure still requires the approved
> Poppins and Noto Sans Arabic font files.** The application, migrations,
> phase-scoped tests and readiness doctor are the executable authority.

## 1. Host architecture

`APP_URL` is the only base-origin setting. `Kernel\Tenancy\PlatformHosts`
parses its scheme, host and optional port and derives every platform URL:

| Surface | Host/path |
|---|---|
| Corporate | `{APP_URL origin}` |
| Super Admin | `superadmin.{base-host}` |
| Center public site | `{registered-slug}.{base-host}` |
| Center login | `{center-origin}/login` |
| Menu | `{center-origin}/list` |
| Booking | `{center-origin}/booking` |

The local port is preserved. Production is HTTPS and subdomain-only. There is
no `.test` configuration, path-based tenant fallback, second domain variable,
or hardcoded production host.

Center identity is not inferred from the subdomain string alone. The label must
be valid and non-reserved, then resolve through the control-plane `domains`
registry. Corporate and Super Admin hosts never initialize tenant context.
Unknown, malformed, nested, reserved, mismatched and cross-center hosts fail
closed.

`RegisteredCenterAddress` is the presentation boundary used by SADMIN. It
accepts only authoritative domain-registry data and, when valid, returns the
four center links. Missing, legacy, invalid or mismatched records return an
explicit unavailable reason. It never derives a host from a UUID, center name
or arbitrary tenant value.

New registrations require a valid requested slug. Provisioning persists the
same slug on the tenant and creates its authoritative primary domain. Exact
submission retries are idempotent; another registration requesting the same
pending slug receives a validation error.

Production readiness verifies APP_URL/routing agreement, HTTPS, wildcard DNS
and wildcard TLS declarations. Those declarations report configuration state;
operators still verify real DNS and certificates outside the application.

## 2. Platform identity and authorization

Platform users are control-plane identities and authenticate through the
`platform` guard. They do not share center users, roles, sessions or tenant
databases. Every SADMIN route requires its named platform permission, and
mutating actions are audited.

MFA enforcement is ON by default, and an absent setting is read as ON. A user
without a confirmed TOTP secret enters enrollment on first sign-in, receives
one-time recovery codes, and cannot reach protected SADMIN routes until
enrollment succeeds. A configured user must complete the normal challenge.
Neither the development seeder nor any route bypasses MFA.

Enforcement can be switched off in **Settings → Security** only by a holder of
`platform.security.manage`, with a written reason AND the actor's own current
password. Turning it off is audited as `critical`; turning it on as `notice`.
Switching enforcement off never clears anyone's enrolled secret or recovery
codes, so switching it back on restores exactly the previous protection. With
enforcement off the (throttled, audited) password is the whole platform
sign-in; center authentication is not affected by this setting at all.

### Platform staff and roles

`Users` invites, edits, blocks, reactivates, archives and restores platform
staff, resets their MFA and sends password links. An invitation never carries a
password: the user is created with a random, never-shown credential and emailed
a one-time link from the `platform_invitations` broker (72 hours); resets use
the `platform` broker (30 minutes). Links carry no email address.

Guards live in the Actions (`ManagePlatformUsers`, `ManagePlatformRoles`), not
the screens: nobody blocks, archives or re-roles themselves; the last active
holder of `platform.user.manage` cannot be removed (checked inside the
transaction); nobody assigns a role, or creates one, carrying a permission they
do not hold; the system `super_admin` role is never edited, archived or
deleted from the screens. Users and roles are archived, never deleted, while
anything still refers to them; a role can be deleted only when archived and
held by nobody. Archived users hold no permissions.

Role templates are pre-selections only (`support_tickets_only`,
`support_agent`, `billing_agent`, `operations_agent`, `cms_manager`,
`read_only`). After sign-in a user lands on the first page their permissions
allow (`PlatformLanding`), so a ticket-desk role opens on Support.

Local, development and test environments seed exactly one idempotent account:

- URL: `http://superadmin.localhost:8000/login` when
  `APP_URL=http://localhost:8000`
- Email: `admin@meta-style.local`
- Password: `MetaStyle@123456`
- Role: `super_admin`
- MFA: enrollment required on first login

The password is hashed and this default account is never seeded in production.

Center staff authenticate only on their registered center subdomain. Forgot
password and single-use reset links stay on that same authoritative host.
Authentication middleware resolves the tenant before loading credentials, and
server-side session/token bindings must agree with the host.

## 3. Localization

The supported internal locale keys are `en`, `ar` and `ckb`. Kurdish Sorani
remains `ckb` in configuration, storage and translation paths; its visible UI
abbreviation is `KU`, never `CKB`. The reusable switcher reads the central
language registry and shows icon, short label and native name.

Locale precedence is:

1. a valid explicit selection;
2. the persisted session selection;
3. an authenticated user preference when available;
4. negotiated `Accept-Language`;
5. tenant/application default;
6. platform fallback.

An explicit choice is written to the session and therefore survives Livewire
navigation, forms and ordinary redirects. It changes presentation only; it
cannot authorize a route, bind a tenant, or cross the corporate/SADMIN/center
boundary. English is LTR; Arabic and Kurdish Sorani are RTL.

Phase 15 translations are feature-first under `lang/{en,ar,ckb}`. Their nested
shapes must match, every rendered leaf must be a non-empty scalar, and group
keys must never be escaped directly. Registration verification, center-created
and center-password-reset mail use the registration/request locale and preserve
signed or expiring links. No email contains a plaintext password.

## 4. Platform UI

The Rose Gold Luxe shell uses semantic light/dark tokens. The light theme keeps
its cream and warm-neutral direction. Dark mode uses near-black warm neutrals
for the application, sidebar and elevated surfaces; Rose Gold is reserved for
focus, selection and primary actions rather than large fills.

Every main navigation item has one consistent inline SVG icon. On desktop the
sidebar is expanded or icon-only and stores its preference locally. On mobile
it is an off-canvas drawer with scrim, Escape handling, focus containment and
focus restoration. Theme and sidebar preferences are applied before paint to
avoid navigation flashes. Motion-sensitive transitions respect reduced-motion
preferences.

The top bar contains the drawer/sidebar control, page context, reusable
language switcher, theme control, alert bell and platform-user menu. The bell
opens a popover; it does not navigate. It shows a per-user unread count and
recent alerts without marking everything read. `View All` is the navigation to
the full alert view.

The approved Poppins (Regular, SemiBold) and Noto Sans Arabic (Light, Medium)
files are self-hosted under `resources/fonts` and declared with weight ranges
in `resources/css/app.css`, so no weight is synthesised.

Theme and sidebar preferences are mirrored into two unencrypted, non-secret
cookies (`metastyle-theme`, `metastyle-sidebar`) so the server renders the
right `<html>` attributes; Livewire navigation keeps them on every swap. This
removes the dark-to-light flash between pages.

The bell polls every 20 seconds while visible (no websocket dependency),
shows an unread badge and a dropdown that never navigates on its own, and plays
a short Web Audio chime for a new alert at most once every 10 seconds. The
chime can be muted per browser, from the bell or the account page. What a user
sees is filtered by permission (`PlatformAlertFeed`): each alert source maps to
the permission that may see it (`PlatformPreferences::EVENTS`).

## 5. Control-plane products

SADMIN provides permission-gated functional surfaces for centers, plans,
subscriptions, scheduled changes, pricing snapshots, lifecycle history,
entitlement overrides, usage limits, manual SaaS billing, support, landing CMS,
operations/readiness, alerts, announcements, currencies, platform users and
roles, audit and platform settings.

### Centers

A Super Admin creates a center through `CreateCenterForPlatform`, which writes
a `source = platform` registration and dispatches the SAME
`ProvisionRegisteredTenant` pipeline a self-registration uses. Nobody chooses
the owner's password: the bootstrap credential is random and destroyed when
provisioning ends (ADR-031), and the owner is emailed a 72-hour set-up link.
The commercial choices (plan, cycle, trial or paid, currency, languages,
timezone, feature grants/revokes) ride on `registrations.options` and are
applied by the pipeline, grants/revokes as audited platform overrides.

Center detail has Overview, People, Subscription, Entitlements, Usage,
Billing, Domains, Lifecycle, Support and Audit tabs. People are read from the
center's own database through the tenant context; a person can be blocked or
reactivated (the owner never) and sent an access link. A center's currency is
locked once it has sales or payments. Archiving is a lifecycle status with
`archived_at`: invoices, payments, subscription history and audit are never
deleted, and an archived center can be restored.

### Plans, subscriptions and currencies

A plan carries a monthly and/or a yearly price in its own currency
(`monthly_price_minor`, `yearly_price_minor`); `Plan::priceFor()` falls back to
the legacy single price for its billing period, so no backfill is needed. A
yearly saving is displayed only when the yearly price is below twelve monthly
payments (`yearlySavingPercent()`).

Subscription changes take effect immediately (`ManageSubscription`): change
plan, change cycle, activate a trial as paid, extend a trial, move the renewal
date. Each runs under a row lock and writes one append-only
`subscription_history` row, an audit entry and a platform alert. A cycle the
plan is not sold on is refused.

`platform_currencies` is the currency catalog (code, name in three languages,
symbol, decimals, enabled, default). The default cannot be disabled; a
currency's decimal places cannot change once plans, invoices or centers use
it. Every change is audited. Money stays integer minor units everywhere.

### SaaS billing corrections

An invoice snapshots its plan, cycle, period, currency, discount and optional
reference. Mistakes are corrected, never deleted: `CorrectSaasBilling` voids an
unpaid invoice (reason required) or reverses a recorded settlement, which
reopens the invoice balance. Payment methods are cash, bank transfer, manual
electronic and other.

### SaaS billing documents (ADR-079)

Every SaaS invoice can be printed or downloaded as a PDF from the Billing list,
the invoice drawer and Center → Billing (`platform.billing.manage`). The print
view (`/billing/invoices/{uuid}/print`, `?print=1` opens the print dialog) and
the PDF (`…/pdf`) are the same Blade document rendered by mPDF on the server:
A4 or Letter, always light, no admin navigation, EN/AR/KU with RTL for Arabic
and Kurdish, and a screen-only toolbar (Print, PDF, EN/AR/KU). Rendering never
writes anything.

Settings → Invoice templates holds the billing identity (company name and
address per language, phone, email, website, tax number), header, footer,
payment instructions and standard notes in three languages, a layout (classic,
modern, compact), one of five named accents, logo size and alignment, paper,
and show/hide toggles. Values are plain text and fixed choices only; a live
preview renders the real template with sample data in any language before
saving. The logo is the platform logo from Branding.

Historical safety: amounts come only from the invoice and payment rows; the
issuer block is frozen on each invoice when it is issued
(`issuer_snapshot`); layout, colours and texts are presentation and follow the
current template.

Center → Billing shows plan, cycle, center currency, outstanding per currency,
recent invoices (with Print/PDF), recent settlements and the account statement:
This month, Last month, This year or a custom range (at most five years),
filtered by invoice status, payment method or currency. The statement is SaaS
billing only, grouped per currency and never summed across currencies; opening
and running balances appear only when unfiltered. It prints and downloads like
an invoice (`/centers/{tenant}/statement/print|pdf`).

### Announcements and platform notifications

`SendPlatformAnnouncement` sends an EN/AR/KU announcement to all, selected or
filtered (status, plan) provisioned centers. One queued
`DeliverPlatformAnnouncement` job per center raises the
`PlatformAnnouncementPublished` event inside that center's tenant context; the
Notifications module delivers it to staff holding `platform_support.view` as
`platform_announcement`, or `platform_notice` when important. Nothing imports
Notifications (ADR-067).

`PlatformNotifier` writes platform alerts for support tickets, center
registration and provisioning failures, subscription changes and billing,
localized at write time, and optionally emails holders of the matching
permission per the notification rules in Settings.

### Support from the center side (Manager)

`/manager/support` (`platform_support.view`; writes need `.manage`) lists the
center's own tickets with a status filter and pagination; `/manager/support/{uuid}`
shows one conversation. Every read goes through `CenterSupportDesk` and every
write through a center Action (`OpenCenterSupportTicket`,
`ReplyToCenterSupportTicket`, `CloseCenterSupportTicket`,
`ReopenCenterSupportTicket`), all scoped by the BOUND tenant: another center's
ticket, message or file is not found. Internal platform notes never reach the
center. Close and reopen lock the ticket and append a `support_ticket_history`
row (`closed_by_center` / `reopened_by_center`, actor type `center`).

Center attachments (PDF, PNG, JPEG, text; 5 MB, three per message; type from
the file's content) are stored on the control-plane `platform_support` disk,
which is deliberately not tenant-suffixed, so the platform team reads the same
file. Downloads are attachments with `nosniff`. Files the Super Admin reply
form stores on the `local` disk are not reachable from a center host (there
`local` is tenant-rooted), so the center lists only `platform_support` files;
moving the Super Admin uploads to `platform_support` closes that gap.

### Settings and secrets

Settings has General, Commercial, Security, Email, Notifications and
Localization tabs (`platform.settings.manage`), plus three sections that are
their own pages with their own permission: Branding
(`platform.branding.manage`), AI (`platform.ai.manage`) and Invoice templates
(`platform.billing.manage`). The shell's Settings entry opens the first
section a person may use. It is not an environment editor: SMTP and other
environment secrets stay in the environment and are only reported as
configured or not; a test email can be sent.

The OpenAI key can be set, replaced, tested and removed by a holder of
`platform.security.manage` (on the AI page). It is stored encrypted in
`platform_provider_credentials`, never rendered, logged or audited; only its
last four characters are shown. The RAYAN OpenAI adapter reads it through
`AiProviderSettings`, falling back to the environment key when none is stored.

AI models (ADR-082): the assistant model and an optional report-analysis model
accept any valid identifier — older or cheaper models included. "Refresh
models" loads the provider's own catalog with the platform key (identifiers,
dates, owners; no prices), searchable and usable with one click; when it cannot
be loaded, an identifier can be typed and is marked "not in the last provider
list". The platform's chosen models are added to the list a center may pick
from; a center still cannot name an arbitrary model.

### Platform branding (ADR-080)

Settings → Branding manages the platform logo for light and dark backgrounds
(PNG/JPEG), whether the platform name shows beside it, the favicon (PNG/ICO,
square) and the platform colours: page background, surfaces, primary,
secondary, accent, text, muted text, borders and the four status colours, for
the light and the dark theme, plus four gradients (brand band, hero, accent
icons, buttons) as two or three colours and a direction. A live preview shows
both themes with buttons, a card, a form field, badges and gradients, and lists
contrast warnings without changing any colour. Rose Gold Luxe is the default
and emits no overrides; "Reset to Meta Style defaults" is confirmed.

The logo, favicon and theme apply to the corporate website, the Super Admin and
its sign-in pages, and SaaS documents — never to a center's pages. Nothing is
CSS: values are validated colours and angles turned into the semantic tokens.

### Center users and the phone rule (ADR-081)

Centers → Center users lists every center's owners, managers and staff from a
control-plane directory refreshed on provisioning, after each platform change
and every 15 minutes (`metastyle:center-users:project`). Filters: name, email
or phone search (any spelling of the number), center, type (owner, manager,
staff), role, branch (within one center), active or blocked, phone missing,
creation date, and sorting. A row opens a drawer with the account details,
recent platform actions and, with `platform.center_user.manage`: edit name,
email and phone, block or reactivate (reason required; never the owner),
and send a one-time access link. Viewing needs `platform.center_user.view`.
Platform users (people operating Meta Style) are a separate page.

Every center user account has a phone. The owner's phone is required on
self-registration, on the registration API and when a Super Admin creates a
center, and it reaches the owner account; a staff login is refused without one
(Manager form, tenant API, `CreateEmployee`). The phone field is one component:
a searchable country list with local flags (Iraq +964 by default) and the
national number, stored as E.164. Legacy accounts without a phone show
"Phone missing" and are completed from the drawer; no number is invented.

### Corporate website and landing CMS

The corporate site renders the PUBLISHED landing page; `/cms/preview` renders
the saved draft (platform auth, MFA and `platform.cms.manage`) in any language
at desktop, tablet or mobile width. The CMS edits the header, top menu, hero
(split, centered or cover; colour, gradient, image or video background with
overlay), an ordered list of typed sections (features, modules, steps,
benefits, text and media, pricing, comparison, FAQ, call to action, contact),
the footer and SEO. Sections can be added, duplicated, hidden, reordered and
removed; each save is an immutable revision that can be loaded again.

`LandingContent` is an allow-list: plain text in three languages, safe links
only (section anchors, local paths, http(s)), platform-uploaded JPG/PNG/WebP
images and MP4/WebM video only (no SVG, no executable files), and presentation
choices from fixed lists. Revisions saved before the default copy was
translated get the default Arabic and Kurdish only where the English is still
exactly the default. Pricing and plan comparison are never stored in the CMS:
`LandingPlans` reads the live public plans at render time, with a
Monthly | Yearly toggle when both cycles are sold. The cycle a visitor picks is
passed to registration and becomes the new center's billing cycle; it never
skips the trial.

Standard Reports remain on Primary. Advanced Reports remain a distinct paid
product on Reporting only and never fall back to Primary. Missing reporting
credentials are a warning while Advanced Reports are commercially inactive and
a failure once activated. Advanced report analysis belongs to
`reports_advanced`, consumes `advanced_report_ai_runs`, shares provider/token
metering, and does not require or consume the customer-facing `rayan_ai` /
`ai_runs` product.

## 6. Operations and verification

Timestamps: on MariaDB with `explicit_defaults_for_timestamp` off, the first
non-nullable TIMESTAMP of a table used to be rewritten on every update
(`saas_invoices.issued_at`, `saas_payments.received_at`,
`tenant_operations.started_at`, …). Migrations
`2026_09_23_100015` (control) and `2026_09_23_160001` (tenant) remove that
implicit ON UPDATE and keep every other column property; no data is changed.

`metastyle:doctor` is read-only. Its SADMIN view localizes each check name and
purpose while retaining exact technical details for operators. It reports the
reporting-connection limitation honestly and never fabricates a replica.

Phase 15 local closure uses focused/affected tests throughout, then exactly:

```text
composer check:phase15
php artisan metastyle:doctor
```

The phase gate includes architecture, unit, Phase 15, SaaS, localization,
readiness, authentication-throttle and critical tenant-isolation suites. The
global `composer check` is reserved for final release/pre-production work and
is not part of Phase 15 local closure.

Live OpenAI and Meta Cloud API credential checks remain external deployment
verification. They do not alter the deterministic adapter contract tests.
