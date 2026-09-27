<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Support\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    /**
     * A new organization owned by `$owner`, which also becomes their current one.
     */
    public function create(User $owner, string $name): Organization
    {
        return DB::transaction(function () use ($owner, $name) {
            $organization = Organization::query()->create(['name' => $name]);
            $organization->members()->attach($owner->id, ['role' => OrganizationRole::Owner->value]);
            $this->switchTo($owner, $organization);

            return $organization;
        });
    }

    /**
     * The user's current organization. Falls back to their oldest membership, and creates a
     * personal organization for users that have none (e.g. customers created by an admin).
     */
    public function current(User $user): Organization
    {
        $organization = $user->current_organization_id
            ? $user->organizations()->whereKey($user->current_organization_id)->first()
            : null;

        $organization ??= $user->organizations()->orderBy('organization_user.id')->first();

        if (! $organization) {
            return $this->create($user, $user->name);
        }

        if ($user->current_organization_id !== $organization->id) {
            $this->switchTo($user, $organization);
        }

        $user->setRelation('currentOrganization', $organization);

        return $organization;
    }

    public function switchTo(User $user, Organization $organization): void
    {
        $user->forceFill(['current_organization_id' => $organization->id])->save();
        $user->setRelation('currentOrganization', $organization);
    }

    /**
     * Invite (or re-invite with a fresh link) `$email` to the organization.
     */
    public function invite(Organization $organization, User $inviter, string $email, OrganizationRole $role): OrganizationInvitation
    {
        $email = Str::lower(trim($email));

        if ($organization->members()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'این کاربر همین حالا عضو سازمان است.']);
        }

        $invitation = $organization->invitations()->updateOrCreate(['email' => $email], [
            'role' => $role,
            'token' => Str::random(48),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(OrganizationInvitation::VALID_DAYS),
        ]);

        rescue(fn () => Notification::route('mail', $email)->notify(new OrganizationInvitationNotification($invitation)));

        return $invitation;
    }

    /**
     * Join the organization through an invitation sent to the user's email.
     */
    public function accept(OrganizationInvitation $invitation, User $user): Organization
    {
        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['token' => 'این دعوت منقضی شده است. از مدیر سازمان بخواهید دوباره دعوتتان کند.']);
        }

        if (Str::lower($user->email) !== $invitation->email) {
            throw ValidationException::withMessages(['token' => 'این دعوت برای ایمیل دیگری فرستاده شده است. با همان ایمیل وارد شوید.']);
        }

        return DB::transaction(function () use ($invitation, $user) {
            $organization = $invitation->organization;
            $organization->members()->syncWithoutDetaching([$user->id => ['role' => $invitation->role->value]]);
            $invitation->delete();
            $this->switchTo($user, $organization);

            return $organization;
        });
    }

    public function changeRole(Organization $organization, User $member, OrganizationRole $role): void
    {
        if ($role !== OrganizationRole::Owner) {
            $this->ensureAnotherOwner($organization, $member);
        }

        $organization->members()->updateExistingPivot($member->id, ['role' => $role->value]);
    }

    public function removeMember(Organization $organization, User $member): void
    {
        $this->ensureAnotherOwner($organization, $member);
        $organization->members()->detach($member->id);

        if ($member->current_organization_id === $organization->id) {
            $member->forceFill(['current_organization_id' => null])->save();
        }
    }

    /**
     * An organization always keeps at least one owner.
     */
    private function ensureAnotherOwner(Organization $organization, User $member): void
    {
        if ($organization->roleOf($member) !== OrganizationRole::Owner) {
            return;
        }

        $owners = $organization->membersWithRole([OrganizationRole::Owner])->count();

        if ($owners <= 1) {
            throw ValidationException::withMessages(['role' => 'سازمان باید دست‌کم یک مالک داشته باشد. اول نقش مالک را به عضو دیگری بدهید.']);
        }
    }
}
