<?php

declare(strict_types=1);

use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Livewire\Sadmin\Alerts\Index as AlertsIndex;
use App\Livewire\Sadmin\Settings\Index as SettingsIndex;
use App\Modules\PlatformOperations\Domain\Models\PlatformAlert;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
});

function phase15PlatformUser(): PlatformUser
{
    $user = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $user->forceFill(['mfa_confirmed_at' => now()])->save();

    return $user;
}

it('renders an accessible icon sidebar and a non-navigating alert popover', function (): void {
    $user = phase15PlatformUser();
    $alert = PlatformAlert::query()->create([
        'severity' => 'warning',
        'source' => 'phase15-test',
        'title' => ['en' => 'Replica attention', 'ar' => 'تنبيه النسخة', 'ckb' => 'ئاگاداری کۆپی'],
        'body' => ['en' => 'Reporting credentials need attention.', 'ar' => 'بيانات اتصال التقارير تحتاج إلى اهتمام.', 'ckb' => 'زانیاری پەیوەندی ڕاپۆرت پێویستی بە سەرنجدانە.'],
        'action_url' => route('superadmin.operations.index'),
        'is_active' => true,
    ]);

    $response = $this->actingAs($user, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/');

    $response->assertOk()
        ->assertSee('data-sidebar-collapse', false)
        ->assertSee('data-nav-toggle', false)
        ->assertSee('data-popover', false)
        ->assertSee('aria-haspopup="menu"', false)
        ->assertSee('Replica attention')
        ->assertSee('data-unread="true"', false)
        ->assertSee('notification-count', false)
        ->assertSee('href="'.route('superadmin.alerts.index').'"', false)
        ->assertSee('data-tooltip="Overview"', false)
        ->assertSee('data-tooltip="Settings"', false);

    expect(substr_count($response->getContent(), 'class="ui-icon"'))->toBeGreaterThanOrEqual(11)
        ->and(DB::connection('control')->table('platform_alert_reads')->where('alert_id', $alert->id)->exists())->toBeFalse();
});

it('marks only the selected platform alert read and updates the topbar count', function (): void {
    $user = phase15PlatformUser();
    $first = PlatformAlert::query()->create(['severity' => 'info', 'source' => 'test', 'title' => ['en' => 'First', 'ar' => 'الأول', 'ckb' => 'یەکەم'], 'body' => ['en' => 'First body', 'ar' => 'النص الأول', 'ckb' => 'دەقی یەکەم'], 'is_active' => true]);
    $second = PlatformAlert::query()->create(['severity' => 'critical', 'source' => 'test', 'title' => ['en' => 'Second', 'ar' => 'الثاني', 'ckb' => 'دووەم'], 'body' => ['en' => 'Second body', 'ar' => 'النص الثاني', 'ckb' => 'دەقی دووەم'], 'is_active' => true]);

    $this->actingAs($user, 'platform');

    Livewire::test(AlertsIndex::class)->call('markRead', $first->id)->assertHasNoErrors();

    expect(DB::connection('control')->table('platform_alert_reads')->where('alert_id', $first->id)->where('platform_user_id', $user->id)->exists())->toBeTrue()
        ->and(DB::connection('control')->table('platform_alert_reads')->where('alert_id', $second->id)->where('platform_user_id', $user->id)->exists())->toBeFalse();

    $this->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/')
        ->assertOk()
        ->assertSee('>1</span>', false);
});

it('persists sidebar and theme state without server-side navigation state', function (): void {
    $script = file_get_contents(resource_path('js/platform/theme.js'));
    $layout = file_get_contents(resource_path('views/layouts/superadmin/app.blade.php'));

    expect($script)->toContain("localStorage.setItem('metastyle-theme'")
        ->toContain("localStorage.setItem('metastyle-sidebar'")
        ->toContain("localStorage.getItem('metastyle-sidebar'")
        ->toContain("document.addEventListener('livewire:navigated'")
        ->toContain("event.key !== 'Escape'")
        ->and($layout)->toContain("localStorage.getItem('metastyle-theme')")
        ->toContain("localStorage.getItem('metastyle-sidebar')");
});

it('updates audited platform defaults through the authorized settings surface', function (): void {
    $user = phase15PlatformUser();
    $plan = Plan::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();

    $this->actingAs($user, 'platform');

    Livewire::test(SettingsIndex::class)
        ->set('trialDays', 21)
        ->set('defaultPlanCode', $plan->code)
        ->set('reason', 'Align the local onboarding offer')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee(__('platform_settings.saved'));

    expect(PlatformSetting::get(PlatformSetting::DEFAULT_TRIAL_DAYS))->toBe(21)
        ->and(PlatformSetting::get(PlatformSetting::DEFAULT_PLAN_CODE))->toBe($plan->code)
        ->and(DB::connection('control')->table('platform_audit_logs')->where('action', 'platform.settings.updated')->where('actor_id', (string) $user->id)->exists())->toBeTrue();
});
