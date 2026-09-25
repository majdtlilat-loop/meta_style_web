<?php

declare(strict_types=1);

namespace App\Kernel\Localization\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * A center changes which languages its CONTENT is written in — services, the
 * public pages, printed documents — and which one is primary.
 *
 * Not the interface language: each member of staff picks that for themselves
 * in the top bar. This is what customers read.
 *
 * The rules, all enforced here rather than in a form:
 *  - only languages the platform supports;
 *  - at least one language stays enabled;
 *  - the primary language must be enabled — so the CURRENT primary cannot be
 *    switched off without choosing another primary in the same change;
 *  - disabling never deletes a translation. {@see TenantLocales} stores only
 *    the list; every text keeps all its languages, and re-enabling one brings
 *    its fields back exactly as they were.
 *
 * Errors are keyed `enabled` and `primary`; a form maps them to its fields.
 */
final class UpdateContentLanguages
{
    public function __construct(
        private readonly TenantLocales $locales,
        private readonly LanguageRegistry $languages,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  list<mixed>  $enabled
     * @return array{enabled: list<string>, primary: string}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actingUser, array $enabled, string $primary): array
    {
        if (! $actingUser->hasPermission(Permission::SettingsManage)) {
            throw new AuthorizationException(__('manager_settings.errors.forbidden'));
        }

        $supported = $this->languages->supported();
        $clean = [];

        foreach ($enabled as $locale) {
            if (! is_string($locale) || ! in_array($locale, $supported, true)) {
                throw ValidationException::withMessages(['enabled' => __('manager_settings.languages.errors.unsupported')]);
            }

            if (! in_array($locale, $clean, true)) {
                $clean[] = $locale;
            }
        }

        // Keep the platform's order, so the tabs on every form are stable.
        $clean = array_values(array_filter($supported, static fn (string $l): bool => in_array($l, $clean, true)));

        if ($clean === []) {
            throw ValidationException::withMessages(['enabled' => __('manager_settings.languages.errors.at_least_one')]);
        }

        if (! in_array($primary, $supported, true)) {
            throw ValidationException::withMessages(['primary' => __('manager_settings.languages.errors.unsupported')]);
        }

        $before = ['enabled' => $this->locales->enabled(), 'primary' => $this->locales->default()];

        if (! in_array($primary, $clean, true)) {
            throw ValidationException::withMessages(['primary' => $primary === $before['primary'] && ! in_array($before['primary'], $clean, true)
                ? __('manager_settings.languages.errors.choose_new_primary')
                : __('manager_settings.languages.primary_must_be_enabled')]);
        }

        if ($before['enabled'] === $clean && $before['primary'] === $primary) {
            return $before;
        }

        $this->locales->setEnabled($clean, $primary);

        $this->audit->record(new AuditEvent(
            action: 'settings.languages.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: TenantLocales::class,
            targetId: 'customer-content-languages',
            before: $before,
            after: ['enabled' => $clean, 'primary' => $primary],
        ));

        return ['enabled' => $clean, 'primary' => $primary];
    }
}
