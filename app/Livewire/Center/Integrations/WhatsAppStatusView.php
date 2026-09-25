<?php

declare(strict_types=1);

namespace App\Livewire\Center\Integrations;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\Modules\Rayan\Application\ToolRegistry;
use App\Modules\Rayan\Domain\Enums\RayanTool;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns the WhatsApp readiness facts into what the page prints.
 *
 * Every label is translated and every instant is put on the center's wall
 * clock here, so the templates decide nothing. Input is what
 * `WhatsAppReadiness` returns — booleans, identifiers that are not secret and
 * timestamps. No credential value ever reaches this class, so none can reach a
 * view.
 */
final class WhatsAppStatusView
{
    private ?string $timezone = null;

    public function __construct(
        private readonly TenantLocales $locales,
        private readonly LanguageRegistry $languages,
        private readonly ToolRegistry $tools,
    ) {}

    /**
     * @param  array<string, mixed>  $facts  WhatsAppReadiness::facts()
     * @return array<string, mixed>
     */
    public function connection(WhatsAppAccount $account, array $facts): array
    {
        /** @var array<string, bool> $fields */
        $fields = $facts['credential_fields'];
        /** @var array{state: string, at: string|null, code: string|null}|null $outbound */
        $outbound = $facts['last_outbound'];
        /** @var array{at: string|null, code: string}|null $error */
        $error = $facts['last_error'];

        return [
            'display_name' => $account->display_name,
            'number' => $account->display_phone_number,
            'phone_number_id' => $account->phone_number_id,
            'business_account_id' => $account->business_account_id,
            'provider' => $facts['provider_name'] ?? '—',
            'enabled' => (bool) $account->enabled,
            'configured' => $account->isConfigured(),
            'configured_label' => $facts['configured_at'] === null ? null : __('manager_whatsapp.connection.configured_by', [
                'name' => $facts['configured_by'] ?? '—',
                'time' => $this->time($facts['configured_at']),
            ]),
            'credentials_state' => $facts['credentials_state'],
            'credentials' => array_map(fn (string $field, bool $set): array => [
                'label' => $this->fieldLabel($field),
                'set' => $set,
            ], array_keys($fields), array_values($fields)),
            'last_outbound' => $outbound === null ? null : [
                'state' => $outbound['state'],
                'tone' => ['sent' => 'success', 'failed' => 'danger'][$outbound['state']] ?? 'warning',
                'label' => __('manager_whatsapp.delivery.'.$outbound['state']),
                'time' => $this->time($outbound['at']),
                'code' => $outbound['code'],
            ],
            'last_error' => $error === null ? null : ['code' => $error['code'], 'time' => $this->time($error['at'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function webhook(array $facts, ?string $url): array
    {
        $state = match (true) {
            (bool) $facts['signature_failing'] => 'failing',
            (bool) $facts['webhook_verified'] || $facts['handshake_at'] !== null => 'verified',
            default => 'pending',
        };

        /** @var array<string, bool> $fields */
        $fields = $facts['credential_fields'];

        return [
            'state' => $state,
            'label' => __('manager_whatsapp.webhook.states.'.$state),
            'tone' => ['failing' => 'danger', 'verified' => 'success', 'pending' => 'warning'][$state],
            'url' => $url,
            'verify_token_set' => (bool) ($fields['verify_token'] ?? false),
            'handshake' => $facts['handshake_at'] === null ? null : $this->time($facts['handshake_at']),
            'last_inbound' => $facts['last_inbound_at'] === null ? null : $this->time($facts['last_inbound_at']),
            'last_rejected' => $facts['last_rejected_at'] === null ? null : $this->time($facts['last_rejected_at']),
        ];
    }

    /**
     * @param  array{state: string, problem: string|null, checks: list<array{key: string, state: string, code: string|null}>}  $result
     * @return array<string, mixed>
     */
    public function checks(array $result, string $checkedAt): array
    {
        $icons = ['ok' => 'check-circle', 'problem' => 'alert-circle', 'pending' => 'clock', 'unknown' => 'circle'];
        $tones = ['ok' => 'success', 'problem' => 'danger', 'pending' => 'warning', 'unknown' => 'neutral'];

        return [
            'state' => $result['state'],
            'label' => __('manager_whatsapp.check.states.'.$result['state']),
            'tone' => ['ready' => 'success', 'waiting' => 'warning', 'problem' => 'danger', 'not_connected' => 'neutral'][$result['state']] ?? 'neutral',
            'checked' => $checkedAt === '' ? null : __('manager_whatsapp.check.checked_at', ['time' => $this->time($checkedAt, 'HH:mm')]),
            'items' => array_map(fn (array $check): array => [
                'key' => $check['key'],
                'label' => __('manager_whatsapp.checks.'.$check['key']),
                'state' => $check['state'],
                'icon' => $icons[$check['state']] ?? 'circle',
                'tone' => $tones[$check['state']] ?? 'neutral',
                'detail' => $check['code'] === null ? null : __('manager_whatsapp.problems.'.$check['code']),
            ], $result['checks']),
        ];
    }

    /**
     * The center's content languages: WhatsApp replies in the customer's own
     * language when it is one of these, otherwise in the primary one.
     *
     * @return list<array{code: string, short: string, native: string, primary: bool, dir: string}>
     */
    public function languages(): array
    {
        $primary = $this->locales->default();

        return array_map(fn (string $code): array => [
            'code' => $code,
            'short' => $this->languages->shortLabel($code),
            'native' => $this->languages->nativeName($code),
            'primary' => $code === $primary,
            'dir' => $this->languages->direction($code),
        ], $this->locales->enabled());
    }

    /**
     * What the booking bot actually does, read from what is REGISTERED — a tool
     * that is not in the registry is not claimed. The team lines describe the
     * channel itself (docs/25-WHATSAPP.md §§10, 12).
     *
     * @return array{team: list<string>, assistant: list<string>}
     */
    public function flow(): array
    {
        $lines = [
            'branches' => [RayanTool::ListBranches],
            'services' => [RayanTool::ListServices, RayanTool::GetServiceDetails],
            'slots' => [RayanTool::GetAvailableSlots],
            'book' => [RayanTool::CreateBooking],
            'reschedule' => [RayanTool::RescheduleBooking],
            'cancel' => [RayanTool::CancelBooking],
            'own_bookings' => [RayanTool::GetCustomerBookings, RayanTool::GetBookingDetails],
        ];

        $assistant = [];

        foreach ($lines as $key => $tools) {
            foreach ($tools as $tool) {
                if ($this->tools->has($tool->value)) {
                    $assistant[] = (string) __('manager_whatsapp.flow.assistant.'.$key);

                    break;
                }
            }
        }

        $assistant[] = (string) __('manager_whatsapp.flow.assistant.language');
        $assistant[] = (string) __('manager_whatsapp.flow.assistant.handoff');

        return [
            'team' => [
                (string) __('manager_whatsapp.flow.team.inbox'),
                (string) __('manager_whatsapp.flow.team.takeover'),
                (string) __('manager_whatsapp.flow.team.reply'),
            ],
            'assistant' => $assistant,
        ];
    }

    public function fieldLabel(string $field): string
    {
        $key = 'manager_whatsapp.credentials.fields.'.$field;
        $label = __($key);

        return $label === $key ? $field : (string) $label;
    }

    /**
     * An instant on the center's wall clock — the main branch's time zone,
     * because the account belongs to the center rather than to a branch.
     */
    public function time(?string $iso, string $pattern = 'D MMM YYYY, HH:mm'): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }

        try {
            $instant = CarbonImmutable::parse($iso)->utc();
        } catch (Throwable) {
            return '—';
        }

        return BranchClock::toLocal($instant, $this->timezone())
            ->locale(app()->getLocale())
            ->isoFormat($pattern);
    }

    private function timezone(): string
    {
        if ($this->timezone !== null) {
            return $this->timezone;
        }

        $zone = Branch::query()->orderByDesc('is_main')->orderBy('id')->value('timezone');

        return $this->timezone = is_string($zone) && $zone !== '' && BranchClock::isKnownTimezone($zone) ? $zone : 'UTC';
    }
}
