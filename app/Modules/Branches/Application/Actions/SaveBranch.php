<?php

declare(strict_types=1);

namespace App\Modules\Branches\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Branches\Domain\Data\BranchInput;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a branch.
 *
 * One action rather than two, because the authorization, the validation and the
 * audit shape are identical and the only difference is whether a row exists.
 * Two near-identical classes is where the second one quietly drifts.
 *
 * The transaction boundary and the audit write live HERE, not in the
 * controller or the Livewire component, so the API, the web UI and the future
 * setup wizard all get the same behaviour without repeating it
 * (docs/04-MODULE-BOUNDARIES.md).
 */
final class SaveBranch
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(BranchInput $input, User $actingUser, ?Branch $branch = null): Branch
    {
        $this->authorize($actingUser, $branch);

        $existing = $branch;

        /** @var Branch $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($input, $existing): Branch {
            $branch = $existing ?? new Branch;

            $branch->forceFill([
                'name' => TranslatedText::fromArray($input->name),
                'address' => $input->address === [] ? null : TranslatedText::fromArray($input->address),
                'timezone' => $input->timezone,
                'phone' => $input->phone,
                'whatsapp' => $input->whatsapp,
                'email' => $input->email,
                'map_url' => $input->mapUrl,
                'latitude' => $input->latitude,
                'longitude' => $input->longitude,
                'is_active' => $input->isActive,
                'is_public' => $input->isPublic,
                'sort_order' => $input->sortOrder,
            ]);

            // `is_main` is deliberately NOT settable here. Promoting a branch to
            // main reassigns what "the center" means and needs its own
            // deliberate action; letting it ride along on an ordinary edit is
            // how a center loses its main branch by accident.
            if ($branch->exists === false) {
                $branch->is_main = false;
            }

            $branch->save();

            return $branch;
        });

        $this->audit->record(new AuditEvent(
            action: $existing === null ? 'branches.branch.created' : 'branches.branch.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Branch::class,
            targetId: $saved->uuid,
            targetLabel: (string) $saved->name,
            before: $existing === null ? null : $this->snapshot($existing->getOriginal()),
            after: $this->snapshot($saved->getAttributes()),
        ));

        return $saved;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actingUser, ?Branch $branch): void
    {
        if (! $actingUser->hasPermission(Permission::BranchManage)) {
            throw new AuthorizationException('You may not manage branches.');
        }

        // Permission AND branch scope. A manager scoped to one branch must not
        // be able to edit another's opening hours or phone number.
        if ($branch !== null && ! $actingUser->branchScope()->allows($branch->id)) {
            throw new AuthorizationException('You may not manage that branch.');
        }
    }

    /**
     * Changed-attribute shape for the audit trail: identifiers and contact
     * details only, never the whole row (docs/08-AUDIT-SECURITY.md §3).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function snapshot(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip([
            'name', 'timezone', 'phone', 'whatsapp', 'email',
            'is_active', 'is_public', 'sort_order',
        ]));
    }
}
