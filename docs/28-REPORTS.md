# 28 — Reports and Advanced Reports

Phase 14. Two paid, read-only products over existing source-module facts.

## 1. Products and authorization

`reports_standard` owns Standard Reports. `reports_advanced` requires
`reports_standard` and owns Advanced Reports, including contextual RAYAN report
analysis. There is no separate report-AI entitlement and `rayan_ai` is not
required.

Every report also requires `report.view`, every CSV export requires
`report.export`, and the caller must hold every applicable source-domain
permission. `BranchScope` is intersected inside `ReportRequestFactory`; a branch
uuid supplied by a client can narrow scope and can never widen it.

## 2. Read targets

Standard Reports use `ReadTarget::Primary`. Advanced Reports use
`ReadTarget::Reporting`, always. `ReportConnection` lazily builds
`tenant_reporting` from `reporting_template` and the currently bound tenant
database name. Advanced execution never falls back to Primary.

Replica configuration is optional while Advanced Reports are commercially
inactive, so application boot and Standard Reports remain available without it.
`REPORTS_ADVANCED_ENABLED=true` makes missing reporting credentials a fatal
production-readiness result. Per-tenant Advanced execution fails closed with a
clear unavailable response whenever Reporting cannot be reached.

## 3. Time, currency, and source truth

The UI accepts inclusive local dates. Each accessible branch produces its own
half-open UTC window `[local midnight, next local midnight)`, using the branch's
current timezone. Facts are read through public contracts owned by Booking,
Journey, Queue, Sales, Payments, Finance, Customers, Reviews, Loyalty and
Branches; the report modules do not write their tables.

Money stays partitioned by currency. A single money KPI is shown only when the
result has one currency; mixed-currency data is never added into a fictional
total. Outstanding is the current unpaid balance of non-voided invoices issued
in the selected period, using all successful settlements for those invoices.
Refunds do not reopen an invoice. Employee delivery uses the actual
`JourneyStage.employee_id`, never merely the employee booked in advance.

## 4. Catalog

Standard Reports:

- business overview, booking activity, visit and service delivery;
- employee delivery, queue operations, sales and payments;
- finance movements, customer activity, review summary, benefit usage.

Advanced Reports:

- period and branch comparison, service and employee analysis;
- booking funnel, customer cohorts, observed retention, anonymized customer value;
- queue trends and benefit trends.

Every metric carries a definition, coverage, an `as_of` timestamp, and explicit
unavailable metrics. Phase 14 does not invent profitability, margin, queue
abandonment, predictive retention, forecasts, occupancy, commission, or
employee-attributed revenue where authoritative facts do not exist. There are
no analytical aggregate or materialized tables.

## 5. RAYAN report analysis and usage

The Advanced page recomputes the authorized report server-side and passes only
its structured context: report identity and glossary, periods, filters, branch
summary, currency, KPIs, series, dimensions, coverage, unavailable metrics and
`as_of`. The analyst receives no database tools and cannot write or execute
domain actions.

`advanced_report_ai_runs` is a separate enforced commercial allowance.
Customer-conversation RAYAN continues to consume `ai_runs`; neither product can
exhaust the other's run allowance. Both share the provider adapter and meter
actual `ai_input_tokens`, `ai_output_tokens`, and `ai_failed_runs`. Report runs
are tagged `advanced_report_analysis` and appear separately on `/center/usage`.

## 6. Surfaces and exports

The manager has separate Standard and Advanced navigation entries. Both have
responsive filters, KPI cards, accessible charts with table alternatives,
detail tables, empty/error/locked states and browser-print styles. Advanced has
a distinct Pro treatment and an accessible RAYAN dialog drawer on every report.

Exports are synchronous streamed CSV from the same authorized application
result used by the page/API. They are audited, formula-injection guarded and
row-capped. They live on the center host (`/manager/reports/{report}/export.csv`,
`/manager/advanced-reports/{report}/export.csv`); like every controller on that
host, the export controllers take the `{center}` host segment as their first
route parameter before `{report}`. Browser print and CSV are the only Phase 14 formats: no Excel or PDF
dependency, scheduled delivery, storage pipeline, or signed download URL is
claimed.

## 7. Operations

Configure the reporting endpoint with `DB_REPORTING_HOST`,
`DB_REPORTING_PORT`, `DB_REPORTING_USERNAME`, `DB_REPORTING_PASSWORD`, and
optionally `DB_REPORTING_SSL_CA`. Never set a database name: the bound tenant's
database is inserted at runtime. Set `REPORTS_ADVANCED_ENABLED=true` only when
the paid product may be enabled in production, then run:

```bash
php artisan metastyle:doctor --production
php artisan metastyle:roles:sync --all
composer check:phase14
```

## 8. Manager surfaces (Phase 15)

