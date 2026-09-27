<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Notifications\AgentRunNotification;
use App\Services\Agents\Delivery\ReportDelivery;
use App\Support\OrganizationRole;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Runs an agent instance once: holds a unit, has the agent do the work, then uses the units
 * the output is worth if something was delivered or gives the unit back otherwise. Costs of
 * the run's model calls are totalled on it.
 *
 * An HTTP agent may accept the run and post its result later (complete / fail); runs whose
 * deadline passes without a result are failed by expireOverdue(). A publisher's test run
 * uses no credits and delivers nowhere.
 */
class AgentRunner
{
    public function __construct(
        private AgentRegistry $registry,
        private AgentCreditService $credits,
        private AgentLlm $llm,
        private ReportDelivery $delivery,
    ) {}

    public function start(AgentInstance $instance, string $trigger): AgentRun
    {
        return $instance->runs()->create([
            'agent_id' => $instance->agent_id,
            'organization_id' => $instance->organization_id,
            'status' => AgentRun::STATUS_QUEUED,
            'trigger' => $trigger,
        ]);
    }

    public function execute(AgentRun $run): AgentRun
    {
        // Claim the run so a retried or duplicated job never executes it twice.
        $claimed = AgentRun::query()->whereKey($run->id)->where('status', AgentRun::STATUS_QUEUED)
            ->update(['status' => AgentRun::STATUS_RUNNING, 'started_at' => now()]);

        if (! $claimed) {
            return $run->refresh();
        }

        $run->refresh()->load(['instance.app', 'instance.organization', 'agent', 'organization']);

        if ($run->isTest()) {
            // A publisher tries its listing as it will be once pending changes are approved.
            $run->agent->fill(Arr::only($run->agent->pending_changes ?? [], Agent::PUBLISHER_FIELDS));
        } elseif (! $this->credits->reserve($run)) {
            $this->finish($run, AgentRun::STATUS_NO_CREDITS, error: "اعتبار «{$run->agent->unit_name}» تمام شده است.");
            $this->notifyNoCredits($run);

            return $run;
        }

        try {
            $result = $this->registry->handler($run->agent)->run($run, $this->llm);
        } catch (Throwable $e) {
            report_unless($e instanceof AgentException, $e);

            return $this->fail($run, $e instanceof AgentException ? $e->getMessage() : 'خطای غیرمنتظره در اجرای ایجنت.');
        }

        return $result->pending ? $run : $this->complete($run, $result);
    }

    /**
     * Record what the agent delivered. Only the first outcome of a run counts.
     */
    public function complete(AgentRun $run, AgentResult $result): AgentRun
    {
        if (! $this->claimOutcome($run)) {
            return $run->refresh();
        }

        $run->loadMissing(['instance', 'agent', 'organization']);
        $run->instance->update(['state' => $result->state]);
        $units = min($result->units ?? 1, $run->agent->max_units_per_run);

        if ($run->isTest()) {
            $this->finish($run, $result->report === null ? AgentRun::STATUS_EMPTY : AgentRun::STATUS_SUCCEEDED, $result->report, $result->itemsFound, $result->meta, data: $result->data);

            return $run;
        }

        if ($result->report === null || $units < 1) {
            $this->credits->release($run);
        } else {
            $this->credits->commit($run, $units);
        }

        if ($result->report === null) {
            $this->finish($run, AgentRun::STATUS_EMPTY, meta: $result->meta);

            if ($run->instance->notify_empty) {
                $this->deliver($run);
            }

            return $run;
        }

        $this->finish($run, AgentRun::STATUS_SUCCEEDED, $result->report, $result->itemsFound, $result->meta, data: $result->data);
        $this->deliver($run);

        rescue(fn () => Notification::send($run->organization->members()->get(), new AgentRunNotification($run)));

        return $run;
    }

    /**
     * The run failed: give its unit back.
     */
    public function fail(AgentRun $run, string $error): AgentRun
    {
        if (! $this->claimOutcome($run)) {
            return $run->refresh();
        }

        $run->loadMissing(['instance', 'agent', 'organization']);
        $this->credits->release($run);
        $this->finish($run, AgentRun::STATUS_FAILED, error: $error);

        return $run;
    }

    /**
     * Fail the runs whose agent did not answer before the deadline.
     */
    public function expireOverdue(): int
    {
        $overdue = AgentRun::query()
            ->where('status', AgentRun::STATUS_RUNNING)
            ->where(fn ($query) => $query->where('deadline_at', '<', now())
                ->orWhere(fn ($query) => $query->whereNull('deadline_at')->where('started_at', '<', now()->subHour())))
            ->get();

        $overdue->each(fn (AgentRun $run) => $this->fail($run, 'ایجنت در زمان مقرر نتیجه‌ای نفرستاد.'));

        return $overdue->count();
    }

    /**
     * Take the right to record the run's outcome: the token stops working and a late or
     * repeated callback, or the deadline sweep, finds nothing left to do.
     */
    private function claimOutcome(AgentRun $run): bool
    {
        $claimed = AgentRun::query()->whereKey($run->id)
            ->where('status', AgentRun::STATUS_RUNNING)
            ->whereNull('finished_at')
            ->update(['finished_at' => now(), 'token_hash' => null]);

        $run->refresh();

        return $claimed === 1;
    }

    /**
     * Send the report to the instance's destinations and keep the outcome with the run.
     */
    private function deliver(AgentRun $run): void
    {
        $deliveries = $this->delivery->deliver($run);

        if ($deliveries !== []) {
            $run->update(['meta' => [...($run->meta ?? []), 'deliveries' => $deliveries]]);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function finish(AgentRun $run, string $status, ?string $report = null, int $items = 0, array $meta = [], ?string $error = null, array $data = []): void
    {
        $cost = (int) $run->usageLogs()->sum('cost');

        $run->update([
            'status' => $status,
            'report' => $report,
            'data' => $data ?: null,
            'items_found' => $items,
            'meta' => $meta,
            'error' => $error,
            'cost' => $cost,
            // A publisher pays for its agent's model calls, whatever the run delivered.
            'publisher_cost' => $run->agent->publisher_organization_id ? $cost : 0,
            'finished_at' => now(),
        ]);

        $run->instance->update(['last_run_at' => now()]);
    }

    /**
     * Tell owners and billing once, not on every scheduled run that finds no units.
     */
    private function notifyNoCredits(AgentRun $run): void
    {
        $previous = $run->instance->runs()->whereKeyNot($run->id)->latest('id')->value('status');

        if ($previous === AgentRun::STATUS_NO_CREDITS) {
            return;
        }

        $recipients = $run->organization->membersWithRole([OrganizationRole::Owner, OrganizationRole::Billing])->get();
        rescue(fn () => Notification::send($recipients, new AgentRunNotification($run)));
    }
}
