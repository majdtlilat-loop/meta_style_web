<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Booking\AppointmentPanel;
use App\Livewire\Center\Booking\Composer;
use App\Livewire\Center\Booking\RescheduleForm;
use App\Livewire\Center\Calendar;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Enums\BookingSource;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager bookings desk — screens
|--------------------------------------------------------------------------
|
| docs/15-BOOKING.md §§1, 7, 9, 13, 14, 16 and docs/24 §11. The page, the
| new-booking drawer, the booking drawer and the move form: every change goes
| through the engine or a Booking Action, every lookup through the scoped
| finder, and nothing untranslated reaches an Arabic or Kurdish screen.
|
*/

function bkdDate(int $days = 7): string
{
    return CarbonImmutable::now('Asia/Baghdad')->addDays($days)->format('Y-m-d');
}

/**
 * @param  array<string, mixed>  $seed
 */
function bkdBook(array $seed, User $by, string $time = '10:00', string $name = 'Sara Ahmed', string $phone = '+9647501234567', int $days = 7): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            startsAt: test()->localTime($seed['branch'], bkdDate($days), $time),
            customer: CustomerRef::details($name, $phone),
        ),
        BookingActor::staff($by),
    )->appointment;
}

function bkdRevokeBooking(string $tenantId): void
{
    DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
        ['tenant_id' => $tenantId, 'entitlement' => 'booking'],
        ['mode' => 'revoke', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
    );

    app(Entitlements::class)->invalidate($tenantId);
}

it('renders the desk in every interface language, with no raw keys', function (string $locale, string $direction, string $title): void {
    $center = $this->registerCenter('Desk Center', 'owner@desk.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $locale, $direction, $title): void {
        $seed = $this->seedBookableCenter();
        bkdBook($seed, $this->ownerWithCatalogAccess(), '10:00');

        $this->actingAs($owner);

        foreach (['day', 'week', 'list'] as $view) {
            $html = $this->get("http://{$slug}.localhost:8000/manager/calendar?locale={$locale}&view={$view}&date=".bkdDate())
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
                ->assertSee('<h1>'.$title.'</h1>', false)
                ->assertSee('Sara Ahmed')
                ->getContent();

            expect(preg_match('/\b(manager_booking|labels|ui)\.[a-z_]+\.[a-z_]+/', strip_tags($html)))->toBe(0);
        }
    });
})->with([
    'English' => ['en', 'ltr', 'Bookings'],
    'Arabic' => ['ar', 'rtl', 'الحجوزات'],
    'Kurdish Sorani' => ['ckb', 'rtl', 'نۆرەکان'],
]);

it('refuses the desk to somebody who may not see bookings', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->seedBookableCenter();

        Livewire::actingAs($this->staffWith([Permission::CustomerView], 'nobody@alpha.test'))
            ->test(Calendar::class)
            ->assertForbidden();
    });
});

it('shows the upgrade page and loads nothing when the center never had bookings', function (): void {
    $center = $this->registerCenter();
    bkdRevokeBooking($center['tenant']->id);
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->seedBookableCenter();

        Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(Calendar::class)
            ->assertSee('feature-lock', false)
            ->assertDontSee('bk-toolbar', false);
    });
});

it('keeps booking history readable, and read-only, after a downgrade', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $appointment = bkdBook($seed, $owner);

        bkdRevokeBooking($center['tenant']->id);

        Livewire::withQueryParams(['date' => bkdDate(), 'view' => 'list'])->actingAs($owner)
            ->test(Calendar::class)
            ->assertSee('Sara Ahmed')
            ->assertSee('feature-lock-notice', false)
            ->assertDontSee('wire:click="startBooking"', false);

        // The drawer offers nothing that writes, and the engine would refuse anyway.
        Livewire::actingAs($owner)
            ->test(AppointmentPanel::class, ['appointment' => $appointment->uuid])
            ->assertSee('Sara Ahmed')
            ->assertDontSee('wire:click="confirm"', false)
            ->call('confirm')
            ->assertSet('error', __('manager_booking.errors.locked'));

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Booked);
    });
});

