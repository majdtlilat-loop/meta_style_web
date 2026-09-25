<?php

declare(strict_types=1);

namespace App\Kernel\Appearance;

use InvalidArgumentException;

/**
 * One appearance value that is not allowed, with enough structure for a form
 * to put a translated message on the right field.
 */
final class AppearanceRejected extends InvalidArgumentException
{
    /**
     * @param  string  $field  `values.<key>` or `texts.<key>.<locale>`
     * @param  string  $reason  unknown_setting|invalid_colour|invalid_choice|unknown_language|invalid_text|markup|too_long
     * @param  array<string, string|int>  $context
     */
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct(sprintf('Appearance value [%s] was rejected: %s.', $field, $reason));
    }
}
