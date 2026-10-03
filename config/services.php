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

    /*
    | Messenger bots agents deliver reports with. Customers add the platform bot to their
    | channel or group, or enter their own bot token. Bale's bot API mirrors Telegram's.
    */
    'telegram' => [
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    ],

    'bale' => [
        'api_url' => env('BALE_API_URL', 'https://tapi.bale.ai'),
        'bot_token' => env('BALE_BOT_TOKEN'),
        'bot_username' => env('BALE_BOT_USERNAME'),
    ],

    /*
    | GapGPT, an OpenAI-compatible gateway to OpenAI, Anthropic, Gemini and others. With
    | GAPGPT_ROUTE_ALL_MODELS the catalog seeder sends every model through it (model ids
    | must match GapGPT's; check them with GET {base_url}/models).
    */
    'gapgpt' => [
        'base_url' => env('GAPGPT_BASE_URL', 'https://api.gapgpt.app/v1'),
        'api_key' => env('GAPGPT_API_KEY'),
        'route_all_models' => (bool) env('GAPGPT_ROUTE_ALL_MODELS', false),
    ],

    /*
    | Zarinpal payment gateway (BILLING_PAYMENT_GATEWAY=zarinpal). Orders are in USD and are
    | charged in Toman at ZARINPAL_TOMAN_PER_USD.
    */
    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        'sandbox' => (bool) env('ZARINPAL_SANDBOX', false),
        'toman_per_usd' => env('ZARINPAL_TOMAN_PER_USD'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
