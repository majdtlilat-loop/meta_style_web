<?php

declare(strict_types=1);

namespace App\Modules\PlatformOperations\Application;

use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Notifications\PlatformNotifier;
use App\Kernel\Platform\Settings\PlatformPreferences;
use App\Modules\PlatformOperations\Domain\Models\PlatformAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The platform notifications one person may see, and their read state.
 *
 * Visibility follows permission, per event: a support agent sees support
 * events, a billing agent billing events. Alerts raised by operations (not a
 * configurable event) are for people who can see operations. Read state is
 * per person, so one admin reading an alert never hides it from another.
 */
final class PlatformAlertFeed
{
    /** @return Builder<PlatformAlert> */
    public function query(PlatformUser $user): Builder
    {
        $events = PlatformNotifier::visibleEvents($user);
        $seesOperations = $user->hasPermission('platform.operations.view') || $user->hasPermission('platform.dashboard.view');

        return PlatformAlert::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(function (Builder $q) use ($events, $seesOperations): void {
                $q->whereIn('source', $events === [] ? ['-'] : $events);
                if ($seesOperations) {
                    $q->orWhereNotIn('source', array_keys(PlatformPreferences::EVENTS));
                }
            });
    }

    /** @return list<int> */
    public function readIds(PlatformUser $user): array
    {
        return DB::connection('control')->table('platform_alert_reads')
            ->where('platform_user_id', $user->getKey())
            ->pluck('alert_id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    public function unreadCount(PlatformUser $user): int
    {
        return $this->query($user)
            ->whereNotExists(fn ($q) => $q->from('platform_alert_reads')
                ->whereColumn('platform_alert_reads.alert_id', 'platform_alerts.id')
                ->where('platform_alert_reads.platform_user_id', $user->getKey()))
            ->count();
    }

    public function latestUnreadId(PlatformUser $user): int
    {
        return (int) $this->query($user)
            ->whereNotExists(fn ($q) => $q->from('platform_alert_reads')
                ->whereColumn('platform_alert_reads.alert_id', 'platform_alerts.id')
                ->where('platform_alert_reads.platform_user_id', $user->getKey()))
            ->max('id');
    }

    /** @return Collection<int, PlatformAlert> */
    public function latest(PlatformUser $user, int $limit = 6): Collection
    {
        return $this->query($user)->latest('id')->limit($limit)->get();
    }

    public function markRead(PlatformUser $user, int $alertId): ?PlatformAlert
    {
        $alert = $this->query($user)->whereKey($alertId)->first();
        if ($alert instanceof PlatformAlert) {
            DB::connection('control')->table('platform_alert_reads')->updateOrInsert(
                ['alert_id' => $alert->id, 'platform_user_id' => $user->getKey()],
                ['read_at' => now()],
            );
        }

        return $alert;
    }

    public function markAllRead(PlatformUser $user): void
    {
        $read = $this->readIds($user);
        $rows = $this->query($user)->whereNotIn('id', $read === [] ? [0] : $read)->pluck('id')
            ->map(fn (mixed $id): array => ['alert_id' => (int) $id, 'platform_user_id' => $user->getKey(), 'read_at' => now()])->all();
        if ($rows !== []) {
            DB::connection('control')->table('platform_alert_reads')->insertOrIgnore($rows);
        }
    }
}
