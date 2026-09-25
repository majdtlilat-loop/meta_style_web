<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payment providers
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§16–18.
|
| WHERE a provider lives is platform configuration, set by whoever operates
| Meta Style — never by a center. A center supplies its own merchant
| credentials; it can never point an outbound request at an address of its
| choosing. An environment with no base URL here simply cannot be configured.
|
| Provider documentation behind each adapter is recorded in docs/19-PAYMENTS.md
| §16, with the date it was read.
|
*/

return [

    'http' => [
        // Seconds. A provider that does not answer must not hold a worker.
        'timeout' => (int) env('PAYMENTS_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('PAYMENTS_HTTP_CONNECT_TIMEOUT', 5),
    ],

    'providers' => [

        /*
         * First Iraqi Bank — Online Payments.
         *
         * The STAGE host is published in FIB's own documentation. FIB issues
         * production access, including where production lives, when a merchant
         * is approved; there is no published production host to hardcode, so an
         * operator sets it. Until then a live FIB account cannot be configured.
         */
        'fib' => [
            'base_urls' => [
                'sandbox' => 'https://fib.stage.fib.iq',
                'live' => env('FIB_LIVE_BASE_URL'),
            ],
        ],

        /*
         * Registered, NOT implemented. Official documentation exists for each,
         * but no adapter has been written against a verified contract or proven
         * with a sandbox transaction. They cannot be configured.
         */
        'zaincash' => ['base_urls' => []],
        'qi' => ['base_urls' => []],
        'fastpay' => ['base_urls' => []],
    ],

];
