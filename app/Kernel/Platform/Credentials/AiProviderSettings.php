<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Credentials;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Platform\Settings\PlatformPreferences;
use DomainException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Where the platform's AI provider key comes from, and whether AI is on.
 *
 * A key set by a Super Admin (encrypted in the control database) wins over the
 * deployment's environment key. Turning AI off makes the provider report
 * itself unavailable, which already hands every conversation to staff.
 *
 * The key is never returned to a screen, never audited and never logged.
 */
final class AiProviderSettings
{
    public const PROVIDER = 'openai';

    public function __construct(
        private readonly PlatformPreferences $preferences,
        private readonly Config $config,
        private readonly Http $http,
        private readonly Audit $audit,
    ) {}

    public function apiKey(): ?string
    {
        if (! $this->preferences->ai()['enabled']) {
            return null;
        }

        return $this->storedKey() ?? $this->environmentKey();
    }

    public function defaultModel(): string
    {
        return $this->preferences->ai()['model'];
    }

    /** The model for report analysis, or null for "same as the assistant". */
    public function reportModel(): ?string
    {
        return $this->preferences->ai()['report_model'];
    }

    /** @return list<string> */
    public function approvedModels(): array
    {
        return $this->preferences->approvedModels();
    }

    /**
     * The provider's own list of models this key can use (GET /models). The
     * key never leaves this class; only model identifiers are returned.
     *
     * @return array{ok: bool, error: string|null, models: list<array{id: string, created: int|null, owned_by: string|null}>}
     */
    public function fetchModels(): array
    {
        $key = $this->storedKey() ?? $this->environmentKey();
        if ($key === null) {
            return ['ok' => false, 'error' => 'not_configured', 'models' => []];
        }
        try {
            $base = rtrim((string) $this->config->get('rayan.providers.openai.base_url', 'https://api.openai.com/v1'), '/');
            $response = $this->http->withToken($key)->acceptJson()->connectTimeout(5)->timeout(15)->get($base.'/models');
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'unreachable', 'models' => []];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'error' => $response->status() === 401 ? 'rejected' : 'http_'.$response->status(), 'models' => []];
        }
        $data = $response->json('data');
        if (! is_array($data)) {
            return ['ok' => false, 'error' => 'invalid_response', 'models' => []];
        }
        $models = [];
        foreach ($data as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            if (! is_string($id) || ! PlatformPreferences::isModelIdentifier($id)) {
                continue;
            }
            $models[$id] = [
                'id' => $id,
                'created' => is_int($row['created'] ?? null) ? $row['created'] : null,
                'owned_by' => is_string($row['owned_by'] ?? null) ? mb_substr($row['owned_by'], 0, 64) : null,
            ];
        }
        ksort($models);

        return ['ok' => true, 'error' => null, 'models' => array_values($models)];
    }

    /**
     * @return array{source: string|null, hint: string|null, configured_at: Carbon|null, configured_by: string|null, tested_at: Carbon|null, test_ok: bool|null, test_message: string|null}
     */
    public function status(): array
    {
        $row = $this->row();
        $stored = $this->storedKey();

        return [
            'source' => $stored !== null ? 'platform' : ($this->environmentKey() !== null ? 'environment' : null),
            'hint' => $stored !== null ? $row?->hint : null,
            'configured_at' => $stored !== null ? $row?->configured_at : null,
            'configured_by' => $stored !== null ? $row?->configured_by_label : null,
            'tested_at' => $row?->last_tested_at,
            'test_ok' => $row?->last_test_ok,
            'test_message' => $row?->last_test_message,
        ];
    }

    public function setKey(#[\SensitiveParameter] string $key, Actor $actor): void
    {
        $key = trim($key);
        if (preg_match('/^[A-Za-z0-9_\-]{20,256}$/', $key) !== 1) {
            throw new DomainException(__('platform_settings.errors.api_key'));
        }

        $row = $this->row() ?? new PlatformProviderCredential(['provider' => self::PROVIDER]);
        $row->store(['api_key' => $key]);
        $row->forceFill([
            'hint' => mb_substr($key, -4),
            'configured_by_label' => $actor->label,
            'configured_at' => now(),
            'last_tested_at' => null,
            'last_test_ok' => null,
            'last_test_message' => null,
        ])->save();

        // Never the key, never its hint: only that it changed.
        $this->audit->record(new AuditEvent(
            action: 'platform.ai.credential.replaced',
            category: AuditCategory::Security,
            actor: $actor,
            severity: AuditSeverity::Notice,
            targetType: PlatformProviderCredential::class,
            targetId: self::PROVIDER,
            targetLabel: 'OpenAI API key',
        ));
    }

    public function clearKey(Actor $actor): void
    {
        $row = $this->row();
        if (! $row instanceof PlatformProviderCredential) {
            return;
        }
        $row->forceFill([
            'credentials' => null, 'hint' => null, 'configured_at' => null, 'configured_by_label' => null,
            'last_tested_at' => null, 'last_test_ok' => null, 'last_test_message' => null,
        ])->save();

        $this->audit->record(new AuditEvent(
            action: 'platform.ai.credential.removed',
            category: AuditCategory::Security,
            actor: $actor,
            severity: AuditSeverity::Notice,
            targetType: PlatformProviderCredential::class,
            targetId: self::PROVIDER,
            targetLabel: 'OpenAI API key',
        ));
    }

    /**
     * Asks the provider to list models with the configured key: it proves the
     * key is accepted without spending anything. The body is never kept.
     */
    public function test(Actor $actor): bool
    {
        $key = $this->storedKey() ?? $this->environmentKey();
        $ok = false;
        $result = 'not_configured';

        if ($key !== null) {
            try {
                $base = rtrim((string) $this->config->get('rayan.providers.openai.base_url', 'https://api.openai.com/v1'), '/');
                $response = $this->http->withToken($key)->acceptJson()->connectTimeout(5)->timeout(10)->get($base.'/models');
                $ok = $response->successful();
                $result = $ok ? 'ok' : ($response->status() === 401 ? 'rejected' : 'http_'.$response->status());
            } catch (Throwable) {
                $result = 'unreachable';
            }
        }

        $row = $this->row() ?? new PlatformProviderCredential(['provider' => self::PROVIDER]);
        $row->forceFill(['last_tested_at' => now(), 'last_test_ok' => $ok, 'last_test_message' => $result])->save();

        $this->audit->record(new AuditEvent(
            action: 'platform.ai.connection.tested',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: PlatformProviderCredential::class,
            targetId: self::PROVIDER,
            targetLabel: 'OpenAI',
            after: ['result' => $result],
        ));

        return $ok;
    }

    private function row(): ?PlatformProviderCredential
    {
        /** @var PlatformProviderCredential|null $row */
        $row = PlatformProviderCredential::query()->where('provider', self::PROVIDER)->first();

        return $row;
    }

    private function storedKey(): ?string
    {
        $key = $this->row()?->readable()['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function environmentKey(): ?string
    {
        $key = $this->config->get('rayan.providers.openai.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
