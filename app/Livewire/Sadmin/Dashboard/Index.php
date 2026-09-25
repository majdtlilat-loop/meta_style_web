<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Dashboard;

use App\Livewire\Concerns\HasDateRange;
use App\Modules\PlatformOperations\Application\PlatformOverview;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The platform overview: key figures against the comparable period, the
 * trends behind them, and the lists an operator acts on. One date range
 * drives every card, so the numbers always agree with each other.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use HasDateRange;

    protected function rangeTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    public function render(PlatformOverview $overview): mixed
    {
        $period = $this->dateRange();

        return view('livewire.sadmin.dashboard.index', [
            'period' => $period,
            'data' => $overview->build($period, app()->getLocale()),
        ]);
    }
}