it('scopes branches, defaults to one in scope, and never opens another branch\'s booking', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $other = $this->seedBranch('Mansour');
        $this->openEveryDay($other);
        $seed['employee']->branches()->attach($other->id);

        $there = app(BookingEngine::class)->book(new BookingRequest(
            branchUuid: $other->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            startsAt: $this->localTime($other, bkdDate(), '11:00'),
            customer: CustomerRef::details('Elsewhere Customer', '+9647509998877'),
        ), BookingActor::staff($owner))->appointment;

        $scoped = $this->staffWith([Permission::AppointmentView, Permission::AppointmentCreate, Permission::CustomerView], 'scoped@alpha.test');
        $scoped->forceFill(['all_branches' => false])->save();
        $scoped->syncBranchScope([(int) $other->id]);
        $scoped->forgetPermissionCache();

        // The default is the ONLY branch in scope, not the center's first.
        Livewire::withQueryParams(['date' => bkdDate()])->actingAs($scoped)
            ->test(Calendar::class)
            ->assertSet('branchUuid', $other->uuid)
            ->assertSee('Elsewhere Customer')
            // A branch typed into the request falls back to the scope.
            ->set('branchUuid', $seed['branch']->uuid)
            ->assertSet('branchUuid', $other->uuid);

        // Every branch at once still filters by service — each menu item once.
        Livewire::withQueryParams(['date' => bkdDate(), 'branch' => 'all'])->actingAs($owner)
            ->test(Calendar::class)
            ->assertSet('branchUuid', 'all')
            ->assertViewHas('services', fn (array $services): bool => in_array($seed['service']->uuid, array_column($services, 'uuid'), true)
                && count($services) === count(array_unique(array_column($services, 'uuid'))))
            ->set('serviceUuid', $seed['service']->uuid)
            ->assertSee('Elsewhere Customer');

        // A crafted open() of an out-of-scope booking finds nothing.
        $here = bkdBook($seed, $owner, '12:00', 'Hidden Customer', '+9647501110001');

        $elsewhere = $this->staffWith([Permission::AppointmentView], 'elsewhere@alpha.test');
        $elsewhere->forceFill(['all_branches' => false])->save();
        $elsewhere->syncBranchScope([(int) $other->id]);
        $elsewhere->forgetPermissionCache();

        Livewire::actingAs($elsewhere)
            ->test(AppointmentPanel::class, ['appointment' => $here->uuid])
            ->assertSee(__('manager_booking.panel.missing_title'))
            ->assertDontSee('Hidden Customer');

        unset($there);
    });
});

it('books a two-service visit once, shows the code once, and refuses a queued second click', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $beard = $this->seedService('Beard trim', 20, 8000, $seed['employee']);

        $composer = Livewire::actingAs($owner)
            ->test(Composer::class, ['branch' => $seed['branch']->uuid, 'date' => bkdDate()])
            ->set('form.customerSearch', 'Sara')
            ->call('chooseCustomer', $customer->uuid)
            ->assertSet('form.customerUuid', $customer->uuid)
            ->set('form.lines.0.service', $seed['service']->uuid)
            ->call('addLine')
            ->set('form.lines.1.service', $beard->uuid)
            ->call('findSlots')
            ->assertSet('error', '');

        $slots = $composer->get('slots');
        expect($slots)->not->toBeEmpty();

        $composer->call('pickSlot', $slots[0]['starts_at'])
            ->call('book')
            ->assertSet('step', 'done')
            ->assertDispatched('booking-created');

        // Grouped for reading aloud, and on THIS component only.
        expect($composer->get('issuedCode'))->toMatch('/^[0-9A-Z]{5}-[0-9A-Z]{5}$/');

        // A click queued behind the first one books nothing more.
        $composer->call('book');

        $appointment = Appointment::query()->with('items')->sole();

        expect($appointment->customer_id)->toBe($customer->id)
            ->and($appointment->source)->toBe(BookingSource::Staff)
            ->and($appointment->items)->toHaveCount(2);

        // The next action clears the code.
        $composer->call('bookAnother')->assertSet('issuedCode', '')->assertSet('step', 'form');
    });
});

it('creates a new customer from the phone field in E.164', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $composer = Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(Composer::class, ['branch' => $seed['branch']->uuid, 'date' => bkdDate()])
            ->call('useCustomerMode', 'new')
            ->set('form.newName', 'Noor Karim')
            ->set('form.newCountry', 'IQ')
            ->set('form.newPhone', '0770 555 1234')
            ->set('form.lines.0.service', $seed['service']->uuid)
            ->call('findSlots');

        $composer->call('pickSlot', $composer->get('slots')[0]['starts_at'])->call('book')->assertHasNoErrors();

        expect(Customer::query()->where('name', 'Noor Karim')->value('phone'))->toBe('+9647705551234');
    });
});

