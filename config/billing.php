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

    // Customer panel (ai-accounting-web-app) address, used in invitation links sent by email.
    'panel_url' => env('PANEL_URL', env('APP_URL', 'http://localhost')),

    'upstream_timeout' => (int) env('GATEWAY_UPSTREAM_TIMEOUT', 600),

    // Gateway requests per minute per API key, unless the key sets its own (0 = unlimited).
    // Customers may set their keys up to `max_per_minute`; the admin may set any value.
    'gateway_rate_limit' => [
        'per_minute' => (int) env('GATEWAY_RATE_LIMIT_PER_MINUTE', 60),
        'max_per_minute' => (int) env('GATEWAY_RATE_LIMIT_MAX_PER_MINUTE', 600),
    ],

    // Marketplace agents made by publisher organizations: the publisher's default percent of
    // the revenue, the default cap on one run's model spend (USD) and test runs per agent per day.
    'publishers' => [
        'revenue_share' => (int) env('PUBLISHER_REVENUE_SHARE', 70),
        'max_cost_per_run_usd' => env('PUBLISHER_MAX_COST_PER_RUN_USD', '0.10'),
        // The highest cap a publisher may set for itself, unless the admin sets another per agent.
        'max_cost_ceiling_usd' => env('PUBLISHER_MAX_COST_CEILING_USD', '1.00'),
        // How far below zero a publisher's balance may go (model costs of its runs) before its test runs stop.
        'test_run_debt_limit_usd' => env('PUBLISHER_TEST_RUN_DEBT_LIMIT_USD', '5.00'),
        'test_runs_per_day' => (int) env('PUBLISHER_TEST_RUNS_PER_DAY', 30),
        // Most free trial units a publisher may give each organization (it pays their model cost).
        'max_free_trial_units' => (int) env('PUBLISHER_MAX_FREE_TRIAL_UNITS', 10),
        // The platform settles a publisher's balance once it reaches this amount.
        'payout_min_usd' => env('PUBLISHER_PAYOUT_MIN_USD', '10'),
        // Limits of one deposit a publisher makes from an app wallet to cover its debt.
        'deposit_min_usd' => env('PUBLISHER_DEPOSIT_MIN_USD', '1'),
        'deposit_max_usd' => env('PUBLISHER_DEPOSIT_MAX_USD', '1000'),
        // Only for developing agents locally: lets publisher services run on private addresses.
        'allow_private_endpoints' => (bool) env('PUBLISHER_ALLOW_PRIVATE_ENDPOINTS', false),
    ],
];
