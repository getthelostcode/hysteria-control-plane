<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Provider;
use App\Models\ProviderUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_only_sees_own_nodes(): void
    {
        $alpha = Provider::factory()->create(['code' => 'alpha01']);
        $beta = Provider::factory()->create(['code' => 'beta002']);

        $user = ProviderUser::factory()->create(['provider_id' => $alpha->id]);

        Node::factory()->create(['provider_id' => $alpha->id, 'ip' => '203.0.113.1']);
        Node::factory()->create(['provider_id' => $beta->id, 'ip' => '203.0.113.2']);

        $this->actingAs($user, 'provider')
            ->get("/provider/{$alpha->code}/nodes")
            ->assertOk()
            ->assertSee('203.0.113.1')
            ->assertDontSee('203.0.113.2');
    }

    public function test_provider_cannot_open_other_tenant(): void
    {
        $alpha = Provider::factory()->create(['code' => 'alpha01']);
        $beta = Provider::factory()->create(['code' => 'beta002']);

        $user = ProviderUser::factory()->create(['provider_id' => $alpha->id]);
        $foreignNode = Node::factory()->create(['provider_id' => $beta->id, 'ip' => '203.0.113.2']);

        // Filament 的 IdentifyTenant 对「不是自己的租户」统一 abort(404)，不泄露租户是否存在
        $this->actingAs($user, 'provider')
            ->get("/provider/{$beta->code}/nodes")
            ->assertNotFound();

        $this->actingAs($user, 'provider')
            ->get("/provider/{$beta->code}/nodes/{$foreignNode->id}")
            ->assertNotFound();
    }

    public function test_suspended_provider_account_is_blocked(): void
    {
        $provider = Provider::factory()->suspended()->create(['code' => 'alpha01']);
        $user = ProviderUser::factory()->create(['provider_id' => $provider->id]);

        $this->actingAs($user, 'provider')
            ->get("/provider/{$provider->code}")
            ->assertForbidden();
    }
}
