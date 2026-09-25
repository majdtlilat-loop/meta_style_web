<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Manager — today's visits (the floor board)
|--------------------------------------------------------------------------
|
| docs/16-JOURNEY-RESOURCES.md. Named after the SURFACE, never a dotless
| literal (SafeguardsTest). Refusals come from manager_queue.errors.
|
*/

return [
    'live' => 'Live',
    'live_hint' => 'Refreshes on its own while this tab is open.',

    'actions' => [
        'walk_in' => 'New walk-in',
        'check_in' => 'Check in',
        'start' => 'Start',
        'finish' => 'Finish',
        'checkout' => 'Checkout',
        'details' => 'Open visit',
        'hand_on' => 'Hand on',
        'give_number' => 'Give a queue number',
        'reassign' => 'Reassign',
        'swap' => 'Swap room',
        'skip' => 'Skip',
        'note' => 'Note',
        'left' => 'Customer left',
        'cancel_booking' => 'Cancel booking',
        'complete' => 'Complete visit',
    ],

    'stats' => [
        'label' => 'Filter by stage',
        'late' => ':count late',
        'walk_ins' => '{1} :count walk-in|[2,*] :count walk-ins',
    ],

    'filters' => [
        'team_member' => 'Team member',
        'everyone' => 'Everyone',
    ],

    'lanes' => [
        'label' => 'Show',
        'not_arrived' => 'Not arrived',
        'waiting' => 'Waiting',
        'in_service' => 'In service',
        'completed' => 'Completed',
        'abandoned' => 'Left',
        'empty_not_arrived' => 'Nobody else is expected.',
        'empty_waiting' => 'Nobody is waiting.',
        'empty_in_service' => 'Nobody is in a chair.',
        'empty_completed' => 'No finished visits yet.',
        'empty_abandoned' => 'Nobody left early.',
    ],

    'card' => [
        'walk_in' => 'Walk-in',
        'late' => ':minutes min late',
        'waiting' => 'Waiting :minutes min',
        'elapsed' => ':minutes of :expected min',
        'progress' => ':done of :total services',
    ],

    'notices' => [
        'checked_in' => 'Checked in.',
        'started' => 'Service started.',
        'finished' => 'Service finished.',
        'walk_in' => 'Walk-in visit created.',
        'walk_in_ticket' => 'Walk-in visit created with number :number.',
        'ticket' => 'Number :number issued.',
        'skipped' => 'Service skipped.',
        'reassigned' => 'Reassigned. The booking still shows who was booked.',
        'swapped' => 'Room swapped. The previous one stays in the history.',
        'handed_on' => 'Handed on to the next service.',
        'note_added' => 'Note added.',
        'note_deleted' => 'Note deleted.',
        'completed' => 'Visit completed.',
        'left' => 'Visit ended. The booking itself was not changed.',
        'cancelled' => 'Visit ended and booking cancelled.',
    ],

    'panel' => [
        'walk_in_arrived' => 'Walk-in · arrived :time',
        'booked_for' => 'Booked for :time · :reference',
        'arrived' => 'Arrived :time',
        'completed_at' => 'Completed :time',
        'left_at' => 'Left :time',
        'left_reason' => 'Left early: :reason',
        'services' => 'Services',
        'booked_with' => 'Booked with',
        'anyone' => 'Anyone',
        'with' => 'Now with',
        'reassigned' => 'Reassigned',
        'planned' => 'Planned',
        'actual' => 'Actual',
        'skipped_because' => 'Skipped: :reason',
        'handoffs' => 'Hand-offs',
        'handoff_end' => 'Finished',
    ],

    'visibility' => [
        'internal' => 'Team',
        'manager_only' => 'Managers only',
    ],

    'notes' => [
        'delete' => 'Delete note',
        'delete_confirm' => 'Delete this note? This cannot be undone.',
        'advisory' => 'Operational notes only — never medical or health information. Other staff can read them.',
    ],

    'forms' => [
        'skip' => [
            'title' => 'Skip this service',
            'reason' => 'Why?',
            'submit' => 'Skip service',
        ],
        'handoff' => [
            'title' => 'Hand on to the next service',
            'note' => 'Note for the next person',
            'submit' => 'Hand on',
        ],
        'reassign' => [
            'title' => 'Reassign',
            'none' => 'Nobody else at this branch can do this service.',
            'employee' => 'Team member',
            'submit' => 'Reassign',
        ],
        'swap' => [
            'title' => 'Swap room or device',
            'none' => 'This service is not using a room or device right now.',
            'from' => 'Current',
            'to' => 'New',
            'submit' => 'Swap',
        ],
        'note' => [
            'title' => 'Add a note',
            'body' => 'Note',
            'visibility' => 'Who can read it',
            'submit' => 'Add note',
        ],
        'leave' => [
            'title' => 'Customer left',
            'help' => 'Ends the visit. The booking itself is not cancelled.',
            'confirm' => 'End this visit? Services not yet started will not happen.',
            'submit' => 'End visit',
        ],
        'cancel' => [
            'title' => 'Cancel booking',
            'help' => 'Ends the visit and cancels the booking, through the booking rules.',
            'confirm' => 'End the visit and cancel the booking?',
            'submit' => 'Cancel booking',
        ],
    ],
];
