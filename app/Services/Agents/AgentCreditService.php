<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentCredit;
use App\Models\AgentCreditTransaction;
use App\Models\AgentPackage;
use App\Models\AgentRun;
use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agent units of an organization: bought in packages with an app wallet, used one by one by runs.
 */
class AgentCreditService
{
    public function __construct(private WalletService $wallet) {}

    public function balance(Organization $organization, Agent $agent): AgentCredit
    {
        return AgentCredit::query()->firstOrNew(['organization_id' => $organization->id, 'agent_id' => $agent->id], ['units' => 0, 'value' => 0]);
    }

    /**
     * Pay a package from `$app`'s wallet and add its units.
     */
    public function purchase(Organization $organization, App $app, AgentPackage $package, User $buyer): AgentCredit
    {
        if ($app->balance < $package->price) {
            throw ValidationException::withMessages(['app_id' => 'موجودی این اپ برای خرید بسته کافی نیست. اول کیف پول را شارژ کنید.']);
        }

        return DB::transaction(function () use ($organization, $app, $package, $buyer) {
            $this->wallet->adjust($app, -$package->price, 'agent_purchase', "خرید «{$package->name}» ({$package->agent->name})", by: $buyer);

            $credit = $this->locked($organization, $package->agent);
            $credit->units += $package->units;
            $credit->value += $package->price;
            $credit->save();

            AgentCreditTransaction::query()->create([
                'organization_id' => $organization->id,
                'agent_id' => $package->agent_id,
                'app_id' => $app->id,
                'agent_package_id' => $package->id,
                'type' => AgentCreditTransaction::TYPE_PURCHASE,
                'units' => $package->units,
                'value' => $package->price,
                'description' => "خرید «{$package->name}» به مبلغ ".Money::format($package->price),
                'created_by' => $buyer->id,
            ]);

            return $credit;
        });
    }

    /**
     * Hold one unit for a run; false when none is left.
     */
    public function reserve(AgentRun $run): bool
    {
        return DB::transaction(function () use ($run) {
            $credit = $this->locked($run->organization, $run->agent);

            if ($credit->units < 1) {
                return false;
            }

            $value = $credit->unitValue();
            $credit->units--;
            $credit->value -= $value;
            $credit->save();

            $run->forceFill(['units' => 1, 'revenue' => $value])->save();

            return true;
        });
    }

    /**
     * Give back the held unit of a run that delivered nothing.
     */
    public function release(AgentRun $run): void
    {
        if ($run->units < 1) {
            return;
        }

        DB::transaction(function () use ($run) {
            $credit = $this->locked($run->organization, $run->agent);
            $credit->units += $run->units;
            $credit->value += $run->revenue;
            $credit->save();

            $run->forceFill(['units' => 0, 'revenue' => 0])->save();
        });
    }

    /**
     * Record the held unit as used by a run that delivered.
     */
    public function commit(AgentRun $run): void
    {
        AgentCreditTransaction::query()->create([
            'organization_id' => $run->organization_id,
            'agent_id' => $run->agent_id,
            'app_id' => $run->instance->app_id,
            'agent_run_id' => $run->id,
            'type' => AgentCreditTransaction::TYPE_USAGE,
            'units' => -$run->units,
            'value' => -$run->revenue,
            'description' => "«{$run->instance->name}»",
        ]);
    }

    private function locked(Organization $organization, Agent $agent): AgentCredit
    {
        $credit = AgentCredit::query()->firstOrCreate(['organization_id' => $organization->id, 'agent_id' => $agent->id], ['units' => 0, 'value' => 0]);

        return AgentCredit::query()->lockForUpdate()->findOrFail($credit->id);
    }
}
