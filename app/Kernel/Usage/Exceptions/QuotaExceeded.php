<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Exceptions;

use RuntimeException;

/**
 * A center has used everything it bought of an enforced resource.
 *
 * NOT AN ERROR, and the distinction drives how every caller handles it. A
 * rate limit means something is going wrong; this means a center is using the
 * product and has reached the end of a number they agreed to. So it is reported
 * to STAFF as a commercial fact, never to the customer in front of the center
 * (docs/26-USAGE-QUOTAS.md §12).
 *
 * In particular, nothing built on this may tell a customer that the center has
 * run out of a paid allowance. A person messaging a salon about their haircut
 * is not a party to the salon's billing, and saying so would embarrass the
 * center to their own customer. The conversation hands off to a human instead.
 */
final class QuotaExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $resource,
        public readonly int $allowance,
    ) {
        parent::__construct(sprintf('The allowance for "%s" (%d) is used up for this period.', $resource, $allowance));
    }
}
