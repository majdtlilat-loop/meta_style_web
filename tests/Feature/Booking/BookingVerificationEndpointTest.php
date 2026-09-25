<?php

declare(strict_types=1);

use App\Kernel\Identity\TenantApiToken;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The two authenticated "new verification code" endpoints
|--------------------------------------------------------------------------
|
| docs/24-BOOKING-VERIFICATION.md §8. The lookups moved into
| IssueVerificationCode (forStaffByUuid / forAccountByUuid) so the HTTP
| adapter no longer handles the Appointment model; these pin the behaviour
| the move had to keep: the code once, grouped, with the reference; a
| booking that is not found — or somebody else's — is 404.
|
*/

it('issues staff a new code and answers 404 for a booking that does not exist', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $uuid = $this->asCenter($center['tenant'], function (): string {
        $seed = $this->seedBookableCenter();

        return app(BookingEngine::class)->book(new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            startsAt: $this->localTime($seed['branch'], CarbonImmutable::now('Asia/Baghdad')->addDays(5)->format('Y-m-d'), '10:00'),
            customer: CustomerRef::details('Sara Ahmed', '+9647501234567'),
        ), BookingActor::staff($this->ownerWithCatalogAccess()))->appointment->uuid;
    });

    $issued = $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/appointments/{$uuid}/verification-code")
        ->assertStatus(201);

    expect($issued->json('data.verification_code'))->toMatch('/^[0-9A-Z]{5}-[0-9A-Z]{5}$/')
        ->and($issued->json('data.reference'))->toMatch('/^B-\d{6,}$/');

    $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/appointments/00000000-0000-0000-0000-000000000000/verification-code')
        ->assertNotFound();
});

it('issues a customer a code for their own booking only', function (): void {
    $center = $this->registerCenter();

    [$token, $mine, $theirs] = $this->asCenter($center['tenant'], function () use ($center): array {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $date = CarbonImmutable::now('Asia/Baghdad')->addDays(5)->format('Y-m-d');

        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $other = $this->seedCustomer('Fatima Hassan', '0750 765 4321');
        $account = $this->seedCustomerAccount($customer);

        $book = fn (string $uuid, string $time): string => app(BookingEngine::class)->book(new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            startsAt: $this->localTime($seed['branch'], $date, $time),
            customer: CustomerRef::existing($uuid),
        ), BookingActor::staff($owner))->appointment->uuid;

        $issued = $account->createToken('test', ['*'], CarbonImmutable::now()->addDay());

        return [
            TenantApiToken::format($this->publicKeyOf($center['tenant']), $issued->plainTextToken),
            $book($customer->uuid, '10:00'),
            $book($other->uuid, '11:00'),
        ];
    });

    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

    $this->withHeaders($headers)
        ->postJson("/api/v1/customer/appointments/{$mine}/verification-code")
        ->assertStatus(201)
        ->assertJsonStructure(['data' => ['reference', 'verification_code']]);

    // Somebody else's booking is not found, never forbidden.
    $this->withHeaders($headers)
        ->postJson("/api/v1/customer/appointments/{$theirs}/verification-code")
        ->assertNotFound();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
