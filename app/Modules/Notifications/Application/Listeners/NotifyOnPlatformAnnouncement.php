<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application\Listeners;

use App\Kernel\Authorization\Permission;
use App\Kernel\Database\AfterCommit;
use App\Modules\Notifications\Application\NotificationCenter;
use App\Modules\Notifications\Application\StaffTargets;
use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\PlatformOperations\Domain\Events\PlatformAnnouncementPublished;

/**
 * A Meta Style announcement reaches the people in the center who deal with
 * the platform: holders of `platform_support.view` (the owner always is).
 */
final class NotifyOnPlatformAnnouncement
{
    public function __construct(
        private readonly NotificationCenter $center,
        private readonly StaffTargets $staff,
        private readonly AfterCommit $afterCommit,
    ) {}

    public function handle(PlatformAnnouncementPublished $event): void
    {
        $this->afterCommit->run('notifications.platform_announcement', function () use ($event): void {
            $recipients = $this->staff->withPermission(Permission::PlatformSupportView);
            if ($recipients === []) {
                return;
            }

            $this->center->deliver(new NotificationRequest(
                type: $event->important ? NotificationType::PlatformNotice : NotificationType::PlatformAnnouncement,
                sourceType: 'platform_announcement',
                sourceUuid: $event->announcementUuid,
                recipients: $recipients,
            ));
        });
    }
}
