<?php

declare(strict_types=1);

namespace App\Kernel\Observability\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Monolog tap: one JSON object per line.
 *
 * Structured logs are what make a per-tenant incident diagnosable — you can
 * filter by request_id or tenant_id instead of grepping prose. Redaction of
 * sensitive fields (docs/08-AUDIT-SECURITY.md §17) is added here in Phase 2,
 * as a processor, so it applies centrally rather than at each call site.
 */
final class FormatAsJson
{
    public function __invoke(Logger $logger): void
    {
        $formatter = new JsonFormatter(
            batchMode: JsonFormatter::BATCH_MODE_NEWLINES,
            appendNewline: true,
            ignoreEmptyContextAndExtra: true,
        );

        foreach ($logger->getHandlers() as $handler) {
            // Not every handler accepts a formatter (SyslogHandler, for one).
            // Skipping those keeps the tap safe to add to any channel.
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter($formatter);
            }
        }

        $logger->pushProcessor(new PsrLogMessageProcessor);
    }
}
