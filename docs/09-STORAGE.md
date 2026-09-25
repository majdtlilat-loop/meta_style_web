# 09 — Storage & Files

> Status: **Kernel implemented (Phase 2).** `MediaStore` and tenant-rooted
> disks are live and covered by isolation tests. Collections, variants, quotas
> and signed-URL serving arrive with the features that need them.

## 1. Principle

> **No module ever builds a storage path.** Every file operation goes through
> `Kernel/Storage`.

A single `"tenants/{$id}/logos/..."` string concatenated in a controller is how
tenant file isolation is lost. The path layout below is an implementation
detail of one service, not shared knowledge.


## 2. Layout

```
tenants/{tenant_uuid}/
├── branding/          logos, covers, favicons, white-label assets
├── services/          service and category images, menu media
├── employees/         employee photos
├── customers/         customer photos, consent forms, before/after images
├── invoices/          rendered invoice PDFs (immutable once published)
├── receipts/          rendered receipt artefacts
├── documents/         signed forms, uploads, attachments
├── templates/         published template assets and versions
├── exports/           generated reports and data exports (expiring)
├── audio/             queue voice assets
└── tmp/               uploads in progress (auto-purged after 24h)

platform/
├── landing/           marketing site media (control plane)
├── whitelabel/        submitted store assets per app build
└── system/            platform-level assets
```

`tenant_uuid` (not the numeric id) is the prefix, so a path never reveals how
many tenants exist or the ordering of signups.

## 3. Disks

| Disk | Driver (dev) | Driver (prod) | Visibility |
|---|---|---|---|
| `local` | `local` | S3-compatible | **private** |
| `public` | `local` (symlinked) | S3-compatible + CDN | public-read, unguessable names |

Laravel's two standard disks are made tenant-aware rather than duplicated: the
tenancy filesystem bootstrapper **re-roots them** at `tenants/{tenant-key}/`
whenever a tenant is initialised, and restores them when tenancy ends. Adding
parallel `tenant`/`tenant_public` disks would mean two sets of disks to keep in
sync for no benefit.

Access always goes through `MediaStore`, which calls `TenantContext::require()`
first — so a file operation with no tenant bound throws, the same fail-closed
rule as the database connection (`02` §4).

Platform-level assets (landing-page media, white-label store assets) get their
own disks when the Landing CMS and White Label phases arrive; nothing writes
them today.

### 3.1 Private by default

Everything is private unless it has a specific reason to be public.

| Content | Disk | Why |
|---|---|---|
| Service images, category images, logos, covers | `tenant_public` | Rendered on the public electronic menu; must be CDN-cacheable. |
| Employee photos | `tenant_public` **only if** the tenant publishes them on the menu; otherwise `tenant` | Tenant setting. |
| Customer photos, consent forms, before/after images | `tenant` | Sensitive. Signed URLs only. |
| Invoices, receipts, exports, documents | `tenant` | Financial and personal data. |
| Queue voice assets | `tenant_public` | Fetched by display devices. |

Public files still get random UUID filenames — public means "no auth required
if you have the exact URL", not "listable" or "guessable".

## 4. One bucket, many prefixes

Production uses **one S3 bucket with a per-tenant prefix**, not a bucket per
tenant.

**Why** (ADR-009): S3 accounts have bucket limits in the low thousands; a
bucket per tenant means a bucket-creation step in provisioning that can fail
independently, N lifecycle policies, N CORS configs, and an IAM policy that
grows without bound. A prefix is free, instant, and imposes no ceiling.

Isolation is enforced by the application (all access via `MediaStore`) and by
signed URLs, not by bucket boundaries. This is a conscious trade: an
application bug could cross the prefix, which is why `MediaStore` is small,
centralised and heavily tested.

## 5. The MediaStore API

As built in Phase 2 — deliberately smaller than the eventual surface:

```php
final class MediaStore
{
    public function put(MediaCollection $c, string $contents, ?string $ext = null): string;
    public function get(MediaCollection $c, string $path): ?string;
    public function exists(MediaCollection $c, string $path): bool;
    public function delete(MediaCollection $c, string $path): void;
    public function files(MediaCollection $c): array;
    public function absolutePath(MediaCollection $c, string $path): string;
    public function disk(MediaCollection $c): Filesystem;   // requires a tenant
}
```

