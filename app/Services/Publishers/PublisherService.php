<?php

namespace App\Services\Publishers;

use App\Models\Agent;
use App\Models\AgentPackage;
use App\Models\AgentRun;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\PublisherReviewNotification;
use App\Services\Agents\AgentRunner;
use App\Services\Agents\ConfigSchema;
use App\Support\AgentDriver;
use App\Support\AgentStatus;
use App\Support\Money;
use App\Support\OrganizationRole;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use NumberFormatter;

/**
 * A publisher organization's marketplace listings: drafts it edits freely, submission for
 * review, changes to a live listing that wait for the admin, and test runs of its own agent.
 */
class PublisherService
{
    public function __construct(private AgentRunner $runner) {}

    /**
     * A new draft listing. Model access, cost cap and revenue share start at the platform
     * defaults; the admin may change them when reviewing.
     *
     * @param  array<string, mixed>  $data  publisher fields, `slug` and `packages`
     */
    public function create(Organization $publisher, array $data): Agent
    {
        return DB::transaction(function () use ($publisher, $data) {
            $agent = Agent::query()->create([
                ...Arr::only($data, Agent::PUBLISHER_FIELDS),
                'slug' => $data['slug'],
                'publisher_organization_id' => $publisher->id,
                'status' => AgentStatus::Draft,
                'driver' => AgentDriver::Http,
                'is_active' => false,
                'revenue_share' => config('billing.publishers.revenue_share'),
                'max_cost_per_run' => Money::fromUsd(config('billing.publishers.max_cost_per_run_usd')),
                'sort_order' => 100,
            ]);

            $this->syncPackages($agent, $data['packages'] ?? []);

            return $agent;
        });
    }

    /**
     * Change a listing: directly while it is a draft (or sent back), otherwise the changes
     * wait for review and the live listing stays as it is.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Agent $agent, array $data): Agent
    {
        $changes = Arr::only($data, [...Agent::PUBLISHER_FIELDS, 'packages']);

        if ($agent->status->isEditable()) {
            DB::transaction(function () use ($agent, $changes) {
                $agent->update(Arr::except($changes, 'packages'));

                if (array_key_exists('packages', $changes)) {
                    $this->syncPackages($agent, $changes['packages']);
                }
            });

            return $agent;
        }

        $pending = array_filter(
            [...($agent->pending_changes ?? []), ...$changes],
            fn ($value, string $key) => $key === 'packages' ? $value != $this->currentPackages($agent) : $value != $agent->getAttribute($key),
            ARRAY_FILTER_USE_BOTH,
        );

        $agent->update(['pending_changes' => $pending ?: null, 'submitted_at' => $pending ? now() : $agent->submitted_at]);

        return $agent;
    }

    /**
     * Send a draft for review, once it has what a customer needs.
     */
    public function submit(Agent $agent): Agent
    {
        if (! $agent->status->isEditable()) {
            throw ValidationException::withMessages(['status' => 'این ایجنت در انتظار بررسی یا منتشرشده است.']);
        }

        $missing = array_filter([
            'endpoint_url' => blank($agent->endpoint_url) ? 'آدرس سرویس را وارد کنید.' : null,
            'packages' => $agent->packages()->where('is_active', true)->doesntExist() ? 'دست‌کم یک بسته تعریف کنید.' : null,
            'description' => blank($agent->description) ? 'توضیح ایجنت را بنویسید.' : null,
        ]);

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        $agent->update(['status' => AgentStatus::PendingReview, 'submitted_at' => now(), 'review_note' => null]);

        return $agent;
    }

    /**
     * Publish a listing that was waiting for review, with any changes made meanwhile.
     */
    public function approve(Agent $agent, ?int $revenueShare = null): Agent
    {
        if ($agent->pending_changes) {
            $this->applyPending($agent);
        }

        $agent->update([
            'status' => AgentStatus::Approved,
            'is_active' => true,
            'reviewed_at' => now(),
            'review_note' => null,
            ...($revenueShare !== null ? ['revenue_share' => $revenueShare] : []),
        ]);
        $this->notifyPublisher($agent, PublisherReviewNotification::APPROVED);

        return $agent;
    }

    /**
     * Send a listing back with a note; for a live listing only its pending changes are turned down.
     */
    public function reject(Agent $agent, string $note): Agent
    {
        $live = $agent->status === AgentStatus::Approved;
        $agent->update($live
            ? ['pending_changes' => null, 'review_note' => $note, 'reviewed_at' => now()]
            : ['status' => AgentStatus::Rejected, 'review_note' => $note, 'reviewed_at' => now()]);
        $this->notifyPublisher($agent, $live ? PublisherReviewNotification::CHANGES_REJECTED : PublisherReviewNotification::REJECTED);

        return $agent;
    }

