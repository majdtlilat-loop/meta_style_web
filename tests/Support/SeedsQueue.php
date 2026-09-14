<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Resources\Domain\Models\OperationalResource;

/**
 * Destinations and screens for the Phase 8 tests.
 *
 * Deliberately thin, like `SeedsResources`: every helper writes exactly the rows
 * a test needs and nothing else, so a failure points at its own setup rather
 * than at a shared fixture three files away.
 */
trait SeedsQueue
{
    /**
     * Buy the queue for this center, explicitly.
     *
     * NO PLAN SELLS IT. The queue keys are in the catalog and in none of the
     * seeded packages, because Phase 8 defined a capability and does not get to
     * decide which commercial package receives it — that is SaaS work, decided
     * on purpose and later.
     *
     * So every queue test grants what it needs the way a real center would get
     * it: a per-tenant override. Which is also why none of them can pass
     * vacuously — remove this call and the Action refuses, rather than the test
     * quietly proving nothing (`QueueEntitlementTest`).
     *
     * @param  list<string>  $keys
     */
    protected function grantQueueEntitlements(
        array $keys = ['queue_management', 'queue_display', 'queue_voice'],
        ?string $tenantId = null,
    ): void {
        $tenantId ??= app(TenantContext::class)->id();

        foreach ($keys as $key) {
            // One override per (tenant, entitlement) — the table says so. A
            // second call re-decides an existing add-on rather than stacking a
            // duplicate, which is also how support would actually use it.
            TenantEntitlementOverride::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'entitlement' => $key],
                ['mode' => OverrideMode::Grant, 'reason' => 'queue sold as an add-on', 'expires_at' => null],
            );
        }

        app(Entitlements::class)->invalidate($tenantId);
    }

    protected function seedServicePoint(
        Branch $branch,
        string $code = 'R1',
        string $name = 'Reception Desk',
        ?Department $department = null,
        ?string $prefix = null,
        ?OperationalResource $resource = null,
        int $sortOrder = 0,
    ): QueueServicePoint {
        /** @var QueueServicePoint $point */
        $point = QueueServicePoint::query()->create([
            'branch_id' => $branch->getKey(),
            'department_id' => $department?->getKey(),
            'name' => TranslatedText::fromArray(['en' => $name]),
            'display_code' => $code,
            'ticket_prefix' => $prefix,
            'resource_id' => $resource?->getKey(),
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);

        return $point;
    }

    protected function seedDisplay(
        Branch $branch,
        string $name = 'Entrance TV',
        ?Department $department = null,
        ?QueueServicePoint $point = null,
        bool $voice = true,
        int $recent = 5,
        ?string $locale = 'en',
    ): QueueDisplay {
        /** @var QueueDisplay $display */
        $display = QueueDisplay::query()->create([
            'branch_id' => $branch->getKey(),
            'name' => $name,
            'department_id' => $department?->getKey(),
            'service_point_id' => $point?->getKey(),
            'locale' => $locale,
            'recent_calls_limit' => $recent,
            'sound_enabled' => true,
            'voice_enabled' => $voice,
            'voice_locales' => ['en', 'ar', 'ckb'],
            'is_active' => true,
        ]);

        return $display;
    }
}
