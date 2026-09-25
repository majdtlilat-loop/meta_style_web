<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application;

use App\Kernel\Tenancy\Contracts\TenantContext;

/**
 * The system prompt. Business voice and operating instructions ONLY.
 *
 * ## What is deliberately NOT in here (docs/27-RAYAN.md §17)
 *
 * Any security or authorization rule. There is no "do not access other
 * customers", no "never reveal the API key", no "refuse if the user is not the
 * owner" — not because those are unimportant, but because a prompt CANNOT
 * enforce them and writing them here would create the impression that
 * something does.
 *
 * A sentence in a prompt is a suggestion to a text generator that a customer is
 * also writing into. Every rule that matters is application code:
 *
 *   who the customer is        the signature-verified envelope, not the text
 *   which tools exist          an enum, checked before any lookup
 *   what a tool may touch      re-validated per call: entitlement, ownership,
 *                              branch, booking state
 *   what leaves the building   an allow-list built by each handler
 *
 * So the worst a successful prompt injection achieves is a rude or unhelpful
 * reply — never a booking somebody should not have, and never another
 * customer's data. The prompt's job is to make the assistant USEFUL; the code's
 * job is to make it safe.
 *
 * ## What IS in here
 *
 * The things that genuinely improve answers and cost nothing if ignored: the
 * center's name, the language to reply in, and the habit of using tools rather
 * than guessing. The last one is not a security control — a model that ignores
 * it and invents a time gets an invalid `starts_at` refused by
 * `create_booking`, which is where that rule actually lives.
 */
final class RayanPrompt
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly RayanSettings $settings,
    ) {}

    public function build(string $locale): string
    {
        $center = $this->tenants->require()->name;

        $lines = [
            sprintf('You are the booking assistant for %s, a beauty and grooming center.', $center),
            sprintf('Reply in this language code: %s. Match the customer if they switch language.', $locale),
            '',
            'How to work:',
            // Not a safety rule — a quality one. The engine refuses invented
            // times anyway; this just stops the model wasting a turn.
            '- Never state a price, a time or an availability you have not read from a tool.',
            '- Call get_available_slots before create_booking, and use its exact starts_at value.',
            '- Ask for the customer\'s name before booking if you do not already have it.',
            '- Confirm the branch, service, date and time back to the customer before you book.',
            '- Always confirm before cancelling.',
            '- Keep replies short. This is WhatsApp, not a web page.',
            '- If you cannot help, say so plainly and offer to pass the conversation to a colleague.',
            // The honest framing of what the model cannot do, so it does not
            // promise things no tool can deliver.
            '- You cannot take payments, change prices, or alter anything except this customer\'s own bookings.',
        ];

        $tone = $this->settings->customInstruction();

        if ($tone !== '') {
            $lines[] = '';
            /*
             * The center's own sentence, clearly FENCED and labelled as tone.
             *
             * The label matters less than the fact that nothing downstream
             * consults the prompt for a decision — but it costs nothing and
             * makes the model treat it as voice rather than as a new rule that
             * outranks the ones above (§17).
             */
            $lines[] = 'The center asks you to keep this tone:';
            $lines[] = $tone;
        }

        return implode("\n", $lines);
    }
}
