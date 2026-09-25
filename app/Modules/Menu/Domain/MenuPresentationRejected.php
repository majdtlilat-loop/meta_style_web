<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain;

use InvalidArgumentException;

/**
 * A presentation value the catalog does not allow.
 *
 * Still an {@see InvalidArgumentException}, with the same English message the
 * API has always answered with, so nothing that catches the parent changes.
 * It also carries WHY in a stable form — a reason code and the setting it is
 * about — so the Manager can say it in the viewer's own language instead of
 * showing an English sentence full of internal keys.
 */
final class MenuPresentationRejected extends InvalidArgumentException
{
    /**
     * @param  string  $reason  unknown_template|invalid_colour|unknown_option|invalid_choice|missing_key|unknown_section|duplicate_section|no_sections|unknown_setting|no_rule|out_of_range
     * @param  array<string, string|int>  $context  `setting`, `section`, `min`, `max` where they apply
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
