<?php

declare(strict_types=1);

namespace App\Kernel\Database;

use RuntimeException;
use Throwable;

/**
 * An after-commit reaction failed. Reported, never thrown to a caller: the fact
 * it reacted to is already committed, and a reconciler repairs the reaction.
 */
final class AfterCommitFailed extends RuntimeException
{
    public function __construct(public readonly string $label, Throwable $previous)
    {
        parent::__construct("After-commit work \"{$label}\" failed and was left for reconciliation: ".$previous->getMessage(), 0, $previous);
    }
}
