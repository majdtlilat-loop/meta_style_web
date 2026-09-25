<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\CenterSite\Domain\CenterBrand;
use Illuminate\Support\Facades\DB;

/**
 * Where the center's brand is kept: one row in the tenant's own `settings`
 * table, next to its languages and booking knobs. Tenant business
 * configuration, read only inside tenant context — never the control plane,
 * never Meta Style's platform branding.
 *
 * Reads are re-validated (CenterBrand::hydrate) so a value an older release
 * accepted and this one does not is replaced by its default, never rendered.
 * Writes go through the Actions, which authorise and audit.
 */
final class BrandSettings
{
    private const KEY = 'site_brand';

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{light: array<string, string>, dark: array<string, string>, scheme: string, gradients: array<string, array{from: string, via: string, to: string, angle: int}>, radius: string, button_style: string, card_style: string, logo_light: string, logo_dark: string, favicon: string}
     */
    public function get(): array
    {
        $this->tenants->require();

        $raw = DB::connection('tenant')->table('settings')->where('key', self::KEY)->value('value');
        $stored = is_string($raw) ? json_decode($raw, true) : null;

        return CenterBrand::hydrate(is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $brand  a normalized CenterBrand
     */
    public function put(array $brand): void
    {
        $this->tenants->require();

        DB::connection('tenant')->table('settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($brand, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()],
        );
    }
}
