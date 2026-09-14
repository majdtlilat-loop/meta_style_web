<?php

declare(strict_types=1);

use App\Kernel\Contact\PhoneNumber;

/*
|--------------------------------------------------------------------------
| Phone normalisation
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-039.
|
| The bug this prevents is mundane and expensive: one person becoming three
| customers because they wrote their number three ways. Everything below is a
| spelling a real Iraqi customer actually uses.
|
| In the FEATURE suite rather than Unit, because `PhoneNumber` reads the
| deployment's default country from config and the Unit suite deliberately runs
| without an application container (docs/11-TESTING-STRATEGY.md §2). Making the
| class pure would mean passing a country through every call site to avoid one
| honest configuration read.
|
*/

it('collapses every common spelling of one Iraqi mobile', function (string $input): void {
    expect(PhoneNumber::parse($input)?->e164)->toBe('+9647501234567');
})->with([
    '0750 123 4567',
    '07501234567',
    '0750-123-4567',
    '(0750) 123 4567',
    '+964 750 123 4567',
    '+9647501234567',
    '964 750 123 4567',
    '9647501234567',
    // `00` is the international prefix in most of the world and means `+`.
    '00964 750 123 4567',
    '  0750 123 4567  ',
]);

it('keeps what the person actually typed, alongside the canonical form', function (): void {
    $phone = PhoneNumber::parse('0750 123 4567');

    // Shown back to them unchanged; compared as E.164.
    expect($phone?->display)->toBe('0750 123 4567')
        ->and($phone?->e164)->toBe('+9647501234567');
});

it('treats two spellings of one number as equal', function (): void {
    $a = PhoneNumber::parse('0750 123 4567');
    $b = PhoneNumber::parse('+964 750 123 4567');

    expect($a?->equals($b))->toBeTrue();
});

it('does not confuse a trunk zero with part of the number', function (): void {
    // `+9640750…` would be a different number entirely, and would match nothing.
    expect(PhoneNumber::parse('0750 123 4567')?->e164)->not->toContain('+9640');
});

it('returns null rather than throwing for input it cannot canonicalise', function (mixed $input): void {
    // A blank or unusable phone is an ordinary case — a walk-in placeholder has
    // no number at all — so callers decide what it means, not an exception.
    expect(PhoneNumber::parse($input))->toBeNull();
})->with([
    null,
    '',
    '   ',
    'not a phone',
    // Too short even once the dialing code is prepended: `+96412` is five
    // digits, and E.164 has no numbers that short.
    '12',
    '123',
    // Beyond E.164's 15-digit limit.
    '+12345678901234567890',
]);

it('normalises rather than validates', function (): void {
    // A seven-digit local number is not an Iraqi mobile, and this class does
    // not claim otherwise — it makes input canonical so two spellings compare
    // equal. Deciding a number is real needs a verification provider, which
    // Phase 5 deliberately does not have (ADR-040).
    expect(PhoneNumber::parse('1234567')?->e164)->toBe('+9641234567');
});

it('honours a different default country', function (): void {
    config()->set('metastyle.contact.default_country', 'AE');

    expect(PhoneNumber::parse('050 123 4567')?->e164)->toBe('+971501234567');
});

it('respects an explicit country over the default', function (): void {
    expect(PhoneNumber::parse('0532 123 4567', 'TR')?->e164)->toBe('+905321234567');
});

it('leaves an explicitly international number alone whatever the default is', function (): void {
    config()->set('metastyle.contact.default_country', 'IQ');

    // Someone typing a full international number knows which country they mean.
    expect(PhoneNumber::parse('+90 532 123 4567')?->e164)->toBe('+905321234567');
});

it('exposes only the last digits for masking', function (): void {
    expect(PhoneNumber::parse('0750 123 4567')?->last(2))->toBe('67')
        ->and(PhoneNumber::parse('0750 123 4567')?->last(4))->toBe('4567');
});
