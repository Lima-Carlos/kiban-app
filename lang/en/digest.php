<?php

// Weekly summary of all systems (App\Digest).
return [
    'title' => 'Weekly Summary',
    'week' => 'Week from :from to :to',
    'previous' => 'Previous week',
    'next' => 'Next week',
    'email_on' => 'By e-mail: on',
    'email_off' => 'By e-mail: off',
    'email_help' => 'Every Monday morning, the owner and the administrators get this summary in the bell and by e-mail. Click to turn the e-mail on or off.',
    'email_turned_on' => 'The summary will arrive by e-mail every Monday again.',
    'email_turned_off' => 'The summary no longer goes by e-mail (it stays in the bell).',
    'empty' => 'Nothing to show this week.',
    'attention' => 'Needs attention now',
    'footer' => 'All your Kiban systems together, on one page. It arrives every Monday morning.',
    'notice_title' => 'Weekly summary: :week',
    'notice_body' => 'All your systems together, on one page. Tap to see.',
    'console' => 'Weekly summary sent to :count companies.',
    'timeclock' => [
        'extra' => '{1} :hours of overtime (1 person).|[2,*] :hours of overtime (:count people).',
        'absences' => '{1} 1 absence.|[2,*] :count absences.',
        'late' => ':hours of late arrivals and early departures.',
        'missing' => '{1} 1 day with a missing punch.|[2,*] :count days with a missing punch.',
        'clean' => 'A week with no overtime, absences or late arrivals (:count people).',
    ],
    'fleet' => [
        'fuel' => '{1} :amount in 1 fuel-up (:liters liters).|[2,*] :amount in :count fuel-ups (:liters liters, :vehicles vehicles).',
        'no_fuel' => 'No fuel-ups recorded this week.',
    ],
    'field' => [
        'done' => '{0} No work orders completed.|{1} 1 work order completed.|[2,*] :count work orders completed.',
        'rating' => 'Average customer rating: :rating out of 5.',
        'late' => '{1} 1 work order past its time and not done yet.|[2,*] :count work orders past their time and not done yet.',
        'quotes' => '{1} 1 quote waiting for the customer.|[2,*] :count quotes waiting for the customer.',
    ],
    'agenda' => [
        'served' => '{0} No appointments completed.|{1} 1 appointment completed.|[2,*] :count appointments completed.',
        'no_show' => '{1} 1 customer did not show up.|[2,*] :count customers did not show up.',
    ],
    'expenses' => [
        'approved' => ':amount in approved expenses.',
        'pending' => '{1} 1 expense waiting for approval.|[2,*] :count expenses waiting for approval.',
    ],
];
