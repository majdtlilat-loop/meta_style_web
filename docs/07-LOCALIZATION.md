# 07 — Localization

> Status: **Partly implemented.** The language registry (Phase 1) and the
> `Translatable` cast plus `TranslatedText` value object (Phase 3) are live and
> used by branch, employee and role names. Locale resolution middleware, UI
> strings and the tenant-enabled-locales setting arrive in Phase 4.

## 1. Launch languages

| Code | Language | Script | Direction |
|---|---|---|---|
| `en` | English | Latin | LTR |
| `ar` | Arabic | Arabic | RTL |
| `ckb` | Kurdish Sorani | Arabic (Sorani) | RTL |

`ckb` is the correct ISO 639-3 code for Sorani. **Do not use `ku`** — that is
the macrolanguage and is commonly read as Kurmanji (Latin script, LTR), which
would produce the wrong direction and the wrong font stack.

Adding a language later must require **no migration and no code change**: one
row in the control-plane `languages` table plus a translation file.

## 2. The rule

> **Never create fixed per-language columns.**

```sql
-- FORBIDDEN
name_ar VARCHAR(255), name_en VARCHAR(255), name_ku VARCHAR(255)
```

Adding Turkish would mean a migration across every tenant database, plus every
model, form, validator, resource, export and report. That is the failure this
design exists to prevent.

## 3. Two kinds of translatable content

They have different lifecycles and different storage.

| Kind | Example | Who writes it | Storage |
|---|---|---|---|
| **UI strings** | "Book now", "Invoice", validation messages | Meta Style developers | Laravel lang files |
| **Tenant content** | Service names, category names, offer text, menu copy, message templates | Center staff | JSON columns in the tenant database |

### 3.1 UI strings

Standard Laravel: `resources/lang/{en,ar,ckb}/*.php` plus JSON files. Nothing
custom.

A per-tenant UI string override layer (a center renaming "Employee" to
"Stylist") is deferred; when it arrives it will be a `Kernel/Localization`
override map resolved after the file loader, not a fork of the lang files.

### 3.2 Tenant content — JSON columns

```php
Schema::create('services', function (Blueprint $t) {
    $t->json('name');          // {"ar": "قص شعر", "en": "Haircut", "ckb": "..."}
    $t->json('description')->nullable();
});
```

```php
protected $casts = [
    'name'        => Translatable::class,
    'description' => Translatable::class,
];
```

```php
$service->name;              // resolved string in the current locale
$service->name->in('ar');    // explicit locale
$service->name->all();       // full map, for admin editing
$service->setTranslation('name', 'ckb', '...');
```

**Why JSON columns and not a `translations` table** (ADR-008):

- One row read, no join, no N+1. Service listings and the electronic menu are
  the highest-traffic reads in the product.
- Adding a language changes no schema.
- Copying, exporting, and versioning an entity keeps its translations attached.
- MySQL 8 supports functional indexes on JSON paths, so the searchability
  objection is solvable where it actually matters.

Cost accepted: searching or sorting across all locales at once requires either
a functional index per indexed locale, or a small denormalised search column.

### 3.3 Indexing translatable columns

Only where a real query needs it:

```sql
ALTER TABLE services
  ADD COLUMN name_search VARCHAR(255)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(name, '$.en'))) STORED,
  ADD INDEX services_name_search_idx (name_search);
```

Rule: **every generated column must be justified by a named query** in the
migration comment. Do not add one per locale per table by reflex.

For genuine multilingual full-text search (Phase 12+), the answer is a search
index (`14-FUTURE-INTEGRATIONS.md` §7), not more generated columns.

## 4. Which locales a tenant has

```
config/localization.php        code, name_native, name_en, direction   ← platform
tenant settings                default_locale, enabled_locales (json)  ← center
```

**Implemented as `Kernel\Localization\TenantLocales`, reading the tenant's own
`settings` table.** This deviates from the original plan of a control-plane
`languages` table and columns on `tenants`, for two reasons: `default_locale`
already lived in tenant settings from provisioning, so the control-plane columns
would have been a second source of truth for one answer; and these values are
only ever read inside tenant context, so there is nothing to gain from the round
trip. The platform registry stayed in config because nothing yet needs to add a
language without a deploy — move it to a table when SADMIN does.

A newly provisioned center enables **only the language it registered in**.
Enabling all three for everyone would put empty Arabic and Kurdish fields on
every form of a center that will only ever use English, and empty fields are how
translatable content ends up half-filled.

- The platform decides which languages **exist**.
- Each tenant decides which of them it **enables**, and which is its default.
- Admin forms render one input per **enabled** locale only. A tenant that has
  not enabled Kurdish never sees a Kurdish field.
- Disabling a locale hides it but **never deletes stored translations** — they
  reappear if it is re-enabled.

## 5. Locale resolution

```
1. Explicit ?locale= query parameter        (must be in tenant enabled_locales)
2. Authenticated user / customer preference
3. Accept-Language header, negotiated       (against enabled_locales)
4. Tenant default_locale
5. Platform fallback: en
```

