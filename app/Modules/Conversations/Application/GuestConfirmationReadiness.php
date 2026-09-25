<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Localization\TenantLocales;
use App\Modules\Conversations\Domain\Enums\NoticePurpose;
use App\Modules\Conversations\Domain\Enums\NoticeStatus;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

/**
 * Can this center confirm a guest's booking on WhatsApp right now — and what
 * happened to the ones it tried (docs/25-WHATSAPP.md §22).
 *
 * READ-ONLY. The sender ({@see GuestBookingConfirmations}) and the Manager's
 * settings card ask the SAME question here, so the screen can never claim the
 * switch works while the sender refuses, or the reverse.
 *
 * The center-level blockers, in the order they are reported:
 *
 *   channel_inactive      the center does not own `whatsapp_booking`
 *   disabled              the center switched guest confirmations off
 *   not_connected         no account, or one never given usable credentials
 *   account_off           the account is switched off
 *   provider_unavailable  the adapter is unavailable or cannot send templates
 *   no_template           no approved template is mapped for the purpose
 *                         (`config/whatsapp.php` ships empty on purpose, §14)
 *   template_language     none of the center's enabled languages is mapped
 *                         to a Meta template language code
 *
 * ## The template language
 *
 * Meta addresses an approved template by name AND language code, and its codes
 * are not the application's locales. The platform maps one to the other
 * (`whatsapp.template_languages`: `en` and `ar` by default; Kurdish stays
 * unmapped until Meta support for it is verified). A confirmation is written
 * in the customer's preferred language when the center has it enabled AND it
 * is mapped, else in the center's primary language when that is mapped, else
 * in any enabled language that is — and when there is none, nothing is sent.
 */
final class GuestConfirmationReadiness
{
    /** How far back the Manager's summary looks. */
    public const SUMMARY_DAYS = 30;

    /**
     * Skip reasons that belong to the CHANNEL — something a manager can act
     * on, unlike one customer's missing number or consent.
     */
    public const CHANNEL_REASONS = ['not_connected', 'account_off', 'provider_unavailable', 'no_template', 'template_language'];

    /** The shape of a Meta template language code: `en`, `ar`, `en_US`. */
    private const LANGUAGE_CODE = '/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/';

    public function __construct(
        private readonly ConversationsAccess $access,
        private readonly WhatsAppNotificationSettings $settings,
        private readonly WhatsAppReadiness $readiness,
        private readonly MessagingProviderRegistry $providers,
        private readonly TenantLocales $locales,
        private readonly Config $config,
    ) {}

    /**
     * The first reason nothing can be sent, or null when a confirmation would
     * go out.
     */
    public function blocker(?WhatsAppAccount $account = null): ?string
    {
        if (! $this->access->channelEnabled()) {
            return 'channel_inactive';
        }

        if (! $this->settings->guestBookingConfirmation()) {
            return 'disabled';
        }

        return $this->channelBlocker($account ?? $this->readiness->account());
    }

    /**
     * The same, ignoring the center's own switch — what the Manager needs to
     * see before turning it on.
     */
    public function channelBlocker(?WhatsAppAccount $account): ?string
    {
        if (! $account instanceof WhatsAppAccount || ! $account->isConfigured()) {
            return 'not_connected';
        }

        if (! $account->enabled) {
            return 'account_off';
        }

        if (! $this->providers->has($account->provider)) {
            return 'provider_unavailable';
        }

        $capabilities = $this->providers->get($account->provider)->capabilities();

        if (! $capabilities->available || ! $capabilities->templates) {
            return 'provider_unavailable';
        }

        if ($this->template() === null) {
            return 'no_template';
        }

        return $this->fallbackLanguage() === null ? 'template_language' : null;
    }

    public function account(): ?WhatsAppAccount
    {
        return $this->readiness->account();
    }

