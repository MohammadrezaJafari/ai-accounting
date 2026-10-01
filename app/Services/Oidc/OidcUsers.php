<?php

namespace App\Services\Oidc;

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds, links or creates the user behind a verified OpenID Connect identity.
 *
 * A user is keyed by (issuer, subject). An existing account is linked by email only when the
 * provider says the email is verified, and never when it already belongs to another identity.
 * Roles inside organizations are not touched here: they come from provisioning.
 */
class OidcUsers
{
    public const EMAIL_NOT_VERIFIED = 'ایمیل شما در سامانه هویت تأیید نشده است';

    public const NO_ACCOUNT = 'حسابی برای شما ساخته نشده است';

    public const LINKED_ELSEWHERE = 'این ایمیل به حساب سازمانی دیگری وصل است';

    public const DISABLED = 'این حساب غیرفعال است';

    public function __construct(private OrganizationService $organizations) {}

    /**
     * @param  array<string, mixed>  $claims  ID token claims merged with userinfo
     */
    public function resolve(string $issuer, ?string $tenant, array $claims): User
    {
        $subject = (string) $claims['sub'];
        $email = is_string($claims['email'] ?? null) ? Str::lower(trim($claims['email'])) : null;
        $emailVerified = ($claims['email_verified'] ?? null) === true;
        $name = $this->nameFrom($claims, $email);

        $user = User::query()->where('oidc_issuer', $issuer)->where('oidc_subject', $subject)->first()
            // Provisioned before OIDC was configured: the Hub knew the subject but not the issuer.
            ?? User::query()->whereNull('oidc_issuer')->where('oidc_subject', $subject)->first();

        if (! $user && $email) {
            $user = User::query()->where('email', $email)->first();

            if ($user) {
                if (! $emailVerified) {
                    abort(403, self::EMAIL_NOT_VERIFIED);
                }

                if ($user->oidc_subject !== null && ($user->oidc_subject !== $subject || ($user->oidc_issuer !== null && $user->oidc_issuer !== $issuer))) {
                    abort(403, self::LINKED_ELSEWHERE);
                }
            }
        }

        if (! $user) {
            if (! config('oidc.auto_provision')) {
                abort(403, self::NO_ACCOUNT);
            }

            if (! $email) {
                abort(403, self::EMAIL_NOT_VERIFIED);
            }

            return DB::transaction(function () use ($issuer, $subject, $email, $emailVerified, $name, $tenant) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    // Never usable: these users sign in through their identity provider.
                    'password' => Str::random(64),
                    'role' => User::ROLE_CUSTOMER,
                ]);
                $user->forceFill([
                    'oidc_issuer' => $issuer,
                    'oidc_subject' => $subject,
                    'email_verified_at' => $emailVerified ? now() : null,
                ])->save();
                $this->workInTenant($user, $tenant);

                return $user;
            });
        }

        if (! $user->is_active) {
            abort(403, self::DISABLED);
        }

        $updates = ['oidc_issuer' => $issuer, 'oidc_subject' => $subject, 'name' => $name];

        if ($email && $email !== $user->email && ! User::query()->where('email', $email)->whereKeyNot($user->id)->exists()) {
            $updates['email'] = $email;
        }

        if ($emailVerified && ($updates['email'] ?? $user->email) === $email && ! $user->email_verified_at) {
            $updates['email_verified_at'] = now();
        }

        $user->forceFill($updates)->save();
        $this->workInTenant($user, $tenant);

        return $user;
    }

    /**
     * Sign in to the tenant's organization when the user is a member of it.
     */
    private function workInTenant(User $user, ?string $tenant): void
    {
        if ($tenant === null || $user->currentOrganization?->tenant === $tenant) {
            return;
        }

        $organization = $user->organizations()->where('organizations.tenant', $tenant)->first();

        if ($organization instanceof Organization) {
            $this->organizations->switchTo($user, $organization);
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function nameFrom(array $claims, ?string $email): string
    {
        $name = $claims['name'] ?? trim(($claims['given_name'] ?? '').' '.($claims['family_name'] ?? ''));

        if (! is_string($name) || trim($name) === '') {
            $name = $claims['preferred_username'] ?? ($email ? Str::before($email, '@') : 'کاربر');
        }

        return Str::limit(trim((string) $name), 255, '');
    }
}
