<?php

/*
|--------------------------------------------------------------------------
| WhatsApp providers
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §§4, 15.
|
| WHERE a provider lives is platform configuration, set by whoever operates Meta
| Style — never by a center. A center supplies its own WhatsApp Business
| credentials; it can never point an outbound request at an address of its
| choosing, and there is no URL column anywhere in `whatsapp_accounts` for it to
| try (ADR-071).
|
| Provider documentation behind the adapter, and the date it was read, is
| recorded in docs/25-WHATSAPP.md §15.
|
*/

return [

    'http' => [
        // Seconds. A provider that does not answer must not hold a worker — and
        // a timeout here becomes an `unknown` delivery state, never a retry.
        'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('WHATSAPP_HTTP_CONNECT_TIMEOUT', 5),
    ],

    'providers' => [

        /*
         * Meta WhatsApp Cloud API, used DIRECTLY — no BSP, no intermediary.
         *
         * The Graph API version is pinned rather than floating. Meta's payload
         * shapes and field names change between versions, and a floating
         * version means the day an adapter breaks is decided by Meta's release
         * calendar rather than by a deploy anybody made.
         *
         * Upgrading is: read the changelog for the fields this adapter reads,
         * bump this, run the adapter contract tests, deploy.
         */
        'meta_cloud' => [
            'base_url' => env('WHATSAPP_META_BASE_URL', 'https://graph.facebook.com'),
            'graph_version' => env('WHATSAPP_META_GRAPH_VERSION', 'v25.0'),
        ],
    ],

    /*
     * Approved message templates, by purpose.
     *
     * Meta requires a pre-APPROVED template to message a customer outside the
     * service window their own message opens. Approval happens in the center's
     * Meta account, not here, so what lives here is only the MAPPING from a
     * purpose this application knows about to the template name the center had
     * approved.
     *
     * EMPTY BY DEFAULT, deliberately. A template name configured here that does
     * not exist at Meta produces a send that fails at the provider, and one
     * that exists but was never approved fails the same way — so shipping
     * plausible-looking defaults would be shipping an outage. A purpose with no
     * mapping simply cannot be sent as a template, and the code paths that
     * would have used one say so rather than guessing (§14).
     */
    'templates' => [
        /*
         * A guest's booking confirmation (docs/25-WHATSAPP.md §22). UNSET by
         * default — null is "no template", and nothing is sent. The approved
         * template takes eight body variables, in this order: customer name,
         * center name, branch name, date, time, services, booking number,
         * branch contact (App\Modules\Conversations\Application\BookingConfirmationContent).
         */
        'booking_confirmation' => env('WHATSAPP_TEMPLATE_BOOKING_CONFIRMATION'),
        // 'booking_reminder' => env('WHATSAPP_TEMPLATE_BOOKING_REMINDER'),
        // 'handoff_acknowledgement' => env('WHATSAPP_TEMPLATE_HANDOFF'),
    ],

    /*
     * Template languages: app locale => Meta template language code.
     *
     * Meta addresses an approved template by its name AND a language code from
     * its own list, which is not the application's locale list — the app
     * locale is never sent as is. A business-initiated notice is written only
     * in a language mapped here (docs/25-WHATSAPP.md §22); a language left
     * unmapped (null) is never sent, and a center with no mapped language
     * sends nothing and says so.
     *
     * `en` and `ar` are mapped by default. Kurdish Sorani (`ckb`) is NOT:
     * whether Meta accepts templates in it has not been verified, and a guessed
     * code would be a send that fails at the provider. Map it only once the
     * code is confirmed in Meta's template language list and a template is
     * approved in it. A code must look like `en`, `ar` or `en_US`.
     */
    'template_languages' => [
        'en' => env('WHATSAPP_TEMPLATE_LANGUAGE_EN', 'en'),
        'ar' => env('WHATSAPP_TEMPLATE_LANGUAGE_AR', 'ar'),
        'ckb' => env('WHATSAPP_TEMPLATE_LANGUAGE_CKB'),
    ],

];