    /**
     * Make a live listing's pending changes live.
     */
    public function applyChanges(Agent $agent): Agent
    {
        $this->applyPending($agent);
        $this->notifyPublisher($agent, PublisherReviewNotification::CHANGES_APPLIED);

        return $agent;
    }

    private function applyPending(Agent $agent): void
    {
        $changes = $agent->pending_changes ?? [];

        DB::transaction(function () use ($agent, $changes) {
            $agent->update([
                ...Arr::only($changes, Agent::PUBLISHER_FIELDS),
                'pending_changes' => null,
                'review_note' => null,
                'reviewed_at' => now(),
            ]);

            if (array_key_exists('packages', $changes)) {
                $this->syncPackages($agent, $changes['packages']);
            }
        });
    }

    /**
     * Tell the publisher's owners and developers how the review went.
     */
    private function notifyPublisher(Agent $agent, string $outcome): void
    {
        $recipients = $agent->publisher?->membersWithRole([OrganizationRole::Owner, OrganizationRole::Developer])->get();

        if ($recipients?->isNotEmpty()) {
            rescue(fn () => Notification::send($recipients, new PublisherReviewNotification($agent, $outcome)));
        }
    }

    /**
     * Run the publisher's agent once with the given parameters, without credits or delivery.
     * A live listing is tried with its pending changes (see AgentRunner).
     *
     * @param  array<string, mixed>  $config
     */
    public function startTestRun(Agent $agent, array $config, User $by): AgentRun
    {
        $today = $agent->runs()->where('trigger', AgentRun::TRIGGER_TEST)->where('created_at', '>=', now()->startOfDay())->count();

        if ($today >= config('billing.publishers.test_runs_per_day')) {
            throw ValidationException::withMessages(['run' => 'سقف اجرای آزمایشی امروز پر شده است.']);
        }

        if ($agent->runs()->where('trigger', AgentRun::TRIGGER_TEST)->whereIn('status', [AgentRun::STATUS_QUEUED, AgentRun::STATUS_RUNNING])->where('created_at', '>=', now()->subMinutes(15))->exists()) {
            throw ValidationException::withMessages(['run' => 'یک اجرای آزمایشی همین حالا در جریان است.']);
        }

        $instance = $agent->instances()->firstOrNew(['organization_id' => $agent->publisher_organization_id, 'is_test' => true]);
        $instance->fill([
            'name' => 'اجرای آزمایشی ناشر',
            'config' => $config,
            'run_hours' => [],
            'is_active' => false,
            'created_by' => $by->id,
        ])->save();

        return $this->runner->start($instance, AgentRun::TRIGGER_TEST);
    }

    /**
     * The schema a test run's parameters are checked against: the pending one, if any.
     */
    public function testSchema(Agent $agent): ConfigSchema
    {
        return new ConfigSchema(ConfigSchema::define($agent->pending_changes['config_schema'] ?? $agent->config_schema ?? []));
    }

    /**
     * Packages as the publisher edits them: `units` and `price` (USD string).
     *
     * @return list<array{units: int, price: string}>
     */
    public function currentPackages(Agent $agent): array
    {
        return $agent->packages()->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (AgentPackage $package) => ['units' => $package->units, 'price' => Money::toUsd($package->price)])
            ->all();
    }

    /**
     * Make the active packages match `$packages`; packages no longer offered are deactivated,
     * not deleted, since purchases refer to them.
     *
     * @param  list<array{units: int|string, price: string|int|float}>  $packages
     */
    private function syncPackages(Agent $agent, array $packages): void
    {
        $digits = new NumberFormatter('fa_IR', NumberFormatter::DECIMAL);
        $kept = [];

        foreach (array_values($packages) as $order => $package) {
            $units = (int) $package['units'];
            $model = $agent->packages()->firstOrNew(['units' => $units]);
            $model->fill([
                'name' => $digits->format($units).' '.($agent->unit_name ?: 'واحد'),
                'price' => Money::fromUsd((string) $package['price']),
                'is_active' => true,
                'sort_order' => $order,
            ])->save();
            $kept[] = $model->id;
        }

        $agent->packages()->whereNotIn('id', $kept)->update(['is_active' => false]);
    }
}
