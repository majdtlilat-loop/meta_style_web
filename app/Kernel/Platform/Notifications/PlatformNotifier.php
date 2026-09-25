<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Notifications;

use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Settings\PlatformPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Raises a notification for the platform team.
 *
 * One row in `platform_alerts`, titled and worded in every platform language
 * at write time from `lang/*\/platform_notifications.php`, with `source` set to
 * the event key. Who sees it is decided when it is READ, from the permission
 * the event maps to ({@see PlatformPreferences::EVENTS}) — so a "support
 * tickets only" agent hears about tickets and nothing else.
 *
 * Whether an event notifies at all, and whether it is also emailed, is the
 * Super Admin's choice (Settings → Notifications). A notification never breaks
 * the operation that raised it: failures are logged and dropped.
 */
final class PlatformNotifier
{
    public const LOCALES = ['en', 'ar', 'ckb'];

    public function __construct(private readonly PlatformPreferences $preferences) {}

    /**
     * @param  'info'|'warning'|'critical'  $severity
     * @param  array<string, string|int>  $params
     */
    public function notify(string $event, string $severity, string $message, array $params = [], ?string $tenantId = null, ?string $actionPath = null): void
    {
        if (! array_key_exists($event, PlatformPreferences::EVENTS)) {
            return;
        }

        try {
            $inApp = $this->preferences->notifies($event, 'in_app');
            $email = $this->preferences->notifies($event, 'email');
            if (! $inApp && ! $email) {
                return;
            }

            $title = [];
            $body = [];
            foreach (self::LOCALES as $locale) {
                $title[$locale] = (string) __('platform_notifications.'.$message.'.title', $params, $locale);
                $body[$locale] = (string) __('platform_notifications.'.$message.'.body', $params, $locale);
            }

            if ($inApp) {
                DB::connection('control')->table('platform_alerts')->insert([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'severity' => $severity,
                    'source' => $event,
                    'title' => json_encode($title, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'action_url' => $actionPath !== null && str_starts_with($actionPath, '/') ? $actionPath : null,
                    'is_active' => true,
                    'starts_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($email) {
                $this->email($event, $title, $body, $actionPath);
            }
        } catch (Throwable $exception) {
            Log::warning('Platform notification dropped.', ['event' => $event, 'error' => $exception::class]);
        }
    }

    /**
     * The events a platform user may see, by permission.
     *
     * @return list<string>
     */
    public static function visibleEvents(PlatformUser $user): array
    {
        return array_keys(array_filter(PlatformPreferences::EVENTS, fn (string $permission): bool => $user->hasPermission($permission)));
    }

    /**
     * @param  array<string, string>  $title
     * @param  array<string, string>  $body
     */
    private function email(string $event, array $title, array $body, ?string $actionPath): void
    {
        $permission = PlatformPreferences::EVENTS[$event];
        $recipients = PlatformUser::query()->where('is_active', true)->whereNull('archived_at')->get()
            ->filter(fn (PlatformUser $user): bool => $user->hasPermission($permission));

        foreach ($recipients as $user) {
            Mail::to($user->email)->queue(new PlatformNotificationMail($title, $body, $actionPath));
        }
    }
}
