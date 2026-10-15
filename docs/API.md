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

### GET /api/agent/v1/config/latest?current_version=N

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
| GET | `/nodes/{node_id}/configs` | 配置版本列表 |
| POST | `/nodes/{node_id}/configs` | 发布配置（`content_yaml` 为空时用模板渲染；`overrides` 可只覆盖部分键） |
| POST | `/nodes/{node_id}/configs/{version}/rollback` | 回滚到指定版本（生成新版本） |
| GET | `/nodes/{node_id}/configs/{version}/diff` | 与上一版本的 diff（HTML + 统计） |
| GET/POST | `/enroll-tokens` | 列出/生成注册令牌（明文只返回一次） |
| DELETE | `/enroll-tokens/{id}` | 作废令牌 |
| GET/POST | `/upgrade-packages` | 升级包发布（version/arch/url/sha256/signature/channel/rollout_percent） |
| PATCH/DELETE | `/upgrade-packages/{id}` | 灰度调整 / 停用 / 删除 |

权限：`super-admin`/`admin` 全部；`operator` 可下发指令、发布配置、注册令牌，但不能发布升级包或删除节点；
`viewer` 只读。越权返回 403，未认证返回 401。

错误格式：`{"error":"<code>","message":"..."}`；校验失败为 Laravel 标准 422；
非法 YAML 为 `422 {"error":"invalid_config"}`。