it('names the owner of a typed number only to somebody who may see phone numbers', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        bkdBook($seed, $owner, '10:00', 'Known Caller', '+9647705551234');

        Livewire::actingAs($owner)
            ->test(Composer::class, ['branch' => $seed['branch']->uuid, 'date' => bkdDate()])
            ->call('useCustomerMode', 'new')
            ->set('form.newCountry', 'IQ')
            ->set('form.newPhone', '0770 555 1234')
            ->assertSee(__('manager_booking.composer.phone_known', ['name' => 'Known Caller']));

        // Typing a number must not become a way to learn whose it is (ADR-042).
        $desk = $this->staffWith([Permission::AppointmentView, Permission::AppointmentCreate, Permission::CustomerView, Permission::CustomerCreate], 'desk@alpha.test');

        Livewire::actingAs($desk)
            ->test(Composer::class, ['branch' => $seed['branch']->uuid, 'date' => bkdDate()])
            ->call('useCustomerMode', 'new')
            ->set('form.newCountry', 'IQ')
            ->set('form.newPhone', '0770 555 1234')
            ->assertDontSee('Known Caller');
    });
});

it('validates the desk form in the viewer\'s language', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(Composer::class, ['branch' => $seed['branch']->uuid, 'date' => bkdDate()])
            ->call('useCustomerMode', 'new')
            ->set('form.newPhone', '12')
            ->call('book')
            ->assertHasErrors(['form.newName', 'form.newPhone', 'form.lines.0.service', 'form.slot']);

        expect(Appointment::query()->count())->toBe(0);
    });
});

it('refuses the new-booking drawer to somebody who may not book', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        Livewire::actingAs($this->staffWith([Permission::AppointmentView], 'viewer@alpha.test'))
            ->test(Composer::class, ['branch' => $seed['branch']->uuid])
            ->assertForbidden();
    });
});

it('confirms, notes, issues a code and cancels from the booking drawer', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $appointment = bkdBook($seed, $owner);

        $panel = Livewire::actingAs($owner)
            ->test(AppointmentPanel::class, ['appointment' => $appointment->uuid])
            // The advisory sits in front of the person about to type (§11).
            ->assertSee('not a medical record', false)
            ->call('confirm')
            ->assertSet('error', '')
            ->assertDispatched('appointment-changed');

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);

        $panel->set('noteBody', 'Prefers the window seat')->set('noteVisibility', 'manager_only')->call('addNote')->assertHasNoErrors();
        $note = $appointment->internalNotes()->sole();
        expect($note->body)->toBe('Prefers the window seat');

        $panel->call('deleteNote', $note->uuid);
        expect($appointment->internalNotes()->count())->toBe(0);

        $panel->call('issueCode');
        expect($panel->get('issuedCode'))->toMatch('/^[0-9A-Z]{5}-[0-9A-Z]{5}$/');

        // A no-show before the start is refused — in words, not a 500.
        $panel->call('noShow')->assertSet('error', __('manager_booking.errors.no_show_early'));

        $panel->call('show', 'cancel')
            ->set('cancelReason', 'Customer called')
            ->call('cancel')
            ->assertSet('error', '')
            ->assertSet('issuedCode', '');

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled)
            ->and($appointment->cancellation_reason)->toBe('Customer called');
    });
});

it('changes the team member from the drawer', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');
        $appointment = bkdBook($seed, $owner);
        $item = $appointment->items()->sole();

        Livewire::actingAs($owner)
            ->test(AppointmentPanel::class, ['appointment' => $appointment->uuid])
            ->call('startReassign', $item->uuid)
            ->assertSee('Sara')
            ->set('reassignTo', $sara->uuid)
            ->call('reassign')
            ->assertSet('error', '')
            ->assertSet('mode', 'detail');

        expect($item->refresh()->employee_id)->toBe($sara->id);
    });
});

it('moves a booking by half an hour through the move form', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $colour = $this->seedService('Colour', 60, 40000, $seed['employee']);

        $appointment = app(BookingEngine::class)->book(new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($colour->uuid)],
            startsAt: $this->localTime($seed['branch'], bkdDate(), '10:00'),
            customer: CustomerRef::details('Mover', '+9647502223344'),
        ), BookingActor::staff($owner))->appointment;

        $form = Livewire::actingAs($owner)->test(RescheduleForm::class, ['appointment' => $appointment->uuid])
            ->assertSet('date', bkdDate());

        $target = collect($form->get('slots'))->firstWhere('time', '10:30');
        expect($target)->not->toBeNull();

        $form->call('pick', $target['starts_at'])
            ->call('move')
            ->assertSet('error', '')
            ->assertDispatched('appointment-moved')
            ->assertDispatched('appointment-changed');

        expect($appointment->refresh()->localStart()->format('H:i'))->toBe('10:30');
    });
});

afterEach(function (): void {
    URL::defaults(['center' => null]);
    $this->tearDownRegisteredCenters();
});
