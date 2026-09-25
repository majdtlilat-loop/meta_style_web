<?php

declare(strict_types=1);

namespace App\Modules\AdvancedReports\Data;

final readonly class AnalysisAnswer
{
    /** The structured insight headings, in presentation order. */
    public const SECTIONS = ['summary', 'changes', 'issues', 'trends', 'review'];

    /**
     * @param  array<string, list<string>>  $sections  plain-text points per
     *                                                 heading (insights only)
     */
    private function __construct(public bool $ok, public ?string $text, public ?string $failureCode, public array $sections = []) {}

    public static function answered(string $text): self
    {
        return new self(true, $text, null);
    }

    /**
     * A structured insights answer. Only the known headings are kept, each a
     * short list of plain strings — the model's output is untrusted text,
     * never markup and never a key the page did not ask for.
     *
     * @param  array<string, mixed>  $sections
     */
    public static function insights(string $text, array $sections): self
    {
        $clean = [];

        foreach (self::SECTIONS as $key) {
            $given = $sections[$key] ?? [];
            $points = array_values(array_filter(array_map(
                static fn (mixed $point): string => is_string($point) ? mb_substr(trim($point), 0, 600) : '',
                is_array($given) ? $given : [$given],
            ), static fn (string $point): bool => $point !== ''));

            if ($points !== []) {
                $clean[$key] = array_slice($points, 0, 5);
            }
        }

        return new self(true, $text, null, $clean);
    }

    public static function failed(string $code): self
    {
        return new self(false, null, $code);
    }
}
