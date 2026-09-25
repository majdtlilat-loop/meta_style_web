# Manager — Appearance (menu, booking, cart, print) and Center Settings

Phase 15 Manager area A10. What the center controls about the pages its
customers see and the paper it prints, and its genuinely center-level
settings. Brand and the landing site are the Center Site area (`CenterSite`),
read here only through `CenterSite\Contracts\CenterBrandReader`.

## 1. Pages and routes

| Page | Route | Permission (open / change) | Entitlement |
|---|---|---|---|
| Menu appearance | `center.menu` `/manager/menu` | `menu.view` / `menu.manage` | — (the menu is core) |
| Menu draft preview | `center.appearance.menu.preview` | `menu.view` | — |
| Booking page | `center.appearance.booking` (+ `.preview`) | `appearance.view` / `appearance.manage` | `booking` |
| Cart page | `center.appearance.cart` (+ `.preview`) | `appearance.view` / `appearance.manage` | — |
| Print | `center.appearance.print` | `appearance.view` / `appearance.manage` | `printing` |
| Settings | `center.settings?tab=general\|languages\|booking\|policies\|notifications` | `settings.view` / `settings.manage` | Booking tab: `booking` |

Every write checks its permission (and entitlement) in the Action; hidden
buttons are presentation only. A person with only the `*.view` permission sees
every page read-only: controls disabled, per-language texts read-only
(`x-readonly-fields`, `resources/js/manager/appearance.js`) with the language
tabs still usable, and no Save button. Previews live in the authenticated `/manager`
group — never under `public.tenant` (ADR-036) — send `X-Robots-Tag: noindex`
and `Cache-Control: no-store`, use `?lang=` (one of the ENABLED content
languages, for that render only) and write nothing.

## 2. Menu appearance

- Catalog: `config/menu.php`. New closed options: `layout` (stacked / grid /
  compact), `type_scale`, `image_ratio`, `price_style`, `cta_style`,
  `hero_style` (plain / tint / gradient), `gradient_angle` (90 / 135 / 180),
  `language_switch` (buttons / menu / hidden), `colors_source` (menu / brand);
  section settings `categories.layout` (+ chips), `categories.show_images`,
  `location.show_hours`, `contact.show_phone|show_email`.
- `theme` defaults reproduce the pre-Phase-15 look, so a menu published
  earlier renders the same until its owner changes it; a template's `preset`
  is applied only when the owner picks that template in the editor.
- `section_defaults` fill any setting a stored section lacks, in
  `MenuPresentation` — every reader sees one complete, validated shape.
- Rejections are `MenuPresentationRejected` (still an
  `InvalidArgumentException` with the old English message for the API) with a
  `reason` + `context` the Manager translates (`manager_appearance.errors.*`).
- The renderer (`menu/show.blade.php` + `menu/sections/*`) draws
  `visibleSections()` IN ORDER. Style tokens come from `MenuStyle` (validated
  hex + enum maps; gradients assembled from the two colours and a listed
  angle; font stacks carry no quotes so Blade escaping cannot break them).
  Brand colours/logo come from `PublicBrand` → `CenterBrandReader` when bound.
- Editor: templates, colours, grouped options, drag-and-drop section order
  (`x-sortable="moveSection"`) with Move up/down buttons, per-section settings,
  history with who/when and restore (a restore also resets the draft), a
  publish confirmation, and a full-screen preview (desktop / tablet / phone ×
  enabled languages) of the DRAFT through the real template.
  `MenuPublisher::previewPresentation()` is a read — opening the editor or a
  preview never creates a draft row.

## 3. Booking and cart appearance, policies

- `Kernel\Appearance` — a generic, closed document: `AppearanceSchema`
  (colours, choices, flags, texts with length caps), `Appearance`
  (`fromInput` strict / `fromStored` lenient), `AppearanceRejected`,
  `AppearanceStore` (tenant `settings` rows, no cache). Texts are plain text:
  anything that looks like a tag is refused; control characters are stripped.
  Texts are kept for every supported language — disabling a language hides
  its fields and never deletes its text.
