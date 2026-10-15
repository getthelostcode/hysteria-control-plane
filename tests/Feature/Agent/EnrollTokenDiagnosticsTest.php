<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Filament\Resources\EnrollTokenResource;
use App\Filament\Resources\EnrollTokenResource\Pages\ListEnrollTokens;
use App\Models\EnrollToken;
use App\Models\Node;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 注册令牌被拒时，必须能一眼看出是「复制了掩码」「已用尽」「已过期」「已作废」还是「根本不存在」。
 *
 * 现场教训：运维在面板列表上看到 enr_GmCVlx2W…（哈希方案下只能显示掩码），手抄去注册拿到
 * 「enroll_token 无效、已使用或已过期」，而库里那条令牌其实是未使用、未过期的 —— 排查花了很久。
 */
class EnrollTokenDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $token): array
    {
        return [
            'enroll_token' => $token,
            'hostname' => 'diag-host',
            'node_name' => 'diag-node',
            'os' => 'linux',
            'arch' => 'amd64',
            'agent_version' => '0.1.0',
            'machine_id' => 'diag-machine-' . substr(md5($token), 0, 8),
        ];
    }

    public function test_full_token_enrolls_while_its_masked_prefix_does_not(): void
    {
        [$token, $plain] = EnrollToken::issue(['note' => 'japan-mylove333']);

        // 掩码值（面板列表里显示的样子）：必须被明确诊断为「截断/掩码」，而不是笼统的无效
        $this->postJson('/api/v1/agent/enroll', $this->payload($token->prefix . '…'))
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_token')
            ->assertJsonPath('reason', 'token_truncated')
            ->assertJsonPath('token_prefix', $token->prefix)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, '掩码')
                && str_contains($m, 'japan-mylove333'));

        // 只发前缀同样被诊断为掩码/截断
        $this->postJson('/api/v1/agent/enroll', $this->payload($token->prefix))
            ->assertStatus(401)
            ->assertJsonPath('reason', 'token_truncated');

        // 完整明文才是有效的：这次注册成功，且令牌未被上面的失败请求消耗
        $this->postJson('/api/v1/agent/enroll', $this->payload($plain))
            ->assertOk()
            ->assertJsonPath('node_name', 'diag-node');

        $this->assertSame(1, Node::query()->count());
        $this->assertSame(1, $token->refresh()->uses, '被拒的请求不得消耗令牌');
    }

    public function test_reveal_action_is_hidden_without_a_fresh_plaintext(): void
    {
        $user = User::query()->create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ]);
        $user->assignRole(Role::findOrCreate('super-admin', 'web'));
        $this->actingAs($user);

        Livewire::test(ListEnrollTokens::class)->assertActionHidden('revealToken');
    }

    public function test_unknown_token_is_reported_as_unknown_not_as_truncated(): void
    {
        EnrollToken::issue(['note' => 'unrelated']);

        $this->postJson('/api/v1/agent/enroll', $this->payload('enr_'.str_repeat('z', 40)))
            ->assertStatus(401)
            ->assertJsonPath('reason', 'token_unknown')
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, '未找到'));
    }

    public function test_revoked_used_and_expired_tokens_are_told_apart(): void
    {
        [$revoked, $revokedPlain] = EnrollToken::issue(['note' => 'revoked-one']);
        $revoked->revoke();

        [$used, $usedPlain] = EnrollToken::issue(['note' => 'used-one', 'max_uses' => 1, 'uses' => 1]);

        [$expired, $expiredPlain] = EnrollToken::issue(['note' => 'expired-one', 'expires_at' => now()->subDay()]);

        $this->postJson('/api/v1/agent/enroll', $this->payload($revokedPlain))
            ->assertStatus(401)->assertJsonPath('reason', 'token_revoked');
        $this->postJson('/api/v1/agent/enroll', $this->payload($usedPlain))
            ->assertStatus(401)->assertJsonPath('reason', 'token_used');
        $this->postJson('/api/v1/agent/enroll', $this->payload($expiredPlain))
            ->assertStatus(401)->assertJsonPath('reason', 'token_expired');

        // 三种状态在数据层面也是可区分的（不再靠伪造 expires_at 冒充作废）
        $revoked->refresh();
        $this->assertTrue($revoked->isRevoked());
        $this->assertFalse($revoked->isUsable());
        // 作废只打标记：有效期与次数保持原样（否则「已作废」和「已用尽」在数据层长得一样）
        $this->assertNotNull($revoked->expires_at);
        $this->assertTrue($revoked->expires_at->isFuture());
        $this->assertSame(0, $revoked->uses);
        $this->assertSame('已作废', $revoked->statusLabel());
        $this->assertSame('已用尽', $used->refresh()->statusLabel());
        $this->assertSame('已过期', $expired->refresh()->statusLabel());
        $this->assertSame('可用', EnrollToken::issue([])[0]->statusLabel());
    }

    public function test_whitespace_wrapped_token_is_diagnosed_as_malformed(): void
    {
        [, $plain] = EnrollToken::issue([]);

        $diag = EnrollToken::diagnose(" {$plain}\n");

        $this->assertSame('token_malformed', $diag['reason']);
        $this->assertStringContainsString('空白', $diag['message']);
        $this->assertSame('token_truncated', EnrollToken::diagnose(substr($plain, 0, 12) . '…')['reason']);

        // HTTP 层不必断言带空白的情形：Laravel 11 全局 TrimStrings 已经先裁掉了两端空白，
        // 也就是说「从终端复制带上换行」不会导致注册失败 —— 这一点值得测试固定下来。
        $this->postJson('/api/v1/agent/enroll', $this->payload(" {$plain}\n"))
            ->assertOk();
    }

    public function test_native_agent_endpoint_reports_the_same_reason(): void
    {
        [$token] = EnrollToken::issue(['note' => 'native-space']);

        $this->postJson('/api/agent/v1/enroll', ['token' => $token->prefix . '…'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_token')
            ->assertJsonPath('reason', 'token_truncated');
    }

    public function test_rejections_are_audited_with_the_reason(): void
    {
        [$token] = EnrollToken::issue(['note' => 'audited']);

        $this->postJson('/api/v1/agent/enroll', $this->payload($token->prefix . '…'))->assertStatus(401);

        $this->assertDatabaseHas('audit_logs', ['action' => 'agent_v1.enroll_rejected']);
        $this->assertStringContainsString('token_truncated', json_encode(
            \App\Models\AuditLog::query()->where('action', 'agent_v1.enroll_rejected')->latest('id')->first()?->meta,
            JSON_THROW_ON_ERROR,
        ));
    }

    public function test_panel_reissue_revokes_the_old_token_and_shows_a_copyable_plaintext(): void
    {
        $user = User::query()->create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ]);
        $user->assignRole(Role::findOrCreate('super-admin', 'web'));
        $this->actingAs($user);

        [$old, ] = EnrollToken::issue(['note' => 'needs-reissue', 'max_uses' => 1], $user->id);

        Livewire::test(ListEnrollTokens::class)
            ->callTableAction('reissue', $old)
            ->assertHasNoTableActionErrors();

        $this->assertTrue($old->refresh()->isRevoked(), '重新签发必须作废旧令牌');

        $fresh = EnrollToken::query()->where('id', '!=', $old->id)->latest('id')->first();
        $this->assertNotNull($fresh);
        $this->assertSame('needs-reissue', $fresh->note);
        $this->assertTrue($fresh->isUsable());
        $this->assertDatabaseHas('audit_logs', ['action' => 'enroll_token.reissued', 'target' => $fresh->getKey()]);

        // 明文通知必须带一键复制动作（手抄掩码是上面那个 401 的根因）
        $plain = 'enr_'.str_repeat('a', 40);
        $notification = EnrollTokenResource::plaintextNotification($fresh, $plain);

        // 通知正文：明文 + 明确的「列表里的是掩码」提示
        $this->assertStringContainsString($plain, strip_tags((string) $notification->getBody()));
        $this->assertStringContainsString('掩码', (string) $notification->getBody());

        // 明文进会话，列表页的「查看刚签发的令牌」据此可见并可再次复制
        $this->assertSame($plain, session(EnrollTokenResource::PLAINTEXT_SESSION_KEY));
        Livewire::test(ListEnrollTokens::class)->assertActionVisible('revealToken');

        // 掩码值也在会话里被避开：绝不把掩码当明文存
        $this->assertNotSame($fresh->prefix, session(EnrollTokenResource::PLAINTEXT_SESSION_KEY));
    }
}
