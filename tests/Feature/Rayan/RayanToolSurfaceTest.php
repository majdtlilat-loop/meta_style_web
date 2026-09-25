<?php

declare(strict_types=1);

use App\Modules\Rayan\Application\ToolRegistry;
use App\Modules\Rayan\Domain\Data\AiToolCallRequest;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Enums\RayanTool;

/*
|--------------------------------------------------------------------------
| The tool surface: what the model can ask for, and what it cannot say
|--------------------------------------------------------------------------
|
| docs/27-RAYAN.md §§11–13.
|
| These live in Feature rather than Architecture because they inspect the
| REGISTERED registry — the one the container builds from
| `AppServiceProvider`, which is the thing that actually runs. A source scan
| would prove the classes exist; this proves what is wired.
|
| They need no tenant: the registry, its definitions and its refusals are
| decided before any database is touched, which is itself part of the point.
|
*/

/**
 * @return list<ToolDefinition>
 */
function registeredTools(): array
{
    return app(ToolRegistry::class)->definitions();
}

it('exposes exactly the tools that were approved, and no others', function (): void {
    /*
     * The allow-list, asserted as an EXACT SET rather than a count. A new tool
     * is a new capability handed to a language model, and it should be a
     * deliberate line in a diff somebody reviewed — never something that
     * appears because a class was added to a folder (§11).
     */
    $registered = array_map(static fn (ToolDefinition $d): string => $d->name(), registeredTools());
    sort($registered);

    $approved = RayanTool::names();
    sort($approved);

    expect($registered)->toBe($approved)->and($registered)->toHaveCount(10);
});

it('gives the model no way to name a tenant, a customer or a phone number', function (): void {
    /*
     * THE PROMPT-INJECTION DEFENCE, as a test.
     *
     * Identity comes from the run's trusted context — a signature-verified
     * envelope and a database lookup. If no tool SCHEMA declares a field for
     * it, a customer writing "book this for Sara on +9647501111111" produces a
     * model with nowhere to put it (§§11, 13).
     *
     * `verification_code` is deliberately allowed: it is a capability the
     * customer holds and quotes, not an identity the model asserts.
     */
    $forbidden = ['customer_id', 'customer', 'phone', 'phone_number', 'tenant', 'tenant_id', 'user_id', 'center', 'account_id'];

    $violations = [];

    foreach (registeredTools() as $tool) {
        foreach (array_keys($tool->properties) as $property) {
            if (in_array($property, $forbidden, true)) {
                $violations[] = $tool->name().'.'.$property;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('gives the model no tool that executes anything it names', function (): void {
    /*
     * No SQL, no HTTP, no class, no action, no route, no file. Every tool takes
     * business input — a service, a date, a reference — and resolves it against
     * the database itself. A parameter naming something EXECUTABLE would be the
     * whole allow-list undone (§11).
     */
    $forbidden = ['query', 'sql', 'table', 'column', 'class', 'action', 'method', 'url', 'endpoint', 'path', 'command'];

    $violations = [];

    foreach (registeredTools() as $tool) {
        foreach (array_keys($tool->properties) as $property) {
            if (in_array($property, $forbidden, true)) {
                $violations[] = $tool->name().'.'.$property;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('closes every tool schema, so an invented field is a schema violation', function (): void {
    // `additionalProperties: false` is what makes the provider's own strict
    // mode refuse a field nobody declared, before it reaches a handler (§11).
    foreach (registeredTools() as $tool) {
        expect($tool->schema()['additionalProperties'] ?? null)->toBeFalse($tool->name());
    }
});

it('refuses a tool the model invented, without looking anything up', function (): void {
    /*
     * The allow-list doing its job. `RayanTool::tryFrom()` returns null and the
     * call is refused before a handler is resolved — there is no path from a
     * model-produced string to a class, a query, a route or a file (§11).
     *
     * No tenant is bound, which is the sharpest form of the claim: the refusal
     * happens before anything could have touched a database.
     */
    $registry = app(ToolRegistry::class);

    $context = new ToolContext(
        conversationUuid: 'conversation-uuid',
        locale: 'en',
        customerId: null,
        branchId: null,
        verifiedPhone: '+9647501234567',
    );

    foreach ([
        'run_sql',
        'App\Modules\Finance\Application\Actions\ReadLedger',
        'get_customer_context; DROP TABLE customers',
        '../../etc/passwd',
        '',
    ] as $invented) {
        $result = $registry->execute(new AiToolCallRequest('call_1', $invented, ['anything' => 'at all']), $context);

        expect($result->ok)->toBeFalse($invented)
            ->and($result->refusalCode)->toBe('unknown_tool')
            // The refusal names nothing that exists: a message listing the real
            // tools would be a readout of the surface to anybody probing it.
            ->and($result->message)->toBe('That operation is not available.');
    }
});

it('refuses a tool about "my" things when the sender is nobody we know', function (): void {
    /*
     * "Show me my bookings" from an unrecognised number. Refused rather than
     * answered for whoever happens to hold that phone — and refused by the
     * REGISTRY, before the handler exists, so no tool can forget to check (§12).
     */
    $registry = app(ToolRegistry::class);

    $stranger = new ToolContext(
        conversationUuid: 'conversation-uuid',
        locale: 'en',
        customerId: null,
        branchId: null,
        verifiedPhone: '+9647509999999',
    );

    foreach ([RayanTool::GetCustomerContext, RayanTool::GetCustomerBookings] as $tool) {
        $result = $registry->execute(new AiToolCallRequest('call_1', $tool->value, []), $stranger);

        expect($result->ok)->toBeFalse($tool->value)
            ->and($result->refusalCode)->toBe('not_identified');
    }
});

it('lets a stranger still discover the center, and still book', function (): void {
    /*
     * The other half of the rule above, and the one that would be easy to get
     * wrong by over-tightening: a FIRST-TIME customer is exactly who most needs
     * to be able to look at services and make a booking. Neither is gated on
     * being recognised (§12).
     */
    foreach ([
        RayanTool::ListBranches,
        RayanTool::ListServices,
        RayanTool::GetServiceDetails,
        RayanTool::GetAvailableSlots,
        RayanTool::CreateBooking,
    ] as $tool) {
        expect($tool->requiresIdentifiedCustomer())->toBeFalse($tool->value);
    }
});

it('describes every tool to the model, so none is a hidden capability', function (): void {
    foreach (registeredTools() as $tool) {
        expect($tool->description)->not->toBe('')
            // Long enough to actually say what the tool does. A one-word
            // description produces a model that guesses when to call it.
            ->and(mb_strlen($tool->description))->toBeGreaterThan(30, $tool->name());
    }
});

it('marks exactly the three booking mutations as changing something', function (): void {
    /*
     * Pinned as an exact set. `mutates()` decides what is audited and what is
     * treated as a change a customer will turn up expecting — a read that
     * quietly became a write, or the reverse, would move that line silently.
     */
    $mutating = array_values(array_map(
        static fn (RayanTool $tool): string => $tool->value,
        array_filter(RayanTool::cases(), static fn (RayanTool $tool): bool => $tool->mutates()),
    ));

    sort($mutating);

    expect($mutating)->toBe(['cancel_booking', 'create_booking', 'reschedule_booking']);
});
