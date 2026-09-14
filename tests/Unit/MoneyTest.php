<?php

declare(strict_types=1);

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;

/*
|--------------------------------------------------------------------------
| Money
|--------------------------------------------------------------------------
|
| docs/10-API-FOUNDATION.md §9.
|
| The bug this class exists to prevent is specific and expensive: assuming two
| decimal places. IQD has ZERO. A price list that assumes 2 is a hundred times
| wrong, in the launch market, on every service.
|
*/

it('treats IQD as a zero-decimal currency', function (): void {
    expect(Currency::IQD->exponent())->toBe(0)
        ->and(Currency::IQD->subunits())->toBe(1);

    $price = Money::fromMinor(25000, Currency::IQD);

    // Twenty-five thousand dinars, not two hundred and fifty.
    expect($price->minor)->toBe(25000)
        ->and($price->toMajorString())->toBe('25000')
        ->and($price->formatted('en'))->toBe('25,000 IQD');
});

it('treats USD as a two-decimal currency', function (): void {
    expect(Currency::USD->exponent())->toBe(2)
        ->and(Currency::USD->subunits())->toBe(100);

    $price = Money::fromMinor(2550, Currency::USD);

    expect($price->toMajorString())->toBe('25.50')
        ->and($price->formatted('en'))->toBe('25.50 $');
});

it('parses a major-unit string without ever touching a float', function (): void {
    // 25.10 as a float times 100 is 2509.9999999999995, which truncates to
    // 2509 — one cent lost, silently, on every parse.
    expect(Money::fromMajorString('25.10', Currency::USD)->minor)->toBe(2510)
        ->and(Money::fromMajorString('0.07', Currency::USD)->minor)->toBe(7)
        ->and(Money::fromMajorString('25000', Currency::IQD)->minor)->toBe(25000);
});

it('refuses more decimal places than the currency has', function (): void {
    expect(fn () => Money::fromMajorString('25000.50', Currency::IQD))
        ->toThrow(InvalidArgumentException::class, 'at most 0 decimal place');

    expect(fn () => Money::fromMajorString('25.505', Currency::USD))
        ->toThrow(InvalidArgumentException::class, 'at most 2 decimal place');
});

it('refuses anything that is not a plain decimal', function (): void {
    foreach (['', 'abc', '25,000', '1e5', '25.', '$25'] as $bad) {
        expect(fn () => Money::fromMajorString($bad, Currency::IQD))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('adds and multiplies in minor units', function (): void {
    $haircut = Money::fromMinor(20000, Currency::IQD);
    $wash = Money::fromMinor(5000, Currency::IQD);

    expect($haircut->plus($wash)->minor)->toBe(25000)
        ->and($haircut->times(3)->minor)->toBe(60000)
        ->and($haircut->minus($wash)->minor)->toBe(15000);
});

it('refuses to combine two currencies', function (): void {
    // Silently adding IQD to USD is how a total becomes meaningless. Conversion
    // needs a rate, and a rate is a decision somebody has to make.
    expect(fn () => Money::fromMinor(1, Currency::IQD)->plus(Money::fromMinor(1, Currency::USD)))
        ->toThrow(InvalidArgumentException::class, 'Cannot combine IQD with USD');
});

it('produces the documented API shape', function (): void {
    $shape = Money::fromMinor(25000, Currency::IQD)->toArray('en');

    expect($shape)->toBe([
        'amount' => 25000,
        'currency' => 'IQD',
        'formatted' => '25,000 IQD',
    ]);
});

it('localises the currency symbol without a hardcoded locale list', function (): void {
    expect(Currency::IQD->symbol('ar'))->toBe('د.ع')
        ->and(Currency::IQD->symbol('ckb'))->toBe('د.ع')
        ->and(Currency::IQD->symbol('en'))->toBe('IQD');
});

it('handles zero and negative amounts', function (): void {
    $zero = Money::zero(Currency::IQD);

    expect($zero->isZero())->toBeTrue()
        ->and($zero->formatted('en'))->toBe('0 IQD');

    $refund = Money::fromMinor(-2550, Currency::USD);

    expect($refund->isNegative())->toBeTrue()
        ->and($refund->toMajorString())->toBe('-25.50');
});
