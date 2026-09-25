<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Identity\Models\User;
use App\Modules\CenterSite\Domain\InvalidSiteContent;
use App\Modules\CenterSite\Domain\Models\SiteVersion;
use App\Modules\CenterSite\Domain\SiteContent;
use App\Modules\CenterSite\Domain\SiteVersionStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Draft → preview → publish → restore for the center's public site.
 *
 * Editing writes to the DRAFT, so the live page never changes under a customer
 * who is reading it. Publishing promotes the draft and archives what was live.
 * Restoring copies an archived version FORWARD into the draft — history is
 * append-only, so "what was live on the 3rd" always has one answer, and a
 * restore is published like any other change, after review.
 *
 * Every write is authorised here (appearance.manage), validated by the
 * SiteContent allow-list against THIS center's references, and audited.
 */
final class SitePublisher
{
    public function __construct(
        private readonly Audit $audit,
        private readonly SiteReferences $references,
        private readonly SiteDefaults $defaults,
    ) {}

    /** The version customers are served. Null before the first publish. */
    public function published(): ?SiteVersion
    {
        /** @var SiteVersion|null $version */
        $version = SiteVersion::query()->published()->first();

        return $version;
    }

    /** The version being edited, if one exists. Reading never creates one. */
    public function currentDraft(): ?SiteVersion
    {
        /** @var SiteVersion|null $draft */
        $draft = SiteVersion::query()->draft()->first();

        return $draft;
    }

    /**
     * What the builder opens: the draft's content, else what is live, else the
     * default page. Tolerant — an older shape is brought up to date.
     *
     * @return array<string, mixed>
     */
    public function editableContent(): array
    {
        $source = $this->currentDraft() ?? $this->published();

        return SiteContent::hydrate($source instanceof SiteVersion ? $source->content : $this->defaults->content());
    }

    /**
     * What the public page renders: the published content, or the default page
     * for a center that has never published.
     *
     * @return array<string, mixed>
     */
    public function liveContent(): array
    {
        $published = $this->published();

        return SiteContent::hydrate($published instanceof SiteVersion ? $published->content : $this->defaults->content());
    }

    /**
     * Validates and stores the draft (creating it on first save).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws InvalidSiteContent
     */
    public function saveDraft(array $input, User $actingUser): SiteVersion
    {
        SiteAccess::ensureManage($actingUser);

        $content = (new SiteContent($this->references->context()))->normalize($input);

        /** @var SiteVersion $draft */
        $draft = DB::connection('tenant')->transaction(function () use ($content, $actingUser): SiteVersion {
            $draft = SiteVersion::query()->draft()->lockForUpdate()->first() ?? $this->createVersion($content, SiteVersionStatus::Draft);
            $draft->forceFill(['content' => $content, 'saved_by_user_id' => $actingUser->getKey()])->save();

            return $draft;
        });

        $this->audit->record(new AuditEvent(
            action: 'center.site.draft_saved',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: SiteVersion::class,
            targetId: $draft->uuid,
            targetLabel: 'site v'.$draft->version,
            after: ['version' => $draft->version, 'sections' => count($content['section_order'])],
        ));

        return $draft;
    }

    /**
     * Saves `$input` as the draft (when given) and makes the draft live.
     *
     * @param  array<string, mixed>|null  $input
     *
     * @throws AuthorizationException
     * @throws InvalidSiteContent
     */
    public function publish(User $actingUser, ?array $input = null): SiteVersion
    {
        SiteAccess::ensureManage($actingUser);

        if ($input !== null) {
            $this->saveDraft($input, $actingUser);
        }

        $draft = $this->currentDraft();
        if (! $draft instanceof SiteVersion) {
            throw new InvalidSiteContent('nothing_to_publish');
        }

        // Re-validated on the way out: a reference may have left this center's
        // catalog, or the primary language changed, since the draft was saved.
        $content = (new SiteContent($this->references->context()))->normalize(SiteContent::hydrate($draft->content));

        $previous = null;

        /** @var SiteVersion $published */
        $published = DB::connection('tenant')->transaction(function () use ($draft, $content, $actingUser, &$previous): SiteVersion {
            $locked = SiteVersion::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SiteVersionStatus::Draft) {
                throw new InvalidSiteContent('nothing_to_publish');
            }
            $previous = SiteVersion::query()->published()->lockForUpdate()->first();
            $previous?->forceFill(['status' => SiteVersionStatus::Archived])->save();

            $locked->forceFill([
                'status' => SiteVersionStatus::Published,
                'content' => $content,
                'published_at' => Carbon::now(),
                'published_by_user_id' => $actingUser->getKey(),
            ])->save();

            return $locked;
        });

        $this->audit->record(new AuditEvent(
            action: 'center.site.published',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: SiteVersion::class,
            targetId: $published->uuid,
            targetLabel: 'site v'.$published->version,
            before: $previous instanceof SiteVersion ? ['version' => $previous->version] : null,
            after: ['version' => $published->version, 'sections' => count($content['section_order'])],
        ));

        return $published;
    }

    /**
     * Copies an archived version's content into the draft. Nothing goes live
     * until the draft is published.
     *
     * @throws AuthorizationException
     * @throws InvalidSiteContent
     */
    public function restore(string $uuid, User $actingUser): SiteVersion
    {
        SiteAccess::ensureManage($actingUser);

        /** @var SiteVersion|null $source */
        $source = SiteVersion::query()->where('uuid', $uuid)->first();
        if (! $source instanceof SiteVersion || $source->status === SiteVersionStatus::Draft) {
            throw new InvalidSiteContent('restore_source');
        }

        $content = SiteContent::hydrate($source->content);

        /** @var SiteVersion $draft */
        $draft = DB::connection('tenant')->transaction(function () use ($content, $source, $actingUser): SiteVersion {
            $draft = SiteVersion::query()->draft()->lockForUpdate()->first() ?? $this->createVersion($content, SiteVersionStatus::Draft);
            $draft->forceFill([
                'content' => $content,
                'restored_from_version' => $source->version,
                'saved_by_user_id' => $actingUser->getKey(),
            ])->save();

            return $draft;
        });

        $this->audit->record(new AuditEvent(
            action: 'center.site.version_restored',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: SiteVersion::class,
            targetId: $draft->uuid,
            targetLabel: 'site v'.$draft->version,
            after: ['version' => $draft->version, 'restored_from' => $source->version],
        ));

        return $draft;
    }

    /**
     * Published and archived versions, newest first.
     *
     * @return Collection<int, SiteVersion>
     */
    public function history(int $limit = 20): Collection
    {
        /** @var Collection<int, SiteVersion> $versions */
        $versions = SiteVersion::query()
            ->whereIn('status', [SiteVersionStatus::Published->value, SiteVersionStatus::Archived->value])
            ->with('publishedBy:id,name')
            ->orderByDesc('version')
            ->limit($limit)
            ->get();

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function createVersion(array $content, SiteVersionStatus $status): SiteVersion
    {
        /** @var int|null $highest */
        $highest = SiteVersion::query()->lockForUpdate()->max('version');

        /** @var SiteVersion $version */
        $version = SiteVersion::query()->create([
            'status' => $status,
            'version' => ((int) $highest) + 1,
            'content' => $content,
        ]);

        return $version;
    }
}
