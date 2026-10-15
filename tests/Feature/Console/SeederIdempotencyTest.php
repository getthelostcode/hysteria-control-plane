<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Node;
use App\Models\NodeMetric;
use App\Models\UpgradePackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * install.sh runs `db:seed` on every install/upgrade, so the seeders must be
 * safe to run repeatedly (regression: DemoDataSeeder used to collide with the
 * unique index on nodes.node_id on the second run).
 */
class SeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_runs_twice_without_side_effects(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $nodes = Node::query()->count();
        $metrics = NodeMetric::query()->count();
        $configs = \App\Models\NodeConfig::query()->count();
        $commands = \App\Models\NodeCommand::query()->count();
        $packages = UpgradePackage::query()->count();

        $this->assertGreaterThan(0, $nodes);
        $this->assertGreaterThan(0, $metrics);

        // Second run: no exception, no duplicated demo data.
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame($nodes, Node::query()->count());
        $this->assertSame($metrics, NodeMetric::query()->count());
        $this->assertSame($configs, \App\Models\NodeConfig::query()->count());
        $this->assertSame($commands, \App\Models\NodeCommand::query()->count());
        $this->assertSame($packages, UpgradePackage::query()->count());
    }

    public function test_demo_seeder_tops_up_missing_upgrade_packages(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        UpgradePackage::query()->delete();

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertGreaterThan(0, UpgradePackage::query()->count());
        $this->assertGreaterThan(0, Node::query()->where('node_id', 'like', 'nd_demo%')->count());
    }
}
