# 30 — Center Site (Brand + Landing page)

> Status: **Implemented (Manager build wave, Phase 15 follow-up)**. Module
> `app/Modules/CenterSite`. Tenant data only — nothing here touches the
> platform's own branding (`PlatformBranding`) or the corporate landing CMS
> (`LandingCms`, control plane).

A center's **public site** is its home page on its own host (`/` →
`center.public`): header, hero, sections, footer, SEO — plus a **brand**
(logo light/dark, favicon, colours, gradients, shape) that every public
surface of the center can read through `CenterSite\Contracts\CenterBrandReader`.

## 1. Surfaces

| Route | Name | Who |
|---|---|---|
| `GET /` | `center.public` | Guests (`public.tenant`, throttled, localized). Renders the PUBLISHED site, or the default page if the center never published. |
| `GET /manager/appearance/brand` | `center.appearance.brand` | `appearance.view` to open, `appearance.manage` to change. |
| `GET /manager/appearance/site` | `center.appearance.site` | Same. The landing page builder. |
| `GET /manager/appearance/site/preview?lang=` | `center.appearance.site.preview` | Authenticated Manager group, `appearance.view`. The saved DRAFT, `noindex`, `no-store`. `lang` is limited to enabled content languages and never changes the staff interface language. Never on the public resolver (ADR-036). |

Uploads additionally need `media.upload` (StoreMediaItem checks it).

## 2. Storage

- **Brand**: tenant `settings` row `site_brand` (JSON), read and re-validated
  by `Application\BrandSettings`. No migration.
- **Site**: tenant table `site_versions` (`2026_09_24_1801`): one table, three
  states like `menu_versions` — at most one `draft`, one `published`, every
  earlier published version `archived`. `content` JSON, `version` unique and
  monotonic, `restored_from_version`, `saved_by_user_id`,
  `published_by_user_id`, `published_at`.
- **Media**: `media_items` with `MediaOwner::Brand` / `MediaOwner::Site`
  (owner id 1), collection `Branding` (public, served by
  `CenterMediaController`). Content references media **by uuid only**.
  Removing an image from a page only unreferences it (published/archived
  versions may still use it). Replacing/removing a BRAND asset deletes the old
  file — pages always read the current brand.

## 3. The allow-list (`Domain\SiteContent`)

`normalize()` is the security boundary and is strict — an unknown key, type or
value is refused with a dotted path (`InvalidSiteContent::$path`), never
silently dropped. `hydrate()` is its tolerant twin for stored content.

- Text: plain text only (`strip_tags` must not change it; no control chars),
  per language, length-capped. Every supported language is **kept** (a
  disabled language never loses its translations); the **center's primary
  content language** is required where text is required — not English.
- Links: a section anchor on this page, one of the center's pages **by key**
  (`home`, `list`, `booking`), or an absolute `https://` URL without
  credentials. Social links are pinned to each network's own host.
- Media: a uuid that exists in THIS center's site library, of the kind the
  slot takes (image vs video).
- Footer phone / WhatsApp: E.164 only. The builder edits them with the one
  phone field (country + national number, `SitePhones`) and stores
  `PhoneNumber::fromParts()`; the public page links `tel:` / `wa.me` from the
  canonical number and never guesses a country code.
- References (services, categories, employees, branches, membership plans,
  packages): uuids that exist in THIS center, read through each module's
  contract. Unknown ⇒ refused; known but archived/hidden ⇒ kept and simply not
  rendered.
- Every presentation choice comes from `Domain\SiteCatalog` (section types,
  layouts, icons, backgrounds, CTA styles…). Colours are never typed into a
  page — backgrounds name a BRAND colour or gradient.

Section types (only data-backed or owner-written): about, services,
featured_services, categories, team, why_us, gallery, memberships, packages,
rating (aggregate only), hours, branches, booking_cta, contact, map, faq,
text_media. **No offers type** — no offers module exists.

## 4. Brand (`Domain\CenterBrand`, `Domain\CenterTheme`)

Light and dark palettes (8 colours each, `#rrggbb`), scheme
`light|auto|dark`, three named gradients (2–3 validated stops + an angle from
a fixed list), radius / button / card style keys, and the three asset slots.
`CenterTheme` emits `--center-*` tokens (the chosen colours exactly; derived
hover/soft/on-colour tokens) and reports contrast below WCAG AA without
changing anything. The emitted CSS contains no quote, `&`, `<`, `>` or
`url(`, so it is safe in a `<style>` through Blade escaping.

`CenterBrandReader::forPublic()` (bound to `Application\PublicCenterBrand`)
returns the center name, logo/favicon URLs, light tokens and radius for the
menu, booking, cart and print surfaces.

## 5. Publishing (`Application\SitePublisher`)

`saveDraft` → `publish` (re-validates, archives the previous version, one
transaction) → `restore(uuid)` copies an archived/published version FORWARD
into the draft; nothing goes live until it is published. Audit actions:
`center.site.draft_saved`, `center.site.published`,
`center.site.version_restored`, `center.brand.updated`, `center.brand.reset`,
`center.brand.asset_uploaded`, `center.brand.asset_removed` (plus
`media.item.uploaded/deleted`).

A center that never published renders `Application\SiteDefaults`: only
data-backed sections (services, rating, hours, booking, contact, map) and a
disabled About — each of which renders nothing without data. No seeding and
no backfill.

## 6. Rendering (`Application\PublicSitePage`)

One allow-listed array per render (brand, header, hero, sections, footer,
SEO); templates hold no models and no decisions. Text resolves requested →
primary → any translation. Data sections read through
`Catalog\Contracts\SiteCatalogReader`, `Employees\Contracts\PublicTeamReader`,
`Branches\Contracts\SiteBranchReader`,
`Memberships\Contracts\SiteMembershipReader`,
`Packages\Contracts\SitePackageReader` and
`Reviews\Contracts\PublicRatingReader` — bounded queries; branches are read
once per render. A data section with no data is not rendered (the preview
marks it instead). The rating shows the aggregate only, and only from
`config('site.rating.min_reviews')` reviews. Memberships and packages render
only while the center owns the entitlement; booking links only while it owns
`booking`. A map is framed only from validated branch coordinates and the
configured provider (`config/site.php`); a center-typed map URL is only a
link.

The layout (`layouts/center-public/app`) also frames the cart and checkout
pages through `CenterPublicShellComposer` (published header/footer/brand).
Header language links use `?locale=` on public pages and `?lang=` in the
preview.

## 7. Builder UI

`Livewire\Center\Appearance\Site` orchestrates only; structure operations are
`Domain\SiteEditor` pure functions (a move names one item and a clamped
position — no client-sent order is trusted); view data comes from
`SiteEditorView`; uploads are the `SiteMediaSlot` child; history is the
`SiteHistory` child. Drag and drop uses `x-sortable` with visible Move up /
Move down buttons. Preview saves the draft, then opens an iframe of the
preview route per device and enabled language. Publish is confirmed in a
modal. `Livewire\Center\Appearance\Brand` edits the brand with a live preview
and contrast report; reset and asset removal are confirmed.

Upload refusals (`Kernel\Media\Application\StoreMediaItem`: size, type,
dimensions, square icon, library cap) are translated through the
`media_upload` lang group, so every Manager surface that stores media shows
them in the staff member's language.

## 8. Tests

`tests/Feature/CenterSite/*` (normalizer, editor, publisher, brand, builder
Livewire, public render in en/ar/ckb, preview access) and
`tests/TenantIsolation/CenterSiteIsolationTest.php`.
