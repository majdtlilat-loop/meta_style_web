<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Kernel\Localization\LanguageRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Phase 1 smoke component.
 *
 * Proves the Blade + Livewire foundation is wired end to end — component
 * resolution, layout, and a round-trip interaction — without introducing any
 * business UI. Replace or delete it once real screens exist.
 */
final class SystemStatus extends Component
{
    public bool $expanded = false;

    public function toggle(): void
    {
        $this->expanded = ! $this->expanded;
    }

    public function render(LanguageRegistry $languages): View
    {
        $locale = app()->getLocale();

        return view('livewire.system-status', [
            'locale' => $locale,
            'direction' => $languages->direction($locale),
            'supported' => $languages->supported(),
        ]);
    }
}
