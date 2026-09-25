<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Identity\Models\User;
use App\Modules\CenterSite\Application\BrandSettings;
use App\Modules\CenterSite\Application\SiteAccess;
use App\Modules\CenterSite\Domain\CenterBrand;
use App\Modules\CenterSite\Domain\InvalidSiteContent;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Saves the brand's colours, gradients and shape — or resets them.
 *
 * The logo and favicon slots are NOT taken from the form: they change only
 * through the upload and remove actions, so a stale form can never point the
 * brand at a file it did not upload. Colours are stored exactly as chosen; a
 * low-contrast pair is reported in the editor, never silently adjusted.
 */
final class UpdateCenterBrand
{
    public function __construct(
        private readonly BrandSettings $settings,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the saved brand
     *
     * @throws AuthorizationException
     * @throws InvalidSiteContent
     */
    public function __invoke(array $input, User $actingUser): array
    {
        SiteAccess::ensureManage($actingUser);

        $current = $this->settings->get();
        $brand = CenterBrand::normalize(array_merge($input, array_intersect_key($current, array_flip(CenterBrand::ASSETS))));

        if ($brand === $current) {
            return $current;
        }

        $this->settings->put($brand);
        $this->record('center.brand.updated', $current, $brand, $actingUser);

        return $brand;
    }

    /**
     * Back to the default design. Logos and favicon stay.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function reset(User $actingUser): array
    {
        SiteAccess::ensureManage($actingUser);

        $current = $this->settings->get();
        $brand = array_merge(CenterBrand::defaults(), array_intersect_key($current, array_flip(CenterBrand::ASSETS)));

        if ($brand !== $current) {
            $this->settings->put($brand);
            $this->record('center.brand.reset', $current, $brand, $actingUser);
        }

        return $brand;
    }

    /**
     * Only what changed, flattened (`light.primary` => `#8a4b5a`).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function record(string $action, array $before, array $after, User $actingUser): void
    {
        $old = $this->flatten(CenterBrand::design($before));
        $new = $this->flatten(CenterBrand::design($after));
        $changed = array_keys(array_diff_assoc($new, $old) + array_diff_assoc($old, $new));

        $this->audit->record(new AuditEvent(
            action: $action,
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: CenterBrand::class,
            targetId: 'site-brand',
            targetLabel: 'center brand',
            before: array_intersect_key($old, array_flip($changed)),
            after: array_intersect_key($new, array_flip($changed)),
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix.$key.'.');
            } else {
                $flat[$prefix.$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return $flat;
    }
}
