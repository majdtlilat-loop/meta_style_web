<?php

declare(strict_types=1);

use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Livewire\Sadmin\Plans\Index as PlansIndex;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->platformUser = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
});

afterEach(function (): void {
    $ids = Plan::query()->where('code', 'phase15_plan')->pluck('id');
    DB::connection('control')->table('plan_price_history')->whereIn('plan_id', $ids)->delete();
    DB::connection('control')->table('plan_entitlements')->whereIn('plan_id', $ids)->delete();
    Plan::query()->whereIn('id', $ids)->delete();
});

it('creates, reprices, archives and restores a commercial plan with history and audit', function (): void {
    $this->actingAs($this->platformUser, 'platform');

    Livewire::test(PlansIndex::class)
        ->call('create')
        ->assertSet('editorOpen', true)
        ->set('code', 'phase15_plan')
        ->set('name.en', 'Phase 15 Plan')
        ->set('name.ar', 'خطة المرحلة 15')
        ->set('name.ckb', 'پلانی قۆناغی ١٥')
        ->set('description.en', 'A managed commercial plan.')
        ->set('priceMinor', 250000)
        ->set('currency', 'iqd')
        ->set('billingPeriod', 'monthly')
        ->set('trialDays', '14')
        ->set('isPublic', true)
        ->set('sortOrder', 45)
        ->set('selectedEntitlements', ['reports_standard'])
        ->set('reason', 'Create the Phase 15 commercial plan')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editorOpen', false);

    $plan = Plan::query()->where('code', 'phase15_plan')->firstOrFail();

    expect($plan->currency)->toBe('IQD')
        ->and($plan->price_minor)->toBe(250000)
        ->and($plan->trial_days)->toBe(14)
        ->and($plan->is_active)->toBeTrue()
        ->and($plan->is_public)->toBeTrue()
        ->and($plan->name->get('ckb'))->toBe('پلانی قۆناغی ١٥')
        ->and($plan->entitlementCodes())->toBe(['reports_standard'])
        ->and(DB::connection('control')->table('plan_price_history')->where('plan_id', $plan->id)->count())->toBe(1)
        ->and(DB::connection('control')->table('platform_audit_logs')->where('action', 'platform.plan.created')->where('target_id', (string) $plan->id)->exists())->toBeTrue();

    Livewire::test(PlansIndex::class)
        ->call('edit', $plan->id)
        ->set('priceMinor', 300000)
        ->set('reason', 'Reprice the plan for the next subscribers')
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->refresh()->price_minor)->toBe(300000)
        ->and(DB::connection('control')->table('plan_price_history')->where('plan_id', $plan->id)->count())->toBe(2)
        ->and(DB::connection('control')->table('plan_price_history')->where('plan_id', $plan->id)->whereNotNull('effective_until')->count())->toBe(1);

    Livewire::test(PlansIndex::class)
        ->call('edit', $plan->id)
        ->set('reason', 'Retire this offer from new registrations')
        ->call('setActive', false)
        ->assertHasNoErrors();

    expect($plan->refresh()->is_active)->toBeFalse()
        ->and($plan->is_public)->toBeFalse();

    Livewire::test(PlansIndex::class)
        ->call('edit', $plan->id)
        ->set('reason', 'Restore the offer for new registrations')
        ->call('setActive', true)
        ->assertHasNoErrors();

    expect($plan->refresh()->is_active)->toBeTrue()
        ->and(DB::connection('control')->table('platform_audit_logs')->where('action', 'platform.plan.archived')->where('target_id', (string) $plan->id)->exists())->toBeTrue()
        ->and(DB::connection('control')->table('platform_audit_logs')->where('action', 'platform.plan.restored')->where('target_id', (string) $plan->id)->exists())->toBeTrue();
});

it('does not allow the active default plan to be archived', function (): void {
    $this->actingAs($this->platformUser, 'platform');
    $plan = Plan::query()->where('code', PlatformSetting::get(PlatformSetting::DEFAULT_PLAN_CODE))->firstOrFail();

    Livewire::test(PlansIndex::class)
        ->call('edit', $plan->id)
        ->set('reason', 'Attempt to retire the default commercial plan')
        ->call('setActive', false)
        ->assertHasErrors('reason');

    expect($plan->refresh()->is_active)->toBeTrue();
});
