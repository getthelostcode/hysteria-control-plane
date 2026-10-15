# API 参考

所有 API 都挂在 `/api` 下，分两个面：

* **Agent API** `/api/agent/v1/*` — 由 `hysteria-agent` 节点调用，HMAC-SHA256 签名认证。
* **Admin API** `/api/admin/v1/*` — 由运维/集成调用，Sanctum Bearer Token。

机器可读的 OpenAPI 3.1 文档：`GET /docs/api.json`（Swagger UI: `/docs/api`）。

---

## 1. Agent 认证：HMAC-SHA256

每个请求必须带四个头：

| Header | 说明 |
|---|---|
| `X-Node-Id` | 注册时下发的 `node_id`（`nd_...`） |
| `X-Timestamp` | Unix 秒；与服务器时间偏差必须 ≤ `AGENT_SIGNATURE_TTL`（默认 300s） |
| `X-Nonce` | 每次请求唯一的随机串（UUID 即可），窗口内一次性 |
| `X-Node-Signature` | 十六进制 HMAC-SHA256 |

签名原文（**逐字节精确**，`\n` 为换行）：

```
METHOD \n PATH \n sha256_hex(rawBody) \n X-Timestamp \n X-Nonce
```

* `METHOD` 大写；`PATH` 只含 URL 路径（**不含查询串**），例如 `/api/agent/v1/upgrade/check`；
* `rawBody` 是**传输中的字节**：使用 `Content-Encoding: gzip` 时，签名针对压缩后的字节；
* `secret` 是 enroll 响应里返回的 `secret`（`nds_...`），服务端只保存它的 sha256 与加密副本。

失败语义：缺少 `X-Node-Id` → 401 `missing_node_id`；未知节点 → 404 `unknown_node`；
被禁用节点 → 403 `node_disabled`；签名错/时间戳过期 → 401 `bad_signature`；
nonce 复用 → 409 `replayed_nonce`；请求体超过 `AGENT_MAX_PAYLOAD_BYTES` → 413。

### 签名示例（Python）

```python
import hashlib, hmac, json, time, uuid, urllib.request

def signed_headers(node_id, secret, method, path, raw: bytes):
    ts, nonce = str(int(time.time())), str(uuid.uuid4())
    canonical = "\n".join([method, path, hashlib.sha256(raw).hexdigest(), ts, nonce])
    return {
        "X-Node-Id": node_id,
        "X-Timestamp": ts,
        "X-Nonce": nonce,
        "X-Node-Signature": hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest(),
        "Content-Type": "application/json",
    }

raw = json.dumps({"version": "2.6.0", "agent_version": "1.0.0"}).encode()
req = urllib.request.Request(
    "https://control.example.com/api/agent/v1/heartbeat",
    data=raw, method="POST",
    headers=signed_headers(node_id, secret, "POST", "/api/agent/v1/heartbeat", raw),
)
print(urllib.request.urlopen(req).read().decode())
```

---

## 1.5 hysteria-agent 兼容面（`/api/v1/agent/*`）

`hysteria-agent` 客户端按自己的 `DESIGN.md §5.1` 打 `/api/v1/agent/*`（路径可在
`control.enroll_path` / `control.token_path` / `control.ws_path` 配置）。本控制面同时提供这一层，
字段名与 agent 的 Go struct（`internal/enroll/*.go`）逐一对齐，两边走同一套 Service 与表，
所以从哪条路径注册的节点都会出现在面板里。