    /**
     * The approved template name mapped for the purpose — platform
     * configuration, never a center's input. Not a secret.
     */
    public function template(): ?string
    {
        $name = $this->config->get('whatsapp.templates.'.NoticePurpose::BookingConfirmation->templateKey());

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * Every language the center publishes in, in its own order, with the Meta
     * template language code the platform mapped for it — null when unmapped,
     * so no confirmation can be written in it.
     *
     * @return array<string, string|null> app locale => Meta code
     */
    public function templateLanguages(): array
    {
        $languages = [];

        foreach ($this->locales->enabled() as $locale) {
            $languages[$locale] = $this->templateCode($locale);
        }

        return $languages;
    }

    /**
     * The language a confirmation to a customer who prefers `$preferred` is
     * written in: theirs when the center has it enabled and it is mapped,
     * else {@see fallbackLanguage()}.
     *
     * @return array{locale: string, code: string}|null null when no enabled
     *                                                  language can be sent
     */
    public function templateLanguage(?string $preferred): ?array
    {
        if ($preferred !== null && $this->locales->isEnabled($preferred)) {
            $code = $this->templateCode($preferred);

            if ($code !== null) {
                return ['locale' => $preferred, 'code' => $code];
            }
        }

        return $this->fallbackLanguage();
    }

    /**
     * The center's primary language when it is mapped, else the first enabled
     * language that is.
     *
     * @return array{locale: string, code: string}|null
     */
    public function fallbackLanguage(): ?array
    {
        $languages = $this->templateLanguages();
        $primary = $this->locales->default();

        if (($languages[$primary] ?? null) !== null) {
            return ['locale' => $primary, 'code' => $languages[$primary]];
        }

        foreach ($languages as $locale => $code) {
            if ($code !== null) {
                return ['locale' => $locale, 'code' => $code];
            }
        }

        return null;
    }

    /**
     * The Meta template language code mapped for one app locale — platform
     * configuration, never a center's input.
     */
    private function templateCode(string $locale): ?string
    {
        $code = $this->config->get('whatsapp.template_languages.'.$locale);

        if (! is_string($code)) {
            return null;
        }

        $code = trim($code);

        return preg_match(self::LANGUAGE_CODE, $code) === 1 ? $code : null;
    }

    /**
     * What the last {@see SUMMARY_DAYS} days of guest confirmations came to.
     *
     * The delivery state is read from the MESSAGE when there is one, so a
     * refusal Meta reported later by status callback counts as the refusal it
     * is. `pending` and `unknown` are one bucket: nobody observed an outcome.
     *
     * @return array{
     *     counts: array{sent: int, failed: int, unconfirmed: int, skipped: int},
     *     last_issue: array{state: string, reason: string|null, at: string|null}|null
     * }
     */
    public function summary(?CarbonImmutable $now = null): array
    {
        $since = ($now ?? CarbonImmutable::now())->utc()->subDays(self::SUMMARY_DAYS);

        $rows = DB::connection('tenant')->table('whatsapp_outbound_notices as n')
            ->leftJoin('messages as m', 'm.id', '=', 'n.message_id')
            ->where('n.purpose', NoticePurpose::BookingConfirmation->value)
            ->where('n.created_at', '>=', $since)
            ->selectRaw('COALESCE(m.delivery_state, n.status) as state, COUNT(*) as aggregate')
            ->groupBy('state')
            ->get();

        $counts = ['sent' => 0, 'failed' => 0, 'unconfirmed' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $bucket = match ((string) ($row->state ?? '')) {
                NoticeStatus::Sent->value => 'sent',
                NoticeStatus::Failed->value => 'failed',
                NoticeStatus::Skipped->value => 'skipped',
                default => 'unconfirmed',
            };

            $counts[$bucket] += (int) ($row->aggregate ?? 0);
        }

        return ['counts' => $counts, 'last_issue' => $this->lastIssue($since)];
    }

    /**
     * The most recent confirmation that did not go out cleanly: refused,
     * unconfirmed, or skipped for a reason a manager can act on. A customer
     * without a number or who opted out is not an issue with the channel.
     *
     * @return array{state: string, reason: string|null, at: string|null}|null
     */
    private function lastIssue(CarbonImmutable $since): ?array
    {
        $row = DB::connection('tenant')->table('whatsapp_outbound_notices as n')
            ->leftJoin('messages as m', 'm.id', '=', 'n.message_id')
            ->where('n.purpose', NoticePurpose::BookingConfirmation->value)
            ->where('n.created_at', '>=', $since)
            ->where(function ($query): void {
                $query->whereIn('m.delivery_state', [NoticeStatus::Failed->value, NoticeStatus::Unknown->value])
                    ->orWhere(function ($notice): void {
                        $notice->whereNull('m.id')->whereIn('n.status', [NoticeStatus::Failed->value, NoticeStatus::Unknown->value]);
                    })
                    ->orWhere(function ($skipped): void {
                        $skipped->where('n.status', NoticeStatus::Skipped->value)
                            ->whereIn('n.reason', self::CHANNEL_REASONS);
                    });
            })
            ->orderByDesc('n.id')
            ->first(['n.status', 'n.reason', 'n.updated_at', 'm.delivery_state', 'm.failure_code']);

        if ($row === null) {
            return null;
        }

        $delivery = is_string($row->delivery_state ?? null) ? $row->delivery_state : null;

        return [
            'state' => $delivery ?? (string) $row->status,
            'reason' => is_string($row->failure_code ?? null) && $delivery !== null ? $row->failure_code : (is_string($row->reason ?? null) ? $row->reason : null),
            'at' => is_string($row->updated_at ?? null) ? CarbonImmutable::parse($row->updated_at, 'UTC')->utc()->toIso8601String() : null,
        ];
    }
}
