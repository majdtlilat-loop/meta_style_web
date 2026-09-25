<?php

declare(strict_types=1);

use App\Modules\Reviews\Application\Actions\SubmitReview;
use App\Modules\Reviews\Domain\Data\ReviewSubmission;

/*
|--------------------------------------------------------------------------
| The inbox over HTTP
|--------------------------------------------------------------------------
|
| docs/23-NOTIFICATIONS.md §§16–17.
|
| Two guards, two id spaces, and no way across. The negative checks run FIRST in
| each test: a guard that already resolved a user earlier in the same request
| cycle can make a later assertion pass for the wrong reason
| (docs/11-TESTING-STRATEGY.md).
|
*/

it('keeps the staff inbox away from a customer token, and the customer inbox away from a staff token', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $this->ownerWithCatalogAccess();
        $this->seedCustomerAccount($this->seedCustomer());
    });

    $customerToken = $this->customerTokenFor($center['tenant']);
    $staffToken = $this->apiTokenFor($center['tenant']);

    // NEGATIVE FIRST, before any request has resolved the other guard.
    $this->getJson('/api/v1/tenant/notifications', $this->tokenHeaders($customerToken))->assertUnauthorized();
    $this->getJson('/api/v1/customer/notifications', $this->tokenHeaders($staffToken))->assertUnauthorized();

    // And each reaches its own.
    $this->getJson('/api/v1/tenant/notifications', $this->tokenHeaders($staffToken))->assertOk();
    $this->getJson('/api/v1/customer/notifications', $this->tokenHeaders($customerToken))->assertOk();
});

it('gives a customer their own inbox, their unread count and their switches', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->seedCustomerAccount($customer);

        $this->customerInvoice($seed, $owner, $customer);
    });

    $headers = $this->tokenHeaders($this->customerTokenFor($center['tenant']));

    $inbox = $this->getJson('/api/v1/customer/notifications', $headers)->assertOk()->json('data.notifications');

    expect($inbox)->toHaveCount(1)
        ->and($inbox[0])->toHaveKeys(['id', 'type', 'severity', 'message', 'source', 'created_at', 'read_at'])
        // An allow-list: no recipient id, no branch id, no internal key (§16).
        ->and($inbox[0])->not->toHaveKeys(['recipient_id', 'recipient_kind', 'notification_id', 'branch_id', 'params']);

    $this->getJson('/api/v1/customer/notifications/unread', $headers)->assertOk()->assertJsonPath('data.unread', 1);

    $this->postJson('/api/v1/customer/notifications/'.$inbox[0]['id'].'/read', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.unread', 0);

    $this->getJson('/api/v1/customer/notifications/preferences', $headers)
        ->assertOk()
        ->assertJsonPath('data.preferences.appointment_reminders', true);

    $this->putJson('/api/v1/customer/notifications/preferences', ['key' => 'appointment_reminders', 'enabled' => false], $headers)
        ->assertOk()
        ->assertJsonPath('data.preferences.appointment_reminders', false);

    // Only the keys that exist.
    $this->putJson('/api/v1/customer/notifications/preferences', ['key' => 'everything', 'enabled' => false], $headers)
        ->assertStatus(422);
});

it('never lets one customer mark another\'s notification read', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $mine = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $this->seedCustomerAccount($mine);
        $this->customerInvoice($seed, $owner, $mine);

        $theirs = $this->seedCustomer('Layla Noor', '0750 123 4599');
        $this->seedCustomerAccount($theirs, 'another-correct-horse');
        $this->customerInvoice($seed, $owner, $theirs);
    });

    $mineHeaders = $this->tokenHeaders($this->customerTokenFor($center['tenant'], '0750 123 4567'));
    $theirsHeaders = $this->tokenHeaders($this->customerTokenFor($center['tenant'], '0750 123 4599', 'another-correct-horse'));

    $theirs = $this->getJson('/api/v1/customer/notifications', $theirsHeaders)->assertOk()->json('data.notifications');

    expect($theirs)->toHaveCount(1);

    /*
     * One application instance serves every request in a test, and the guard
     * caches whoever it resolved last — so without this the next request would
     * still be the customer who just listed their inbox, and the assertion
     * below would pass for entirely the wrong reason
     * (docs/11-TESTING-STRATEGY.md).
     */
    $this->app['auth']->forgetGuards();

    // Somebody else's notification is NOT FOUND — never forbidden, which would
    // still confirm that it exists (§16).
    $this->postJson('/api/v1/customer/notifications/'.$theirs[0]['id'].'/read', [], $mineHeaders)->assertNotFound();

    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/customer/notifications/unread', $theirsHeaders)->assertOk()->assertJsonPath('data.unread', 1);
});

it('puts a low rating in the manager\'s own inbox, over HTTP', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());
        app(SubmitReview::class)->byToken($this->reviewToken($journey, $owner), new ReviewSubmission(1, 'Not good.'));
    });

    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $inbox = $this->getJson('/api/v1/tenant/notifications', $headers)->assertOk()->json('data.notifications');

    expect($inbox)->toHaveCount(1)
        ->and($inbox[0]['type'])->toBe('low_rating_received')
        ->and($inbox[0]['severity'])->toBe('important')
        // The customer's words stay in the review (§7).
        ->and(json_encode($inbox[0]))->not->toContain('Not good.');

    $this->postJson('/api/v1/tenant/notifications/read-all', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.marked', 1);

    $this->getJson('/api/v1/tenant/notifications/unread', $headers)->assertOk()->assertJsonPath('data.unread', 0);
});

it('lists a signed-in customer\'s pending review invitations without a secret', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->seedCustomerAccount($customer);

        $this->customerVisit($seed, $owner, $customer);
    });

    $headers = $this->tokenHeaders($this->customerTokenFor($center['tenant']));

    $pending = $this->getJson('/api/v1/customer/reviews', $headers)->assertOk()->json('data.invitations');

    expect($pending)->toHaveCount(1)
        ->and($pending[0])->toHaveKeys(['id', 'center', 'branch', 'visited_on', 'stages'])
        // The invitation's own uuid, never a capability secret: their session
        // is the authority (§40).
        ->and($pending[0]['id'])->not->toMatch('/^[a-f0-9]{64}$/');

    $this->postJson('/api/v1/customer/reviews/'.$pending[0]['id'], ['overall' => 5], $headers)
        ->assertOk()
        ->assertJsonPath('data.review.overall_rating', 5);

    // Spent.
    $this->postJson('/api/v1/customer/reviews/'.$pending[0]['id'], ['overall' => 1], $headers)->assertStatus(409);
    $this->getJson('/api/v1/customer/reviews', $headers)->assertOk()->assertJsonCount(0, 'data.invitations');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
