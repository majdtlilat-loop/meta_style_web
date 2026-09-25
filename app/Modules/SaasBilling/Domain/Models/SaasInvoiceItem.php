<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class SaasInvoiceItem extends Model
{
    protected $connection = 'control';

    protected $table = 'saas_invoice_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'total_minor' => 'integer', 'meta' => 'array'];
    }
}
