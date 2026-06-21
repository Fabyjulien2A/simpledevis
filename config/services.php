<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Services tiers (Third Party Services)
    |--------------------------------------------------------------------------
    |
    | Ce fichier permet de centraliser les identifiants et paramètres
    | des services externes utilisés par l’application.
    |
    | Les valeurs sont généralement récupérées depuis le fichier .env
    | grâce à la fonction env().
    |
    | Cela permet :
    | - de sécuriser les clés API,
    | - de changer facilement d’environnement,
    | - d’éviter de hardcoder des secrets dans le code.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Configuration Postmark
    |--------------------------------------------------------------------------
    |
    | Service d’envoi d’e-mails transactionnels.
    |
    */
    'postmark' => [

        // Clé API Postmark
        'key' => env('POSTMARK_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration Resend
    |--------------------------------------------------------------------------
    |
    | Service d’envoi d’e-mails moderne.
    |
    */
    'resend' => [

        // Clé API Resend
        'key' => env('RESEND_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration Amazon SES
    |--------------------------------------------------------------------------
    |
    | Service Amazon Simple Email Service (SES)
    | utilisé pour l’envoi d’e-mails.
    |
    */
    'ses' => [

        // Identifiant AWS
        'key' => env('AWS_ACCESS_KEY_ID'),

        // Clé secrète AWS
        'secret' => env('AWS_SECRET_ACCESS_KEY'),

        // Région AWS utilisée
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration Slack
    |--------------------------------------------------------------------------
    |
    | Utilisé pour envoyer des notifications Slack.
    |
    */
    'slack' => [

        'notifications' => [

            // Token OAuth du bot Slack
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),

            // Canal Slack par défaut
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration Stripe
    |--------------------------------------------------------------------------
    |
    | Stripe est utilisé pour :
    | - les paiements,
    | - les abonnements,
    | - les webhooks.
    |
    */
    'stripe' => [

        // Modèle utilisateur utilisé par Laravel Cashier
        'model' => User::class,

        // Clé publique Stripe
        'key' => env('STRIPE_KEY'),

        // Clé secrète Stripe
        'secret' => env('STRIPE_SECRET'),

        // Configuration des webhooks Stripe
        'webhook' => [

            // Secret de signature du webhook
            'secret' => env('STRIPE_WEBHOOK_SECRET'),

            // Tolérance maximale en secondes
            // pour valider la signature du webhook
            'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration IOPOLE
    |--------------------------------------------------------------------------
    |
    | Paramètres utilisés pour communiquer
    | avec l’API IOPOLE.
    |
    */
    'iopole' => [

        // URL d’authentification OAuth2
        'auth_url' => env('IOPOLE_AUTH_URL'),

        // URL principale de l’API
        'base_url' => env('IOPOLE_BASE_URL'),

        // Identifiant client API
        'client_id' => env('IOPOLE_CLIENT_ID'),

        // Secret client API
        'client_secret' => env('IOPOLE_CLIENT_SECRET'),

        // Identifiant client/fournisseur IOPOLE
        'customer_id' => env('IOPOLE_CUSTOMER_ID'),
    ],

];
