<?php

declare(strict_types=1);

namespace App\Modules\LandingCms\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Modules\LandingCms\Domain\LandingContent;
use App\Modules\LandingCms\Domain\Models\LandingPage;
use Illuminate\Support\Facades\DB;

final class SaveLandingPage
{
    public function __construct(
        private readonly Audit $audit,
        private readonly LandingContent $schema,
    ) {}

    /** @param array<string, mixed> $content */
    public function __invoke(LandingPage $page, array $content, Actor $actor, bool $publish = false): LandingPage
    {
        $content = $this->schema->normalize($content);
        $version = $page->draft_version + 1;
        DB::connection('control')->transaction(function () use ($page, $content, $version, $actor, $publish): void {
            DB::connection('control')->table('landing_page_revisions')->insert(['landing_page_id' => $page->id, 'version' => $version, 'content' => json_encode($content, JSON_THROW_ON_ERROR), 'created_by_id' => (string) $actor->id, 'created_at' => now()]);
            $values = ['draft_content' => $content, 'draft_version' => $version, 'updated_by_id' => $actor->id];
            if ($publish) {
                $values += ['published_content' => $content, 'published_version' => $version, 'status' => 'published', 'published_at' => now()];
            }
            $page->forceFill($values)->save();
        });
        $this->audit->record(new AuditEvent(action: $publish ? 'platform.cms.published' : 'platform.cms.draft_saved', category: AuditCategory::Config, actor: $actor, targetType: LandingPage::class, targetId: $page->uuid, targetLabel: $page->slug, after: ['version' => $version]));

        return $page->refresh();
    }
}
