# 运维手册

## 1. 进程

| 进程 | 命令 | 说明 |
|---|---|---|
| HTTP | nginx + php-fpm（或 `php artisan serve` 本地调试） | Laravel 全站 |
| 队列 | `php artisan horizon` | 配置推送、指令广播、指标聚合 |
| WebSocket | `php artisan reverb:start --host=0.0.0.0 --port=8081` | 面板与节点的实时通道 |
| 调度 | `php artisan schedule:work`（或 crontab `schedule:run` 每分钟） | 离线检测、指标清理、Horizon 快照 |

`install.sh` 会按 `deploy/systemd/` 模板安装三个 systemd 单元；
使用 Supervisor 的机器用 `deploy/supervisor/hysteria-control-plane.conf`。

## 2. 排障速查

| 现象 | 排查 |
|---|---|
| Agent 全部 401 `bad_signature` | 时间漂移（`date`/NTP）；签名原文是否用了完整路径含查询串；body 是否被代理改写；gzip 场景签名是否针对压缩后字节 |
| Agent 409 `replayed_nonce` | Agent 侧 nonce 没随机化（每次请求必须新 UUID） |
| 节点一直 offline | 检查 `AGENT_OFFLINE_THRESHOLD`（默认 90s）与 `nodes:check-offline` 是否在跑；`LastSeenAt` 写入门控是 15s，属正常 |
| 配置发布后节点没热重载 | Horizon 是否在跑（`PushConfigToNode`）；节点是否订阅了 `private-node.{id}`；节点可退回轮询 `/config/latest` |
| 面板样式突然“少了” | `npm run build` 未执行或 manifest 缺失 → `Vite manifest not found at public/build/manifest.json` |
| 面板 403 登录后 | `users` 角色不含 super-admin/admin/operator/viewer 之一（`canAccessPanel` 会直接 403） |
| WebSocket 连不上 | nginx `/app` 反代是否带 Upgrade 头；`REVERB_HOST/PORT/SCHEME` 与前端 `VITE_REVERB_*` 是否一致；`php artisan reverb:start` 是否在跑 |

## 2.5 SQLite 试用模式

除了生产用的 MySQL 8，控制平面也可以在 SQLite 上完整运行（迁移、种子、agent API、
管理 API、Filament 面板、OpenAPI 均已验证；本仓库两套测试配置都是 89 用例全绿）：

```bash
php artisan test -c phpunit.sqlite.xml        # 内存库，不落文件
DB_CONNECTION=sqlite bash install.sh          # 文件库 database/database.sqlite
```

差异与注意：

| 项 | MySQL 8（生产） | SQLite（试用） |
|---|---|---|
| `json` 列 | 原生 JSON 类型，可查询 | TEXT + Eloquent cast（不支持下推查询） |
| 排序规则 | `utf8mb4_0900_ai_ci` | 二进制比较（中文排序/去重行为不同） |
| 严格类型/长度 | 有（超长会报错） | 无（写超长值不报错） |
| 并发写 | 行级锁 | 库级锁（5000 节点场景必须用 MySQL） |
| 外键 | 默认开启 | `DB_FOREIGN_KEYS=true`（install.sh 已写入） |

Redis 仍然是必需的（限流/防重放/队列/会话）；只把数据库换掉而已。

## 3. 数据与保留

* **指标**：`metrics:prune --days=N`（默认取 `settings.metrics_retention_days=30`），每天 03:30 自动执行。
* **审计**：`audit_logs` 只增不删，面板 `系统 → 审计日志` 可筛选 `action`/`target_type`；
  模型级变更另存于 owen-it 的 `audits` 表。
* **备份**：spatie/laravel-backup，配置在 `config/backup.php`：
  ```bash
  php artisan backup:run            # 数据库 + 文件
  php artisan backup:list
  php artisan backup:clean
  ```
  建议把 `backup:run` 加入调度。

## 4. 密钥与轮换

* 节点密钥轮换：面板节点页 →「轮换密钥」或 `POST /api/admin/v1/nodes/{id}/secret/rotate`。
  旧凭证在同一次操作中被 `revoked_at` 标记，但 `Node::signingSecrets()` 会接受
  **尚未撤销**的凭证，因此轮换期间的重复注册请求不会立刻中断（可自主决定是否收紧）。
* 注册令牌：明文只出现一次（仅存 sha256）；`enroll_tokens.max_uses/expires_at` 控制生命周期，
  面板可一键作废。
* `APP_KEY` 变更会导致 `secret_ciphertext` 无法解密 → 必须在换 key 前先轮换全部节点密钥。

## 5. 可观测性

* 队列与失败任务：`/horizon`（Horizon 面板，需授权；生产建议加 Basic Auth 或限制来源 IP）。
* 日志：`storage/logs/laravel.log`、`horizon.log`、`reverb.log`、`scheduler.log`。
* 面板「节点日志」聚合节点上报的 `error/warning/diagnostic`。
* API 文档：`/docs/api`，机器可读 `/docs/api.json`（生产环境建议给 docs 路由加认证中间件，
  见 `config/scramble.php` 的 `middleware`）。

## 节点 IP 显示为 127.0.0.1 怎么办

控制面部署在反向代理/隧道后面（Cloudflare Tunnel、nginx、ALB）时，不做任何配置
`$request->ip()` 返回的是**代理自己的地址**：同机跑 cloudflared 时就是 127.0.0.1。
于是面板「公网 IP」列、审计日志的 IP 全是 127.0.0.1。

1. `.env` 里设置 `TRUSTED_PROXIES`（默认 `127.0.0.1,::1` 已覆盖同机隧道场景；
   多跳代理填上游网段，如 `10.0.0.0/8,172.16.0.0/12`；只有应用无法被直连时才用 `*`，
   因为 `X-Forwarded-For` 可伪造）。
2. 重启 PHP-FPM / dev server 让配置生效。

**地址族**：面板按 **IPv4** 展示。双栈节点从 IPv6 出口访问 Cloudflare 时，控制面观测到的
客户端地址是 `2001:...`（真实现场：某个日本节点的 ip 被写成 `2a14:640:1:f6eb::1`），所以：
agent 探测出口地址时**强制走 tcp4**（Go 在双栈机器上默认优先 IPv6）；面板在候选里优先取
IPv4，且**已有的 IPv4 不会被新的 IPv6 顶掉**（避免列表在 v4/v6 之间跳变）。IPv6 出口仍记录在
`nodes.meta.ip_v6`，面板有默认隐藏的「IPv6 出口」列。

取值优先级（见 `App\Support\PublicIp`）：

| 来源 | 何时使用 | `nodes.meta.ip_source` |
|---|---|---|
| agent 自报 `public_ip` | 节点在 NAT/隧道后（桥接模式下 Laravel 只看到 Go 控制面的地址） | `agent` |
| 代理链推导（X-Forwarded-For） | 请求经过可信代理 | `proxy` |
| 观测到的对端地址 | 前两者都拿不到（内网部署） | `peer` |

私网/回环地址**不会**覆盖已记录的公网地址（agent 上报内网地址是常态）。
运维也可以直接在面板编辑节点，或 `PATCH /api/admin/v1/nodes/{node_id}` 手工修正 `ip`。
