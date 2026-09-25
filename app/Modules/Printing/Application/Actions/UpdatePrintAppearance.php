<?php

declare(strict_types=1);

namespace App\Modules\Printing\Application\Actions;

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
use App\Modules\Printing\Application\PrintAppearance;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Saves how the center's receipts, A4 invoices and queue tickets look.
 *
 * `printing` owns the paper (docs/18-SALES.md, locked entitlements), so a
 * center without it cannot change how paper it cannot print looks; losing it
 * stops the next change and leaves the stored settings alone.
 * `appearance.manage` is the permission. Audited by which settings changed,
 * never by the text itself.
 */
final class UpdatePrintAppearance
{
    public function __construct(
        private readonly PrintAppearance $print,
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
    public function __invoke(User $actingUser, array $input): Appearance
    {
        if (! $actingUser->hasPermission(Permission::AppearanceManage)) {
            throw new AuthorizationException(__('manager_appearance.errors.forbidden'));
        }

        $this->entitlements->ensure('printing');

        $before = $this->print->get();
        $after = Appearance::fromInput($this->print->schema(), $input, $this->languages->supported());
        $changed = $after->changedKeys($before);

        if ($changed === []) {
            return $after;
        }

        $this->print->put($after);

        $this->audit->record(new AuditEvent(
            action: 'print.appearance.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: 'appearance',
            targetId: 'print',
            targetLabel: 'print',
            after: ['changed' => $changed],
        ));

        return $after;
    }
}
