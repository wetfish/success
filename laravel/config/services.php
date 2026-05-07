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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | AI extraction provider configuration. The driver is bound to a
    | concrete ExtractionProvider implementation in
    | App\Providers\ExtractionServiceProvider. Costs are stored in cents
    | per million tokens, matching the Money helper convention used
    | elsewhere in the app.
    */
    'extraction' => [
        'driver' => env('EXTRACTION_DRIVER', 'claude'),
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('EXTRACTION_MODEL', 'claude-sonnet-4-6'),
        'input_cost_per_mtok_cents' => env('EXTRACTION_INPUT_COST_CENTS', 300),
        'output_cost_per_mtok_cents' => env('EXTRACTION_OUTPUT_COST_CENTS', 1500),
    ],

];