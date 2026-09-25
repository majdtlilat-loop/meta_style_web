<?php

/*
|--------------------------------------------------------------------------
| In-app notifications
|--------------------------------------------------------------------------
|
| docs/23-NOTIFICATIONS.md §§6, 13.
|
| IN-APP IS THE ONLY CHANNEL. There is no mail, SMS, WhatsApp or push
| configuration here, and no place to put one: a channel is a phase, not a
| config key (§2).
|
| Every sweep below is bounded and idempotent. `max_per_run` is what stops a
| backlog — a center that was offline for a week, an import — from turning one
| scheduled minute into an hour, and re-running simply continues.
|
*/

return [

    'reminders' => [

        /*
         * Reminders can be switched off for the whole platform. A center's
         * customers switch them off for themselves through their preferences;
         * this is the operational kill switch.
         */
        'enabled' => (bool) env('METASTYLE_REMINDERS_ENABLED', true),

        /*
         * How far ahead of the appointment the reminder appears. A day: it
         * arrives while the customer can still rearrange their evening, and it
         * is one simple number rather than a reminder-rule designer (§13).
         *
         * The window is computed in the BRANCH's timezone; the comparison is
         * UTC, like every other instant in the system.
         */
        'lead_minutes' => (int) env('METASTYLE_REMINDER_LEAD_MINUTES', 1440),

        'max_per_run' => 500,
    ],

    'expiry' => [

        /*
         * A membership or a package that is about to run out. One threshold,
         * one notification per benefit — not a campaign scheduler (§14).
         */
        'enabled' => (bool) env('METASTYLE_EXPIRY_NOTICES_ENABLED', true),

        'lead_days' => (int) env('METASTYLE_EXPIRY_LEAD_DAYS', 7),

        'max_per_run' => 500,
    ],

    'retention' => [

        /*
         * Notifications are communication history, not domain truth: the
         * appointment, the invoice and the review remain whatever happens here.
         * Six months is long enough to answer "did you tell me?" and short
         * enough that the table does not grow forever (§15).
         *
         * An UNREAD `important` notification is never swept: the one row this
         * cleanup could do real harm to is the one-star review nobody has read.
         */
        'days' => (int) env('METASTYLE_NOTIFICATION_RETENTION_DAYS', 180),

        'max_per_run' => 1000,
    ],

];
