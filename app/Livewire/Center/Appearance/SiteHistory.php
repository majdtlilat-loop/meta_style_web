<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance;

use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Contracts\SiteBranchReader;
use App\Modules\CenterSite\Application\SiteAccess;
use App\Modules\CenterSite\Application\SitePublisher;
use App\Modules\CenterSite\Domain\InvalidSiteContent;
use App\Modules\CenterSite\Domain\Models\SiteVersion;
use App\Modules\CenterSite\Domain\SiteVersionStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Component;

/**
 * The site's version history: every published version, newest first, with
 * who published it and when. Restoring copies a version FORWARD into the
 * draft (SitePublisher::restore) — nothing goes live until it is published.
 */
final class SiteHistory extends Component
{
    public function restore(string $uuid, SitePublisher $publisher): void
    {
        try {
            $draft = $publisher->restore($uuid, $this->actor());
        } catch (AuthorizationException|InvalidSiteContent $e) {
            $this->addError('restore', $e->getMessage());

            return;
        }
        session()->flash('notice', __('manager_site.history.restored', ['version' => $draft->restored_from_version]));
        $this->dispatch('site-restored');
    }

    public function render(SitePublisher $publisher, SiteBranchReader $branches): mixed
    {
        $user = $this->actor();
        abort_unless(SiteAccess::canView($user), 403);
        $timezone = $branches->mainTimezone();

        return view('livewire.center.appearance.site-history', [
            'canManage' => SiteAccess::canManage($user),
            'versions' => $publisher->history()->map(fn (SiteVersion $version): array => [
                'uuid' => $version->uuid,
                'version' => $version->version,
                'live' => $version->status === SiteVersionStatus::Published,
                'at' => $version->published_at?->copy()->setTimezone($timezone)->translatedFormat('j M Y, H:i'),
                'by' => $version->publishedBy?->name,
            ])->all(),
        ]);
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
