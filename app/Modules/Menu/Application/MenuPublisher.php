<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Menu\Domain\MenuPresentation;
use App\Modules\Menu\Domain\MenuVersionStatus;
use App\Modules\Menu\Domain\Models\MenuVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft → preview → publish → rollback for the electronic menu.
 *
 * A center must be able to redesign its menu without the live page changing
 * under a customer who is reading it. So editing writes to a DRAFT, publishing
 * promotes the draft and archives what was live, and rollback re-publishes an
 * archived version.
 *
 * Deliberately NOT a generic versioned-document engine. Invoices and reports
 * may want something similar later, but "similar" is not "the same", and an
 * abstraction built for one consumer before the second exists ends up fitting
 * neither (docs/13-ROADMAP.md Phase 4 §15).
 */
final class MenuPublisher
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * The version customers are served. Null before the first publish.
     */
    public function published(): ?MenuVersion
    {
        /** @var MenuVersion|null $version */
        $version = MenuVersion::query()->published()->first();

        return $version;
    }

    /**
     * The version the owner is editing, created on first use.
     *
     * Seeded from what is currently live rather than from the template
     * defaults: opening the editor should show the menu as it is, not reset it.
     */
    public function draft(): MenuVersion
    {
        /** @var MenuVersion|null $draft */
        $draft = MenuVersion::query()->draft()->first();

        if ($draft instanceof MenuVersion) {
            return $draft;
        }

        $source = $this->published();

        $presentation = $source instanceof MenuVersion
            ? $source->presentation()
            : MenuPresentation::default();

        return $this->createVersion($presentation, MenuVersionStatus::Draft);
    }

    /**
     * Saves the draft. Validation happens in {@see MenuPresentation}, so an
     * unknown template, an unlisted section or a colour that is not a colour
     * never reaches the database.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveDraft(array $input, User $actingUser): MenuVersion
    {
        $this->authorize($actingUser);

        $presentation = MenuPresentation::fromArray($input);

        $draft = $this->draft();

        $draft->forceFill($presentation->toArray())->save();

        return $draft;
    }

    /**
     * Makes the draft live.
     */
    public function publish(User $actingUser): MenuVersion
    {
        $this->authorize($actingUser);

        /** @var MenuVersion|null $draft */
        $draft = MenuVersion::query()->draft()->first();

        if (! $draft instanceof MenuVersion) {
            throw ValidationException::withMessages([
                'menu' => 'There is nothing to publish.',
            ]);
        }

        // Re-validated on the way out, in case the draft was written by an
        // older release whose catalog allowed something this one does not.
        $draft->presentation();

        $previous = $this->published();

        /** @var MenuVersion $published */
        $published = DB::connection('tenant')->transaction(function () use ($draft, $previous, $actingUser): MenuVersion {
            $previous?->forceFill(['status' => MenuVersionStatus::Archived])->save();

            $draft->forceFill([
                'status' => MenuVersionStatus::Published,
                'published_at' => Carbon::now(),
                'published_by_user_id' => $actingUser->getKey(),
            ])->save();

            return $draft;
        });

        $this->audit->record(new AuditEvent(
            action: 'menu.version.published',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: MenuVersion::class,
            targetId: $published->uuid,
            targetLabel: 'menu v'.$published->version,
            before: $previous === null ? null : ['version' => $previous->version],
            after: ['version' => $published->version, 'template' => $published->template_key],
        ));

        return $published;
    }

    /**
     * Republishes an archived version.
     *
     * Copies it forward as a NEW version rather than flipping the old row back
     * to published. History stays append-only, so "what was live on the 3rd"
     * has one answer, and the rollback itself is a visible event rather than a
     * gap in the sequence.
     */
    public function rollbackTo(MenuVersion $target, User $actingUser): MenuVersion
    {
        $this->authorize($actingUser);

        if ($target->status !== MenuVersionStatus::Archived) {
            throw ValidationException::withMessages([
                'menu' => 'Only an archived version can be restored.',
            ]);
        }

        $previous = $this->published();

        /** @var MenuVersion $restored */
        $restored = DB::connection('tenant')->transaction(function () use ($target, $previous, $actingUser): MenuVersion {
            $previous?->forceFill(['status' => MenuVersionStatus::Archived])->save();

            $version = $this->createVersion($target->presentation(), MenuVersionStatus::Published);

            $version->forceFill([
                'published_at' => Carbon::now(),
                'published_by_user_id' => $actingUser->getKey(),
            ])->save();

            return $version;
        });

        $this->audit->record(new AuditEvent(
            action: 'menu.version.rolled_back',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: MenuVersion::class,
            targetId: $restored->uuid,
            targetLabel: 'menu v'.$restored->version,
            before: $previous === null ? null : ['version' => $previous->version],
            after: ['version' => $restored->version, 'restored_from' => $target->version],
        ));

        return $restored;
    }

    /**
     * Gives a brand-new center a published menu.
     *
     * Called from provisioning so the public menu works the moment the center
     * exists, rather than 404ing until somebody visits the editor.
     */
    public function seed(): MenuVersion
    {
        if ($this->published() instanceof MenuVersion) {
            return $this->published();
        }

        $version = $this->createVersion(MenuPresentation::default(), MenuVersionStatus::Published);

        $version->forceFill(['published_at' => Carbon::now()])->save();

        return $version;
    }

    private function createVersion(MenuPresentation $presentation, MenuVersionStatus $status): MenuVersion
    {
        /** @var int $highest */
        $highest = MenuVersion::query()->max('version') ?? 0;

        /** @var MenuVersion $version */
        $version = MenuVersion::query()->create(array_merge($presentation->toArray(), [
            'status' => $status,
            'version' => $highest + 1,
        ]));

        return $version;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::MenuManage)) {
            throw new AuthorizationException('You may not manage the menu.');
        }
    }
}
