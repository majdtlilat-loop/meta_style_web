<?php

declare(strict_types=1);

namespace App\View\Components\Navigation;

use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Modules\PlatformOperations\Domain\Models\PlatformAlert;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Component;

final class PlatformAlerts extends Component
{
    /** @var Collection<int, PlatformAlert> */
    public readonly Collection $alerts;

    /** @var list<int> */
    public readonly array $readIds;

    public readonly int $unreadCount;

    public function __construct()
    {
        $user = auth('platform')->user();

        if (! $user instanceof PlatformUser) {
            $this->alerts = collect();
            $this->readIds = [];
            $this->unreadCount = 0;

            return;
        }

        $active = PlatformAlert::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest()
            ->get();

        /** @var list<int> $readIds */
        $readIds = DB::connection('control')
            ->table('platform_alert_reads')
            ->where('platform_user_id', $user->getKey())
            ->whereIn('alert_id', $active->pluck('id'))
            ->pluck('alert_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->alerts = $active->take(5)->values();
        $this->readIds = $readIds;
        $this->unreadCount = $active->whereNotIn('id', $readIds)->count();
    }

    public function isUnread(PlatformAlert $alert): bool
    {
        return ! in_array((int) $alert->id, $this->readIds, true);
    }

    public function render(): View
    {
        return view('components.navigation.platform-alerts');
    }
}
