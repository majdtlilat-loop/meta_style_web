<?php

declare(strict_types=1);

use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Usage\Models\TenantLimitOverride;
use App\Livewire\Sadmin\Centers\Show as CenterShow;
use App\Livewire\Sadmin\Usage\Index as UsageIndex;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->platformUser = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->center = $this->registerCenter('Override Test Center', 'owner@overrides.test');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('sets and resets a center entitlement override without sharing the lifecycle reason', function (): void {
    $this->actingAs($this->platformUser, 'platform');

    $component = Livewire::test(CenterShow::class, ['tenant' => $this->center['tenant']->id])
        ->set('lifecycleReason', 'Keep this independent from entitlement operations')
        ->set('entitlement', 'reports_standard')
        ->set('mode', 'grant')
        ->set('entitlementReason', 'Temporary reporting access for evaluation')
        ->call('setEntitlement')
        ->assertHasNoErrors()
        ->assertSet('lifecycleReason', 'Keep this independent from entitlement operations');

    $override = TenantEntitlementOverride::query()
        ->where('tenant_id', $this->center['tenant']->id)
        ->where('entitlement', 'reports_standard')
        ->firstOrFail();

    expect($override->mode->value)->toBe('grant')
        ->and(DB::connection('control')->table('platform_audit_logs')
            ->where('action', 'platform.entitlement.override.set')
            ->where('tenant_id', $this->center['tenant']->id)
            ->exists())->toBeTrue();

    $component
        ->set('entitlementReason', 'Evaluation ended; return to the subscribed plan')
        ->call('clearEntitlement', $override->id)
        ->assertHasNoErrors()
        ->assertSet('lifecycleReason', 'Keep this independent from entitlement operations');

    expect(TenantEntitlementOverride::query()->whereKey($override->id)->exists())->toBeFalse()
        ->and(DB::connection('control')->table('platform_audit_logs')
            ->where('action', 'platform.entitlement.override.cleared')
            ->where('tenant_id', $this->center['tenant']->id)
            ->where('reason', 'Evaluation ended; return to the subscribed plan')
            ->exists())->toBeTrue();
});

it('sets and resets the independent Advanced Reports AI allowance', function (): void {
    $this->actingAs($this->platformUser, 'platform');

    $component = Livewire::test(UsageIndex::class)
        ->set('tenantId', $this->center['tenant']->id)
        ->set('resource', 'advanced_report_ai_runs')
        ->set('allowance', '20')
        ->set('reason', 'Grant a separate Advanced Reports analysis allowance')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Advanced Reports AI')
        ->assertSee('Customer-facing RAYAN');

    $override = TenantLimitOverride::query()
        ->where('tenant_id', $this->center['tenant']->id)
        ->where('resource', 'advanced_report_ai_runs')
        ->firstOrFail();

    expect($override->allowance)->toBe(20)
        ->and(TenantLimitOverride::query()
            ->where('tenant_id', $this->center['tenant']->id)
            ->where('resource', 'ai_runs')
            ->exists())->toBeFalse()
        ->and(DB::connection('control')->table('platform_audit_logs')
            ->where('action', 'usage.limit_override.set')
            ->where('target_label', 'advanced_report_ai_runs')
            ->exists())->toBeTrue();

    $component
        ->set("clearReasons.{$override->id}", 'Return Advanced Reports AI to the plan allowance')
        ->call('clear', $override->id)
        ->assertHasNoErrors();

    expect(TenantLimitOverride::query()->whereKey($override->id)->exists())->toBeFalse()
        ->and(DB::connection('control')->table('platform_audit_logs')
            ->where('action', 'usage.limit_override.cleared')
            ->where('target_label', 'advanced_report_ai_runs')
            ->where('reason', 'Return Advanced Reports AI to the plan allowance')
            ->exists())->toBeTrue();
});