**Overview (`/manager`).** A summary of the period, never a second Reports
page. `ManagerOverview` builds every PERIOD figure from the same report read
contracts and `ReportRequestFactory` as the Standard Reports (current range and
its like-for-like previous range). A section exists only with its permission
AND entitlement: bookings `appointment.view` + `booking` (never `view_own` —
the reader has no own-scope), visits `journey.view`, sales `sale.view` + `pos`,
payments `payment.view` + `pos`, customers `customer.view` + `journey.view`,
queue `queue.view` + `queue_management`, loyalty / packages / memberships their
view permission + entitlement. The average ticket is billed value over the
NON-VOIDED invoices of the same currency (the Standard Reports' definition);
one lead currency is charted and any other is disclosed, never added in. A
one-day range is drawn in 24 hourly buckets (readers emit `hourly`), up to two
months in days, beyond that in months; the previous series is aligned position
by position.

The NOW cards are separate reads, each a module's own `Dashboard*` class,
branch-scoped in SQL and never cached: today's bookings and the next days
(`view_own` respected), the queue on each branch's CURRENT business date only
(a ticket left open from an earlier day is not "waiting now"), visits in
progress today, the latest invoices (`sale.view` + `pos`), the newest customers
(center-wide, like the directory; names only), and the team — "employees with
bookings today", which is never presented as attendance. There is no activity
feed: `audit_logs` has no branch, so it cannot be scoped for a branch manager.
The period figures are cached for 60 seconds per tenant, viewer, branch scope,
gates, range, branch and locale; "Refresh" drops the key. The plan chip reads
`CurrentSubscription` (translated status, plan name, trial days left) for
`settings.view`, linking to `center.plan`; a past-due, suspended or ended
subscription is the shell's account-wide banner, which the overview does not
repeat. Features the viewer would use but the plan lacks are one compact
upsell line each, never a zero.

**Standard Reports** (`/manager/reports/{report}`) is an analytics workspace:
one tab per catalog report (Overview, Sales, Bookings, Services, Team,
Customers, Loyalty, Reviews, Queue, Finance — the viewer's permitted ones, in
that order), each laid out KPIs → trends → distribution → comparison → peak
times → detail tables.

- *Period.* `ReportPeriod` (Reports module): today → yesterday, last 7 days →
  the 7 before, this month → the same days of last month, last month → the
  whole month before, this year → the same days of last year, custom → the
  equal preceding range. Every KPI shows current, previous, the absolute
  difference and a semantic change (`App\View\Charts\Change`: lower
  cancellation / no-show / refunds / outstanding / waits is good; no
  percentage from a zero base). A comparison whose length crosses the
  day/month bucket grain (this year across 29 February) is re-bucketed into
  the current period's grain before it is aligned. "Upcoming" bookings appear
  only while the period is still running.
- *Filters.* Branch (narrows only) plus what the report's readers support:
  bookings `source`, `status`, `employee`, `service`, `category` (the reserved
  lines' menu category); services, team and queue `employee`, `service`.
  Journey and Queue never read menu categories (ADR-037): "services by
  category" groups the performed services through the Catalog's
  `ReportServiceCategories` contract. Sales, finance, customers, reviews and
  loyalty take the branch only — invoice lines are never filtered by performer. `filtersFor()` applies the same set on the page and in CSV; the
  export audit records only the filters applied.
- *Facts, not Livewire.* `Reports\Application\Analytics\StandardAnalytics`
  authorizes exactly like `StandardReports::authorize()`, builds the current
  and previous `ReportReadRequest`, asks each reader at most once per period
  (`AnalyticsReads`) and returns facts (units, series on `DateRange::buckets`,
  dimensions with previous values). Rates share one denominator, bookings with
  an outcome (completed + cancelled + no-show), so they add up to 100 %. A
  cross-domain part appears only with its permission (most booked needs
  `appointment.view`, billed services `sale.view`, ratings `review.view`) and
  never beside a filter its source cannot apply. The loyalty tile needs the
  `loyalty` entitlement or history.
- *Presentation.* `App\View\Reports\StandardReportView` + `Standard\*Layouts`
  choose the shared chart components (docs/31) and drop any card that would
  draw nothing; a period without activity is one empty state ("No report data
  for this period."). Averages (employee / service ratings) are never summed
  into an "Other" bar — the chart names the best eight, the table lists all;
  the 1–5 star distribution keeps its empty steps. Detail tables sort and page in the browser
  (`resources/js/manager/reports.js`). Figures are cached 60 s per tenant,
  viewer, scope, permissions, report, both periods, branch, filters and locale
  (`ReportsData`, authorization re-checked on every read); Refresh drops the
  key. Tab switches show skeletons; refetches dim.
- *Reader facts added for this page* (additive keys; API/CSV shapes unchanged
  except the new `quantity` column on sales items):
  bookings `daily_status`, `hourly_status`, `heat`, `upcoming`, `services`,
  `employees` (reserved lines); journeys `stages.daily/hourly` (stages are
  read through a subquery of the windowed visits, never a bound id list);
  sales `branches`, `branch_invoices`; customers `frequency`, `top` (names
  only); queue `heat`; reviews `daily`, `employees`, `services`; finance
  `daily`; loyalty `daily_points`.

`report.view` missing is its own state, a report whose source data the viewer
may not see is refused with a link to one they can open, and a center without
`reports_standard` gets the upgrade page with no data loaded. The domain keeps
English labels for the API, CSV and RAYAN; the page translates by key
(`lang/*/manager_reports.php`, `std.*`). Print hides the Manager shell and
shows every table row. `App\View\Reports\ReportPresenter` (old name
`App\Livewire\Center\Reports\ReportPresenter` kept as an alias) still formats a
`ReportResult` by column type.

**Advanced Reports (`/manager/advanced-reports`).** A paid analytics
workspace, locked without `reports_advanced` (the shared `FeatureOffer`
upgrade page, no data read) and refused without `report.view`. The page
(`App\Livewire\Center\AdvancedReports`) owns one toolbar — presets today,
yesterday, last 7/30/90 days, this/last month, this quarter, this year, custom
(≤ 366 days, never past today); comparison **previous period** (DateRange
semantics: month/quarter/year presets against the same days of the previous
one) or **same period last year**; branch (on a phone the comparison and
branch fold behind a "Filters" toggle with an active count) — and three views, each its own lazy
Livewire component with reactive props (skeleton on first load, dimmed
refetch, an AI question never re-reads the figures):

- *Insights* (`Workspace`): `AdvancedWorkspace` reads every permitted section
  (`SectionFacts`: bookings, visits, sales, payments, customers, queue, reviews,
  benefits — each needs its source permissions) ONCE for the period and once for
  the comparison period, reporting target only. Presented by
  `App\View\AdvancedReports\*`: executive summary KPIs with semantic deltas and
  sparklines, the strongest judged movements (arithmetic, not AI), business
  trends with the comparison overlay, and sales / booking / customer / service /
  employee / queue / retention sections — a section with no facts in either
  period is not drawn; nothing at all is one empty state. Money is one lead
  currency; billed or collected money in any other currency is disclosed
  under the summary and the sales section (and in Compare), never added.
  Employee figures are actual-performer work only (no employee-attributed
  revenue); "most booked services / employees" are the RESERVED lines (demand),
  labelled apart from performed work; bookings by status over time is the
  reader's `daily_status` / `hourly_status` stacked on the period's buckets. Completion, cancellation and no-show
  rates share the Standard denominator — bookings that reached an outcome
  (completed + cancelled + no-show) — so open bookings never dilute "today".
  Employees, services and booking lines are matched to their comparison value
  by reader id, never by name; loyalty points are split by kind AND direction.
  *Focus* (`Workspace::$focus`, `employee:<uuid>` / `service:<uuid>`, only for
  `journey.view`, checked against the viewer's own options on every render)
  narrows the whole view through `AdvancedWorkspace::build(..., $focus)`: only
  bookings, visits and queue are read (the readers' own filters); money,
  customers, reviews and benefits are not attributed to one employee or
  service and are not read. Detail tables (employee table, report library)
  sort and page in the browser (`advancedTable`, advanced-reports.js) from
  each cell's raw value.
- *Compare* (`Compare`): current vs comparison period (every metric: value,
  comparison, difference, change) and 2–4 picked branches, employees or
  services (`AdvancedComparison`, narrowed from the one authorized request: a
  window it already holds, or the readers' `employee` / `service` filter; a
  pick outside the viewer's options or scope is dropped). KPI table against the
  first pick, grouped bars per unit (a metric one entity cannot have — a rate
  with no denominator — is left out of the bars and shown as "—" in the
  table), trend overlays (`x-chart.multiline`), work mix. Branch comparison
  ignores the branch filter; it compares every branch in scope.
- *Reports* (`Library`): the ten catalog reports (the API/CSV/RAYAN identity)
  with the page's comparison period — `AdvancedReports::run()` takes an
  optional comparison request (dates only; branches always the current
  request's). CSV export accepts `compare_from` / `compare_to` and audits them.

*AI Insights* (`AiInsights`, not lazy): a structured analysis (executive
summary, key changes, potential issues, strongest trends, areas to review —
the model's JSON reply is allow-listed by heading and rendered as plain text;
the asked-for object with every heading empty is a quiet "nothing stands out"
state, anything that is not that object becomes the summary) and the RAYAN drawer for questions, both on
the Phase 14 structured context of one permitted catalog report (the page's
comparison period included), spending `advanced_report_ai_runs`, throttled like
`throttle:report-analysis` (10 a minute per center and person) and answered in
the viewer's language (Kurdish named as Sorani). `ReportAnalyst::available()`
false (no provider key, the local case) shows only "AI analysis is currently
unavailable." — no button, no configuration detail, and every other view
works. A provider failure is a translated state; no run is opened while
unavailable.

