<?php

// Connections between the systems (App\Connections): the window "your systems now work together", the page
// "Systems together" and the texts of the features that link two systems.
return [
    'and' => 'and',
    'window' => [
        'title' => 'News: your systems now work together',
        'intro' => 'Your company uses :systems. See what one system does for the other:',
        'see_where' => 'See where',
        'see_all' => 'See all',
        'forever' => 'Do not show again',
        'ok' => 'Got it',
    ],
    'page' => [
        'title' => 'Systems together',
        'help' => 'What your company\'s systems do together: what one records, the other uses.',
        'active' => 'Working now',
        'none' => 'With a single system there are no connections yet. They show up when the company uses two or more Kiban Core systems.',
        'with' => 'With :system, you would also have',
        'with_help' => 'These connections start working by themselves when the system is added.',
        'plans' => 'Learn about :system and the plans',
    ],
    'items' => [
        'punch_reminder' => [
            'title' => 'Time clock reminder at the first and the last work order',
            'body' => 'When tapping "I arrived" at the first work order of the day without clocking in, the technician sees "Clock in now?". After finishing the last work order, the reminder to clock out shows up. Clocking in is still done by the employee, with the selfie and the location.',
        ],
        'utilization' => [
            'title' => 'Utilization of each technician',
            'body' => 'On the Field dashboard, the hours worked on the Time Clock sit next to the hours spent at work orders. You see how much of the day became work at the customer.',
        ],
        'field_km_to_fleet' => [
            'title' => 'The arrival odometer goes to the Fleet',
            'body' => 'The odometer the technician types when arriving at the customer goes into the vehicle\'s history in the Fleet. Nobody types it twice, and oil changes and services stay up to date.',
        ],
        'travel_cost' => [
            'title' => 'Estimated travel cost',
            'body' => 'On the finished work order and on the Field dashboard, the manager sees roughly what it cost to get to the customer: the distance driven times the vehicle\'s cost per distance in the Fleet.',
        ],
        'inspection_after_punch' => [
            'title' => 'Inspection reminder when clocking in',
            'body' => 'When the driver clocks in and their vehicle has not been inspected yet, the time clock screen shows "Do the vehicle\'s inspection now". It is only a suggestion.',
        ],
        'documents_license' => [
            'title' => 'The driver\'s license registered by HR warns the Fleet',
            'body' => 'HR registers the driver\'s license in People → Documents. The driver\'s record in the Fleet is updated by itself, and the Fleet manager sees it in "Attention" when it expires.',
        ],
        'documents_training' => [
            'title' => 'Safety trainings warn the Field team',
            'body' => 'The safety trainings registered by HR (NR-10, NR-35, NR-33 in Brazil) reach the Field office: a technician with an expired training shows up in "Attention" and with a warning when being scheduled.',
        ],
        'epi_field' => [
            'title' => 'Delivered PPE warns the Field team',
            'body' => 'A type of service can say which PPE it needs (e.g. work at height → safety harness). A technician who did not receive that PPE shows a warning when being scheduled and when opening the work order on the phone. It is only a warning: nothing is blocked.',
        ],
        'one_person' => [
            'title' => 'One person, one record',
            'body' => 'Each person is registered once in People and signs in with the same login to every system. Ready-made profiles, such as "Field technician" and "Driver", give access to both systems at once.',
        ],
    ],
    'clock' => [
        'in' => 'You have not clocked in yet. Clock in now?',
        'in_help' => 'Your arrival is already recorded. You clock in yourself, on the time clock screen.',
        'in_button' => 'Clock in',
        'out' => 'This was today\'s last service. Clock out now?',
        'out_help' => 'The service is already finished. You clock out yourself, on the time clock screen.',
        'out_button' => 'Clock out',
        'later' => 'Not now',
    ],
    'inspection' => [
        'title' => 'Do the vehicle\'s inspection now',
        'help' => 'Your vehicle has not been inspected yet. It takes 1 or 2 minutes.',
        'do' => 'Inspect: :vehicle',
    ],
    'dashboard' => [
        'utilization' => 'Utilization',
        'utilization_hours' => ':service of :worked',
        'utilization_help' => 'Utilization = hours at work orders (from arrival to the end) ÷ hours worked on the Time Clock.',
        'travel' => 'Travel (estimate)',
        'travel_help' => 'Travel = estimate: distance to the customer (the arrival odometer minus the previous one of the same day) × the vehicle\'s cost per distance in the Fleet.',
    ],
    'order' => [
        'travel' => 'Travel cost (estimate)',
        'travel_help' => 'Distance driven to the customer × :cost per :unit (the vehicle\'s cost in the Fleet in the last months).',
    ],
];
