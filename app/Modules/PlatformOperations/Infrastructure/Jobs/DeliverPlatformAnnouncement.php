<?php

declare(strict_types=1);

namespace App\Modules\PlatformOperations\Infrastructure\Jobs;

use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\PlatformOperations\Domain\Events\PlatformAnnouncementPublished;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Hands one announcement to one center.
 *
 * One job per center: a center whose database is unavailable fails alone and
 * never blocks the others. The payload is two identifiers. Delivery is
 * idempotent downstream (the inbox's unique indexes), so a retry cannot
 * double-post.
 */
final class DeliverPlatformAnnouncement implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $announcementUuid,
        public readonly string $tenantId,
        public readonly bool $important,
    ) {}

    public function handle(StanclTenantContext $context): void
    {
        $tenant = TenantModel::query()->find($this->tenantId);
        if (! $tenant instanceof TenantModel || ! $tenant->toValueObject()->isProvisioned()) {
            return;
        }

        $context->runForModel($tenant, function (): void {
            event(new PlatformAnnouncementPublished($this->announcementUuid, $this->important));
        });
    }
}
