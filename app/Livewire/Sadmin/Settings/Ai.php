<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Settings;

use App\Kernel\Audit\Actor;
use App\Kernel\Platform\Credentials\AiModelCatalog;
use App\Kernel\Platform\Credentials\AiProviderSettings;
use App\Kernel\Platform\Settings\PlatformPreferences;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings → AI: the provider key (write-only, encrypted, `platform.security.manage`),
 * and the models the platform uses (`platform.ai.manage`).
 *
 * The model is not limited to a hard-coded list: the provider's own catalog
 * can be refreshed and searched, and any valid identifier can be entered by
 * hand when the catalog is unavailable. Nothing here claims what a model
 * costs — prices change and no maintained source is configured.
 */
#[Layout('layouts.superadmin.app')]
final class Ai extends Component
{
    use AuthorizesPlatform;

    public bool $enabled = true;

    public string $model = '';

    /** Empty = the report analyst uses the assistant's model. */
    public string $reportModel = '';

    public string $search = '';

    /** `key`, `remove`. */
    public ?string $panel = null;

    public string $apiKey = '';

    public function mount(PlatformPreferences $preferences): void
    {
        $this->requirePlatformPermission('platform.ai.manage');
        $ai = $preferences->ai();
        $this->enabled = $ai['enabled'];
        $this->model = $ai['model'];
        $this->reportModel = (string) $ai['report_model'];
    }

    public function useModel(string $model, string $workload = 'chat'): void
    {
        $this->requirePlatformPermission('platform.ai.manage');
        if (! PlatformPreferences::isModelIdentifier($model)) {
            return;
        }
        if ($workload === 'reports') {
            $this->reportModel = $model;
        } else {
            $this->model = $model;
        }
    }

    public function save(PlatformPreferences $preferences): void
    {
        $user = $this->requirePlatformPermission('platform.ai.manage');
        $this->validate([
            'model' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/'],
            'reportModel' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/'],
            'enabled' => ['boolean'],
        ], [], ['model' => __('platform_settings.ai.chat_model'), 'reportModel' => __('platform_settings.ai.report_model')]);

        try {
            $preferences->saveAi($this->enabled, $this->model, $this->reportModel !== '' ? $this->reportModel : null, Actor::platform($user));
        } catch (DomainException $exception) {
            $this->addError('model', $exception->getMessage());

            return;
        }
        session()->flash('notice', __('platform_settings.saved'));
    }

    public function refreshModels(AiModelCatalog $catalog): void
    {
        $user = $this->requirePlatformPermission('platform.ai.manage');
        $result = $catalog->refresh(Actor::platform($user));
        if ($result['ok']) {
            session()->flash('notice', __('platform_settings.ai.catalog.refreshed', ['count' => $result['count']]));
        } else {
            session()->flash('notice-error', __('platform_settings.ai.catalog.failed', ['reason' => __('platform_settings.ai.results.'.(in_array($result['error'], ['rejected', 'unreachable', 'not_configured'], true) ? $result['error'] : 'error'))]));
        }
    }

    public function testConnection(AiProviderSettings $ai): void
    {
        $user = $this->requirePlatformPermission('platform.ai.manage');
        $ok = $ai->test(Actor::platform($user));
        session()->flash($ok ? 'notice' : 'notice-error', $ok ? __('platform_settings.ai.test_ok') : __('platform_settings.ai.test_failed'));
    }

    public function openPanel(string $panel): void
    {
        $this->requirePlatformPermission('platform.ai.manage');
        $this->requirePlatformPermission('platform.security.manage');
        $this->closePanel();
        $this->panel = in_array($panel, ['key', 'remove'], true) ? $panel : null;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->reset('apiKey');
        $this->resetValidation();
    }

    public function saveApiKey(AiProviderSettings $ai): void
    {
        $this->requirePlatformPermission('platform.ai.manage');
        $user = $this->requirePlatformPermission('platform.security.manage');
        $this->validate(['apiKey' => ['required', 'string', 'min:20', 'max:256']], [], ['apiKey' => __('platform_settings.ai.key')]);
        $key = $this->apiKey;
        // Cleared before anything else can fail, so the secret never lingers in component state.
        $this->apiKey = '';
        try {
            $ai->setKey($key, Actor::platform($user));
        } catch (DomainException $exception) {
            $this->addError('apiKey', $exception->getMessage());

            return;
        }
        $this->closePanel();
        session()->flash('notice', __('platform_settings.ai.key_saved'));
    }

    public function removeApiKey(AiProviderSettings $ai): void
    {
        $this->requirePlatformPermission('platform.ai.manage');
        $user = $this->requirePlatformPermission('platform.security.manage');
        $ai->clearKey(Actor::platform($user));
        $this->closePanel();
        session()->flash('notice', __('platform_settings.ai.key_removed'));
    }

    public function render(AiProviderSettings $ai, AiModelCatalog $catalog, PlatformPreferences $preferences): mixed
    {
        $user = $this->requirePlatformPermission('platform.ai.manage');
        $cached = $catalog->cached();
        $needle = mb_strtolower(trim($this->search));
        $models = $needle === ''
            ? $cached['models']
            : array_values(array_filter($cached['models'], static fn (array $model): bool => str_contains(mb_strtolower($model['id']), $needle)));
        $saved = $preferences->ai();

        return view('livewire.sadmin.settings.ai', [
            'status' => $ai->status(),
            'canSecurity' => $user->hasPermission('platform.security.manage'),
            'catalog' => $cached,
            'models' => array_slice($models, 0, 60),
            'matches' => count($models),
            'saved' => $saved,
            'inCatalog' => [
                'model' => $catalog->contains($this->model),
                'reportModel' => $this->reportModel !== '' ? $catalog->contains($this->reportModel) : null,
            ],
            'dirty' => $this->enabled !== $saved['enabled'] || $this->model !== $saved['model'] || ($this->reportModel !== '' ? $this->reportModel : null) !== $saved['report_model'],
        ])->title(__('platform_settings.tabs.ai'));
    }
}
