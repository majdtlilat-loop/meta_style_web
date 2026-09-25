<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use Illuminate\Support\Facades\DB;

final class UpdatePlatformSettings
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(int $trialDays, string $defaultPlanCode, Actor $actor, string $reason): void
    {
        $plan = Plan::query()->where('code', $defaultPlanCode)->where('is_active', true)->firstOrFail();
        $before = [
            PlatformSetting::DEFAULT_TRIAL_DAYS => PlatformSetting::get(PlatformSetting::DEFAULT_TRIAL_DAYS),
            PlatformSetting::DEFAULT_PLAN_CODE => PlatformSetting::get(PlatformSetting::DEFAULT_PLAN_CODE),
        ];
        $after = [
            PlatformSetting::DEFAULT_TRIAL_DAYS => $trialDays,
            PlatformSetting::DEFAULT_PLAN_CODE => $plan->code,
        ];

        DB::connection('control')->transaction(function () use ($after): void {
            PlatformSetting::put(PlatformSetting::DEFAULT_TRIAL_DAYS, $after[PlatformSetting::DEFAULT_TRIAL_DAYS]);
            PlatformSetting::put(PlatformSetting::DEFAULT_PLAN_CODE, $after[PlatformSetting::DEFAULT_PLAN_CODE]);
        });

        $this->audit->record(new AuditEvent(
            action: 'platform.settings.updated',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: PlatformSetting::class,
            targetId: 'saas-defaults',
            targetLabel: 'SaaS defaults',
            before: $before,
            after: $after,
            reason: trim($reason),
        ));
    }
}
