<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Sales\Domain\InvoiceShareToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The revocable key a customer uses to open their invoice — stored as a digest.
 *
 * Not a column on the invoice, because the invoice is immutable and a credential
 * must be rotatable. Revoking sets `revoked_at` and clears `active_invoice_id`;
 * rotation is revoke-then-create, and the unique `active_invoice_id` means an
 * invoice has at most one live link (docs/18-SALES.md §20).
 *
 * The row holds `token_hash` only. The secret itself is never persisted: it is
 * returned once, by the Action that minted it, and lives in the customer's URL
 * ({@see InvoiceShareToken}). Even the digest is hidden from serialisation —
 * nothing that renders this model needs it.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $token_hash
 * @property int|null $active_invoice_id
 * @property Carbon|null $revoked_at
 * @property string|null $created_by_id
 * @property string|null $created_by_label
 */
final class InvoiceShareLink extends Model
{
    use UsesTenantConnection;

    protected $table = 'invoice_share_links';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