| Method | Endpoint | 说明 |
|---|---|---|
| POST | `/api/v1/agent/heartbeat` | REST 兜底心跳：控制通道（二进制 WS）不可用时 agent 用 `Authorization: Bearer <node_secret 自签 JWT>` 或 `?key=<node_secret>` 打这里，刷新 `last_seen_at`/状态/版本。返回字段与 `/token` 同构 |
| POST | `/api/v1/agent/enroll` | `{enroll_token, hostname, node_name, os, arch, kernel, machine_id, boot_id, agent_version, hysteria_version, protocol, replace, public_ip}`；也接受 `X-Enroll-Token` 头。`public_ip` 是**节点自报的出口公网地址**：控制面在隧道/NAT 后只能看到代理地址，没有它面板就显示不了真实 IP |
| GET | `/api/v1/agent/token` | 可选自检：`Authorization: Bearer <JWT>`（agent 用 `node_secret` 本地 HS256 自签，`sub`=node_id）或 `?key=<node_secret>` |
| GET/POST | `/api/v1/agent/control` | agent 的二进制 Envelope 长连接通道；本面返回 **501 JSON** + 可用替代（见下） |

enroll 响应（严格按 agent 契约，注意 `config_revision` 是**字符串**）：

```json
{ "node_id": "nd_…", "node_secret": "nds_…", "node_name": "hk-edge-1",
  "control_url": "https://cp.example.com", "ws_path": "/api/v1/agent/control",
  "pin_sha256": [], "jwt_issuer": "hysteria-agent", "jwt_audience": "hysteria-control",
  "heartbeat_interval_seconds": 30, "telemetry_interval_seconds": 15,
  "config_revision": "3", "message": "注册成功。" }
```

错误语义与 agent 约定一致：缺令牌/无效/已用/过期 → **401**；同一 `machine_id`（或同名）已注册且未带
`replace: true` → **409** `node_exist`+`node_id`；`replace: true` 会轮换该节点密钥并作废旧密钥。
注册后拿到的 `node_secret` 可直接用于原生 HMAC 接口（`/api/agent/v1/heartbeat` 等）。

**关于 `/api/v1/agent/control`**：agent 用 protobuf Envelope 二进制帧 + 服务端 Ping/Pong 的长连接，
php-fpm / `artisan serve` 无法终结这种连接（agent 自己的 DESIGN.md §5.1 也是这个结论）。三个可选落地：

1. **REST 替代**：用本面原生 `/api/agent/v1/*`（HMAC 签名，心跳/配置/指令/指标全都有）；
   agent 客户端若没有 REST 传输模式，则不适用；
2. **Reverb 推送**：面板与第三方走 `private-node.{node_id}` 频道（`/api/agent/v1/broadcasting/auth` 签名）；
3. **分工拓扑（推荐给 Go agent）**：Go 控制面独占 `/api/v1/agent/*`（含该 WS），Laravel 面板继续做
   UI/审计/管理 API，两者共享同一个 MySQL。

另外：**`/api/*` 的所有 404/405 现在都返回 JSON**（`{"error":"not_found", …}`），
不会再出现“打错路径却收到 HTML 404 页”这种难排查的形态。

---

## 1.8 桥接消费方：Go 控制面（hysteria-agent 原生通道）

当 `hysteria-agent` 需要走自己的 Envelope WS 协议（`/api/v1/agent/control`，本面无法终结）时，
由它的 Go 控制面终结该通道，并以「节点代理」身份调用本面的既有 Agent API（HMAC REST）——
数据、审计、权限、面板全部仍然只在本面。

* 设计文档（接口映射表、指令类型映射、时序图、降级、规模预算）：
  `hysteria-agent` 仓库 `docs/BRIDGE-TO-CONTROL-PLANE.md`
* 本面需要的改动只有：`NodeCommand::TYPES` 增加 6 个 agent 原生类型
  （`tail_logs` / `get_state` / `cancel_tail` / `rollback_config` / `self_restart` / `clear_outbox`）、
  危险类型的限权，以及可选的通道标记（`nodes.meta.protocol = bridge/v1`）。**不需要新表、新 agent 接口。**

