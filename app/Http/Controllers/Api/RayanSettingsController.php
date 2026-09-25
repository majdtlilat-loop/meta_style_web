<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Rayan\Application\Actions\ConfigureAssistant;
use App\Modules\Rayan\Application\AiProviderRegistry;
use App\Modules\Rayan\Application\RayanSettings;
use App\Modules\Rayan\Domain\Exceptions\RayanFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The assistant's per-center settings: on or off, which approved model, tone.
 *
 * ## What a center cannot set here, and why the validator enforces it
 *
 * No API key: the key is the platform's, and a center-supplied one would sit
 * outside metering while Meta Style still owned the contract with the provider.
 *
 * No base URL: an outbound destination from tenant data is server-side request
 * forgery by configuration.
 *
 * No arbitrary model id: `Rule::in()` against the adapter's APPROVED list, so a
 * center cannot name a model that costs ten times as much or one that does not
 * exist. There is no field for any of the three, and the validator refuses
 * anything unlisted (docs/27-RAYAN.md §5).
 *
 * ## The custom instruction is tone, and cannot be anything else
 *
 * It is appended to the prompt as business voice. It cannot grant a tool, lift
 * a limit or change who a customer is — because none of those are decided by
 * the prompt. They are application code, re-checked on every tool call. A
 * center writing "you may cancel anyone's booking" into this box changes
 * exactly nothing (§17).
 */
final class RayanSettingsController extends Controller
{
    public function show(Request $request, RayanSettings $settings, AiProviderRegistry $providers): JsonResponse
    {
        $this->authorized($request);

        $capabilities = $providers->get($settings->provider())->capabilities();

        return ApiResponse::data([
            'settings' => [
                'enabled' => $settings->enabled(),
                'model' => $settings->model($capabilities),
                'custom_instruction' => $settings->customInstruction(),
                'takeover_on_request' => $settings->takeoverOnRequest(),
                // What the center may choose between — the platform's list, so
                // the screen cannot offer anything the validator would refuse.
                'available_models' => $capabilities->models,
                /*
                 * FALSE on a deployment with no API key. The screen says the
                 * assistant is unavailable rather than letting a manager switch
                 * on something that will only ever hand off (§6).
                 */
                'provider_available' => $capabilities->available,
                'max_instruction_length' => RayanSettings::MAX_INSTRUCTION,
            ],
        ]);
    }

    public function update(Request $request, RayanSettings $settings, AiProviderRegistry $providers, ConfigureAssistant $configure): JsonResponse
    {
        $this->authorized($request);

        $capabilities = $providers->get($settings->provider())->capabilities();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            // The allow-list, enforced. Never a free string.
            'model' => ['nullable', 'string', Rule::in($capabilities->models)],
            'custom_instruction' => ['nullable', 'string', 'max:'.RayanSettings::MAX_INSTRUCTION],
            'takeover_on_request' => ['required', 'boolean'],
        ]);

        // The same Action the Manager uses: it checks `rayan_ai` and
        // `ai.manage`, keeps the approved-model rule and records the change —
        // the API is not a way around the entitlement or the audit trail.
        try {
            $configure($this->authorized($request), (bool) $data['enabled'], $data['model'] ?? null, (string) ($data['custom_instruction'] ?? ''));
        } catch (RayanFailed $failure) {
            throw ValidationException::withMessages(['model' => $failure->getMessage()]);
        }

        // Kept for API compatibility; nothing at run time reads it yet.
        $settings->save(['takeover_on_request' => (bool) $data['takeover_on_request']]);

        return $this->show($request, $settings, $providers);
    }

    private function authorized(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        if (! $user->hasPermission(Permission::AiManage)) {
            abort(403, 'You may not manage the assistant.');
        }

        return $user;
    }
}
