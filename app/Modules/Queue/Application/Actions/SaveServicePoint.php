<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Queue\Domain\TicketPrefix;
use App\Modules\Resources\Domain\Models\OperationalResource;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Create, update or archive a destination.
 *
 * ## Archive, never delete
 *
 * Tickets reference where a customer was sent, and that history has to stay
 * readable after a counter is retired — the same rule the rest of the
 * operational schema follows (CLAUDE.md, Phase 7 §12).
 *
 * ## Everything is branch-scoped and validated against it
 *
 * The department must belong to the center and the linked resource must stand
 * at THIS branch. A service point pointing at another branch's room would send
 * a customer to a building they are not in, and is exactly the cross-branch
 * leak §20 forbids.
 *
 * ## The prefix is validated here, where somebody can be told why
 *
 * `TicketPrefix::normalise()` refuses spaces and anything outside A-Z0-9, and
 * upper-cases what is left. `l` and `L` must not become two sequences at one
 * branch on one day, because that is two customers holding `L001` (§6).
 */
final class SaveServicePoint
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws QueueFailed
     * @throws AuthorizationException
     */
    public function __invoke(array $input, User $actingUser, ?QueueServicePoint $point = null): QueueServicePoint
    {
        $this->entitlements->ensure('queue_management');

        if (! $actingUser->hasPermission(Permission::QueueDisplayManage)) {
            throw new AuthorizationException('You may not manage queue destinations.');
        }

        $branch = $point instanceof QueueServicePoint
            ? $point->branch
            : $this->branch($input['branch'] ?? null);

        if (! $branch instanceof Branch) {
            throw QueueFailed::policy('That branch was not found.');
        }

        if (! $actingUser->canAccessBranch((int) $branch->getKey())) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        $code = $this->displayCode($input['display_code'] ?? null, $branch, $point);
        $prefix = array_key_exists('ticket_prefix', $input) && $input['ticket_prefix'] !== null
            ? TicketPrefix::normalise((string) $input['ticket_prefix'])
            : $point?->ticket_prefix;

        $department = $this->department($input['department'] ?? null);
        $resource = $this->resource($input['resource'] ?? null, $branch);

        $attributes = [
            'branch_id' => $branch->getKey(),
            'department_id' => $department?->getKey(),
            'name' => $this->name($input['name'] ?? null, $point),
            'display_code' => $code,
            'ticket_prefix' => $prefix,
            'resource_id' => $resource?->getKey(),
            'is_active' => (bool) ($input['is_active'] ?? $point->is_active ?? true),
            'sort_order' => (int) ($input['sort_order'] ?? $point->sort_order ?? 0),
        ];

        /** @var QueueServicePoint $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($point, $attributes): QueueServicePoint {
            if ($point instanceof QueueServicePoint) {
                $point->forceFill($attributes)->save();

                return $point;
            }

            /** @var QueueServicePoint $created */
            $created = QueueServicePoint::query()->create($attributes);

            return $created;
        });

        $this->audit->record(new AuditEvent(
            action: $point instanceof QueueServicePoint ? 'queue.service_point.updated' : 'queue.service_point.created',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueServicePoint::class,
            targetId: $saved->uuid,
            targetLabel: $saved->display_code,
            after: [
                'display_code' => $saved->display_code,
                'branch_id' => $saved->branch_id,
                'department_id' => $saved->department_id,
                'resource_id' => $saved->resource_id,
                'is_active' => $saved->is_active,
            ],
        ));

        return $saved;
    }

    /**
     * @throws AuthorizationException
     */
    public function archive(QueueServicePoint $point, User $actingUser): QueueServicePoint
    {
        $this->entitlements->ensure('queue_management');

        if (! $actingUser->hasPermission(Permission::QueueDisplayManage)) {
            throw new AuthorizationException('You may not manage queue destinations.');
        }

        if (! $actingUser->canAccessBranch((int) $point->branch_id)) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        // Retiring a counter stops the NEXT call going there and leaves every
        // ticket that was already sent to it exactly as it is.
        $point->forceFill([
            'is_active' => false,
            'archived_at' => CarbonImmutable::now()->utc(),
        ])->save();

        $this->audit->record(new AuditEvent(
            action: 'queue.service_point.archived',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueServicePoint::class,
            targetId: $point->uuid,
            targetLabel: $point->display_code,
            after: ['archived_at' => $point->archived_at?->toIso8601String()],
        ));

        return $point;
    }

    private function branch(mixed $uuid): ?Branch
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return Branch::query()->where('uuid', $uuid)->first();
    }

    /**
     * @throws QueueFailed
     */
    private function department(mixed $uuid): ?Department
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $department = Department::query()->where('uuid', $uuid)->first();

        if (! $department instanceof Department) {
            throw QueueFailed::policy('That department was not found.');
        }

        return $department;
    }

    /**
     * The room or device this counter IS, when it is one.
     *
     * @throws QueueFailed
     */
    private function resource(mixed $uuid, Branch $branch): ?OperationalResource
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $resource = OperationalResource::query()
            ->where('uuid', $uuid)
            // A destination at this branch cannot be a room at another one.
            ->where('branch_id', $branch->getKey())
            ->first();

        if (! $resource instanceof OperationalResource) {
            throw QueueFailed::policy('That resource is not available at this branch.');
        }

        return $resource;
    }

    /**
     * @throws QueueFailed
     */
    private function displayCode(mixed $code, Branch $branch, ?QueueServicePoint $point): string
    {
        $candidate = mb_strtoupper(trim((string) ($code ?? $point->display_code ?? '')));

        if ($candidate === '' || preg_match('/^[A-Z0-9-]{1,8}$/', $candidate) !== 1) {
            throw QueueFailed::policy(
                'A display code must be one to eight letters, digits or dashes — it is read from across a room.'
            );
        }

        $taken = QueueServicePoint::query()
            ->where('branch_id', $branch->getKey())
            ->where('display_code', $candidate)
            ->when($point instanceof QueueServicePoint, fn ($q) => $q->whereKeyNot($point?->getKey()))
            ->exists();

        if ($taken) {
            // Two "R1"s make a call ambiguous at the exact moment it has to be
            // obvious: a customer looking up at a screen.
            throw QueueFailed::policy('Another service point at this branch already uses that code.');
        }

        return $candidate;
    }

    /**
     * @throws QueueFailed
     */
    private function name(mixed $name, ?QueueServicePoint $point): TranslatedText
    {
        if (is_array($name) && $name !== []) {
            /** @var array<string, string|null> $name */
            return TranslatedText::fromArray($name);
        }

        if ($point instanceof QueueServicePoint) {
            return $point->name;
        }

        throw QueueFailed::policy('A service point needs a name.');
    }
}