Every method resolves the disk through `TenantContext::require()`, so a write
with no tenant bound raises `TenantNotResolved` instead of landing in the shared
storage root where the next tenant could read it. That is the fail-closed rule
(`02-TENANCY.md` §4) applied to files, and it has its own test.

Isolation itself comes from the tenancy filesystem bootstrapper, which roots the
`local` and `public` disks at `tenants/{tenant-key}/` whenever a tenant is
initialised. `MediaStore` adds collection semantics, the fail-closed check, and
generated UUID filenames — "public" means no auth is required if you have the
exact URL, never that the URL can be guessed.

`UploadedFile` handling, image variants, `Media` records, signed URLs and
storage quotas arrive with the first feature that uploads something (Phase 4).
Building them now would be a store with no shop.

`MediaCollection` is a **backed enum** carrying its own disk and visibility, so
"which disk, public or private" is a property of the collection rather than a
decision repeated — and eventually got wrong — at every call site.

Phase 2 defines only the collections it can exercise: `Branding` (public),
`Exports` and `Temp` (private). `Services`, `Employees`, `Customers`,
`Invoices`, `Documents` and `Audio` are added with their features, along with
allowed MIME types, size caps and retention. An enum full of unreachable cases
is documentation pretending to be code.

A `media` table in the tenant database will record `uuid`, `collection`, `disk`,
`path`, `mime`, `size`, `checksum`, `original_name`, morph target, uploader and
`variants`. It arrives in Phase 4 with the first feature that uploads a file;
Phase 2 stores and reads by path only.

## 6. Uploads

```
receive → validate size and real MIME (content sniff, not extension)
        → reject executables and SVG
        → store to tmp/ with a generated name
        → queue: re-encode images (strip EXIF/metadata), build variants
        → move to the target collection
        → record media row
        → check storage quota entitlement
```

- Direct-to-S3 presigned uploads for large files from Phase 9; the same
  validation runs server-side on finalise.
- `tmp/` is purged daily.
- Storage usage is metered against the `storage_mb` limit entitlement
  (`05` §2), checked on upload and reported in tenant health.

## 7. Serving files

**Private files are never served by a public URL.**

```
GET /api/v1/tenant/media/{uuid}
  → resolve tenant (host/token)
  → authorize (policy on the owning record, plus branch scope)
  → issue a short-lived signed URL, or stream through the app
```

Signed URL lifetimes: 15 minutes for viewing, 60 minutes for a download the
user initiated, 7 days for a customer-facing invoice link.

Never place a customer identifier, invoice number, or phone number in a media
URL (`08` §14).

**Public collections on a center (ADR-083).** A center's `public` disk is rooted
at `tenants/{key}/app/public`, which the global `/storage` link cannot reach.
On the center's own host, `MediaItem::url()` therefore returns
`https://{center-host}/media/{collection}/{uuid}.{ext}`, served by
`CenterMediaController` inside the resolved center: only `branding` and
`catalog`, only MediaStore-shaped names and allow-listed extensions, each with
its fixed content type, `nosniff`, a sandboxing CSP and an immutable cache
header (`throttle:public-media`). Another center's host resolves another disk
and finds nothing. Livewire's temporary upload and preview endpoints resolve
the center too, so a temporary file is written to and read from the center's
own disk.

Two runtime prerequisites make that work in a browser, and neither is visible
to `Livewire::test()` (ADR-105):

- **The resolver runs before the upload throttle.** The upload endpoint carries
  `throttle:60,1`, which keys on the signed-in user — a tenant-database row.
  `ResolveLivewireTenant` is in the middleware priority list with the other
  resolvers, and `MiddlewareOrderGuard` fails the boot otherwise.
