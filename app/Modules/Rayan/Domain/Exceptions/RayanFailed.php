<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Exceptions;

use RuntimeException;

/**
 * A configuration or programming fault in the assistant.
 *
 * NOT for ordinary runtime failures. A provider outage, a timeout, a refused
 * tool and an exhausted quota are all expected outcomes of a run and come back
 * as data, so the conversation can hand off to a person and the run can be
 * recorded and metered (docs/27-RAYAN.md §16).
 *
 * This is for the cases where continuing would be wrong: an unknown provider
 * code, a model that is not on the approved list. Things a deploy got wrong,
 * which should be loud.
 */
final class RayanFailed extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
