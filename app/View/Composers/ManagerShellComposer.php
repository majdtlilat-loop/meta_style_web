<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\View\Manager\ManagerShell;
use Illuminate\View\View;

/**
 * Hands the Manager layout its `$shell` (navigation, trail, account menu,
 * subscription banner). Registered for `components.layouts.app`, which
 * Livewire renders around every Manager page and the center sign-in pages.
 */
final class ManagerShellComposer
{
    public function __construct(private readonly ManagerShell $shell) {}

    public function compose(View $view): void
    {
        $view->with('shell', $this->shell->build());
    }
}
