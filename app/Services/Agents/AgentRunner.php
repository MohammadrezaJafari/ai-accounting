<?php

namespace App\Services\Agents;

use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Notifications\AgentRunNotification;
use App\Services\Agents\Delivery\ReportDelivery;
use App\Support\OrganizationRole;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Runs an agent instance once: holds a unit, does the work, then uses the unit if something
 * was delivered or gives it back otherwise. Costs of the run's model calls are totalled on it.
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

        if (! $this->credits->reserve($run)) {
            $this->finish($run, AgentRun::STATUS_NO_CREDITS, error: "اعتبار «{$run->agent->unit_name}» تمام شده است.");
            $this->notifyNoCredits($run);

            return $run;
        }

        try {
            $result = $this->registry->handler($run->agent)->run($run, $this->llm);
        } catch (Throwable $e) {
            $this->credits->release($run);
            report_unless($e instanceof AgentException, $e);
            $this->finish($run, AgentRun::STATUS_FAILED, error: $e instanceof AgentException ? $e->getMessage() : 'خطای غیرمنتظره در اجرای ایجنت.');

            return $run;
        }

        $run->instance->update(['state' => $result->state]);

        if ($result->report === null) {
            $this->credits->release($run);
            $this->finish($run, AgentRun::STATUS_EMPTY, meta: $result->meta);

            if ($run->instance->config['notify_empty'] ?? false) {
                $this->deliver($run);
            }

            return $run;
        }

        $this->credits->commit($run);
        $this->finish($run, AgentRun::STATUS_SUCCEEDED, $result->report, $result->itemsFound, $result->meta);
        $this->deliver($run);

        rescue(fn () => Notification::send($run->organization->members()->get(), new AgentRunNotification($run)));

        return $run;
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

    private function finish(AgentRun $run, string $status, ?string $report = null, int $items = 0, array $meta = [], ?string $error = null): void
    {
        $run->update([
            'status' => $status,
            'report' => $report,
            'items_found' => $items,
            'meta' => $meta,
            'error' => $error,
            'cost' => (int) $run->usageLogs()->sum('cost'),
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
