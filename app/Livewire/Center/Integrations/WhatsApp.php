<?php

declare(strict_types=1);

namespace App\Livewire\Center\Integrations;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Modules\Conversations\Application\Actions\ManageWhatsAppAccount;
use App\Modules\Conversations\Application\WhatsAppConnections;
use App\Modules\Conversations\Application\WhatsAppReadiness;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Manager → Settings → WhatsApp: the center's own WhatsApp connection and the
 * booking bot that answers through it.
 *
 * ## Built on the Phase 13 backend, not beside it
 *
 * Credentials are written ONLY by `ManageWhatsAppAccount` (in the connection
 * drawer, {@see ConnectionForm}); the status is read from
 * `WhatsAppReadiness`, which reports what Meta Style recorded and calls no
 * provider — the Cloud API adapter is implemented, NOT live-verified
 * (docs/25-WHATSAPP.md §16), so the check is labelled "Check setup" and never
 * claims a live connection. Nothing secret is ever in this component's state
 * or its view: credentials appear as "configured / not configured" only.
 *
 * ## Who sees what
 *
 *   VIEW    `settings.view` or `whatsapp.manage` — the status, read-only.
 *   MANAGE  `whatsapp.manage` — connect, replace credentials, turn on or off,
 *           the webhook URL. `ai.manage` for the assistant ({@see AssistantSettings}).
 *
 * A center without `whatsapp_booking` sees the upgrade offer; with an account
 * already configured it also sees that account's status, read-only. Nothing
 * is operable, and every Action refuses on the server regardless.
 */
#[Layout('components.layouts.app')]
final class WhatsApp extends Component
{
    use RequiresFeature;

    public string $notice = '';

    public string $noticeTone = 'success';

    /** When "Check setup" last ran on this page — ISO-8601 UTC, display only. */
    public string $checkedAt = '';

    public function mount(): void
    {
        $this->viewer();
    }

    /**
     * Re-reads every recorded fact and says what it found. Reads only: no
     * provider is called, nothing is written.
     */
    public function checkSetup(WhatsAppReadiness $readiness): void
    {
        $this->viewer();

        $result = $readiness->check($readiness->account());
        $this->checkedAt = CarbonImmutable::now()->utc()->toIso8601String();

        [$this->notice, $this->noticeTone] = match ($result['state']) {
            'ready' => [(string) __('manager_whatsapp.check.result.ready'), 'success'],
            'waiting' => [(string) __('manager_whatsapp.check.result.waiting'), 'info'],
            'not_connected' => [(string) __('manager_whatsapp.check.result.not_connected'), 'warning'],
            default => [(string) __('manager_whatsapp.check.result.problem', [
                'item' => __('manager_whatsapp.problems.'.($result['problem'] ?? 'unknown')),
            ]), 'danger'],
        };
    }

    public function turnOn(ManageWhatsAppAccount $manage, WhatsAppReadiness $readiness): void
    {
        $this->switchChannel($manage, $readiness, true);
    }

    public function turnOff(ManageWhatsAppAccount $manage, WhatsAppReadiness $readiness): void
    {
        $this->switchChannel($manage, $readiness, false);
    }

    #[On('whatsapp-connection-saved')]
    public function connectionSaved(string $message = ''): void
    {
        $this->notice = $message !== '' ? $message : (string) __('manager_whatsapp.form.saved');
        $this->noticeTone = 'success';
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function render(
        Entitlements $entitlements,
        WhatsAppReadiness $readiness,
        WhatsAppConnections $connections,
        WhatsAppStatusView $present,
    ): View {
        $user = $this->viewer();
        $owned = $entitlements->enabled('whatsapp_booking');
        $account = $readiness->account();

        // Never had it and nothing to show: the upgrade offer, and no data.
        if (! $owned && ! $account instanceof WhatsAppAccount && ($offer = $this->lockedFeature('whatsapp_booking')) !== null) {
            return view('livewire.center.feature-locked', ['offer' => $offer])->title(__('manager_whatsapp.title'));
        }

        $canManage = $owned && $user->hasPermission(Permission::WhatsAppManage);
        $connection = null;
        $webhook = null;
        $facts = null;

        if ($account instanceof WhatsAppAccount) {
            $facts = $readiness->facts($account);
            $connection = $present->connection($account, $facts);

            // The URL a manager pastes into Meta: public key + public uuid,
            // safe to show — but only to the people who configure it.
            $webhook = $owned ? $present->webhook($facts, $canManage ? $connections->webhookUrl($account) : null) : null;
        }

        return view('livewire.center.integrations.whatsapp', [
            'owned' => $owned,
            'offer' => $owned ? null : $this->lockedFeature('whatsapp_booking'),
            'canManage' => $canManage,
            'account' => $connection,
            'webhook' => $webhook,
            'checks' => $owned ? $present->checks($readiness->check($account, $facts), $this->checkedAt) : null,
            'languages' => $present->languages(),
            'flow' => $present->flow(),
            'assistantOwned' => $entitlements->enabled('rayan_ai'),
            'languagesHref' => $user->hasPermission(Permission::SettingsView) && Route::has('center.settings')
                ? route('center.settings', ['tab' => 'languages'])
                : null,
            'conversationsHref' => $user->hasPermission(Permission::ConversationView) && Route::has('center.conversations')
                ? route('center.conversations')
                : null,
        ])->title(__('manager_whatsapp.title'));
    }

    private function switchChannel(ManageWhatsAppAccount $manage, WhatsAppReadiness $readiness, bool $on): void
    {
        $this->notice = '';

        $account = $readiness->account();

        if (! $account instanceof WhatsAppAccount) {
            $this->fail(__('manager_whatsapp.errors.not_connected'));

            return;
        }

        try {
            $manage->setEnabled($this->viewer(), $account, $on);
        } catch (EntitlementRequired) {
            $this->fail(__('manager_whatsapp.errors.entitlement'));

            return;
        } catch (AuthorizationException) {
            $this->fail(__('manager_whatsapp.errors.forbidden'));

            return;
        } catch (ConversationFailed $refused) {
            $this->fail(ConnectionForm::refusal($refused));

            return;
        }

        $this->notice = (string) __($on ? 'manager_whatsapp.connection.turned_on' : 'manager_whatsapp.connection.turned_off');
        $this->noticeTone = 'success';
    }

    private function fail(mixed $message): void
    {
        $this->notice = (string) $message;
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
