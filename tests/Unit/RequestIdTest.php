<?php

declare(strict_types=1);

use App\Kernel\Observability\RequestId;

/*
|--------------------------------------------------------------------------
| Correlation id
|--------------------------------------------------------------------------
|
| docs/10-API-FOUNDATION.md §3. Client-supplied ids are echoed into responses
| and log lines, which makes them untrusted input.
|
*/

it('generates an id when none is supplied', function (): void {
    expect((new RequestId)->value())->toMatch('/^[0-9a-f-]{36}$/');
});

it('accepts a well-formed client id', function (): void {
    expect((new RequestId('abc-123_XY.9'))->value())->toBe('abc-123_XY.9');
});

it('rejects ids that could inject into headers or logs', function (string $candidate): void {
    $generated = (new RequestId)->value();

    expect((new RequestId($candidate))->value())
        ->not->toBe($candidate)
        ->toMatch('/^[0-9a-f-]{36}$/')
        ->not->toBe($generated === '' ? 'never' : $candidate);
})->with([
    'crlf' => ["evil\r\nX-Injected: 1"],
    'newline' => ["a\nb"],
    'spaces' => ['has spaces'],
    'quotes' => ['"quoted"'],
    'unicode' => ['identifiant-é'],
    'empty' => [''],
    'whitespace only' => ['   '],
    'too long' => [str_repeat('a', 129)],
]);

it('keeps the existing id when set() is given something unusable', function (): void {
    $id = new RequestId('good-id');

    $id->set("bad\r\nid");

    expect($id->value())->toBe('good-id');
});

it('accepts an id exactly at the length limit', function (): void {
    $max = str_repeat('a', 128);

    expect((new RequestId($max))->value())->toBe($max);
});
