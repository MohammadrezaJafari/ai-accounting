<?php

/*
| Sign-in with the organization's identity provider (OpenID Connect, e.g. Keycloak) and the
| service key the Rahap Hub provisions organizations and members with. Both are optional:
| with OIDC_ISSUER and SERVICE_KEY empty the product works exactly as before.
*/

$csv = fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [
    // e.g. https://sso.example/realms/acme, or https://sso.example/realms/{tenant} on a shared installation.
    'issuer' => env('OIDC_ISSUER'),
    'client_id' => env('OIDC_CLIENT_ID'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),
    'groups_claim' => env('OIDC_GROUPS_CLAIM', 'groups'),
    'roles_claim' => env('OIDC_ROLES_CLAIM', 'roles'),

    // Create a user on their first sign-in; otherwise only provisioned or existing users may sign in.
    'auto_provision' => (bool) env('OIDC_AUTO_PROVISION', true),

    // Turn password sign-in and sign-up off (only while OIDC_ISSUER is set).
    'only' => (bool) env('OIDC_ONLY', false),

    // Tenants accepted when the issuer has `{tenant}`; empty means any tenant known from provisioning.
    'tenants' => $csv(env('OIDC_TENANTS')),

    // Seconds the discovery document and the signing keys of each issuer are cached.
    'cache_ttl' => 3600,

    // Clock skew allowed when checking `exp` and `iat`.
    'leeway' => 60,

    // Bearer key the Hub calls /api/service/v1 with; the endpoints answer 404 while it is empty.
    'service_key' => env('SERVICE_KEY'),
];
