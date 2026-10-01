<?php

namespace App\Services\Oidc;

use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The relying-party side of OpenID Connect against one issuer, or against one realm per tenant
 * when OIDC_ISSUER has `{tenant}` (e.g. https://sso.example/realms/{tenant}). The discovery
 * document and the signing keys of each issuer are cached for an hour.
 */
class OidcClient
{
    public const BACKCHANNEL_LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function enabled(): bool
    {
        return filled(config('oidc.issuer'));
    }

    /**
     * Whether password sign-in and sign-up are turned off.
     */
    public function only(): bool
    {
        return $this->enabled() && (bool) config('oidc.only');
    }

    public function multiTenant(): bool
    {
        return str_contains((string) config('oidc.issuer'), '{tenant}');
    }

    /**
     * The issuer for `$tenant`; null when the tenant is missing or not accepted here.
     */
    public function issuerFor(?string $tenant): ?string
    {
        $issuer = rtrim((string) config('oidc.issuer'), '/');

        if (! $this->multiTenant()) {
            return $issuer;
        }

        if (! $this->acceptsTenant($tenant)) {
            return null;
        }

        return str_replace('{tenant}', $tenant, $issuer);
    }

    /**
     * The tenant an issuer belongs to (the realm, i.e. the `{tenant}` part); null on a single
     * issuer, false when `$issuer` does not match the configured issuer or its tenant is not accepted.
     */
    public function tenantOf(string $issuer): string|false|null
    {
        $configured = rtrim((string) config('oidc.issuer'), '/');
        $issuer = rtrim($issuer, '/');

        if (! $this->multiTenant()) {
            return $issuer === $configured ? null : false;
        }

        [$prefix, $suffix] = explode('{tenant}', $configured, 2);

        if (! str_starts_with($issuer, $prefix) || ! str_ends_with($issuer, $suffix)) {
            return false;
        }

        $tenant = substr($issuer, strlen($prefix), strlen($issuer) - strlen($prefix) - strlen($suffix));

        return $this->acceptsTenant($tenant) ? $tenant : false;
    }

    /**
     * A tenant listed in OIDC_TENANTS, or one the Hub has provisioned an organization for.
     */
    public function acceptsTenant(?string $tenant): bool
    {
        if (! is_string($tenant) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $tenant)) {
            return false;
        }

        return in_array($tenant, config('oidc.tenants', []), true)
            || Organization::query()->where('tenant', $tenant)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function discovery(string $issuer): array
    {
        return Cache::remember('oidc:discovery:'.sha1($issuer), config('oidc.cache_ttl'), function () use ($issuer) {
            $document = Http::acceptJson()->timeout(10)->get($issuer.'/.well-known/openid-configuration')->throw()->json();

            if (! is_array($document) || rtrim((string) ($document['issuer'] ?? ''), '/') !== $issuer) {
                throw new OidcException('The discovery document does not belong to the issuer.');
            }

            return $document;
        });
    }

    public function authorizationUrl(string $issuer, string $redirectUri, string $state, string $nonce, string $codeVerifier): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('oidc.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => Jwt::base64UrlEncode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        $endpoint = (string) $this->discovery($issuer)['authorization_endpoint'];

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Exchange the authorization code; returns the token response (id_token, access_token, …).
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $issuer, string $code, string $redirectUri, string $codeVerifier): array
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post((string) $this->discovery($issuer)['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => config('oidc.client_id'),
            'client_secret' => config('oidc.client_secret'),
            'code_verifier' => $codeVerifier,
        ]);

        if ($response->failed() || ! is_string($response->json('id_token'))) {
            throw new OidcException('The identity provider did not accept the authorization code.');
        }

        return $response->json();
    }

    /**
     * The claims of an ID token after checking its signature, issuer, audience, expiry and nonce.
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $issuer, string $idToken, string $nonce): array
    {
        $claims = $this->verifySigned($issuer, $idToken);

        if (! is_numeric($claims['exp'] ?? null) || time() > $claims['exp'] + config('oidc.leeway')) {
            throw new OidcException('The ID token has expired.');
        }

        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new OidcException('The ID token nonce does not match.');
        }

        return $claims;
    }

    /**
     * The claims of a back-channel logout token (OpenID Connect Back-Channel Logout 1.0, §2.6).
     *
     * @return array<string, mixed>
     */
    public function verifyLogoutToken(string $logoutToken): array
    {
        $issuer = (string) (Jwt::decode($logoutToken)['claims']['iss'] ?? '');

        if ($this->tenantOf($issuer) === false) {
            throw new OidcException('The logout token comes from an unknown issuer.');
        }

        $claims = $this->verifySigned(rtrim($issuer, '/'), $logoutToken);

        if (! is_array($claims['events'] ?? null) || ! array_key_exists(self::BACKCHANNEL_LOGOUT_EVENT, $claims['events'])) {
            throw new OidcException('The logout token has no back-channel logout event.');
        }

        if (array_key_exists('nonce', $claims) || (blank($claims['sid'] ?? null) && blank($claims['sub'] ?? null))) {
            throw new OidcException('The logout token is malformed.');
        }

        if (! is_numeric($claims['iat'] ?? null) || time() < $claims['iat'] - config('oidc.leeway')) {
            throw new OidcException('The logout token is not valid yet.');
        }

        if (is_numeric($claims['exp'] ?? null) && time() > $claims['exp'] + config('oidc.leeway')) {
            throw new OidcException('The logout token has expired.');
        }

        return $claims;
    }

    /**
     * Claims from the userinfo endpoint; empty when the provider has none.
     *
     * @return array<string, mixed>
     */
    public function userinfo(string $issuer, string $accessToken): array
    {
        $endpoint = $this->discovery($issuer)['userinfo_endpoint'] ?? null;

        if (! is_string($endpoint) || $accessToken === '') {
            return [];
        }

        $response = Http::withToken($accessToken)->acceptJson()->timeout(10)->get($endpoint);

        return $response->successful() && is_array($response->json()) ? $response->json() : [];
    }

    public function endSessionUrl(string $issuer, ?string $idToken, string $postLogoutRedirectUri): ?string
    {
        $endpoint = $this->discovery($issuer)['end_session_endpoint'] ?? null;

        if (! is_string($endpoint)) {
            return null;
        }

        $query = http_build_query(array_filter([
            'id_token_hint' => $idToken,
            'client_id' => config('oidc.client_id'),
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
        ]), '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Claims of a JWT signed by `$issuer`, issued by it and meant for this client.
     *
     * @return array<string, mixed>
     */
    private function verifySigned(string $issuer, string $jwt): array
    {
        try {
            $claims = Jwt::verify($jwt, $this->keys($issuer));
        } catch (UnknownSigningKey) {
            // The provider may have rotated its keys since they were cached.
            $claims = Jwt::verify($jwt, $this->keys($issuer, fresh: true));
        }

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $issuer) {
            throw new OidcException('The token was issued by another issuer.');
        }

        $audience = (array) ($claims['aud'] ?? []);

        if (! in_array(config('oidc.client_id'), $audience, true)) {
            throw new OidcException('The token is meant for another client.');
        }

        if (count($audience) > 1 && isset($claims['azp']) && $claims['azp'] !== config('oidc.client_id')) {
            throw new OidcException('The token is meant for another client.');
        }

        return $claims;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function keys(string $issuer, bool $fresh = false): array
    {
        $key = 'oidc:jwks:'.sha1($issuer);

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, config('oidc.cache_ttl'), function () use ($issuer) {
            $keys = Http::acceptJson()->timeout(10)->get((string) $this->discovery($issuer)['jwks_uri'])->throw()->json('keys');

            if (! is_array($keys)) {
                throw new OidcException('The identity provider has no signing keys.');
            }

            return array_values($keys);
        });
    }
}
