<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Settings;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\SaaS\Models\PlatformSetting;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Explicit, typed platform settings a Super Admin may change.
 *
 * Never an environment editor: every key here is named, validated and
 * audited. Infrastructure (hosts, databases, mail transport, the application
 * key) stays in configuration; secrets live in PlatformProviderCredential,
 * encrypted, never here.
 */
final class PlatformPreferences
{
    public const PLATFORM_NAME = 'general.platform_name';

    public const SUPPORT_EMAIL = 'general.support_email';

    public const SUPPORT_PHONE = 'general.support_phone';

    public const DEFAULT_LOCALE = 'general.default_locale';

    public const DEFAULT_TIMEZONE = 'general.default_timezone';

    public const MFA_REQUIRED = 'security.platform_mfa_required';

    public const AI_ENABLED = 'ai.enabled';

    public const AI_MODEL = 'ai.model';

    /** The model for report analysis; absent means "same as the assistant". */
    public const AI_REPORT_MODEL = 'ai.report_model';

    public const NOTIFICATION_RULES = 'notifications.rules';

    /**
     * Platform events a Super Admin can switch on or off, and the platform
     * permission that decides who sees each one.
     */
    public const EVENTS = [
        'support.ticket_created' => 'platform.support.view',
        'support.center_replied' => 'platform.support.view',
        'centers.registered' => 'platform.center.view',
        'centers.provisioning_failed' => 'platform.operations.view',
        'subscriptions.changed' => 'platform.subscription.manage',
        'billing.invoice_issued' => 'platform.billing.manage',
        'billing.payment_recorded' => 'platform.billing.manage',
        'subscriptions.trial_expiring' => 'platform.subscription.manage',
        'subscriptions.period_ending' => 'platform.subscription.manage',
        'usage.limit_warning' => 'platform.usage.manage',
        'operations.alert' => 'platform.operations.view',
    ];

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Audit $audit) {}

    /** @return array{platform_name: string, support_email: string, support_phone: string, default_locale: string, default_timezone: string} */
    public function general(): array
    {
        return [
            'platform_name' => $this->string(self::PLATFORM_NAME, 'Meta Style'),
            'support_email' => $this->string(self::SUPPORT_EMAIL, ''),
            'support_phone' => $this->string(self::SUPPORT_PHONE, ''),
            'default_locale' => $this->string(self::DEFAULT_LOCALE, (string) config('localization.fallback', 'en')),
            'default_timezone' => $this->string(self::DEFAULT_TIMEZONE, 'Asia/Baghdad'),
        ];
    }

    /**
     * @param  array{platform_name: string, support_email: string, support_phone: string, default_locale: string, default_timezone: string}  $values
     * @param  list<string>  $locales
     */
    public function saveGeneral(array $values, array $locales, Actor $actor): void
    {
        $values = array_map(static fn ($value): string => trim((string) $value), $values);
        if ($values['platform_name'] === '' || mb_strlen($values['platform_name']) > 80
            || ($values['support_email'] !== '' && filter_var($values['support_email'], FILTER_VALIDATE_EMAIL) === false)
            || mb_strlen($values['support_phone']) > 32
            || ! in_array($values['default_locale'], $locales, true)
            || ! in_array($values['default_timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException(__('platform_settings.errors.general'));
        }

        $this->write('general', [
            self::PLATFORM_NAME => $values['platform_name'],
            self::SUPPORT_EMAIL => $values['support_email'],
            self::SUPPORT_PHONE => $values['support_phone'],
            self::DEFAULT_LOCALE => $values['default_locale'],
            self::DEFAULT_TIMEZONE => $values['default_timezone'],
        ], $actor);
    }

    /**
     * Whether platform staff must pass a second factor.
     *
     * ON by default: absence of the setting is never read as "off".
     */
    public function mfaRequired(): bool
    {
        $value = $this->read(self::MFA_REQUIRED);

        return $value === null ? true : (bool) $value;
    }

    /**
     * Turning enforcement off never deletes anyone's enrolled secret, so
     * turning it back on restores exactly the previous protection.
     */
    public function setMfaRequired(bool $required, Actor $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('platform_settings.errors.reason'));
        }
        $before = $this->mfaRequired();
        PlatformSetting::put(self::MFA_REQUIRED, $required);
        unset($this->memo[self::MFA_REQUIRED]);

        $this->audit->record(new AuditEvent(
            action: $required ? 'platform.security.mfa_enforcement.enabled' : 'platform.security.mfa_enforcement.disabled',
            category: AuditCategory::Security,
            actor: $actor,
            severity: $required ? AuditSeverity::Notice : AuditSeverity::Critical,
            targetType: PlatformSetting::class,
            targetId: self::MFA_REQUIRED,
            targetLabel: 'Platform MFA enforcement',
            before: ['required' => $before],
            after: ['required' => $required],
            reason: $reason,
        ));
    }

    /**
     * The platform's AI configuration. The model is whatever a Super Admin
     * chose — any identifier the provider accepts, older and cheaper ones
     * included — or the deployment default when nothing valid is stored.
     *
     * @return array{enabled: bool, model: string, report_model: string|null}
     */
    public function ai(): array
    {
        $default = (string) config('rayan.providers.openai.model');
        $model = $this->string(self::AI_MODEL, $default);
        $report = $this->read(self::AI_REPORT_MODEL);

        return [
            'enabled' => (bool) ($this->read(self::AI_ENABLED) ?? true),
            'model' => self::isModelIdentifier($model) ? $model : $default,
            'report_model' => is_string($report) && self::isModelIdentifier($report) ? $report : null,
        ];
    }

    /**
     * The models a CENTER may pick from for its own assistant: the deployment's
     * allow-list plus whatever the platform itself is configured to use. A
     * center still never names an arbitrary model (ADR-071); the platform does.
     *
     * @return list<string>
     */
    public function approvedModels(): array
    {
        $models = config('rayan.providers.openai.approved_models', []);
        $models = is_array($models) ? array_values(array_filter($models, 'is_string')) : [];
        $ai = $this->ai();

        return array_values(array_unique(array_merge($models, array_filter([$ai['model'], $ai['report_model']]))));
    }

    /**
     * A syntactically valid provider model identifier. Not a claim that the
     * model exists: only the provider can say that (see AiModelCatalog).
     */
    public static function isModelIdentifier(string $model): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/', $model) === 1;
    }

    public function saveAi(bool $enabled, string $model, ?string $reportModel, Actor $actor): void
    {
        $model = trim($model);
        $reportModel = $reportModel !== null && trim($reportModel) !== '' ? trim($reportModel) : null;
        if (! self::isModelIdentifier($model) || ($reportModel !== null && ! self::isModelIdentifier($reportModel))) {
            throw new DomainException(__('platform_settings.errors.model'));
        }
        $this->write('ai', [self::AI_ENABLED => $enabled, self::AI_MODEL => $model, self::AI_REPORT_MODEL => $reportModel], $actor);
    }

    /**
     * Which platform events raise a notification, and whether it is emailed.
     *
     * @return array<string, array{in_app: bool, email: bool}>
     */
    public function notificationRules(): array
    {
        $stored = $this->read(self::NOTIFICATION_RULES);
        $stored = is_array($stored) ? $stored : [];
        $rules = [];
        foreach (array_keys(self::EVENTS) as $event) {
            $rule = is_array($stored[$event] ?? null) ? $stored[$event] : [];
            $rules[$event] = [
                'in_app' => (bool) ($rule['in_app'] ?? true),
                'email' => (bool) ($rule['email'] ?? false),
            ];
        }

        return $rules;
    }

    public function notifies(string $event, string $channel = 'in_app'): bool
    {
        return (bool) ($this->notificationRules()[$event][$channel] ?? false);
    }

    /** @param array<string, array{in_app: bool, email: bool}> $rules */
    public function saveNotificationRules(array $rules, Actor $actor): void
    {
        $clean = [];
        foreach (array_keys(self::EVENTS) as $event) {
            $clean[$event] = [
                'in_app' => (bool) ($rules[$event]['in_app'] ?? false),
                'email' => (bool) ($rules[$event]['email'] ?? false),
            ];
        }
        $this->write('notifications', [self::NOTIFICATION_RULES => $clean], $actor);
    }

    /** @param array<string, mixed> $values */
    private function write(string $section, array $values, Actor $actor): void
    {
        $before = [];
        foreach (array_keys($values) as $key) {
            $before[$key] = $this->read($key);
        }
        DB::connection('control')->transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                PlatformSetting::put($key, $value);
            }
        });
        foreach (array_keys($values) as $key) {
            unset($this->memo[$key]);
        }

        $this->audit->record(new AuditEvent(
            action: 'platform.settings.'.$section.'.updated',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: PlatformSetting::class,
            targetId: $section,
            targetLabel: 'Platform settings: '.$section,
            before: $before,
            after: $values,
        ));
    }

    private function read(string $key): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = PlatformSetting::get($key);
        }

        return $this->memo[$key];
    }

    private function string(string $key, string $default): string
    {
        $value = $this->read($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
