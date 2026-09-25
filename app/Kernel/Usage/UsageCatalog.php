<?php

declare(strict_types=1);

namespace App\Kernel\Usage;

use App\Kernel\Usage\Exceptions\UnknownResource;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The metered-resource catalog, read from `config/usage.php`.
 *
 * THE REASON `Kernel\Usage` CAN STAY GENERIC. The codes it validates — `ai_runs`,
 * `wa_outbound` — describe RAYAN and WhatsApp, and the Kernel must never learn
 * what either of those is. Holding the list in configuration means this class
 * knows only that some resources exist, which ones are enforced, and what they
 * default to; the modules above supply the codes and the meaning
 * (Phase 13 correction 3, docs/26-USAGE-QUOTAS.md §2).
 *
 * The same reasoning as the entitlement catalog, one layer out: there the list
 * is code because business logic names the keys; here it is config because the
 * Kernel must be able to validate names it is not allowed to know.
 */
final class UsageCatalog
{
    public function __construct(private readonly Config $config) {}

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    public function has(string $resource): bool
    {
        return array_key_exists($resource, $this->all());
    }

    /**
     * @throws UnknownResource
     */
    public function assertKnown(string $resource): void
    {
        if (! $this->has($resource)) {
            throw UnknownResource::code($resource);
        }
    }

    /**
     * Is this resource a HARD limit, or only metered?
     *
     * Enforced means a request is refused when the allowance is spent. Metered
     * means it is counted exactly and refuses nothing — which is the honest
     * answer for anything whose cost is only known after the fact (§3).
     */
    public function isEnforced(string $resource): bool
    {
        return (bool) ($this->all()[$resource]['enforced'] ?? false);
    }

    /**
     * The platform fallback when neither an override nor a plan says anything.
     *
     * NULL means unlimited, everywhere and without exception. There is no
     * sentinel number (§4).
     */
    public function systemDefault(string $resource): ?int
    {
        $default = $this->all()[$resource]['default'] ?? null;

        return is_numeric($default) ? (int) $default : null;
    }

    public function group(string $resource): string
    {
        $group = $this->all()[$resource]['group'] ?? 'other';

        return is_string($group) ? $group : 'other';
    }

    /**
     * @return list<string>
     */
    public function inGroup(string $group): array
    {
        return array_keys(array_filter(
            $this->all(),
            fn (array $resource): bool => ($resource['group'] ?? null) === $group,
        ));
    }

    /**
     * The configured alert thresholds, ascending.
     *
     * @return list<array{percent: int, status: UsageStatus}>
     */
    public function thresholds(): array
    {
        $configured = $this->config->get('usage.thresholds', []);

        if (! is_array($configured)) {
            return [];
        }

        $thresholds = [];

        foreach ($configured as $threshold) {
            if (! is_array($threshold)) {
                continue;
            }

            $percent = $threshold['percent'] ?? null;
            $status = $threshold['status'] ?? null;

            if (is_numeric($percent) && $status instanceof UsageStatus) {
                $thresholds[] = ['percent' => (int) $percent, 'status' => $status];
            }
        }

        usort($thresholds, static fn (array $a, array $b): int => $a['percent'] <=> $b['percent']);

        return $thresholds;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        $resources = $this->config->get('usage.resources', []);

        if (! is_array($resources)) {
            return [];
        }

        $catalog = [];

        foreach ($resources as $code => $definition) {
            if (is_string($code) && $code !== '' && is_array($definition)) {
                $catalog[$code] = $definition;
            }
        }

        return $catalog;
    }
}
