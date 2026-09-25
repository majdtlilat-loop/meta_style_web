<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Queue\Displays;
use App\Livewire\Center\Queue\ServicePoints;
use App\Livewire\Center\QueueBoard;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Queue setup: service desks and waiting-room screens
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §§8, 9, 16, 18. Every save goes through SaveServicePoint /
| SaveDisplay; the lists need `queue.display.manage`; a screen's public link
| is shown only when the center owns screens, and rotating it retires the old.
|
*/

it('creates, edits and archives a service desk through its Action', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $seed = $this->seedBookableCenter();
        $this->grantQueueEntitlements();
        $owner = $this->ownerWithCatalogAccess();
        $primary = app(TenantLocales::class)->default();

        $desks = Livewire::actingAs($owner)->test(ServicePoints::class)
            ->assertSee(__('manager_queue.setup.no_points'))
            ->call('create')
            ->assertSet('branch', $seed['branch']->uuid)
            ->set('names.'.$primary, 'Laser Room')
            ->set('code', 'l2')
            ->set('prefix', 'l')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', '')
            ->assertSee('L2')
            ->assertSee('Laser Room');

        $point = QueueServicePoint::query()->where('display_code', 'L2')->firstOrFail();

        expect($point->ticket_prefix)->toBe('L')
            ->and($point->branch_id)->toBe($seed['branch']->id);

        $desks->call('edit', $point->uuid)
            ->assertSet('code', 'L2')
            ->set('names.'.$primary, 'Laser Room 2')
            ->call('save')
            ->assertHasNoErrors();

        expect($point->fresh()?->name->get($primary))->toBe('Laser Room 2');

        // A duplicate code at the branch is refused by the Action, translated.
        $desks->call('create')->set('names.'.$primary, 'Twin')->set('code', 'L2')->call('save')
            ->assertSet('notice', __('manager_queue.errors.code_taken'));

        $desks->call('closePanel')->call('archive', $point->uuid);

        expect($point->fresh()?->archived_at)->not->toBeNull()
            ->and($point->fresh()?->is_active)->toBeFalse();

        $desks->set('archived', true)->assertSee('L2');
    });
});

it('configures a screen, hides its link without screens and rotates it', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $seed = $this->seedBookableCenter();
        // The queue without screens or voice: set up now, live later.
        $this->grantQueueEntitlements(['queue_management']);
        $point = $this->seedServicePoint($seed['branch'], 'R1', 'Reception');
        $owner = $this->ownerWithCatalogAccess();

        $screens = Livewire::actingAs($owner)->test(Displays::class)
            ->assertSee(__('manager_queue.setup.screens_locked'))
            ->call('create')
            ->set('name', 'Entrance TV')
            ->set('scope', 'service_point')
            ->set('point', $point->uuid)
            ->set('recent', 8)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Entrance TV')
            // No `queue_display`: the screen would 404, so no link is offered.
            ->assertDontSee(__('manager_queue.setup.open_screen'));

        $display = QueueDisplay::query()->where('name', 'Entrance TV')->firstOrFail();

        expect($display->service_point_id)->toBe($point->id)
            ->and($display->recent_calls_limit)->toBe(8);

        // Voice is its own add-on: offered, but locked, until it is owned.
        $screens->call('edit', $display->uuid)->assertSee(__('manager_queue.setup.voice_locked'));

        $this->grantQueueEntitlements(['queue_management', 'queue_display', 'queue_voice']);

        $screens->call('closePanel')
            ->assertSee(__('manager_queue.setup.open_screen'))
            ->assertSee($display->public_key);

        $old = $display->public_key;
        $screens->call('rotate', $display->uuid);

        expect($display->fresh()?->public_key)->not->toBe($old);
        $screens->assertDontSee($old);
    });
});

it('keeps desks and screens to the people who manage them', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $this->seedBookableCenter();
        $this->grantQueueEntitlements();

        $host = $this->staffWith([Permission::QueueView, Permission::QueueCall], 'host@queue.test');

        Livewire::actingAs($host)->test(QueueBoard::class)
            ->assertDontSee(__('manager_queue.tabs.setup'))
            ->call('setTab', 'setup')
            ->assertSet('tab', 'board');

        Livewire::actingAs($host)->test(ServicePoints::class)->assertForbidden();
        Livewire::actingAs($host)->test(Displays::class)->assertForbidden();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
