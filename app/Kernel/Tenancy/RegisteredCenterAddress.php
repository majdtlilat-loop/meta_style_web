<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy;

use App\Kernel\Tenancy\Infrastructure\DomainModel;
use App\Kernel\Tenancy\Infrastructure\TenantModel;

/**
 * Resolves public center links from the authoritative domains registry.
 *
 * A tenant slug is useful consistency data, but it is not enough to publish a
 * host. A host becomes authoritative only when it is registered to the tenant
 * in the control-plane domains table and passes the current platform rules.
 */
final class RegisteredCenterAddress
{
    public function __construct(private readonly PlatformHosts $hosts) {}

    /**
     * @return array{
     *     available: bool,
     *     reason: 'available'|'missing'|'invalid'|'mismatch',
     *     registered_host: string|null,
     *     slug: string|null,
     *     host: string|null,
     *     urls: array{public: string, login: string, list: string, booking: string}|null
     * }
     */
    public function resolve(TenantModel $tenant): array
    {
        $domain = $this->authoritativeDomain($tenant);

        if (! $domain instanceof DomainModel) {
            return $this->unavailable('missing');
        }

        $registeredHost = mb_strtolower(rtrim(trim((string) $domain->domain), '.'));
        $slug = $this->hosts->centerSlugFromHost($registeredHost);

        if ($slug === null || ! hash_equals($this->hosts->centerHost($slug), $registeredHost)) {
            return $this->unavailable('invalid', $registeredHost);
        }

        $storedSlug = is_string($tenant->slug) ? trim($tenant->slug) : '';

        if ($storedSlug !== '' && ! hash_equals($slug, $this->hosts->normalizeSlug($storedSlug))) {
            return $this->unavailable('mismatch', $registeredHost);
        }

        return [
            'available' => true,
            'reason' => 'available',
            'registered_host' => $registeredHost,
            'slug' => $slug,
            'host' => $registeredHost,
            'urls' => [
                'public' => $this->hosts->centerUrl($slug),
                'login' => $this->hosts->centerUrl($slug, '/login'),
                'list' => $this->hosts->centerUrl($slug, '/list'),
                'booking' => $this->hosts->centerUrl($slug, '/booking'),
            ],
        ];
    }

    private function authoritativeDomain(TenantModel $tenant): ?DomainModel
    {
        $domains = $tenant->relationLoaded('domains')
            ? $tenant->domains
            : $tenant->domains()->get();

        /** @var DomainModel|null $domain */
        $domain = $domains
            ->sortBy([
                ['is_primary', 'desc'],
                ['id', 'asc'],
            ])
            ->first();

        return $domain;
    }

    /**
     * @param  'missing'|'invalid'|'mismatch'  $reason
     * @return array{
     *     available: false,
     *     reason: 'missing'|'invalid'|'mismatch',
     *     registered_host: string|null,
     *     slug: null,
     *     host: null,
     *     urls: null
     * }
     */
    private function unavailable(string $reason, ?string $registeredHost = null): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'registered_host' => $registeredHost,
            'slug' => null,
            'host' => null,
            'urls' => null,
        ];
    }
}
