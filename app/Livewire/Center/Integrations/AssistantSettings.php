<?php

declare(strict_types=1);

namespace App\Livewire\Center\Integrations;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Modules\Rayan\Application\Actions\ConfigureAssistant;
use App\Modules\Rayan\Application\AiProviderRegistry;
use App\Modules\Rayan\Application\RayanSettings;
use App\Modules\Rayan\Domain\Exceptions\RayanFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The booking bot: RAYAN on or off, the tone, and — when the platform approved
 * more than one — the model (docs/27-RAYAN.md §5).
 *
 * What a center may NOT set is simply not here: no key, no provider, no URL,
 * no limit. The model list is the platform's approved list, so the screen can
 * never offer what `ConfigureAssistant` would refuse. Switching it off leaves
 * WhatsApp working: every new conversation goes to the team.
 *
 * Needs `rayan_ai` (else the upgrade notice) and `ai.manage` to change
 * anything; everyone who can open the page sees the current state.
 */
final class AssistantSettings extends Component
{
    use RequiresFeature;

    public bool $enabled = true;

    public string $model = '';

    public string $tone = '';

    public string $notice = '';

    public string $noticeTone = 'success';

    public function mount(RayanSettings $settings): void
    {
        $this->viewer();
        $this->fill($this->stored($settings));
    }

    public function save(ConfigureAssistant $configure, RayanSettings $settings, AiProviderRegistry $providers): void
    {
        $this->notice = '';
        $capabilities = $providers->get($settings->provider())->capabilities();

        $this->validate([
            'enabled' => ['boolean'],
            'model' => ['nullable', 'string', Rule::in(['', ...$capabilities->models])],
            'tone' => ['nullable', 'string', 'max:'.RayanSettings::MAX_INSTRUCTION],
        ], [], [
            'model' => __('manager_whatsapp.assistant.model'),
            'tone' => __('manager_whatsapp.assistant.tone'),
        ]);

        try {
            $configure($this->viewer(), $this->enabled, $this->model === '' ? null : $this->model, $this->tone);
        } catch (EntitlementRequired) {
            $this->fail('manager_whatsapp.errors.assistant_entitlement');

            return;
        } catch (AuthorizationException) {
            $this->fail('manager_whatsapp.errors.forbidden');

            return;
        } catch (RayanFailed) {
            $this->fail('manager_whatsapp.errors.assistant_invalid');

            return;
        }

        $settings->forget();
        $this->fill($this->stored($settings));
        $this->notice = (string) __('manager_whatsapp.assistant.saved');
        $this->noticeTone = 'success';
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function render(Entitlements $entitlements, RayanSettings $settings, AiProviderRegistry $providers): View
    {
        $user = $this->viewer();
        $owned = $entitlements->enabled('rayan_ai');
        $capabilities = $providers->get($settings->provider())->capabilities();

        return view('livewire.center.integrations.assistant-settings', [
            'owned' => $owned,
            'offer' => $owned ? null : $this->lockedFeature('rayan_ai'),
            'canManage' => $owned && $user->hasPermission(Permission::AiManage),
            'isOn' => $owned && $settings->enabled(),
            // No platform key: RAYAN cannot answer on this installation, and
            // the screen says so instead of offering a switch that only ever
            // hands off (docs/27-RAYAN.md §6).
            'available' => $capabilities->available,
            'models' => $capabilities->models,
            'defaultModel' => $capabilities->defaultModel,
            'effectiveModel' => $capabilities->available ? $settings->model($capabilities) : null,
            'maxTone' => RayanSettings::MAX_INSTRUCTION,
            'toneLength' => mb_strlen($this->tone),
        ]);
    }

    /**
     * @return array{enabled: bool, model: string, tone: string}
     */
    private function stored(RayanSettings $settings): array
    {
        $model = $settings->all()['model'] ?? null;

        return [
            'enabled' => $settings->enabled(),
            'model' => is_string($model) ? $model : '',
            'tone' => $settings->customInstruction(),
        ];
    }

    private function fail(string $key): void
    {
        $this->notice = (string) __($key);
        $this->noticeTone = 'danger';
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless(
            $user instanceof User
            && ($user->hasPermission(Permission::SettingsView) || $user->hasPermission(Permission::WhatsAppManage)),
            403,
        );

        return $user;
    }
}
