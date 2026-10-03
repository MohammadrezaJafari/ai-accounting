<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentCreditTransaction;
use App\Models\Organization;
use App\Models\User;
use App\Support\AgentDriver;
use App\Support\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentTrialTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->inOrganization($this->organization, OrganizationRole::Developer)->create());

        $this->agent = Agent::query()->create([
            'slug' => 'competitor-watch',
            'driver' => AgentDriver::Http,
            'endpoint_url' => 'https://agent.test/run',
            'name' => 'پایش رقبا',
            'unit_name' => 'گزارش',
            'free_trial_units' => 3,
            'is_active' => true,
        ]);
    }

    public function test_an_organization_claims_the_free_trial_once(): void
    {
        $this->getJson('/api/v1/agents')->assertOk()
            ->assertJsonPath('data.0.free_trial_units', 3)
            ->assertJsonPath('data.0.trial_available', true);

        $this->postJson("/api/v1/agents/{$this->agent->id}/trial")->assertOk()->assertJsonPath('credits', 3);

        $this->assertDatabaseHas('agent_credit_transactions', [
            'organization_id' => $this->organization->id,
            'agent_id' => $this->agent->id,
            'type' => AgentCreditTransaction::TYPE_TRIAL,
            'units' => 3,
            'value' => 0,
        ]);
        $this->getJson('/api/v1/agents')->assertJsonPath('data.0.credits', 3)->assertJsonPath('data.0.trial_available', false);

        $this->postJson("/api/v1/agents/{$this->agent->id}/trial")->assertJsonValidationErrors('trial');
        $this->getJson('/api/v1/agents')->assertJsonPath('data.0.credits', 3);
    }

    public function test_an_agent_without_a_trial_cannot_be_claimed(): void
    {
        $this->agent->update(['free_trial_units' => 0]);

        $this->getJson('/api/v1/agents')->assertJsonPath('data.0.trial_available', false);
        $this->postJson("/api/v1/agents/{$this->agent->id}/trial")->assertJsonValidationErrors('trial');
    }

    public function test_a_member_without_app_access_cannot_claim(): void
    {
        Sanctum::actingAs(User::factory()->inOrganization($this->organization, OrganizationRole::Member)->create());

        $this->postJson("/api/v1/agents/{$this->agent->id}/trial")->assertForbidden();
    }
}
