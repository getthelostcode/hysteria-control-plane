<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\EnrollToken;
use App\Models\Node;
use App\Models\NodeCommand;
use App\Models\NodeConfig;
use App\Models\UpgradePackage;
use App\Services\ConfigService;
use App\Support\SignatureVerifier;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $regions = [
            'hk' => ['ip' => '203.0.113.10', 'tags' => ['premium', 'edge']],
            'sg' => ['ip' => '203.0.113.24', 'tags' => ['premium']],
            'jp' => ['ip' => '203.0.113.31', 'tags' => ['trial']],
            'us' => ['ip' => '198.51.100.8', 'tags' => ['premium', 'bulk']],
            'de' => ['ip' => '198.51.100.19', 'tags' => ['edge']],
            'uk' => ['ip' => '198.51.100.42', 'tags' => ['trial']],
        ];

        $metricRows = [];
        $now = now();

        foreach ($regions as $region => $profile) {
            $index = array_search($region, array_keys($regions), true);
            for ($i = 1; $i <= 2; $i++) {
                [$plain, $hash, $cipher] = SignatureVerifier::issueSecret();

                $node = Node::create([
                    'name' => strtoupper($region) . '-edge-' . $i,
                    'node_id' => 'nd_demo' . strtolower($region) . $i,
                    'secret_hash' => $hash,
                    'secret_ciphertext' => $cipher,
                    'status' => $i === 1 ? Node::STATUS_ONLINE : Node::STATUS_OFFLINE,
                    'ip' => $profile['ip'],
                    'region' => $region,
                    'tags' => $profile['tags'],
                    'version' => '2.6.0',
                    'agent_version' => '1.0.0',
                    'meta' => ['arch' => 'amd64', 'demo_secret' => $plain],
                    'enrolled_ip' => $profile['ip'],
                    'enrolled_at' => $now->copy()->subDays(10 - $index),
                    'last_seen_at' => $i === 1 ? $now->copy()->subSeconds(12) : $now->copy()->subMinutes(45),
                ]);

                $node->credentials()->create([
                    'secret_hash' => $hash,
                    'secret_ciphertext' => $cipher,
                    'label' => 'initial',
                    'rotated_at' => $now,
                ]);

                // 6h of 5-minute samples so the dashboard charts have real data.
                for ($step = 72; $step >= 0; $step--) {
                    $recordedAt = $now->copy()->subMinutes($step * 5);
                    $wave = sin($step / 6) * 0.5 + 0.5;

                    $metricRows[] = [
                        'node_id' => $node->id,
                        'cpu' => round(10 + $wave * 35, 2),
                        'mem' => round(25 + $wave * 30, 2),
                        'disk' => round(40 + $index * 3, 2),
                        'net_rx' => (int) (($i === 1 ? 4_200_000 : 900_000) * (0.6 + $wave)),
                        'net_tx' => (int) (($i === 1 ? 5_600_000 : 1_100_000) * (0.5 + $wave)),
                        'conn_count' => (int) (($i === 1 ? 180 : 40) * (0.7 + $wave)),
                        'users' => (int) (($i === 1 ? 22 : 6) * (0.8 + $wave)),
                        'recorded_at' => $recordedAt,
                        'created_at' => $recordedAt,
                        'updated_at' => $recordedAt,
                    ];
                }

                if ($i === 1) {
                    app(ConfigService::class)->publish(
                        $node,
                        "listen: :443\ntls:\n  cert: /etc/hysteria/cert.pem\n  key: /etc/hysteria/key.pem\ntrafficStats:\n  listen: 127.0.0.1:9999\n  secret: {$node->statsSecret()}\n",
                        null,
                        'seed: initial rollout',
                        ['channel' => 'seeder'],
                    );
                }
            }
        }

        foreach (array_chunk($metricRows, 500) as $chunk) {
            DB::table('node_metrics')->insert($chunk);
        }

        $online = Node::query()->where('status', Node::STATUS_ONLINE)->get();

        foreach ($online as $node) {
            NodeCommand::create([
                'node_id' => $node->id,
                'type' => NodeCommand::TYPE_RESTART,
                'payload' => [],
                'status' => NodeCommand::STATUS_ACKED,
                'issued_at' => $now->copy()->subMinutes(20),
                'delivered_at' => $now->copy()->subMinutes(20),
                'acked_at' => $now->copy()->subMinutes(19),
                'result' => ['exit_code' => 0, 'message' => 'hysteria-server restarted'],
            ]);

            NodeCommand::create([
                'node_id' => $node->id,
                'type' => NodeCommand::TYPE_DIAGNOSTICS,
                'payload' => ['include' => ['system', 'hysteria']],
                'status' => NodeCommand::STATUS_PENDING,
            ]);
        }

        [$token, $plainToken] = EnrollToken::issue([
            'note' => 'demo enrollment token',
            'max_uses' => 10,
            'expires_at' => $now->copy()->addDays(30),
        ]);

        UpgradePackage::query()->create([
            'version' => '1.1.0',
            'arch' => 'amd64',
            'url' => 'https://github.com/getthelostcode/hysteria-agent/releases/download/v1.1.0/hysteria-agent-linux-amd64',
            'sha256' => hash('sha256', 'demo-agent-1.1.0'),
            'signature' => Str::random(64),
            'channel' => UpgradePackage::CHANNEL_STABLE,
            'rollout_percent' => 100,
            'is_active' => true,
            'released_at' => $now->copy()->subDays(3),
            'notes' => 'Demo release',
        ]);

        UpgradePackage::query()->create([
            'version' => '1.2.0-beta.1',
            'arch' => 'amd64',
            'url' => 'https://github.com/getthelostcode/hysteria-agent/releases/download/v1.2.0-beta.1/hysteria-agent-linux-amd64',
            'sha256' => hash('sha256', 'demo-agent-1.2.0-beta'),
            'signature' => Str::random(64),
            'channel' => UpgradePackage::CHANNEL_BETA,
            'rollout_percent' => 20,
            'is_active' => true,
            'released_at' => $now->copy()->subDay(),
            'notes' => 'Canary rollout at 20%',
        ]);

        foreach ([
            ['action' => 'node.enrolled', 'target' => 'ND_DEMO', 'meta' => ['via' => 'seeder']],
            ['action' => 'config.published', 'target' => 'ND_DEMO', 'meta' => ['version' => 1]],
            ['action' => 'command.issued', 'target' => 'ND_DEMO', 'meta' => ['type' => 'restart_hysteria']],
        ] as $log) {
            AuditLog::query()->create([
                'actor' => 'seeder',
                'actor_type' => 'system',
                'action' => $log['action'],
                'target' => $log['target'],
                'target_type' => 'Node',
                'meta' => $log['meta'],
                'ip' => '127.0.0.1',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        $this->command?->info('Demo fleet created. Enrollment token: ' . $plainToken);
    }
}
