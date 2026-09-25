<?php

declare(strict_types=1);

use App\Kernel\Localization\TranslatedText;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Booking-time snapshots
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §§3, 18, 19, 41.
|
| A manager raises a price or a duration tomorrow. Every appointment already in
| the book keeps what the customer agreed to — otherwise a price change silently
| rewrites yesterday's bookings and next week's schedule shifts by fifteen
| minutes per appointment.
|
*/

const SNAPSHOT_DATE = '2026-10-14';

/**
 * Books one line and returns the appointment.
 *
 * Reaches the harness through Pest's `test()` proxy rather than a `$this`
 * passed in: a module-level function is global scope, and calling a protected
 * trait method on the test object directly is a fatal.
 *
 * @param  array<string, mixed>  $seed
 * @param  array<string, mixed>  $line
 */
function bookOne(array $seed, array $line = []): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine(
                serviceUuid: $line['service'] ?? $seed['service']->uuid,
                variationUuid: $line['variation'] ?? null,
                addonUuids: $line['addons'] ?? [],
            )],
            startsAt: test()->localTime($seed['branch'], SNAPSHOT_DATE, $line['at'] ?? '10:00'),
            customer: CustomerRef::existing(test()->seedCustomer('Sara '.uniqid(), null)->uuid),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

it('keeps the agreed price when the service price changes later', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = bookOne($seed);

        // The manager raises the price the next morning.
        $seed['service']->forceFill(['price_minor' => 25000])->save();

        $item = $appointment->refresh()->items()->first();

        expect($item->price_minor)->toBe(20000)
            ->and($item->price()->formatted())->toContain('20,000')
            ->and($appointment->totalMinor())->toBe(20000)
            // The live catalog HAS changed. The booking simply does not follow.
            ->and($seed['service']->refresh()->price_minor)->toBe(25000);
    });
});

it('keeps the agreed duration when the service duration changes later', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = bookOne($seed);

        $seed['service']->forceFill(['duration_minutes' => 45])->save();

        $item = $appointment->refresh()->items()->first();

        expect($item->duration_minutes)->toBe(30)
            // And the window on the calendar is unchanged, which is the point:
            // a duration change must not silently move every later appointment.
            ->and($item->window()->durationMinutes())->toBe(30)
            ->and($appointment->localEnd()->format('H:i'))->toBe('10:30');
    });
});

it('snapshots a variation that overrides price and duration', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $variation = $seed['service']->variations()->create([
            'name' => TranslatedText::fromArray(['en' => 'Long hair']),
            'price_minor' => 35000,
            'duration_minutes' => 50,
            'is_active' => true,
            'sort_order' => 9,
        ]);

        $appointment = bookOne($seed, ['variation' => $variation->uuid]);

        $item = $appointment->items()->first();

        expect($item->price_minor)->toBe(35000)
            ->and($item->duration_minutes)->toBe(50)
            ->and($item->service_variation_id)->toBe($variation->id)
            ->and($item->variation_name?->get())->toBe('Long hair');
    });
});

it('snapshots the INHERITED price of a variation that has none of its own', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // Phase 4's rule: a null price inherits and keeps inheriting (ADR-037).
        // At booking time that inheritance is RESOLVED — from then on the
        // appointment carries the number, not the rule.
        $variation = $seed['service']->variations()->create([
            'name' => TranslatedText::fromArray(['en' => 'Standard']),
            'price_minor' => null,
            'duration_minutes' => null,
            'is_active' => true,
            'sort_order' => 9,
        ]);

        $appointment = bookOne($seed, ['variation' => $variation->uuid]);

        $item = $appointment->items()->first();

        expect($item->price_minor)->toBe(20000)
            ->and($item->duration_minutes)->toBe(30);

        // The service price changes. The variation still inherits — for NEW
        // bookings. This one does not move.
        $seed['service']->forceFill(['price_minor' => 25000])->save();

        expect($item->refresh()->price_minor)->toBe(20000);

        $second = bookOne($seed, ['variation' => $variation->uuid, 'at' => '12:00']);

        expect($second->items()->first()->price_minor)->toBe(25000);
    });
});

it('snapshots each add-on with its own price and duration', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = bookOne($seed, ['addons' => [$seed['addon']->uuid]]);

        $item = $appointment->items()->with('addons')->first();
        $addon = $item->addons->first();

        expect($item->duration_minutes)->toBe(40)   // 30 + 10
            ->and($item->price_minor)->toBe(25000)  // 20,000 + 5,000
            ->and($addon->price_minor)->toBe(5000)
            ->and($addon->duration_minutes)->toBe(10)
            ->and($addon->name->get())->toBe('Hair Wash');

        // The add-on's price changes. Neither the line total nor the add-on
        // snapshot follows.
        $seed['addon']->forceFill(['price_minor' => 9000, 'duration_minutes' => 25])->save();

        expect($addon->refresh()->price_minor)->toBe(5000)
            ->and($addon->duration_minutes)->toBe(10)
            ->and($item->refresh()->price_minor)->toBe(25000)
            ->and($item->duration_minutes)->toBe(40);
    });
});

it('snapshots the service name as it was, in every language', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = bookOne($seed);

        $seed['service']->forceFill([
            'name' => TranslatedText::fromArray(['en' => 'Premium Cut', 'ar' => 'قص مميز']),
        ])->save();

        $item = $appointment->items()->first();

        // A receipt or a reminder has to reproduce what the customer actually
        // saw — including in the language they saw it in.
        expect($item->service_name->get('en'))->toBe('Haircut')
            ->and($item->service_name->get('ar'))->toBe('قص شعر')
            ->and($seed['service']->refresh()->name->get('en'))->toBe('Premium Cut');
    });
});

it('keeps the currency it was booked in', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = bookOne($seed);

        expect($appointment->items()->first()->currency)->toBe('IQD')
            ->and($appointment->currencyCode())->toBe('IQD');
    });
});

it('keeps the canonical service linked while the snapshot stands alone', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = bookOne($seed);
        $item = $appointment->items()->first();

        // Linked "when possible": reports group by it, screens link to it.
        expect($item->service_id)->toBe($seed['service']->id);

        // Archiving the service — the normal lifecycle — leaves the link alone.
        $seed['service']->forceFill(['archived_at' => CarbonImmutable::now()])->save();

        expect($item->refresh()->service_id)->toBe($seed['service']->id)
            ->and($item->service_name->get())->toBe('Haircut');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
