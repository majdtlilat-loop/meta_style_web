<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Modules\Rayan\Application\AiProviderRegistry;
use App\Modules\Rayan\Application\RayanSettings;
use App\Modules\Rayan\Domain\Events\AssistantSettingsChanged;
use App\Modules\Rayan\Domain\Exceptions\RayanFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The center's choices about its assistant: on or off, an APPROVED model, the
 * tone. Nothing else (docs/27-RAYAN.md §5).
 *
 * Checked here, whatever a screen showed: the center owns `rayan_ai` (NEW
 * configuration of a feature it does not own is refused), the person holds
 * `ai.manage`, the model is on the platform's approved list, and the tone is
 * within {@see RayanSettings::MAX_INSTRUCTION}. There is no field for a key, a
 * provider, a URL or a limit — those are the platform's.
 *
 * `takeover_on_request` is deliberately NOT written: nothing at runtime reads
 * it yet, and offering a switch that changes nothing would be a claim the
 * product cannot back. Whatever is stored for it is kept.
 *
 * The change is announced as {@see AssistantSettingsChanged}; the audit entry
 * is written by the listener, because this module may not reach the audit
 * trail itself.
 */
final class ConfigureAssistant
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly RayanSettings $settings,
        private readonly AiProviderRegistry $providers,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  string|null  $model  an approved model id, or null for the
     *                              platform default
     *
     * @throws EntitlementRequired
     * @throws AuthorizationException
     * @throws RayanFailed when the model is not approved or the tone too long
     */
    public function __invoke(User $user, bool $enabled, ?string $model, string $tone): void
    {
        $this->entitlements->ensure('rayan_ai');

        if (! $user->hasPermission(Permission::AiManage)) {
            throw new AuthorizationException('You may not manage the assistant.');
        }

        $model = $model === null || trim($model) === '' ? null : trim($model);
        $capabilities = $this->providers->get($this->settings->provider())->capabilities();

        if ($model !== null && ! $capabilities->allowsModel($model)) {
            throw RayanFailed::because('That model is not approved.');
        }

        $tone = trim($tone);

        if (mb_strlen($tone) > RayanSettings::MAX_INSTRUCTION) {
            throw RayanFailed::because('The tone is too long.');
        }

        $stored = $this->settings->all()['model'] ?? null;
        $before = ['enabled' => $this->settings->enabled(), 'model' => is_string($stored) && $stored !== '' ? $stored : null];
        $toneBefore = $this->settings->customInstruction();

        $this->settings->save([
            'enabled' => $enabled,
            'model' => $model,
            'custom_instruction' => $tone,
        ]);

        $after = ['enabled' => $enabled, 'model' => $model];
        $toneChanged = $toneBefore !== $this->settings->customInstruction();

        if ($before === $after && ! $toneChanged) {
            return;
        }

        $this->events->dispatch(new AssistantSettingsChanged(
            actorUuid: $user->uuid,
            actorName: $user->name,
            before: $before,
            after: $after,
            toneChanged: $toneChanged,
        ));
    }
}
