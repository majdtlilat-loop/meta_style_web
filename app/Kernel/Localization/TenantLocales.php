<?php

declare(strict_types=1);

namespace App\Kernel\Localization;

use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Which languages THIS center has turned on, and which is its default.
 *
 * The platform decides which languages exist ({@see LanguageRegistry}); each
 * center decides which of them it uses. A barbershop in Erbil may run Kurdish
 * and Arabic and never touch English; forcing three sets of fields on every
 * admin form would guarantee two of them stay empty
 * (docs/07-LOCALIZATION.md §4).
 *
 * Stored in the tenant's own `settings` table rather than on the control-plane
 * tenant row. This is tenant business configuration, read only inside tenant
 * context, and `default_locale` already lived there from provisioning — putting
 * the pair in two planes would be two sources of truth for one answer.
 *
 * DISABLING NEVER DELETES. A center that turns Kurdish off keeps every Kurdish
 * translation it has written; they reappear intact when it turns it back on.
 * The alternative — pruning the JSON — makes an off switch destructive.
 */
final class TenantLocales
{
    private const ENABLED_KEY = 'enabled_locales';

    private const DEFAULT_KEY = 'default_locale';

    /** @var array<string, array{enabled: list<string>, default: string}> */
    private array $cache = [];

    public function __construct(
        private readonly LanguageRegistry $languages,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        return $this->settings()['enabled'];
    }

    public function default(): string
    {
        return $this->settings()['default'];
    }

    public function isEnabled(string $locale): bool
    {
        return in_array($locale, $this->enabled(), true);
    }

    /**
     * The best locale for a request that asked for `$requested`.
     *
     * Never an error: a customer whose browser asks for French gets the
     * center's default, not a 404 (docs/07-LOCALIZATION.md §5).
     */
    public function resolve(?string $requested): string
    {
        return $requested !== null && $this->isEnabled($requested)
            ? $requested
            : $this->default();
    }

    /**
     * @param  list<string>  $locales
     */
    public function setEnabled(array $locales, string $default): void
    {
        $clean = array_values(array_unique(array_filter(
            $locales,
            fn (string $locale): bool => $this->languages->supports($locale),
        )));

        // The default must be enabled, or the fallback chain terminates at a
        // language the center has switched off.
        if (! in_array($default, $clean, true)) {
            $clean[] = $default;
        }

        $this->put(self::ENABLED_KEY, $clean);
        $this->put(self::DEFAULT_KEY, $default);

        $this->forget();
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * @return array{enabled: list<string>, default: string}
     */
    private function settings(): array
    {
        $key = $this->tenants->id() ?? 'none';

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $fallback = $this->platformFallback();

        if (! $this->tenants->isBound()) {
            // Corporate and platform surfaces expose the complete platform
            // registry. There is no tenant preference to narrow it.
            return $this->cache[$key] = ['enabled' => $this->languages->supported(), 'default' => $fallback];
        }

        $default = $this->read(self::DEFAULT_KEY);
        $default = is_string($default) && $this->languages->supports($default) ? $default : $fallback;

        $enabled = $this->read(self::ENABLED_KEY);

        $enabled = is_array($enabled)
            ? array_values(array_filter(
                $enabled,
                fn (mixed $l): bool => is_string($l) && $this->languages->supports($l),
            ))
            : [];

        if ($enabled === []) {
            $enabled = [$default];
        }

        if (! in_array($default, $enabled, true)) {
            $enabled[] = $default;
        }

        /** @var list<string> $enabled */
        return $this->cache[$key] = ['enabled' => $enabled, 'default' => $default];
    }

    private function read(string $key): mixed
    {
        $raw = DB::connection('tenant')->table('settings')->where('key', $key)->value('value');

        if (! is_string($raw)) {
            return null;
        }

        return json_decode($raw, true);
    }

    private function put(string $key, mixed $value): void
    {
        DB::connection('tenant')->table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function platformFallback(): string
    {
        $fallback = config('localization.fallback');

        return is_string($fallback) ? $fallback : 'en';
    }
}
