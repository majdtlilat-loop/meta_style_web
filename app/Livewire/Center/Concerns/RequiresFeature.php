<?php

declare(strict_types=1);

namespace App\Livewire\Center\Concerns;

use App\Kernel\Identity\Models\User;
use App\View\Manager\FeatureOffer;
use Illuminate\Contracts\View\View;

/**
 * A Manager page that belongs to a plan feature.
 *
 * When the center owns NONE of the given features, the page renders the
 * upgrade state instead of itself and loads no data:
 *
 *     public function render(): View
 *     {
 *         if ($locked = $this->lockedView('queue_management')) {
 *             return $locked;
 *         }
 *         …
 *     }
 *
 * This is presentation. The module's Actions still refuse on the server
 * (Entitlements::ensure) whatever a client sends, and a page with history to
 * read after a downgrade decides for itself to stay readable (ADR "locked
 * pages"): call lockedFeature() and show the compact notice instead.
 */
trait RequiresFeature
{
    /**
     * The upgrade offer for the first feature, or null when any one is owned.
     *
     * @return array<string, mixed>|null
     */
    protected function lockedFeature(string ...$features): ?array
    {
        $offers = app(FeatureOffer::class);
        foreach ($features as $feature) {
            if ($offers->owns($feature)) {
                return null;
            }
        }

        $viewer = auth('web')->user();

        return $features === [] ? null : $offers->for($features[0], null, $viewer instanceof User ? $viewer : null);
    }

    /** The full-page upgrade state, or null when the page may render. */
    protected function lockedView(string ...$features): ?View
    {
        $offer = $this->lockedFeature(...$features);

        return $offer === null ? null : view('livewire.center.feature-locked', ['offer' => $offer]);
    }
}
