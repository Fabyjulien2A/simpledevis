<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more.
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
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    */

    'stripe' => [
        'model' => App\Models\User::class,
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),

        'webhook' => [
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
            'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SUPER PDP
    |--------------------------------------------------------------------------
    */

    'superpdp' => [
        'client_id' => env('SUPERPDP_CLIENT_ID'),
        'client_secret' => env('SUPERPDP_CLIENT_SECRET'),

        'redirect_uri' => env(
            'SUPERPDP_REDIRECT_URI',
            'http://127.0.0.1:8000/facturation-electronique/callback'
        ),

        'authorize_url' => env(
            'SUPERPDP_AUTHORIZE_URL',
            'https://api.superpdp.tech/oauth2/authorize'
        ),

        'token_url' => env(
            'SUPERPDP_TOKEN_URL',
            'https://api.superpdp.tech/oauth2/token'
        ),

        'revoke_url' => env(
            'SUPERPDP_REVOKE_URL',
            'https://api.superpdp.tech/oauth2/revoke'
        ),

        'api_url' => env(
            'SUPERPDP_API_URL',
            'https://api.superpdp.tech/v1.beta'
        ),
    ],

];