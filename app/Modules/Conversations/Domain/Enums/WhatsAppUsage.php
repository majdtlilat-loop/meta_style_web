<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Enums;

/**
 * The metered-resource codes this module reports to `Kernel\Usage`.
 *
 * They exist HERE, in the module that produces the usage, rather than in the
 * Kernel — which must never learn what a WhatsApp message is. The Kernel
 * validates the code against `config/usage.php` and counts it; what the code
 * MEANS is this module's business (Phase 13 correction 3,
 * docs/26-USAGE-QUOTAS.md §2).
 *
 * Kept separate from RAYAN's codes, and separately from each other: "bot usage"
 * as a single number would make an inbound message a customer sent
 * indistinguishable from a template the center paid Meta to deliver, which are
 * not remotely the same cost or the same fact (§41).
 */
enum WhatsAppUsage: string
{
    /** A message a customer sent us, accepted after signature verification. */
    case Inbound = 'wa_inbound';

    /** A free-form message the center sent inside the service window. */
    case Outbound = 'wa_outbound';

    /**
     * An approved template. Counted separately because Meta bills it
     * differently and because a center's template volume is the number they
     * will ask about (§13).
     */
    case Template = 'wa_template';

    /** An outbound the provider explicitly refused. */
    case Failed = 'wa_failed';

    public function code(): string
    {
        return $this->value;
    }
}
