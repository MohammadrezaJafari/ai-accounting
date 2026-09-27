<?php

return [
    /* Timezone of every date shown or entered in the admin panel; storage stays UTC. */
    'display_timezone' => env('BILLING_DISPLAY_TIMEZONE', 'Asia/Tehran'),

    /*
    | Default markup applied on top of the real provider price when neither the
    | model, its provider nor the app defines one. In basis points (2000 = 20%).
    | Can be changed at runtime from the admin settings page.
    */
    'default_markup_bps' => (int) env('BILLING_DEFAULT_MARKUP_BPS', 2000),

    // Requests are rejected with 402 while an app's balance is at or below this value (USD).
    'min_balance_usd' => env('BILLING_MIN_BALANCE_USD', '0'),

    // Top-ups with an arbitrary amount ("pay what you want").
    'custom_topup' => [
        'enabled' => (bool) env('BILLING_CUSTOM_TOPUP_ENABLED', true),
        'min_usd' => env('BILLING_CUSTOM_TOPUP_MIN_USD', '5'),
        'max_usd' => env('BILLING_CUSTOM_TOPUP_MAX_USD', '1000'),
    ],

    // manual: admin approves orders; fake: orders are paid immediately (development only).
    'payment_gateway' => env('BILLING_PAYMENT_GATEWAY', 'manual'),

    'api_key_prefix' => 'sk-aia-',

    'upstream_timeout' => (int) env('GATEWAY_UPSTREAM_TIMEOUT', 600),
];
