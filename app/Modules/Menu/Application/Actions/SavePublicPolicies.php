<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\Actions;

use App\Kernel\Appearance\Appearance;
use App\Kernel\Appearance\AppearanceRejected;
use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Modules\Menu\Application\PublicPageAppearance;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Saves the cancellation and terms texts a guest reads on the booking page.
 *
 * Plain text in each language, capped in length. These are the center's own
 * words to its customers — they describe a policy, they do not enforce one:
 * the Booking Engine's rules (notice periods, lead times) stay in
 * BookingSettings and are changed by `UpdateBookingSettings`.
 *
 * Center configuration, so `settings.manage`. Audited by which text changed,
 * never the text itself.
 */
final class SavePublicPolicies
{
    public function __construct(
        private readonly PublicPageAppearance $pages,
        private readonly LanguageRegistry $languages,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, array<string, string|null>>  $texts  `cancellation` / `terms` => locale => text
     *
     * @throws AuthorizationException
     * @throws AppearanceRejected
     */
    public function __invoke(User $actingUser, array $texts): Appearance
    {
        if (! $actingUser->hasPermission(Permission::SettingsManage)) {
            throw new AuthorizationException(__('manager_settings.errors.forbidden'));
        }

        $before = $this->pages->get('policies');
        $after = Appearance::fromInput($this->pages->schema('policies'), ['texts' => $texts], $this->languages->supported());
        $changed = $after->changedKeys($before);

        if ($changed === []) {
            return $after;
        }

        $this->pages->put('policies', $after);

        $this->audit->record(new AuditEvent(
            action: 'settings.policies.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: 'settings',
            targetId: 'public_policies',
            after: ['changed' => $changed],
        ));

        return $after;
    }
}
