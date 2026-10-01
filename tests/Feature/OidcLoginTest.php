<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Oidc\Jwt;
use App\Services\Oidc\OidcClient;
use App\Support\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class OidcLoginTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://sso.test/realms/alpha';

    private const PANEL = 'https://panel.test';

    private \OpenSSLAsymmetricKey $signingKey;

    /** A key the provider does not publish; tokens signed with it must be refused. */
    private ?\OpenSSLAsymmetricKey $forgedKey = null;

    private string $nonce = '';

    /** @var array<string, mixed> Claims of the ID token the fake provider hands out next. */
    private array $nextClaims = [];

    private Organization $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oidc.issuer' => 'https://sso.test/realms/{tenant}',
            'oidc.client_id' => 'ai-accounting',
            'oidc.client_secret' => 'secret',
            'billing.panel_url' => self::PANEL,
        ]);

        $this->signingKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->alpha = Organization::query()->create(['tenant' => 'alpha', 'external_key' => 'alpha', 'name' => 'Alpha']);
        $this->fakeProvider();
    }

    public function test_a_new_user_signs_in_and_gets_a_dashboard_token(): void
    {
        $code = $this->signIn(['sub' => 'sub-sara', 'email' => 'Sara@Alpha.test', 'email_verified' => true, 'name' => 'Sara', 'sid' => 'sid-1'], intended: '/apps');

        $response = $this->postJson('/api/v1/auth/oidc/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('user.email', 'sara@alpha.test')
            ->assertJsonStructure(['token', 'user', 'organization', 'organizations']);

        $user = User::query()->where('email', 'sara@alpha.test')->sole();
        $this->assertSame(self::ISSUER, $user->oidc_issuer);
        $this->assertSame('sub-sara', $user->oidc_subject);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('sid-1', PersonalAccessToken::query()->sole()->oidc_sid);

        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'sara@alpha.test');

        // The code is good for one exchange only.
        $this->postJson('/api/v1/auth/oidc/exchange', ['code' => $code])->assertUnprocessable();
    }

    public function test_the_callback_hands_the_panel_a_one_time_code_and_keeps_the_intended_path(): void
    {
        $state = $this->startSignIn(intended: '/apps/3');
        $this->nextClaims = $this->claims(['sub' => 'sub-1', 'email' => 'a@alpha.test', 'email_verified' => true]);

        $location = $this->get('/auth/oidc/callback?code=abc&state='.$state)->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::PANEL.'/login?redirect=%2Fapps%2F3#oidc_code=', $location);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/token') && filled($request['code_verifier']) && $request['code'] === 'abc');
    }

    public function test_only_relative_intended_paths_are_kept(): void
    {
        $state = $this->startSignIn(intended: '//evil.test/x');
        $this->nextClaims = $this->claims(['sub' => 'sub-1', 'email' => 'a@alpha.test', 'email_verified' => true]);

        $location = $this->get('/auth/oidc/callback?code=abc&state='.$state)->headers->get('Location');

        $this->assertStringStartsWith(self::PANEL.'/login#oidc_code=', $location);
    }

    public function test_an_existing_account_is_linked_by_a_verified_email(): void
    {
        $existing = User::factory()->inOrganization($this->alpha, OrganizationRole::Developer)->create(['email' => 'reza@alpha.test']);

        $code = $this->signIn(['sub' => 'sub-reza', 'email' => 'reza@alpha.test', 'email_verified' => true, 'name' => 'Reza New']);
        $this->postJson('/api/v1/auth/oidc/exchange', ['code' => $code])->assertOk()->assertJsonPath('user.id', $existing->id);

        $existing->refresh();
        $this->assertSame('sub-reza', $existing->oidc_subject);
        $this->assertSame('Reza New', $existing->name);
        $this->assertSame(1, User::query()->count());
        // The role inside the organization is not changed by signing in.
        $this->assertSame(OrganizationRole::Developer, $this->alpha->roleOf($existing));
    }

    public function test_an_unverified_email_is_not_linked(): void
    {
        User::factory()->create(['email' => 'reza@alpha.test']);

        $state = $this->startSignIn();
        $this->nextClaims = $this->claims(['sub' => 'sub-reza', 'email' => 'reza@alpha.test', 'email_verified' => false]);

        $this->get('/auth/oidc/callback?code=abc&state='.$state)
            ->assertForbidden()
            ->assertSee('ایمیل شما در سامانه هویت تأیید نشده است');
        $this->assertNull(User::query()->sole()->oidc_subject);
    }

    public function test_unknown_users_are_refused_without_auto_provisioning(): void
    {
        config(['oidc.auto_provision' => false]);

        $state = $this->startSignIn();
        $this->nextClaims = $this->claims(['sub' => 'sub-new', 'email' => 'new@alpha.test', 'email_verified' => true]);

        $this->get('/auth/oidc/callback?code=abc&state='.$state)->assertForbidden()->assertSee('حسابی برای شما ساخته نشده است');
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_tenant_that_is_not_provisioned_or_listed_is_refused(): void
    {
        $this->get('/auth/oidc/redirect?tenant=beta')->assertForbidden();
        $this->get('/auth/oidc/redirect')->assertForbidden();

        config(['oidc.tenants' => ['beta']]);
        $this->assertStringStartsWith('https://sso.test/realms/beta/protocol/openid-connect/auth?', $this->get('/auth/oidc/redirect?tenant=beta')->headers->get('Location'));
    }

    public function test_a_token_from_another_tenants_realm_is_refused(): void
    {
        $state = $this->startSignIn();
        $this->nextClaims = $this->claims(['iss' => 'https://sso.test/realms/beta', 'sub' => 'sub-1', 'email' => 'a@beta.test', 'email_verified' => true]);

        $this->get('/auth/oidc/callback?code=abc&state='.$state)->assertForbidden();
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_user_of_one_tenant_is_never_linked_to_another_tenants_identity(): void
    {
        User::factory()->create(['email' => 'sara@alpha.test'])->forceFill(['oidc_issuer' => 'https://sso.test/realms/beta', 'oidc_subject' => 'beta-sara'])->save();

        $state = $this->startSignIn();
        $this->nextClaims = $this->claims(['sub' => 'alpha-sara', 'email' => 'sara@alpha.test', 'email_verified' => true]);

        $this->get('/auth/oidc/callback?code=abc&state='.$state)->assertForbidden();
    }

    public function test_bad_signatures_wrong_nonces_and_states_are_refused(): void
    {
        $state = $this->startSignIn();
        $this->nextClaims = $this->claims(['sub' => 'sub-1', 'email' => 'a@alpha.test', 'email_verified' => true]);
        $this->forgedKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->get('/auth/oidc/callback?code=abc&state='.$state)->assertForbidden();

        $this->forgedKey = null;
        $state = $this->startSignIn();
        $this->nextClaims = ['nonce' => 'another'] + $this->claims(['sub' => 'sub-1', 'email' => 'a@alpha.test', 'email_verified' => true]);
        $this->get('/auth/oidc/callback?code=abc&state='.$state)->assertForbidden();

        $this->startSignIn();
        $this->get('/auth/oidc/callback?code=abc&state=forged')->assertForbidden();

        $this->assertSame(0, User::query()->count());
    }

    public function test_back_channel_logout_ends_the_sessions_of_a_sid_or_a_subject(): void
    {
        $sara = User::factory()->create();
        $sara->forceFill(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'sub-sara'])->save();
        $first = $sara->createToken('dashboard');
        $first->accessToken->forceFill(['oidc_sid' => 'sid-1'])->save();
        $second = $sara->createToken('dashboard');
        $second->accessToken->forceFill(['oidc_sid' => 'sid-2'])->save();

        $this->post('/auth/backchannel-logout', ['logout_token' => $this->logoutToken(['sid' => 'sid-1', 'sub' => 'sub-sara'])])->assertOk();
        $this->assertSame(['sid-2'], PersonalAccessToken::query()->pluck('oidc_sid')->all());
        $this->withToken($first->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->post('/auth/backchannel-logout', ['logout_token' => $this->logoutToken(['sub' => 'sub-sara'])])->assertOk();
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_back_channel_logout_rejects_tokens_it_cannot_trust(): void
    {
        $sara = User::factory()->create();
        $sara->createToken('dashboard')->accessToken->forceFill(['oidc_sid' => 'sid-1'])->save();

        $withoutEvent = $this->logoutToken(['sid' => 'sid-1', 'events' => []]);
        $withNonce = $this->logoutToken(['sid' => 'sid-1', 'nonce' => 'x']);
        $otherRealm = $this->logoutToken(['sid' => 'sid-1', 'iss' => 'https://sso.test/realms/beta']);

        foreach ([$withoutEvent, $withNonce, $otherRealm, 'not-a-jwt'] as $token) {
            $this->post('/auth/backchannel-logout', ['logout_token' => $token])->assertStatus(400);
        }

        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_logout_signs_out_at_the_identity_provider(): void
    {
        $code = $this->signIn(['sub' => 'sub-sara', 'email' => 'sara@alpha.test', 'email_verified' => true]);
        $this->assertNotNull($code);

        $location = $this->get('/auth/oidc/logout')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::ISSUER.'/protocol/openid-connect/logout?id_token_hint=', $location);
        $this->assertStringContainsString('post_logout_redirect_uri='.rawurlencode(self::PANEL.'/login'), $location);
    }

    public function test_users_land_in_their_tenants_organization(): void
    {
        $user = User::factory()->inOrganization(null, OrganizationRole::Owner)->create(['email' => 'sara@alpha.test']);
        $this->alpha->members()->attach($user->id, ['role' => OrganizationRole::Member->value]);

        $code = $this->signIn(['sub' => 'sub-sara', 'email' => 'sara@alpha.test', 'email_verified' => true]);

        $this->postJson('/api/v1/auth/oidc/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('organization.id', $this->alpha->id)
            ->assertJsonPath('organization.role', 'member')
            ->assertJsonPath('organization.permissions', []);
    }

    public function test_oidc_only_turns_password_sign_in_off(): void
    {
        User::factory()->create(['email' => 'sara@example.com']);
        config(['oidc.only' => true]);

        $this->postJson('/api/v1/auth/login', ['email' => 'sara@example.com', 'password' => 'password'])->assertForbidden();
        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123'])->assertForbidden();
        $this->getJson('/api/v1/auth/methods')->assertOk()->assertJsonPath('password', false)->assertJsonPath('oidc', true);
    }

    public function test_without_an_issuer_everything_is_as_before(): void
    {
        config(['oidc.issuer' => null, 'oidc.only' => true]);
        User::factory()->create(['email' => 'sara@example.com']);

        $this->get('/auth/oidc/redirect')->assertNotFound();
        $this->get('/auth/oidc/callback?code=a&state=b')->assertNotFound();
        $this->get('/auth/oidc/logout')->assertNotFound();
        $this->post('/auth/backchannel-logout', ['logout_token' => 'x'])->assertNotFound();
        $this->postJson('/api/v1/auth/oidc/exchange', ['code' => 'x'])->assertNotFound();

        $this->getJson('/api/v1/auth/methods')->assertOk()->assertJsonPath('password', true)->assertJsonPath('oidc', false);
        $this->postJson('/api/v1/auth/login', ['email' => 'sara@example.com', 'password' => 'password'])->assertOk()->assertJsonStructure(['token']);
        Http::assertNothingSent();
    }

    /**
     * Run the whole browser flow and return the one-time code handed to the panel.
     *
     * @param  array<string, mixed>  $claims
     */
    private function signIn(array $claims, ?string $intended = null): string
    {
        $state = $this->startSignIn($intended);
        $this->nextClaims = $this->claims($claims);

        $location = $this->get('/auth/oidc/callback?code=abc&state='.$state)->assertRedirect()->headers->get('Location');

        return explode('#oidc_code=', $location, 2)[1];
    }

    /**
     * Start signing in to tenant alpha; returns the `state` sent to the provider (the nonce is kept for the ID token).
     */
    private function startSignIn(?string $intended = null): string
    {
        $location = $this->get('/auth/oidc/redirect?tenant=alpha'.($intended ? '&intended='.urlencode($intended) : ''))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith(self::ISSUER.'/protocol/openid-connect/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('ai-accounting', $query['client_id']);
        $this->nonce = $query['nonce'];

        return $query['state'];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    private function claims(array $claims): array
    {
        return $claims + ['iss' => self::ISSUER, 'aud' => 'ai-accounting', 'exp' => time() + 300, 'iat' => time(), 'nonce' => $this->nonce];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function logoutToken(array $claims): string
    {
        return $this->sign($claims + [
            'iss' => self::ISSUER,
            'aud' => 'ai-accounting',
            'iat' => time(),
            'jti' => uniqid(),
            'events' => [OidcClient::BACKCHANNEL_LOGOUT_EVENT => new \stdClass],
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function sign(array $claims): string
    {
        $signed = Jwt::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT'])).'.'.Jwt::base64UrlEncode(json_encode($claims));
        openssl_sign($signed, $signature, $this->forgedKey ?? $this->signingKey, OPENSSL_ALGO_SHA256);

        return $signed.'.'.Jwt::base64UrlEncode($signature);
    }

    /**
     * A Keycloak-like provider for realm alpha that signs with `$signingKey`.
     */
    private function fakeProvider(): void
    {
        $details = openssl_pkey_get_details($this->signingKey);
        $jwk = ['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => Jwt::base64UrlEncode($details['rsa']['n']), 'e' => Jwt::base64UrlEncode($details['rsa']['e'])];
        $base = self::ISSUER.'/protocol/openid-connect';

        Http::preventStrayRequests();
        Http::fake([
            'https://sso.test/realms/beta/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://sso.test/realms/beta',
                'authorization_endpoint' => 'https://sso.test/realms/beta/protocol/openid-connect/auth',
            ]),
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => $base.'/auth',
                'token_endpoint' => $base.'/token',
                'userinfo_endpoint' => $base.'/userinfo',
                'jwks_uri' => $base.'/certs',
                'end_session_endpoint' => $base.'/logout',
            ]),
            $base.'/certs' => Http::response(['keys' => [$jwk]]),
            $base.'/token' => fn () => Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $this->sign($this->nextClaims)]),
            $base.'/userinfo' => fn () => Http::response(array_intersect_key($this->nextClaims, array_flip(['sub', 'email', 'email_verified', 'name']))),
        ]);
    }
}
