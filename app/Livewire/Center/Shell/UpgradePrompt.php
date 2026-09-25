<?php

declare(strict_types=1);

namespace App\Livewire\Center\Shell;

use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Identity\Models\User;
use App\View\Manager\FeatureOffer;
use App\View\Manager\ManagerNavigation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The upgrade dialog a locked sidebar item opens (never a 403 page).
 *
 * The feature key comes from the page, so it is checked here against the
 * catalog AND the navigation's own feature map for THIS person — a crafted
 * event can neither advertise a module to somebody without its permission
 * nor turn an arbitrary string into a translation lookup. An owned feature,
 * or any feature while the subscription is not in good standing, opens
 * nothing: there is nothing to sell, and the banner already explains.
 *
 * It grants nothing. Every locked page and Action still refuses on the server.
 */
final class UpgradePrompt extends Component
{
    public ?string $feature = null;

    #[On('open-upgrade')]
    public function open(string $feature = ''): void
    {
        $user = $this->user();

        $this->feature = $user !== null
            && app(EntitlementCatalog::class)->has($feature)
            && in_array($feature, app(ManagerNavigation::class)->offerableFeatures($user), true)
            && app(FeatureOffer::class)->isLocked($feature)
                ? $feature
                : null;
    }

    public function close(): void
    {
        $this->feature = null;
    }

    public function render(FeatureOffer $offers, ManagerNavigation $navigation): View
    {
        $user = $this->user();
        $offer = null;
        $history = null;
        if ($this->feature !== null && $user !== null) {
            $offer = $offers->for($this->feature, null, $user);
            $history = $offer !== null ? $navigation->historyHref($user, $offer['key']) : null;
        }

        return view('livewire.center.shell.upgrade-prompt', [
            'offer' => $offer,
            'history' => $history,
            // Neither the Plan page nor platform support is open to this
            // person: say who can act instead of leaving a dead end.
            'askManager' => $offer !== null && $offer['links']['plans'] === null && $offer['links']['support'] === null,
        ]);
    }

    private function user(): ?User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : null;
    }
}
