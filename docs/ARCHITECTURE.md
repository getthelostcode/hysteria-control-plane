# 架构与时序

## 1. 数据模型（19 张业务/框架表）

| 表 | 作用 | 关键字段 |
|---|---|---|
| `nodes` | 节点主数据 | `node_id`(唯一, `nd_*`)、`secret_hash`、`secret_ciphertext`、`status`、`region`、`tags`、`version`、`last_seen_at`、软删除 |
| `node_credentials` | 凭证/轮换历史 | `secret_hash`、`secret_ciphertext`、`rotated_at`、`revoked_at` |
| `node_configs` | 配置版本 | `version`(节点内自增)、`content_yaml`、`sha256`、`status`、`applied_at`、`published_by`，唯一 `(node_id,version)` |
| `node_config_history` | 配置审计 | `from_version`、`to_version`、`action`(publish/ack/rollback)、`operator`、`reason` |
| `node_metrics` | 指标 | `cpu/mem/disk/net_rx/net_tx/conn_count/users`、`recorded_at`，索引 `(node_id,recorded_at)` |
| `node_commands` | 指令 | `command_id`(UUID)、`type`、`payload`、`status`、`issued_by`、`delivered_at`、`acked_at`、`result` |
| `node_logs` | 日志/诊断 | `level`、`message`、`context`、`recorded_at` |
| `enroll_tokens` | 注册令牌 | `token_hash`(sha256)、`prefix`、`max_uses`、`uses`、`expires_at`、`region_hint`、`tags_hint` |
| `upgrade_packages` | 升级包 | `version`、`arch`、`url`、`sha256`、`signature`、`channel`、`rollout_percent` |
| `audit_logs` | 业务审计 | `actor`、`action`、`target`、`meta`、`ip` |
| `audits` | 模型级审计（owen-it） | 与 `Auditable` 模型联动 |
| `users`/`settings`/`permissions*`/`personal_access_tokens`/`jobs`/`cache`/`sessions` | 框架与权限 | — |

安全说明：服务端必须能重算 HMAC，因此除了 `secret_hash`（sha256，用于等值查找/指纹）之外，
还保存了 Laravel `Crypt` 加密的 `secret_ciphertext`；明文 secret 只在 enroll 响应中出现一次。

## 2. 注册时序

```
Agent                          Control Plane                         MySQL/Redis
  │  POST /agent/v1/enroll                                        │
  │  {token,...}                                                  │
  ├──────────────────────────────►│  EnrollController            │
  │                               │  hash(token) 查 enroll_tokens ─┤
  │                               │  校验 有效期/uses             │
  │                               │  DB 事务:                     │
  │                               │   生成 secret (nds_*)         │
  │                               │   INSERT nodes (status=online)│
  │                               │   INSERT node_credentials     │
  │                               │   UPDATE enroll_tokens.uses   │
  │                               │   INSERT audit_logs(enrolled) │
  │◄──────────────────────────────┤  201 {node_id, secret,        │
  │   保存 node_id + secret       │       endpoints, ws, ttl}     │
  │  POST heartbeat (HMAC)        │                               │
  ├──────────────────────────────►│ AgentAuth: 校验签名/时间戳/nonce
  │                               │  门控写 last_seen_at (15s) ────┤
  │◄──────────────────────────────┤  200 {config, commands.pending}
```

## 3. 配置发布 → 热重载 → 失败自动回滚

```
运维(面板/REST)              ConfigService            Queue           Agent
    │ POST configs           │                         │                │
    ├────────────────────────►│ 校验 YAML               │                │
    │                         │ INSERT node_configs vN  │                │
    │                         │ (sha256, status=published)               │
    │                         │ INSERT node_config_history(publish)      │
    │                         │ INSERT audit_logs       │                │
    │                         │ Cache: node:pending_config:{id}=vN       │
    │                         │ PushConfigToNode ───────►│                │
    │◄────────────────────────┤ 201 {version, sha256}    │                │
    │                         │        ConfigPublished  │ ──WS 事件──────►│
    │                         │                         │                │ 拉 /config/latest
    │                         │◄─────────────────────────────────────────┤ (或 WS 事件后立即拉)
    │                         │ 返回 content_yaml + sha256               │
    │                         │◄─────────────────────────────────────────┤ POST config/ack
    │                         │  status=applied → 更新 config.status/applied_at
    │                         │  status=failed  → rollback(auto):
    │                         │     复制上一个 applied 版本 → 新版本 vN+1
    │                         │     node.status=error, 审计 + 历史
```