Resolved once per request by middleware, stored in `Kernel/Localization`, and
applied to `app()->setLocale()`, Carbon, and number formatting.

A requested locale that the tenant has not enabled falls back silently; it is
not an error.

### 5.1 Fallback chain for a missing translation

```
requested locale → tenant default → platform fallback (en) → any non-empty value → ""
```

**A translatable field must never render as an empty string when any
translation exists.** A service with only an Arabic name shows the Arabic name
to an English user — a blank service name in the menu is worse than the wrong
language.

Admin surfaces show a "missing translation" indicator instead of falling back
silently, so gaps are visible to the people who can fix them.

## 6. Direction and typography

Direction comes from the `languages` registry, never from a hardcoded list in
application code.

```php
Locale::direction();          // 'rtl' | 'ltr'
Locale::isRtl();
```

**Content-aware direction** — the important part. A page's direction and a
field's direction are different things:

- Page direction follows the **UI locale**.
- Each rendered piece of content is wrapped with the direction of **the locale
  that content is in**, using `dir="auto"` or an explicit `dir` attribute.
- An Arabic-language UI displaying an English service name must not mangle it,
  and vice versa.
- Numbers, phone numbers, prices, invoice numbers and codes embedded in RTL
  text require Unicode bidi isolation (`&#8296;`/`FSI`+`PDI`, or CSS
  `unicode-bidi: isolate`). Without it, `+964 770 123` renders in a visually
  wrong order inside Arabic sentences — a real, frequent, and very confusing
  bug in RTL products.
- PDF and thermal-receipt rendering needs its own RTL shaping check; browser
  correctness does not imply PDF correctness. This is a specific Phase 9 test.

Font stacks are per-script, not per-language, and Sorani requires a font with
proper Arabic-script Kurdish glyph coverage.

## 7. Formatting

| Item | Rule |
|---|---|
| Dates | Stored UTC, rendered in the **branch** timezone (`10-API-FOUNDATION.md` §8). |
| Calendars | Gregorian by default; Hijri display is a per-tenant setting, deferred. |
| Digits | Latin digits by default. Arabic-Indic digits are a per-tenant display preference — **never** stored that way. |
| Numbers | `Kernel/Money` and locale-aware formatters. Never `number_format()` inline. |
| Currency | Amount + currency code from the data; symbol and position from the locale. IQD has **0 decimal places** — the currency exponent must come from `Kernel/Money`, not be assumed to be 2. |
| Names | Single `name` field per person. No first/last split — it does not model Iraqi or Kurdish naming well. |
| Addresses | Free-form text plus optional geo. No Western address schema. |

## 8. Validation and API

- Validation messages are localised; **validation rules are not.** A required
  field is required in every language.
- A translatable field is required in the tenant's **default locale** only;
  other enabled locales are optional.
- API request format for translatable fields:

```json
{ "name": { "ar": "قص شعر", "en": "Haircut" } }
```

- API response, default (resolved for the caller):

```json
{ "name": "Haircut", "locale": "en" }
```

- API response with `?translations=all` (admin editing):

```json
{ "name": { "ar": "قص شعر", "en": "Haircut", "ckb": null } }
```

One `TranslatableField` cast and one API resource trait implement both shapes,
so no module writes its own.

## 9. Localised outbound messages

WhatsApp templates, emails, SMS, push notifications and printed documents are
localised **per recipient**, not per tenant:

```
customer.preferred_locale
  → else tenant.default_locale
  → else platform fallback
```

Message templates are tenant-customisable per locale and render through
`Kernel/Templates` (`04` §5). A missing template in the recipient's locale
falls back through the same chain as content.

Queue voice announcements may play **several languages in sequence** — this is
tenant-configured order (e.g. `ckb`, `ar`, `en`), and each language needs its
own audio asset set or TTS voice (`14-FUTURE-INTEGRATIONS.md` §5).

## 10. Anti-patterns

| Anti-pattern | Why |
|---|---|
| `name_ar` / `name_en` / `name_ku` columns | The failure this document prevents. |
| `ku` for Sorani | Wrong script and wrong direction. |
| Hardcoding `['ar','ckb']` as the RTL list in code | Adding Farsi or Hebrew means hunting for it. |
| `if (app()->getLocale() === 'ar') { $dir = 'rtl'; }` | Same, one line at a time. |
| Rendering an empty string when a translation is missing | Blank menu items. |
| Storing Arabic-Indic digits | Breaks sorting, search, arithmetic and export. |
| Assuming two decimal places for currency | IQD has zero; produces 100× errors. |
| Splitting names into first/last | Does not model the target market. |
| Translating enum values in the database | Enums are code; label them in lang files. |
| A `translations` table joined on every menu render | N+1 on the hottest read path. |
| Testing RTL only in the browser | PDF and 80mm receipts shape text differently. |
