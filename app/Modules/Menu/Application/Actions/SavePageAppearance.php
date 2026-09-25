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
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Modules\Menu\Application\PublicPageAppearance;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Saves how the booking page or the cart page looks.
 *
 * `appearance.manage` is the permission — the center's public presentation,
 * separate from menu staff. The booking page also needs the `booking`
 * entitlement: a center that cannot take online bookings has no booking page
 * to style, and losing the entitlement stops the next change like every other
 * operation (docs/05-ENTITLEMENTS.md). The cart page has no entitlement of its
 * own; it has no backend yet and shows no cart.
 *
 * Audited by WHICH settings changed, not by the copy itself.
 */
final class SavePageAppearance
{
    public const PAGES = ['booking', 'cart'];

    public function __construct(
        private readonly PublicPageAppearance $pages,
        private readonly Entitlements $entitlements,
        private readonly LanguageRegistry $languages,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  `values` and `texts`
     *
     * @throws AuthorizationException
     * @throws AppearanceRejected
     */
    public function __invoke(User $actingUser, string $page, array $input): Appearance
    {
        if (! in_array($page, self::PAGES, true)) {
            throw new InvalidArgumentException("Unknown public page [{$page}].");
        }

        if (! $actingUser->hasPermission(Permission::AppearanceManage)) {
            throw new AuthorizationException(__('manager_appearance.errors.forbidden'));
        }

        if ($page === 'booking') {
            $this->entitlements->ensure('booking');
        }

        $before = $this->pages->get($page);
        $after = Appearance::fromInput($this->pages->schema($page), $input, $this->languages->supported());
        $changed = $after->changedKeys($before);

        if ($changed === []) {
            return $after;
        }

        $this->pages->put($page, $after);

        $this->audit->record(new AuditEvent(
            action: 'appearance.'.$page.'.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: 'appearance',
            targetId: $page,
            targetLabel: $page,
            after: ['changed' => $changed],
        ));

        return $after;
    }
}