- Schemas: `config/menu.php` `pages.booking`, `pages.cart`, `pages.policies`.
  Keys: `appearance_booking`, `appearance_cart`, `public_policies`.
- `Menu\Application\PublicPageAppearance` turns a document into what a page
  prints (vars, classes, texts resolved: center copy → primary-language copy →
  built-in `menu_public.*`).
- Actions: `Menu\Application\Actions\SavePageAppearance` (booking | cart;
  `appearance.manage`; `booking` for the booking page) and
  `SavePublicPolicies` (`settings.manage`). Audit `appearance.{page}.updated` /
  `settings.policies.updated` with the CHANGED KEYS only, never the copy.
- The booking FLOW is unchanged; appearance frames it. The confirmation now
  shows the booking reference and the one-time code the flow already returns
  (it previously printed the internal uuid as "Reference", contrary to ADR-068).
- The cart has no backend (checkout is Phase 16): the cart and checkout pages
  are honest empty states in the center's words — never a line, count or total.

## 4. Print appearance

- `Modules\Printing` (the L6 Printing module of docs/04): `PrintAppearance`
  (schema `config/printing.php`, key `print_appearance`) and
  `Actions\UpdatePrintAppearance` (`appearance.manage` + `printing`, audit
  `print.appearance.updated` with changed keys).
- Applied at RENDER time only by `InvoicePrintController` /
  `QueueTicketPrintController`: document language (staff or center primary),
  logo (brand, size, alignment), center/branch lines, up to three header lines,
  localized footer / ticket closing line, receipt text size, A4 density,
  customer-name and ticket service/date toggles. The invoice's immutable view
  model is filtered into a copy for the paper; nothing is ever written to the
  invoice. The shared `sales/partials/invoice-body` identity header and thanks
  line are replaced on paper by `printing/header` / `printing/footer`.
- Meta Style's own SaaS invoice template (SaasBilling) is unrelated.
- Both print controllers live on the center host (`{center}.…/manager/…`) and
  take `string $center` as their first route argument: Laravel passes route
  parameters by POSITION, host parameters first. Without it the slug landed in
  `$uuid` and every receipt, A4 page and queue ticket answered 404.

## 5. Settings

- Parent `App\Livewire\Center\Settings` (tabs, links computed in PHP) with one
  child component per section under `App\Livewire\Center\Settings\*`.
- General — `Kernel\Tenancy\Actions\UpdateOwnCenterProfile`: a control-plane
  write from tenant context to the BOUND tenant's own row (name, contact
  person/email/phone as E.164, timezone, currency). The address (slug) is
  read-only here. The currency is locked once a sale or payment exists (the
  same rule as SaasAdmin `UpdateCenterProfile::currencyLocked`). Audited to
  the tenant AND platform logs with contact email/phone as `Fingerprint`s.
- Languages — `Kernel\Localization\Actions\UpdateContentLanguages`: ≥1
  enabled, primary enabled, the current primary cannot be switched off without
  choosing another, disabling never deletes a translation. KU is shown, `ckb`
  stored. Content languages are separate from the interface language.
- Booking — `Booking\Application\Actions\UpdateBookingSettings`: the five
  BookingSettings values, refused (not clamped) outside the engine's bands,
  `settings.manage` + `booking`, audited before/after.
- Policies — cancellation and terms texts per language (shown on the booking
  page when the booking appearance allows).
- Notifications — the signed-in person's own `PreferenceKey` switches only.

## 6. Tests

`tests/Feature/Menu/{MenuAppearanceTest,PublicPageAppearanceTest}.php`,
`tests/Feature/Printing/PrintAppearanceTest.php`,
`tests/Feature/Phase15/{ManagerSettingsTest,ManagerLanguageSettingsTest}.php`,
`tests/Feature/Sales/InvoicePrintTest.php`,
`tests/TenantIsolation/AppearanceSettingsIsolationTest.php`.