> `jwt_issuer` / `jwt_audience` 必须与 agent 侧 `internal/creds` 的
> `DefaultIssuer`(`hysteria-agent`) / `DefaultAudience`(`hysteria-control`) 一致：agent 用这对值
> 自签控制通道 JWT，控制面再按同一组值校验。值不一致的表现是 **WS 握手 401**（agent 只报
> "bad handshake"），排查成本高 —— 改这两个字段前先看 `internal/creds/creds.go`。
>
> 节点详情 `GET /api/admin/v1/nodes/{node_id}` 会返回 `data.meta`（过滤掉含
> secret/token/password/key/credential 的键），面板与脚本可据此识别接入通道。
* 桥接器不直连 MySQL，只走本节上半部分的签名 REST，因此签名规范（路径不含查询串、gzip 签压缩后字节、
  nonce 一次性、TTL 300s）是两边唯一的强耦合点。

## 2. Agent API

### POST /api/agent/v1/enroll （无需签名，按 IP 限流）

```json
{ "token": "enr_xxx", "name": "hk-edge-1", "region": "hk", "tags": ["premium"],
  "version": "2.6.0", "agent_version": "1.0.0", "arch": "amd64", "kernel": "6.17.2" }
```

`201` 响应包含 `node_id`、`secret`（只出现一次）、`signature`（算法与原文模板）、
`endpoints`（所有后续地址）、`websocket`（url/channel/key）、`heartbeat_interval`。

令牌校验：存在、未过期、`uses < max_uses`，否则 `422 invalid_token`。注册动作写入
`audit_logs: node.enrolled`。

### POST /api/agent/v1/heartbeat

```json
{ "version": "2.6.0", "agent_version": "1.0.0", "uptime": 86400, "region": "hk",
  "tags": ["premium"], "meta": {"load": 0.4} }
```

返回 `heartbeat_interval`、最新配置版本与 `commands.pending` 计数。任何签名请求都会刷新
存活状态（写库按节点 15 秒门控）。

### GET /api/agent/v1/config/latest?current_version=N&current_sha256=<sha>

`current_version` 是节点本机记录的版本号，`current_sha256` 是它当前生效配置的 sha256（可选）。
**两者都对上**才回 `up_to_date: true` —— 否则运维就地把某个版本的正文改掉时（版本号不变），
节点会永远拿不到新内容。返回的是「默认下发」的那一版（面板上可指定，见 Admin API 的 activate）。

* 与最新版本一致：`{"ok":true,"up_to_date":true,"config":{"version":N,"sha256":"..."}}`
* 有更新：额外返回 `content_yaml`、`status`、`published_at`。

### POST /api/agent/v1/config/ack

```json
{ "version": 3, "status": "applied" }        // 或 {"status":"failed","error":"yaml parse error"}
```

`status=failed` 且存在更早的已生效版本时，服务端**自动回滚**（创建一个新版本，内容取自旧的已生效版本），
并把节点标记为 `error`，同时写入 `node_config_history` 与 `audit_logs`。

### POST /api/agent/v1/metrics

支持三种载荷形态：`{"samples":[...]}`、`{"metrics":[...]}`、单条扁平对象。单次上限
`AGENT_MAX_METRIC_SAMPLES`（默认 500）。`202 {"ok":true,"stored":N}`；聚合（缓存摘要、异常状态判定）
异步落到 `AggregateMetrics` 队列任务。同时刷新存活状态。

### POST /api/agent/v1/commands/poll

```json
{ "limit": 10 }
```

返回待执行指令（`pending` → `sent`，并写 `delivered_at`）；过期的 `pending` 指令会被标记 `expired`。
指令类型：`restart_hysteria`、`reload_config`、`upgrade`、`rotate_secret`、`collect_diagnostics`、
`kick_user`、`traffic_reset`、`exec`。

### POST /api/agent/v1/commands/{command_id}/ack

```json
{ "status": "ok", "result": {"exit_code": 0} }   // 或 {"status":"failed","error":"..."}
```

节点只能回执自己的指令（跨节点 → 404）。

### POST /api/agent/v1/logs

```json
{ "entries": [ { "level": "warning", "message": "quic slow", "context": {"ms": 900} } ] }
```

未知 level 会被归一化为 `info`（不会整批判 422）；单次上限 `AGENT_MAX_LOG_ENTRIES`。

### GET /api/agent/v1/upgrade/check?arch=amd64&version=1.0.0&channel=stable

