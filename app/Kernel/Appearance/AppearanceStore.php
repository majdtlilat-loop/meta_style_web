<?php

declare(strict_types=1);

namespace App\Kernel\Appearance;

use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Appearance documents live in the tenant's own `settings` table, one key per
 * document, beside the enabled languages and the booking settings. They are
 * the center's configuration, read inside tenant context only, so a second
 * table — or the control plane — would be a second place to look.
 *
 * Nothing is cached: a page reads its document once per request, and a form
 * that saves must never be answered from a stale copy.
 */
final class AppearanceStore
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function read(string $key): mixed
    {
        if (! $this->tenants->isBound()) {
            return null;
        }

        $raw = DB::connection('tenant')->table('settings')->where('key', $key)->value('value');

        return is_string($raw) ? json_decode($raw, true) : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function write(string $key, array $value): void
    {
        $this->tenants->require();

        DB::connection('tenant')->table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
