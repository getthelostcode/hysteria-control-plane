<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Node;
use App\Models\NodeTrafficDaily;
use App\Models\Provider;
use App\Models\ProviderUser;
use Illuminate\Database\Seeder;

/**
 * 幂等种子：install.sh 每次安装/升级都会跑 db:seed，重复执行不能重复插数据。
 * 默认账号：admin@panel.test / Admin123456，provider-a@panel.test / Provider123456
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        Admin::query()->firstOrCreate(
            ['email' => 'admin@panel.test'],
            ['name' => '平台管理员', 'password' => 'Admin123456'],
        );

        $providers = [
            [
                'code' => '4f13b9d93a8afb6e98283702',
                'name' => '示例服务商 A',
                'contact' => 'ops-a@example.com',
                'email' => 'provider-a@panel.test',
                'nodes' => [
                    ['ip' => '103.47.186.35', 'hostname' => 'japan2.3ai.best', 'port' => 10948],
                    ['ip' => '103.53.81.227', 'hostname' => 'jp8.ip2.one', 'port' => 12166],
                ],
            ],
            [
                'code' => 'jp8carrier01',
                'name' => '示例服务商 B',
                'contact' => 'ops-b@example.com',
                'email' => 'provider-b@panel.test',
                'nodes' => [
                    ['ip' => '198.51.100.24', 'hostname' => 'hk1.example.net', 'port' => 15500],
                ],
            ],
        ];

        foreach ($providers as $definition) {
            $provider = Provider::query()->firstOrCreate(
                ['code' => $definition['code']],
                ['name' => $definition['name'], 'contact' => $definition['contact'], 'status' => Provider::STATUS_ACTIVE],
            );

            ProviderUser::query()->firstOrCreate(
                ['email' => $definition['email']],
                ['provider_id' => $provider->id, 'name' => $definition['name'] . ' 运维', 'password' => 'Provider123456'],
            );

            foreach ($definition['nodes'] as $nodeDefinition) {
                $node = Node::query()->firstOrCreate(
                    ['provider_id' => $provider->id, 'ip' => $nodeDefinition['ip']],
                    [
                        'name' => $nodeDefinition['hostname'],
                        'hostname' => $nodeDefinition['hostname'],
                        'obfs_password' => $provider->code,
                        'traffic_port' => $nodeDefinition['port'],
                        'traffic_secret' => bin2hex(random_bytes(24)),
                        'version' => 'v2.13.0',
                        'status' => Node::STATUS_ACTIVE,
                        'last_seen_at' => now()->subMinutes(1),
                    ],
                );

                // 演示流量：按天生成固定值，重复播种不会翻倍
                foreach (range(6, 0) as $daysAgo) {
                    $date = now()->subDays($daysAgo)->toDateString();
                    $seed = crc32($node->ip . $date);

                    NodeTrafficDaily::query()->updateOrCreate(
                        ['node_id' => $node->id, 'date' => $date],
                        [
                            'tx_bytes' => 200 * 1024 * 1024 + $seed % (3 * 1024 * 1024 * 1024),
                            'rx_bytes' => 60 * 1024 * 1024 + $seed % (1024 * 1024 * 1024),
                            'online_users' => 5 + $seed % 40,
                        ],
                    );
                }
            }
        }
    }
}
