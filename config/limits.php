<?php

/*
|--------------------------------------------------------------------------
| Security rate limits
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 13 §§39, 73.
|
| SECURITY LIMITS, NOT COMMERCIAL QUOTAS. The two are permanently separate and
| must never be conflated:
|
|   a limit here   bounds ABUSE. It protects the platform and the center from
|                  floods, brute force and runaway loops. Hitting one is a
|                  signal that something is wrong, and it resets by itself.
|
|   a quota        is something a center BOUGHT. Hitting one is a normal
|                  commercial event, it is reported in the usage dashboard, and
|                  it is raised by changing a plan — never by editing this file.
|                  Those live in `Kernel\Usage` and the control plane (§40).
|
| Every bucket is a list of windows, all of which must pass. Two windows is the
| usual shape: a tight per-minute ceiling that stops a burst, and a looser
| per-hour one that stops a slow grind the first would never notice.
|
| Values are HERE rather than in the code that enforces them, so that tuning one
| during an incident is a config change rather than a deploy, and so that the
| whole abuse surface can be read on one screen.
|
*/

return [

    /*
     * Guessing a booking verification code.
     *
     * Keyed by the booking REFERENCE being attacked, not only by who is
     * attacking it — the same reasoning as `Kernel\Identity\LoginThrottle`.
     * Spreading attempts across a thousand senders must not raise the ceiling
     * on any one booking, because the booking is what is being attacked.
     *
     * Ten an hour against ~50 bits of entropy is not the protection; it is the
     * belt to the entropy's braces. What it really buys is that a scripted
     * sweep is visible and bounded rather than free.
     */
    'booking_code' => [
        'reference' => [
            ['max' => 5, 'seconds' => 60],
            ['max' => 10, 'seconds' => 3600],
        ],

        /*
         * And per WhatsApp sender, across every reference they try. A sender
         * working through a list of references trips this long before any
         * single reference bucket notices them.
         */
        'sender' => [
            ['max' => 5, 'seconds' => 60],
            ['max' => 20, 'seconds' => 3600],
        ],
    ],

    /*
     * Inbound WhatsApp.
     *
     * `account` is deliberately generous: Meta batches and retries, and a
     * center running a promotion genuinely receives a burst. It exists to stop
     * one misconfigured integration from starving the queue, not to shape
     * normal traffic.
     *
     * `sender` is the one that matters for abuse — a single number flooding a
     * center — and `conversation` bounds how fast one thread can move
     * regardless of how many numbers are pointed at it.
     */
    'whatsapp' => [
        'account' => [
            ['max' => 600, 'seconds' => 60],
        ],
        'sender' => [
            ['max' => 20, 'seconds' => 60],
            ['max' => 200, 'seconds' => 3600],
        ],
        'conversation' => [
            ['max' => 30, 'seconds' => 60],
        ],

        /*
         * Outbound protection. Not Meta's limit — theirs is theirs, and it is
         * not published as a number this code could rely on. This is Meta
         * Style's own guard against a loop that would message a customer
         * repeatedly before anybody noticed (§15).
         */
        'outbound' => [
            ['max' => 30, 'seconds' => 60],
        ],
    ],

    /*
     * AI runs, as a SECURITY limit — the short window that stops a loop.
     *
     * The commercial monthly allowance is a different mechanism entirely
     * (`Kernel\Usage`). A center that has bought a large allowance is still
     * bounded here, because "the plan allows 50,000 runs a month" has never
     * meant "fifty of them in the next four seconds" (§§38, 42).
     */
    'rayan' => [
        'conversation' => [
            ['max' => 10, 'seconds' => 60],
            ['max' => 60, 'seconds' => 3600],
        ],
    ],

];
