<?php

return [
    'date_format' => 'm/d/Y',
    'datetime_format' => 'm/d/Y \a\t g:i A',

    'menu' => [
        'account' => 'My Kiban account',
    ],

    'home' => [
        'title' => 'Home',
        'welcome' => 'Welcome to :name',
        'progress' => 'First steps: :done of :total done.',
        'all_done' => 'First steps done. Everything is ready to use!',
        'locked' => 'Available after: :steps',
        'do_it' => 'Do it now',
        'no_modules' => 'No systems subscribed',
        'no_modules_help' => 'The systems your company subscribes to appear here. Contact Kiban to subscribe.',
    ],

    'license' => [
        'read_only_badge' => 'Read-only',
        'read_only' => ':module is read-only: you can see the data but not change it. Settle the subscription in "My Kiban account".',
        'trial' => 'The :module trial ends on :date.',
        'offline' => 'We have not been able to reach Kiban for a while. The system keeps working until :date. If this notice stays, contact support.',
    ],

    'denied' => [
        'module' => 'This system is not part of the company plan.',
        'read_only' => 'Read-only: the data cannot be changed until the subscription is settled.',
        'step' => 'First complete the step ":step".',
        'limit' => 'The company plan allows up to :limit records here. To add more, upgrade the plan in "My Kiban account".',
        'manager' => 'Only the company owner or an administrator can do this.',
    ],

    'central' => [
        'title' => 'Sign in',
        'intro' => 'Type your company address.',
        'address' => 'Company address',
        'placeholder' => 'yourcompany',
        'not_found' => 'We could not find a company with this address.',
        'go' => 'Continue',
        'account' => 'Not sure of the address? See it in <a href=":url">My Kiban account</a>.',
    ],

    'not_found' => [
        'title' => 'Company not found',
        'body' => 'There is no company at this address. Please check that it was typed correctly.',
        'action' => 'Find my company',
    ],

    'blocked' => [
        'title' => 'Access unavailable',
        'inactive' => 'This company\'s access is suspended. The person in charge can see the details in "My Kiban account".',
        'expired' => 'We could not confirm this company\'s license with Kiban. Try again in a few minutes; if it continues, contact support.',
        'unavailable' => 'We could not confirm this company\'s license right now. Try again in a few minutes.',
        'action' => 'Go to My Kiban account',
    ],

    'no_access' => [
        'title' => 'No access to this company',
        'body' => 'Your account is not part of the :company team. Ask the person in charge of the company to invite you.',
        'signed_as' => 'You signed in as :email.',
        'account' => 'Go to My Kiban account',
        'retry' => 'Try again',
    ],

    'signed_out' => [
        'title' => 'You signed out',
        'body' => 'You signed out of the :company system.',
        'denied_title' => 'Sign-in canceled',
        'denied' => 'The sign-in was canceled. You can try again whenever you want.',
        'again' => 'Sign in again',
    ],

    'failed' => [
        'title' => 'Could not sign in',
        'expired' => 'The sign-in link expired or was already used. Please try again.',
        'core' => 'We could not reach Kiban right now. Try again in a few minutes.',
        'again' => 'Try again',
    ],

    'console' => [
        'licenses' => 'Licenses refreshed: :ok. No answer from the Core: :failed.',
    ],
];
