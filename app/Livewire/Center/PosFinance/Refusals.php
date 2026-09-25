<?php

declare(strict_types=1);

namespace App\Livewire\Center\PosFinance;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * The till's and the money screens' refusals, in the viewer's language.
 *
 * The Sales, Payments and Finance Actions refuse in plain English sentences —
 * the API contract and the tests read them. A person at an Arabic or Kurdish
 * till must not. This is the Livewire boundary's one translation step:
 *
 *   1. English stays exactly what the Action said.
 *   2. A sentence the JSON catalog already knows is used from there.
 *   3. Otherwise the sentence is looked up in `manager_pos.refusals` by a
 *      stable slug of the English text (a dotted group key cannot hold a
 *      sentence, which ends in a full stop).
 *   4. A handful of refusals carry a number or a name; those are matched by
 *      pattern and re-rendered with the value.
 *
 * An unknown sentence is shown as it came: an English refusal is better than
 * an invented one.
 */
final class Refusals
{
    /**
     * Refusals that interpolate a value: pattern → [key, parameter names].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const PATTERNS = [
        '/^A line quantity must be between 1 and (\d+)\.$/' => ['line_quantity_range', ['max']],
        '/^A sale may carry at most (\d+) adjustments\.$/' => ['max_adjustments', ['max']],
        '/^A sale may carry at most (\d+) lines\.$/' => ['max_lines', ['max']],
        '/^Choose a range of at most (\d+) days\.$/' => ['range_max_days', ['days']],
        '/^Every credential field is required: (.+)\.$/' => ['credentials_required', ['fields']],
        '/^(.+) is not available in that environment on this installation\.$/' => ['provider_environment', ['provider']],
        '/^(.+) is not available yet\.$/' => ['provider_not_available', ['provider']],
        '/^That number already belongs to (.+)\.$/' => ['phone_taken', ['name']],
        '/^That sale is ([a-z_]+) and can no longer be changed\.$/' => ['sale_locked', ['status']],
    ];

    public static function text(string $message): string
    {
        $message = trim($message);

        if ($message === '' || app()->getLocale() === 'en') {
            return $message;
        }

        if (Lang::hasForLocale($message)) {
            return (string) __($message);
        }

        $key = 'manager_pos.refusals.'.self::key($message);

        if (Lang::hasForLocale($key)) {
            return (string) __($key);
        }

        foreach (self::PATTERNS as $pattern => [$name, $parameters]) {
            if (preg_match($pattern, $message, $matches) !== 1) {
                continue;
            }

            $values = [];

            foreach ($parameters as $index => $parameter) {
                $values[$parameter] = $matches[$index + 1];
            }

            if (isset($values['status'])) {
                $values['status'] = (string) __('manager_pos.sale_status.'.$values['status']);
            }

            return (string) __('manager_pos.refusal_patterns.'.$name, $values);
        }

        return $message;
    }

    /**
     * The catalog key of an English refusal: its words, snake_cased and
     * bounded, so the three language files share one key per sentence.
     */
    public static function key(string $message): string
    {
        return Str::limit(Str::slug($message, '_'), 96, '');
    }
}
