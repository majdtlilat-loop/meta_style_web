<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use LogicException;

/**
 * The HTTP middleware priority list cannot serve tenant traffic safely.
 *
 * Deliberately fatal at boot rather than logged. A misordered pipeline is not a
 * degraded mode — it is one where authentication runs against whatever database
 * happens to be bound, which is the single failure this platform can least
 * afford (ADR-027).
 */
final class MiddlewareOrderViolation extends LogicException {}
