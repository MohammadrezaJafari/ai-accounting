<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Oidc\OidcClient;
use App\Services\Oidc\OidcException;
use App\Services\Oidc\OidcUsers;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sign-in with the organization's identity provider (authorization code + PKCE).
 *
 * The customer panel is a separate SPA that keeps a Sanctum token, so the callback does not
 * sign anyone in here: it sends the browser back to the panel's login page with a one-time
 * code in the fragment (`/login?redirect=…#oidc_code=…`), which the panel trades for the same
 * response password sign-in gives (`POST /api/v1/auth/oidc/exchange`).
 */
class OidcController extends Controller
{
    /** Seconds the one-time code handed to the panel is valid. */
    public const CODE_TTL = 120;

    public function __construct(private OidcClient $oidc, private OidcUsers $users) {}

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($this->oidc->enabled(), 404);

        $tenant = $this->oidc->multiTenant() ? (string) $request->query('tenant') : null;
        $issuer = $this->oidc->issuerFor($tenant);
        abort_if($issuer === null, 403, 'این سازمان در این سامانه شناخته نشده است');

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put('oidc.login', [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'issuer' => $issuer,
            'tenant' => $tenant,
            'intended' => $this->relativePath($request->query('intended')),
        ]);

        try {
            return redirect()->away($this->oidc->authorizationUrl($issuer, $this->callbackUrl(), $state, $nonce, $verifier));
        } catch (OidcException|RequestException|ConnectionException $exception) {
            Log::warning('OIDC provider unavailable', ['issuer' => $issuer, 'error' => $exception->getMessage()]);
            abort(503, 'سامانه هویت در دسترس نیست؛ کمی بعد دوباره تلاش کنید');
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->oidc->enabled(), 404);

        $login = $request->session()->pull('oidc.login');
        $state = (string) $request->query('state');

        abort_unless(is_array($login) && $state !== '' && hash_equals($login['state'], $state), 403, 'نشست ورود منقضی شده است؛ دوباره وارد شوید');
        abort_if($request->filled('error'), 403, 'سامانه هویت ورود را نپذیرفت');

        try {
            $tokens = $this->oidc->exchangeCode($login['issuer'], (string) $request->query('code'), $this->callbackUrl(), $login['verifier']);
            $claims = $this->oidc->verifyIdToken($login['issuer'], $tokens['id_token'], $login['nonce']);
            $userinfo = $this->oidc->userinfo($login['issuer'], (string) ($tokens['access_token'] ?? ''));
        } catch (OidcException|RequestException|ConnectionException $exception) {
            Log::warning('OIDC sign-in failed', ['issuer' => $login['issuer'], 'error' => $exception->getMessage()]);
            abort(403, 'ورود با حساب سازمانی تأیید نشد');
        }

        if (($userinfo['sub'] ?? $claims['sub']) === $claims['sub']) {
            $claims = array_merge($claims, array_diff_key($userinfo, array_flip(['iss', 'aud', 'sub', 'nonce', 'exp', 'iat'])));
        }

        abort_if(blank($claims['sub'] ?? null), 403, 'ورود با حساب سازمانی تأیید نشد');

        $user = $this->users->resolve($login['issuer'], $login['tenant'], $claims);

        $request->session()->put('oidc.session', [
            'issuer' => $login['issuer'],
            'id_token' => $tokens['id_token'],
            'sid' => $claims['sid'] ?? null,
        ]);

        $code = Str::random(48);
        Cache::put('oidc:code:'.hash('sha256', $code), ['user_id' => $user->id, 'sid' => $claims['sid'] ?? null], self::CODE_TTL);

        return redirect()->away($this->panelUrl('/login', ['redirect' => $login['intended']]).'#oidc_code='.$code);
    }

    /**
     * Trade the one-time code from the callback for a dashboard token; null when it is unknown or used.
     */
    public static function redeem(string $code): ?array
    {
        return Cache::pull('oidc:code:'.hash('sha256', $code));
    }

    /**
     * Sign out at the identity provider too (RP-initiated logout) and come back to the panel.
     */
    public function logout(Request $request): RedirectResponse
    {
        abort_unless($this->oidc->enabled(), 404);

        $session = $request->session()->pull('oidc.session');
        $back = $this->panelUrl('/login');
        $issuer = is_array($session) ? $session['issuer'] : $this->oidc->issuerFor($this->oidc->multiTenant() ? (string) $request->query('tenant') : null);

        if ($issuer === null) {
            return redirect()->away($back);
        }

        try {
            $url = $this->oidc->endSessionUrl($issuer, is_array($session) ? $session['id_token'] : null, $back);
        } catch (OidcException|RequestException|ConnectionException) {
            $url = null;
        }

        return redirect()->away($url ?? $back);
    }

    /**
     * OpenID Connect Back-Channel Logout 1.0: the provider ends a session (`sid`) or every session of
     * a user (`sub`); the dashboard tokens issued for it stop working.
     */
    public function backchannelLogout(Request $request): Response
    {
        abort_unless($this->oidc->enabled(), 404);

        try {
            $claims = $this->oidc->verifyLogoutToken((string) $request->input('logout_token'));
        } catch (OidcException|RequestException|ConnectionException $exception) {
            Log::notice('OIDC back-channel logout rejected', ['error' => $exception->getMessage()]);

            return response(['error' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        }

        if (filled($claims['sid'] ?? null)) {
            PersonalAccessToken::query()->where('oidc_sid', $claims['sid'])->delete();
        } else {
            $user = User::query()
                ->where('oidc_issuer', rtrim((string) $claims['iss'], '/'))
                ->where('oidc_subject', (string) $claims['sub'])
                ->first();
            $user?->tokens()->where('name', 'dashboard')->delete();
        }

        return response('', 200)->header('Cache-Control', 'no-store');
    }

    private function callbackUrl(): string
    {
        return url('/auth/oidc/callback');
    }

    /**
     * @param  array<string, string|null>  $query
     */
    private function panelUrl(string $path, array $query = []): string
    {
        $query = array_filter($query);

        return rtrim(config('billing.panel_url'), '/').$path.($query ? '?'.http_build_query($query) : '');
    }

    /**
     * Only a path on the panel itself (no scheme, host or protocol-relative URL).
     */
    private function relativePath(mixed $intended): ?string
    {
        if (! is_string($intended) || ! str_starts_with($intended, '/') || str_starts_with($intended, '//') || str_contains($intended, '\\') || preg_match('/[\x00-\x1f]/', $intended)) {
            return null;
        }

        return $intended;
    }
}