有新版本时返回 `package`（version/url/sha256/signature/channel）。灰度按
`crc32(node_id) % 100 < rollout_percent` 判定，`is_active=false` 的包不参与。

### POST /api/agent/v1/diagnostics

```json
{ "payload": { "summary": "all good", "dns": "ok" } }
```

整包存入 `node_logs`（`level=diagnostic`）并镜像到 `nodes.meta.diagnostics`。

### POST /api/agent/v1/broadcasting/auth

Pusher/Reverb 频道授权（`socket_id` + `channel_name`），只允许订阅自己的
`private-node.{node_id}`；返回标准签名 `{"auth":"<key>:<hmac>"}`。

---

## 3. Admin API（Bearer Token）

```bash
TOKEN=$(curl -s -X POST https://control.example.com/api/admin/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"password","device_name":"cli"}' | jq -r .token)
```

| Method | Endpoint | 说明 |
|---|---|---|
| POST | `/auth/login` | 签发 Sanctum token（含角色/权限） |
| GET | `/auth/me` | 当前身份 |
| POST | `/auth/logout` | 撤销当前 token |
| GET | `/stats` | 机群总览（节点/流量/指令/主题） |
| GET | `/nodes?status=&region=&search=&per_page=` | 节点列表（分页） |
| GET | `/nodes/{node_id}` | 节点详情（含最新指标/配置统计） |
| PATCH | `/nodes/{node_id}` | 改名称/区域/标签/状态 |
| DELETE | `/nodes/{node_id}` | 软删除 |
| POST | `/nodes/{node_id}/secret/rotate` | 轮换密钥（仅返回一次） |
| GET | `/nodes/{node_id}/metrics?range=1h&points=60` | 分桶时序（5m/15m/1h/6h/12h/24h/7d/30d） |
| GET | `/metrics/fleet?range=1h` | 全网时序 + 汇总 |
| POST | `/nodes/{node_id}/commands` | 下发指令 `{"type":"restart_hysteria","payload":{}}` |
| POST | `/commands/bulk` | 批量/全网指令 `{"type":"reload_config","nodes":["nd_a"]}` |
| GET | `/commands?status=&type=` | 指令列表 |
| GET | `/nodes/{node_id}/configs` | 配置版本列表（含 `is_current`；`active_version` = 默认下发的那一版） |
| POST | `/nodes/{node_id}/configs` | 发布配置（`content_yaml` 为空时用模板渲染；`overrides` 可只覆盖部分键） |
| GET | `/nodes/{node_id}/configs/{version}` | 查看某个版本的正文（`content_yaml` + `data.is_current`） |
| PATCH | `/nodes/{node_id}/configs/{version}` | 就地修改某个版本的正文（重算 sha256；改的是默认下发版本时节点下次同步即取走） |
| POST | `/nodes/{node_id}/configs/{version}/activate` | **指定默认下发的版本**（不新生成版本，只挪指针） |
| POST | `/nodes/{node_id}/configs/{version}/rollback` | 回滚到指定版本（以旧正文生成**新版本**，历史追加） |
| GET | `/nodes/{node_id}/configs/{version}/diff` | 与上一版本的 diff（HTML + 统计） |
| GET/POST | `/enroll-tokens` | 列出/生成注册令牌（明文只返回一次） |
| DELETE | `/enroll-tokens/{id}` | 作废令牌 |
| GET/POST | `/upgrade-packages` | 升级包发布（version/arch/url/sha256/signature/channel/rollout_percent） |
| PATCH/DELETE | `/upgrade-packages/{id}` | 灰度调整 / 停用 / 删除 |

权限：`super-admin`/`admin` 全部；`operator` 可下发指令、发布配置、注册令牌，但不能发布升级包或删除节点；
`viewer` 只读。越权返回 403，未认证返回 401。

错误格式：`{"error":"<code>","message":"..."}`；校验失败为 Laravel 标准 422；
非法 YAML 为 `422 {"error":"invalid_config"}`。
