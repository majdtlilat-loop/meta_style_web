<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Data;

/**
 * A message the center is about to send.
 *
 * Two shapes, because WhatsApp genuinely has two and pretending otherwise
 * produces messages the provider silently refuses
 * (docs/25-WHATSAPP.md §14):
 *
 *   {@see text()}      free-form. Allowed only inside the customer service
 *                      window Meta opens when the customer messages first.
 *   {@see template()}  a pre-approved template, required outside that window.
 *
 * ## Meta Style does not model the window
 *
 * Deliberately. The window's rules are Meta's, they have changed more than once,
 * and a local reimplementation would be a second opinion that goes stale
 * without anybody noticing — the same reason availability is never recomputed
 * outside the Booking Engine. Phase 13 replies to customers who have just
 * messaged, which is inside the window by construction; a template is used only
 * where the product explicitly chose one, and Meta refuses anything it should.
 */
final readonly class OutboundMessage
{
    /**
     * @param  array<string, string>  $parameters  template body parameters, in
     *                                             order. Never customer text
     *                                             pasted into a template.
     */
    private function __construct(
        public string $toPhone,
        public string $body,
        public ?string $templateName = null,
        public ?string $templateLocale = null,
        public array $parameters = [],
    ) {}

    public static function text(string $toPhone, string $body): self
    {
        return new self($toPhone, $body);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    public static function template(
        string $toPhone,
        string $templateName,
        string $templateLocale,
        array $parameters = [],
        string $body = '',
    ): self {
        /*
         * `$body` is what the MESSAGE ROW stores and what staff read in the
         * thread. The provider renders the real thing from the approved
         * template, so this is a local rendering of the same content — never
         * sent, and never allowed to diverge into a second source of wording.
         */
        return new self($toPhone, $body, $templateName, $templateLocale, $parameters);
    }

    public function isTemplate(): bool
    {
        return $this->templateName !== null;
    }
}
