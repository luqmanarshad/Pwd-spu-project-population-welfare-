<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'sms' => [
        'base_url' => 'https://smsgateway.pitb.gov.pk/api/send_sms',
        'secret' => '36d4e87ebde974e6e343117d167819a2',
    ],
    'fcm' => [
        'key' => env('FCM_SECRET_KEY')
    ],
    'google_recaptcha' => [
        'url' => 'https://www.google.com/recaptcha/api/siteverify',
        'site_key' => env('GOOGLE_RECAPTCHA_SITE_KEY'),
        'secret_key' => env('GOOGLE_RECAPTCHA_SECRET_SITE_KEY'),
        'enable_captcha' => env('ENABLE_CAPTCHA'),
    ]

];
