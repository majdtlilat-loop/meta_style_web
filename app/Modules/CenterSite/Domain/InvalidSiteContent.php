<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Domain;

use DomainException;

/**
 * A value the site normalizer refused.
 *
 * Carries WHERE (`path`, dotted from the content root, e.g. `hero.title.ar`)
 * so the editor can put the message next to the field that caused it, and a
 * stable `reason` code tests can assert on without matching prose.
 */
final class InvalidSiteContent extends DomainException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $path = '',
        array $params = [],
    ) {
        parent::__construct((string) __('manager_site.errors.'.$reason, $params));
    }
}