- **The suffixed storage path has `framework/cache`.** `storage_path()` becomes
  `tenants/{key}/` inside a center, and Laravel writes a real-time facade's stub
  there on first use (Livewire's uploads use one). `TenantStorageBootstrapper`
  creates the directory on every tenancy bootstrap — cheap once it exists, and
  correct for centers that predate it and for a freshly started server.

## 8. Invoice and document immutability

A published invoice PDF is written **once** and never regenerated. Re-rendering
from live data would silently restate history when a price or a template
changes.

- Path includes the template version and the invoice uuid.
- The `media` row stores a checksum verified on read.
- A corrected invoice is a **new document** (credit note or revision), never an
  overwrite.

## 9. Backup, retention, deletion

| Content | Backup | Retention |
|---|---|---|
| Invoices, receipts, financial documents | Versioned bucket + lifecycle to cold storage | Per jurisdiction, default 7 years |
| Customer documents and photos | Versioned bucket | Tenant lifetime + retention window |
| Branding, service media | Versioned bucket | Tenant lifetime |
| Exports | None | 7 days, then purged |
| Tmp | None | 24 hours |

**Tenant deletion** removes the entire `tenants/{uuid}/` prefix after the
archive in `02-TENANCY.md` §8.3 is written and verified. The delete is a single
prefix operation — one of the concrete benefits of this layout.

**Orphan reaper:** a weekly job reconciles the `media` table against the
storage prefix in both directions and reports (does not auto-delete)
discrepancies. Storage and database will drift; unnoticed drift becomes both a
cost and a privacy problem.

## 10. Development vs production

Local development uses the `local` driver with the same prefix structure under
`storage/app/tenants/{uuid}/`, so a path bug surfaces on a laptop rather than
in production.

**Business code cannot tell the difference.** No module references `Storage::disk('s3')`
or an environment check. Only `config/filesystems.php` and `Kernel/Storage`
know which driver is active.

The `local` driver is the default for development and requires no extra
services. MinIO is **optional** — worth running only when working on signed
URLs, CORS, or direct uploads, which behave differently enough on S3 to hide
bugs. It is not a prerequisite for contributing.

## 11. Media records (Phase 4)

`MediaStore` gained its first real consumer. Files are still written only
through it; what is new is a `media_items` row recording each one.

```
media_items   uuid, owner_type, owner_id, collection, path,
              mime_type, size_bytes, width, height, alt_text(json), sort_order
```

**`owner_type` is a short stable string** — `branch`, `department`,
`service_category`, `service`, `brand`, `site`, `queue_display` (a waiting-room
screen's promotional images and videos, public `branding` collection, 12 per
screen — docs/17-QUEUE.md §9) — from `Kernel\Media\MediaOwner`, not a model
class name. Laravel's default morph column stores the FQCN, so moving a class
between namespaces silently orphans every row pointing at it: an ordinary
refactor becomes a data migration across every tenant database.

**`path` is disk-relative**, never absolute and never a URL. Isolation comes
from the disk being rooted at `tenants/{key}/`; a stored absolute path would
escape that the first time a dump was restored elsewhere. The column is on the
model's `$hidden`, so a client gets `uuid` and `url` and cannot build its own.

**Validation is on the bytes.** `getClientMimeType()` and the filename are
attacker-controlled — a PHP file renamed `photo.png` arrives with a respectable
declared type. `getimagesize()` parses the actual header, and the stored
extension is derived from what it found, not from what was uploaded.

Deleting removes the row **and** the file together. A row without a file renders
broken; a file without a row is storage nobody will ever reclaim.

## 12. Anti-patterns

| Anti-pattern | Why |
|---|---|
| `Storage::put("tenants/{$tenantId}/...")` in a module | The isolation bug this document prevents. |
| A bucket per tenant | Provisioning failure mode, account limits, IAM sprawl. |
| Public disk for customer documents | Direct data exposure. |
| Storage paths containing the numeric tenant id or record ids | Enumeration and volume disclosure. |
| Regenerating a published invoice PDF | Silently rewrites financial history. |
| Trusting the uploaded file extension | Executable and SVG upload vectors. |
| Accepting SVG for logos | Stored XSS on the public menu. |
| Keeping EXIF in customer photos | GPS coordinates of customers' homes. |
| Long-lived or non-expiring signed URLs | Shared links outlive the permission. |
| Deleting a media row without the file (or vice versa) | Orphans, cost, and privacy exposure. |
| An environment check inside business code to pick a disk | Untestable and inevitably wrong somewhere. |
