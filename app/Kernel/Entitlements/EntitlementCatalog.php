<?php

declare(strict_types=1);

namespace App\Kernel\Entitlements;

use App\Kernel\Entitlements\Exceptions\InvalidEntitlementCatalog;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The set of capabilities that exist, and how they depend on each other.
 *
 * Reads config/entitlements.php. Two jobs beyond lookup:
 *
 *  - **Dependency closure.** `queue_voice` is meaningless without
 *    `queue_management`. Rather than trusting every plan and override to be
 *    coherent, a granted entitlement whose dependencies are unmet is dropped
 *    from the effective set (docs/05-ENTITLEMENTS.md §4).
 *  - **Cycle detection.** A cycle would make closure non-deterministic — the
 *    answer would depend on iteration order. It is a hard error.
 */
final class EntitlementCatalog
{
    /** @var array<string, array{type: EntitlementType, category: string, requires: list<string>}>|null */
    private ?array $entries = null;

    public function __construct(private readonly Config $config) {}

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->entries());
    }

    public function has(string $key): bool
    {
        return isset($this->entries()[$key]);
    }

    public function assertKnown(string $key): void
    {
        if (! $this->has($key)) {
            throw InvalidEntitlementCatalog::unknownEntitlement($key);
        }
    }

    /**
     * Direct dependencies of one entitlement.
     *
     * @return list<string>
     */
    public function requires(string $key): array
    {
        return $this->entries()[$key]['requires'] ?? [];
    }

    public function category(string $key): string
    {
        return $this->entries()[$key]['category'] ?? 'general';
    }

    /**
     * Filters a granted set down to what is actually usable.
     *
     * Applied repeatedly until stable, so a transitive chain resolves
     * correctly: dropping `queue_management` must also drop `queue_voice`,
     * and anything that depended on *that*.
     *
     * @param  list<string>  $granted
     * @return list<string>
     */
    public function applyDependencies(array $granted): array
    {
        $effective = array_values(array_filter($granted, fn (string $key): bool => $this->has($key)));

        do {
            $before = count($effective);

            $effective = array_values(array_filter(
                $effective,
                function (string $key) use ($effective): bool {
                    foreach ($this->requires($key) as $dependency) {
                        if (! in_array($dependency, $effective, true)) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
        } while (count($effective) !== $before);

        return $effective;
    }

    /**
     * @return array<string, array{type: EntitlementType, category: string, requires: list<string>}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        /** @var array<string, array<string, mixed>> $raw */
        $raw = $this->config->get('entitlements.entitlements', []);

        $entries = [];

        foreach ($raw as $key => $definition) {
            /** @var list<string> $requires */
            $requires = is_array($definition['requires'] ?? null) ? array_values($definition['requires']) : [];

            $type = $definition['type'] ?? EntitlementType::Boolean;

            $entries[$key] = [
                'type' => $type instanceof EntitlementType ? $type : EntitlementType::Boolean,
                'category' => is_string($definition['category'] ?? null) ? $definition['category'] : 'general',
                'requires' => $requires,
            ];
        }

        $this->validate($entries);

        return $this->entries = $entries;
    }

    /**
     * @param  array<string, array{type: EntitlementType, category: string, requires: list<string>}>  $entries
     */
    private function validate(array $entries): void
    {
        foreach ($entries as $key => $entry) {
            foreach ($entry['requires'] as $dependency) {
                if (! isset($entries[$dependency])) {
                    throw InvalidEntitlementCatalog::unknownDependency((string) $key, $dependency);
                }
            }
        }

        // Iterative depth-first search: marks nodes grey while on the current
        // path, black when finished. Meeting a grey node is a cycle.
        $state = [];

        foreach (array_keys($entries) as $start) {
            if (($state[$start] ?? 'white') !== 'white') {
                continue;
            }

            $this->detectCycle((string) $start, $entries, $state, []);
        }
    }

    /**
     * @param  array<string, array{type: EntitlementType, category: string, requires: list<string>}>  $entries
     * @param  array<string, string>  $state
     * @param  list<string>  $path
     */
    private function detectCycle(string $key, array $entries, array &$state, array $path): void
    {
        $state[$key] = 'grey';
        $path[] = $key;

        foreach ($entries[$key]['requires'] as $dependency) {
            $dependencyState = $state[$dependency] ?? 'white';

            if ($dependencyState === 'grey') {
                $cycleStart = array_search($dependency, $path, true);

                throw InvalidEntitlementCatalog::cycle(
                    array_merge(array_slice($path, $cycleStart === false ? 0 : $cycleStart), [$dependency])
                );
            }

            if ($dependencyState === 'white') {
                $this->detectCycle($dependency, $entries, $state, $path);
            }
        }

        $state[$key] = 'black';
    }
}