节点侧只需比对 `sha256`，不匹配就重新拉取；Windows/容器场景下没有 WS 也能靠
`heartbeat` 返回的 `config.version/pending` 触发拉取（降级路径）。

## 4. 指令下发与回执

```
运维 → POST /nodes/{id}/commands {type:restart_hysteria}
        │  INSERT node_commands (pending, expires_at=+30m)
        │  INSERT audit_logs(command.issued)
        └─► DispatchCommand (queue) → 广播 NodeCommandIssued 到 private-node.{node_id}
Agent ◄── WS 事件（<1s）        ── 或 ──► POST /commands/poll（WS 不可用时的降级）
        │  执行
        └─► POST /commands/{id}/ack {status:ok,result}   → status=acked, acked_at, audit
```

指令超过 `expires_at` 仍未送达会被 `poll` 标记 `expired`，避免堆积。

## 5. Redis Key 表

| Key（逻辑名，实际带 `CACHE_PREFIX`） | 类型/TTL | 写入者 | 用途 |
|---|---|---|---|
| `agent-nonce:{node_id}:{nonce}` | string / 300s | `SignatureVerifier::registerNonce` | 防重放（同一 nonce 只能成功一次） |
| `node:heartbeat:{node_id}` | string / 15s | `NodeService::heartbeatGateOpen` | 心跳写库门控：5000 节点下每秒只写必要的行 |
| `node:pending_config:{node_id}` | hash / 6h | `PushConfigToNode` | 标记待应用配置版本，供 REST 降级路径提示 |
| `node:summary:{node_id}` | hash / 10m | `AggregateMetrics` | 节点最新指标摘要（避免查时序表） |
| `enroll:ip:{ip}`、`enroll:global` | limiter / 60s | `throttle:agent-enroll` | 注册令牌暴力破解防护（20/分/IP、600/分全局） |
| `agent:{node_id}`、`admin-api:{user_id}`、`admin-login:{ip}` | limiter / 60s | `throttle:agent` / `throttle:admin-api` / `throttle:admin-login` | 分级限流（1200/分/节点，600/分/用户，10/分/IP） |
| `horizon:*` | — | Horizon | 队列监控 |
| `{cache_prefix}_cache_*`、`{prefix}_database_*` | — | Laravel | 缓存/session（`SESSION_DRIVER=redis`） |

## 6. 规模设计（5000+ 节点）

1. **请求路径不写库**：`AgentAuth` 只做签名校验 + nonce 检查（Redis O(1)），存活刷新经 15s 门控；
   实测「连续两次心跳仅 1 次 UPDATE」（见测试）。
2. **指标批量入库**：一次请求一条 `INSERT ... VALUES (...)`（≤500 行），聚合/阈值判断交给队列。
3. **指令与配置走队列**：下发接口只做一次插入 + 一次入队，广播失败不影响主流程。
4. **每节点限流**：1200 req/min/节点，单实例足以覆盖 5 秒心跳 + 指标上报 + 轮询的组合。
5. **索引**：`node_metrics(node_id, recorded_at)`、`nodes(status, last_seen_at)`、
   `node_commands(node_id, status)`、`node_logs(node_id, recorded_at)`。
6. **定时任务**：`nodes:check-offline` 每分钟（`withoutOverlapping`）、`metrics:prune` 每天 03:30。
7. **水平扩展**：无状态 Web 层 ×N 都可；Horizon 与 Reverb 各自独立扩；MySQL 读多写少可上只读副本，
   时序表可换成只写分区表或 Timescale。
