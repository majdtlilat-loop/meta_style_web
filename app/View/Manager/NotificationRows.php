<?php

declare(strict_types=1);

namespace App\View\Manager;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

/**
 * Inbox rows (App\Modules\Notifications\Application\NotificationPresenter) as
 * the Manager shows them: a short kind label, the message, a relative time in
 * the viewer's language and the absolute time in their branch's timezone,
 * and — where the notification is about something the viewer may open — a
 * link to that page. Presentation only; the rows are already the viewer's own.
 */
final class NotificationRows
{
    /** source type => [route, permission] */
    private const LINKS = [
        'conversation' => ['center.conversations', Permission::ConversationView],
        'review' => ['center.reviews', Permission::ReviewView],
        'usage' => ['center.usage', Permission::SettingsView],
    ];

    public function __construct(private readonly ViewerTimezone $timezones) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: string, kind: string, message: string, important: bool, unread: bool, datetime: string, relative: string, absolute: string, href: string|null}>
     */
    public function present(array $rows, ?User $viewer): array
    {
        $locale = app()->getLocale();
        $timezone = $viewer !== null ? $this->timezones->for($viewer) : (string) config('app.timezone', 'UTC');

        return array_map(function (array $row) use ($locale, $timezone, $viewer): array {
            $type = (string) ($row['type'] ?? '');
            $when = CarbonImmutable::parse((string) $row['created_at'])->setTimezone($timezone)->locale($locale);
            $source = is_array($row['source'] ?? null) ? (string) ($row['source']['type'] ?? '') : '';

            return [
                'id' => (string) $row['id'],
                'kind' => Lang::has('notifications_inbox.kind_'.$type) ? (string) __('notifications_inbox.kind_'.$type) : (string) __('notifications_inbox.title'),
                'message' => (string) $row['message'],
                'important' => ($row['severity'] ?? '') === 'important',
                'unread' => ($row['read_at'] ?? null) === null,
                'datetime' => $when->toIso8601String(),
                'relative' => $when->diffForHumans(),
                'absolute' => $when->translatedFormat('j F Y, H:i'),
                'href' => $this->link($source, $viewer),
            ];
        }, $rows);
    }

    private function link(string $source, ?User $viewer): ?string
    {
        if ($viewer === null || ! isset(self::LINKS[$source])) {
            return null;
        }

        [$route, $permission] = self::LINKS[$source];

        return Route::has($route) && $viewer->hasPermission($permission) ? route($route) : null;
    }
}
