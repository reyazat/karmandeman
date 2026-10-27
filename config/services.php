<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // n8n is an untrusted workflow adapter: Laravel owns auth, state and the
    // credit ledger; n8n output is always re-validated on callback.
    'n8n' => [
        'internal_url' => env('N8N_INTERNAL_URL'),
        'api_url' => env('N8N_API_URL'),
        'api_key' => env('N8N_API_KEY'),
        'workflow_auth_token' => env('N8N_WORKFLOW_AUTH_TOKEN'),
    ],

    // Per-workspace keys live in self-hosted OpenBao Transit, never in the app DB (ADR-013).
    'openbao' => [
        'addr' => env('OPENBAO_ADDR'),
        'token' => env('OPENBAO_TOKEN'),
        'transit_mount' => env('OPENBAO_TRANSIT_MOUNT', 'transit'),
        'key_prefix' => env('OPENBAO_KEY_PREFIX', 'ws-'),
        'timeout' => (int) env('OPENBAO_TIMEOUT', 5),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
