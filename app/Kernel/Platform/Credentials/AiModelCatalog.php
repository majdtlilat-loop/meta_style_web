<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Credentials;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\SaaS\Models\PlatformSetting;
use Illuminate\Support\Carbon;

/**
 * The provider's own model list, as last fetched with the platform key.
 *
 * It helps a Super Admin choose — it does not decide. Any valid identifier can
 * still be entered by hand when the list is unavailable or out of date, and
 * nothing here claims a model is cheaper, newer or better: the provider's list
 * carries identifiers, creation dates and owners, and that is all it shows.
 */
final class AiModelCatalog
{
    public const KEY = 'ai.model_catalog';

    public function __construct(
        private readonly AiProviderSettings $provider,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{ok: bool, error: string|null, count: int}
     */
    public function refresh(Actor $actor): array
    {
        $result = $this->provider->fetchModels();
        if ($result['ok']) {
            PlatformSetting::put(self::KEY, ['fetched_at' => now()->toIso8601String(), 'models' => $result['models']]);
        }

        $this->audit->record(new AuditEvent(
            action: 'platform.ai.models.refreshed',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: PlatformProviderCredential::class,
            targetId: AiProviderSettings::PROVIDER,
            targetLabel: 'OpenAI model catalog',
            after: ['result' => $result['ok'] ? 'ok' : $result['error'], 'count' => count($result['models'])],
        ));

        return ['ok' => $result['ok'], 'error' => $result['error'], 'count' => count($result['models'])];
    }

    /**
     * @return array{fetched_at: Carbon|null, models: list<array{id: string, created: int|null, owned_by: string|null}>}
     */
    public function cached(): array
    {
        $stored = PlatformSetting::get(self::KEY);
        if (! is_array($stored) || ! is_array($stored['models'] ?? null)) {
            return ['fetched_at' => null, 'models' => []];
        }
        $models = [];
        foreach ($stored['models'] as $model) {
            if (is_array($model) && is_string($model['id'] ?? null)) {
                $models[] = [
                    'id' => $model['id'],
                    'created' => is_int($model['created'] ?? null) ? $model['created'] : null,
                    'owned_by' => is_string($model['owned_by'] ?? null) ? $model['owned_by'] : null,
                ];
            }
        }

        return [
            'fetched_at' => is_string($stored['fetched_at'] ?? null) ? Carbon::parse($stored['fetched_at']) : null,
            'models' => $models,
        ];
    }

    /** Null when no catalog was ever fetched: then nobody can say either way. */
    public function contains(string $model): ?bool
    {
        $cached = $this->cached();
        if ($cached['fetched_at'] === null) {
            return null;
        }

        return in_array($model, array_column($cached['models'], 'id'), true);
    }
}
