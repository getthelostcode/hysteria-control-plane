# hysteria-control-plane

Hysteria2 机群的**控制平面**：Laravel 11 + Filament v3 写的节点管理后台 + 两套 API。
节点侧跑 [`hysteria-agent`](https://github.com/getthelostcode/hysteria-agent)，这里负责**权威身份、配置版本、指令、遥测、审计**。

```
┌──────────────────────────────────────────────────────────────────────────┐
│                       Laravel Control Plane                              │
│  ┌──────────────┐   ┌────────────────┐   ┌──────────────────────────┐    │
│  │ Agent API    │   │ Reverb / WS    │   │ Filament Admin (v3)      │    │
│  │ /api/agent/v1│   │ private-node.* │   │ 自定义主题 + 5s 轮询      │    │
│  │ HMAC-SHA256  │   │ private-fleet  │   │ + Echo 实时推送           │    │
│  └──────┬───────┘   └───────┬────────┘   └────────────┬─────────────┘    │
│         │                   │                         │                  │
│  ┌──────▼───────────────────▼─────────────────────────▼─────────────┐    │
│  │ Service：NodeService / ConfigService / CommandService /            │    │
│  │          MetricsService / ConfigFileService / HysteriaConfigBuilder │    │
│  └──────┬───────────────────┬─────────────────────────┬─────────────┘    │
│  ┌──────▼──────┐    ┌───────▼───────┐        ┌────────▼──────────┐       │
│  │ MySQL 8     │    │ Redis         │        │ Queue (Horizon)   │       │
│  │ 19 张表     │    │ 限流/防重放/缓存│        │ 配置推送/指令/聚合 │       │
│  └─────────────┘    └───────────────┘        └───────────────────┘       │
└──────────────────────────────────────────────────────────────────────────┘
```

| 能力 | 一句话 |
|---|---|
| 注册与身份 | 一次性 `enroll_token`（`enr_`+40）换 `node_id`(`nd_*`) + `node_secret`(`nds_*`)；密钥**只存 sha256 指纹 + Crypt 密文**，明文只在响应里出现一次；支持轮换（旧凭证 `revoked_at` 但不立刻断服） |
| Agent API | HMAC-SHA256 签名（防重放 nonce + 时间窗）、gzip 解包、每节点限流；心跳/配置/指令/指标/日志/诊断/升级检查/广播授权 |
| 配置下发 | 「**配置管理 → 版本管理**」维护与节点无关的**版本文件库**；每台节点有 `is_current` 的默认下发版本；节点按 **sha256** 判断是否一致，5 秒内自己取回、重启 Hysteria、回执 applied/failed |
| 失败自动回滚 | `failed` 回执 → 用上一个成功版本生成新版本；**三道护栏**（同内容 / 10 分钟 3 次 / 同 sha）止住版本风暴，跳过时审计 `config.auto_rollback_skipped` |
| 实盘版本显示 | 节点列表「配置版本」按节点上报的 `config_sha256` **反查版本号**（版本文件库 → 该节点历史 → 自报），报不出来就显示「未识别」，绝不拿面板期望值糊上去 |
| 指令 | 白名单类型 + 状态机（pending→sent→acked/expired）+ 30 分钟 TTL + 批量/全网下发；Reverb 推送 + REST 轮询兜底 |
| 遥测与日志 | 指标按节点 15s 门控写库、队列聚合摘要；日志/诊断按级别入库；面板「节点日志」聚合 |
| 审计 | `audit_logs`（业务：actor/ip/meta，只增不删）+ owen-it（模型级 `audits`）；敏感操作全覆盖测试 |
| 权限 | spatie：`super-admin` / `admin` / `operator` / `viewer` × 8 个权限，后台与 API 同一套 |
| 实时 | Reverb + Echo：`private-node.{node_id}`、`private-fleet`；面板 widget 5s 轮询 |
| 文档 | Scramble 自动生成 OpenAPI 3.1：`/docs/api`（UI）、`/docs/api.json`（机器可读） |

> 节点侧要什么、怎么接：见 [hysteria-agent README](https://github.com/getthelostcode/hysteria-agent)。
> 本仓库文档索引见 [§11](#11-文档索引)。

## 目录

- [1. 核心概念](#1-核心概念)
- [2. 快速开始](#2-快速开始)
- [3. 配置下发](#3-配置下发)
- [4. API](#4-api)
- [5. 面板](#5-面板)
- [6. 权限](#6-权限)
- [7. 部署与运维](#7-部署与运维)
- [8. 排错](#8-排错)
- [9. 测试与验收](#9-测试与验收)
- [10. 目录结构](#10-目录结构)
- [11. 文档索引](#11-文档索引)

## 1. 核心概念

| 概念 | 存在哪 | 说明 |
|---|---|---|
| **节点** | `nodes` | 一台 VPS 的唯一身份；`status`(online/offline/error/disabled)、`region`、`tags`、`ip`(IPv4)、`meta`(agent 自报现状：配置路径、sha256、通道…) |
| **注册令牌** | `enroll_tokens` | 一次性（`max_uses`/`expires_at`），库里只有 sha256 + 前缀；可作废（`revoked_at`） |
| **凭证** | `node_credentials` | 每次注册/轮换一条；校验时接受「尚未撤销」的凭证 |
| **配置版本文件库** | `config_files` | **与节点无关**的配置正文版本（v1、v2…全库唯一），全库唯一的一份 `is_default` |
| **节点配置版本** | `node_configs` | 某节点下发过的第几版（`(node_id, version)` 唯一）+ `sha256` + `status` + `is_current`（默认下发的那一版） |
| **配置历史** | `node_config_history` | publish / ack / rollback / auto_rollback / applied_from_library 全留痕 |
| **指令** | `node_commands` | `command_id`(UUID) + `type` + `payload` + `status` + `delivered_at`/`acked_at`/`result` |
| **指标** | `node_metrics` | cpu/mem/disk/net/conn/users，索引 `(node_id, recorded_at)`；Redis 里还有 10 分钟摘要 |
| **日志/诊断** | `node_logs` | `level`(info/warning/error/diagnostic) |
| **升级包** | `upgrade_packages` | version/arch/url/sha256/**signature**/channel/rollout_percent（灰度按 `crc32(node_id)%100`） |
| **审计** | `audit_logs` | 谁在什么 IP 干了什么（enroll / command / config / token …） |
| **设置** | `settings` | 品牌、主题、心跳/离线阈值、保留期、鉴权后端 URL、模板… |

## 2. 快速开始

### 2.1 install.sh（推荐）

```bash
git clone https://github.com/getthelostcode/hysteria-control-plane.git
cd hysteria-control-plane
sudo bash install.sh                    # 依赖检查 → .env/APP_KEY → 建库+迁移+种子 → 前端构建 → systemd 单元
php artisan serve --port=8088           # 本地快速体验（生产用 nginx + php-fpm）
```

脚本结束会打印**面板地址、API 文档地址、默认账号**。

| 参数 | 说明 |
|---|---|
| `--no-seed` | 不写演示机群数据（默认会种 `admin@example.com` / `operator@example.com`） |
| `--no-services` | 不安装/启动 systemd 单元（horizon / reverb / scheduler） |
| `--upgrade` | 升级已有部署：`composer install --no-dev`、迁移、重建前端 |
| `DB_CONNECTION=sqlite` | 用 SQLite 文件库替代 MySQL（见 [2.2](#22-sqlite-试用模式无-mysql)） |

环境变量：`DB_HOST DB_PORT DB_USER DB_PASSWORD DB_NAME APP_URL ADMIN_EMAIL ADMIN_PASSWORD SERVICE_USER TRUSTED_PROXIES APP_DIR PHP_BIN`。

### 2.2 SQLite 试用模式（无 MySQL）

迁移、种子、两套 API、面板、OpenAPI 全部在 SQLite 上验证过；只换数据库，Redis 仍然必需（限流/防重放/队列/会话）。

```bash
DB_CONNECTION=sqlite bash install.sh     # 生成 database/database.sqlite（DB_FOREIGN_KEYS=true）
php artisan test -c phpunit.sqlite.xml   # 内存库跑测试
```

| 项 | MySQL 8（生产） | SQLite（试用） |
|---|---|---|
| `json` 列 | 原生 JSON，可下推查询 | TEXT + Eloquent cast |
| 排序规则 | `utf8mb4_0900_ai_ci` | 二进制比较（中文排序/去重不同） |
| 严格类型/长度 | 有 | 无 |
| 并发写 | 行级锁 | 库级锁（**5000 节点必须 MySQL**） |
| 外键 | 默认开启 | `DB_FOREIGN_KEYS=true`（install.sh 已写） |

### 2.3 手动安装

```bash
composer install                 # 开发还要 npm install --include=dev（本机默认会跳过 devDependencies）
cp .env.example .env && php artisan key:generate
php artisan migrate --seed && npm install --include=dev && npm run build
php artisan horizon & php artisan reverb:start --host=0.0.0.0 --port=8081 & php artisan schedule:work &
```

### 2.4 默认入口

| 入口 | 地址 |
|---|---|
| 管理后台 | `/admin`（`admin@example.com` / `password`，`operator@example.com` / `password`） |
| OpenAPI UI / JSON | `/docs/api` / `/docs/api.json` |
| Agent API（原生） | `/api/agent/v1/*`（HMAC-SHA256） |
| Agent API（hysteria-agent 契约） | `/api/v1/agent/*` |
| 管理 API | `/api/admin/v1/*`（Sanctum Bearer） |
| 队列面板 | `/horizon` |
| WebSocket | `wss://<host>/app/<REVERB_APP_KEY>` |

### 2.5 接第一台节点

```bash
# 面板「节点管理 → 注册令牌 → 新建」，或：
curl -s -X POST https://cp.example.com/api/admin/v1/enroll-tokens \
  -H "Authorization: Bearer <admin token>" -H 'Content-Type: application/json' \
  -d '{"name":"hk-edge-1","max_uses":1}'
# → token: enr_xxx（明文只出现一次）

# 节点机上（hysteria-agent 仓库）：
sudo -E bash install.sh https://cp.example.com --token=enr_xxx --name=hk-edge-1
```

装完刷新面板就能看到节点上线：状态、最后心跳、公网 IP、配置版本都会自动出现。

## 3. 配置下发

### 3.1 两套版本，别混

| | 配置版本文件库 | 节点配置版本 |
|---|---|---|
| 表 | `config_files` | `node_configs` |
| 页面 | **配置管理 → 版本管理**（`/admin/config-files`） | **配置管理 → 配置版本**（节点 → 配置版本） |
| 编号 | 全库唯一（v1、v2…） | 每台节点内自增 |
| 有没有节点 | **没有**，与节点无关 | 有（`node_id`） |
| 默认指针 | `config_files.is_default`（全库唯一） | `node_configs.is_current`（每节点唯一） |

节点守护进程默认按**版本文件库的默认版本**收敛（`control.config_source=default`），所以「改一次默认版本 = 全机群跟上」；想按某台节点自己发布的版本收敛，把 agent 的 `config_source` 设成 `node`。

### 3.2 面板操作

* **版本管理页**：顶部「**当前默认版本**」下拉 + **保存**（只挪指针，不新建版本，审计 `config_file.default_set`，需要 `publish configs` 权限）；右上角「创建版本」（正文 + 备注 + 是否设为默认）；行操作「查看正文 / 编辑正文」（重算 sha256 + 审计）；列 = 版本（绿=默认版本）｜备注｜`config_sha256`（可复制可搜索）｜发布时间｜最后修改时间。
* **节点 → 配置版本**：新建时可「**从版本文件载入**」把库里正文填进来（仍可改）；行操作「查看正文 / 编辑正文 / 与上一版 diff」；「**设为默认下发**」把 `is_current` 挪过去（不新建版本）；「**回滚到此版本**」以旧正文生成**新版本**（历史追加）。
* **命令行等价**：

```bash
curl -H "Authorization: Bearer $TOKEN" .../nodes/{node_id}/configs              # 列表（看 is_current）
curl -X PATCH -H "Authorization: Bearer $TOKEN" -d '{"content_yaml":"..."}' \
     .../nodes/{node_id}/configs/3                                            # 就地改第 3 版正文
curl -X POST -H "Authorization: Bearer $TOKEN" -d '{"reason":"就下这版"}' \
     .../nodes/{node_id}/configs/3/activate                                   # 指定默认下发第 3 版
```

### 3.3 判定一律按 sha256

`GET …/config/latest?current_version=N&current_sha256=X`（`…/config/default` 同款）：**版本号与内容哈希都对上**才回 `up_to_date: true`。
只比版本号会漏掉「运维就地把某个版本的正文改了」（版本号没变、内容变了），节点会永远拿不到新内容 —— 这是本项目的硬约束；agent 侧同样按 sha256 判定。

### 3.4 失败自动回滚 + 三道护栏

节点回执 `{"status":"failed","error":"…"}` 时：

1. 复制**上一个 applied 版本**的正文，生成**新版本**（历史追加，可追溯）；
2. 节点置 `error`，写 `node_config_history` 与 `audit_logs`；
3. **护栏**（三种情况一律跳过回滚，审计 `config.auto_rollback_skipped`）：
   * 最新版本已经是回滚目标的内容（sha256 相同）；
   * 同一节点 10 分钟内已自动回滚 ≥3 次；
   * 回滚目标与失败版本 sha256 相同。

**为什么要护栏**：失败往往来自**节点环境**（端口被占、证书路径不对），此时每轮回滚只是新增一个版本号 —— 5 秒一版会把版本历史冲垮（真实现场：某节点涨到 v460）。护栏把这类情况停在原地，错误留在面板上让人去修，而不是制造版本风暴。

### 3.5 节点列表的「配置版本」= 节点磁盘上的那一版

不信节点自报的版本号，按 `config_sha256` 反查（`App\Support\NodeConfigVersion::resolve()`）：

| 顺序 | 命中来源 | 悬停里写 |
|---|---|---|
| ① | 版本文件库 `config_files` 里 sha 相同 | 按版本管理认定 |
| ② | 该节点的 `node_configs` 历史里 sha 相同 | 按本节点历史认定 |
| ③ | 都未命中 → 退回自报版本号 | 标明「sha256 未命中」 |
| — | 从未上报 →「未上报」；上报了但谁都不匹配 →「未识别」 | — |

配色：与默认版本一致 = 绿；不一致 = 黄（待同步）；sha 对不上任何版本 = 红「未识别」（被手工改过）；未上报 = 灰。悬停给出实盘路径、sha256 与认定层级。

### 3.6 模板渲染

`POST …/configs` 的 `content_yaml` 留空时用**模板渲染**（`HysteriaConfigBuilder::render()`；模板可在「面板设置」改，或用 `HYSTERIA_CONFIG_TEMPLATE_JSON` 覆盖）：

* 占位符：`{{auth_url}}`、`{{node_domain}}`、节点实盘证书/私钥路径（agent 上报过就用上报值）、`{{stats_listen}}`、`{{stats_secret}}`、`{{obfs_password}}`；
* `overrides` 只覆盖部分键（深合并，其余沿用模板）；
* 渲染结果必须能解析成 YAML，否则 `422 invalid_config`。

> 节点上报过实盘路径/监听地址时，模板优先采用**实盘值** —— 避免「面板写 `/etc/hysteria/cert.pem`、节点实际是 `/etc/hysteria/certs/server.crt`」这类错配把节点配置搞挂。

## 4. API

机器可读：`/docs/api.json`。完整字段与 curl 见 [docs/API.md](docs/API.md)。

### 4.1 四个面

| 面 | 前缀 | 认证 | 谁用 |
|---|---|---|---|
| Agent API | `/api/agent/v1/*` | **HMAC-SHA256**（4 个头） | `hysteria-agent` / 桥接器 |
| Agent 契约面 | `/api/v1/agent/*` | Bearer 自签 JWT / `?key=node_secret` | `hysteria-agent` 原生契约 |
| 管理 API | `/api/admin/v1/*` | Sanctum Bearer | 面板外的运维/集成 |
| 控制通道 | `/api/v1/agent/control` | JWT | **返回 501 JSON**：二进制 protobuf Envelope 长连接 PHP 终结不了（见 [4.4](#44-控制通道-ws-终结不了怎么办)） |

### 4.2 HMAC 签名（Agent API）

| Header | 说明 |
|---|---|
| `X-Node-Id` | `nd_*` |
| `X-Timestamp` | Unix 秒，偏差 ≤ `AGENT_SIGNATURE_TTL`（默认 300s） |
| `X-Nonce` | 每次唯一，窗口内一次性（Redis `agent:nonce:{node}:{nonce}`） |
| `X-Node-Signature` | hex(HMAC-SHA256(secret, 原文)) |

```
原文 = METHOD \n PATH \n sha256_hex(rawBody) \n X-Timestamp \n X-Nonce
```

* `PATH` **不含查询串**；`rawBody` 是**传输中的字节**（`Content-Encoding: gzip` 时签压缩后字节）；
* `secret` = enroll 响应的 `secret`（`nds_*`）；服务端只存 sha256 + `Crypt` 密文。

失败语义：缺头 → `401 missing_node_id`；未知节点 → `404 unknown_node`；禁用 → `403 node_disabled`；签名错/过期 → `401 bad_signature`；nonce 复用 → `409 replayed_nonce`；体过大 → `413`。
Python 签名示例见 [docs/API.md §1](docs/API.md)。

### 4.3 端点

**Agent（HMAC）**

| Method | Endpoint | 说明 |
|---|---|---|
| POST | `/enroll` | 无需签名，按 IP 限流；`201` 返回 `node_id`/`secret`(一次)/`endpoints`/`websocket`/`signature` |
| GET | `/ping` | 连通性自检 |
| POST | `/heartbeat` | 返回心跳间隔、最新配置版本、`commands.pending`；写库 15s 门控 |
| GET | `/config/latest` | 默认下发版本（`current_version` + `current_sha256` 双比对） |
| POST | `/config/ack` | `applied` / `failed`（失败触自动回滚） |
| POST | `/metrics` | 三种载荷形态，单次 ≤ `AGENT_MAX_METRIC_SAMPLES`(500)；`202 {stored:N}` |
| POST | `/commands/poll` | 取待执行指令（pending→sent，过期标 expired） |
| POST | `/commands/{id}/ack` | 回执（跨节点 404） |
| POST | `/logs` | 日志批量（未知 level 归一化，不整批判错） |
| POST | `/diagnostics` | 整包存 `node_logs(level=diagnostic)` + 镜像到 `nodes.meta.diagnostics` |
| GET | `/upgrade/check` | 按 arch/version/channel + `crc32(node_id)%100 < rollout_percent` 灰度 |
| POST | `/broadcasting/auth` | Reverb 频道授权（只能订阅自己的 `private-node.{node_id}`） |

**Agent 契约面（hysteria-agent 直接可用）**

| Method | Endpoint | 说明 |
|---|---|---|
| POST | `/api/v1/agent/enroll` | 字段与 agent Go struct 逐一对齐（`config_revision` 是**字符串**）；`X-Enroll-Token` 头亦可；同名/machine_id 重复 → `409 node_exist`（带 `replace: true` 则轮换密钥）；采用节点自报 `public_ip` |
| GET | `/api/v1/agent/token` | 自检（Bearer 自签 JWT 或 `?key=`） |
| POST | `/api/v1/agent/heartbeat` | REST 兜底心跳，收养 `config_version`/`config_sha256`/`config_path`/`cert_path`/`key_path` |
| GET | `/api/v1/agent/config/latest` \| `/config/default` | 节点自身版本 / 版本文件库默认版本 |
| POST | `/api/v1/agent/config/ack` | 回执（含 `warnings`）；版本号属于版本文件库时补记 `node_configs`（审计 `config.applied_from_library`） |
| GET/POST | `/api/v1/agent/control` | 501 JSON + 替代方案 |

`jwt_issuer=jysteria-agent` / `jwt_audience=hysteria-control` 必须与 agent `internal/creds` 一致，否则表现为 **WS 握手 401**。

**管理 API（Sanctum）**：`auth/login|me|logout`、`stats`、`nodes`（列表/详情/改/删/`secret/rotate`）、`nodes/{id}/metrics`、`metrics/fleet`、`nodes/{id}/commands`、`commands`、`commands/bulk`、`nodes/{id}/configs`（列表/发布/查看/改/`activate`/`rollback`/`diff`）、`enroll-tokens`（列表/建/删）、`upgrade-packages`（列表/建/改/删）。

### 4.4 控制通道 WS 终结不了怎么办

PHP 不能持有 protobuf 二进制长连接（agent 自己的设计文档也是这个结论）。三种落地：

1. **REST 兜底**：agent 走原生 HMAC 面或 `/api/v1/agent/*`（心跳/配置/指令/指标全覆盖）；控制通道被判 404/405/501 时 agent 自己降级（`control.rest_fallback_seconds` 默认 300s，之后重试 WS）；
2. **Reverb 推送**：面板与第三方走 `private-node.{node_id}`；
3. **分工拓扑（推荐 Go agent）**：Go 控制面独占 `/api/v1/agent/*`（含 WS），本面继续做 UI/审计/管理 API，两者共享同一 MySQL —— 契约见 agent 仓库 `docs/BRIDGE-TO-CONTROL-PLANE.md`。

### 4.5 限流与错误格式

| 限流 | 阈值 |
|---|---|
| `agent` | 1200 次/分/**节点** |
| `admin-api` | 600 次/分/用户 |
| `admin-login` | 10 次/分/IP |
| `agent-enroll` | 20 次/分/IP、600 次/分全局 |

错误统一 `{"error":"<code>","message":"…"}`；校验失败是 Laravel 422；非法 YAML 是 `422 invalid_config`；`/api/*` 的 404/405 也返回 JSON（不再是 HTML 404 页）。

## 5. 面板

### 5.1 导航

| 分组 | 页面 | 路由 |
|---|---|---|
| — | 总览（Dashboard） | `/admin` |
| 节点管理 | 节点 | `/admin/nodes` |
| 节点管理 | 注册令牌 | `/admin/enroll-tokens` |
| 配置管理 | 配置版本（节点配置） | `/admin/node-configs` |
| 配置管理 | **版本管理**（配置版本文件库） | `/admin/config-files` |
| 运维操作 | 指令队列 | `/admin/node-commands` |
| 运维操作 | 升级发布 | `/admin/upgrade-packages` |
| 运维操作 | 节点日志 | `/admin/node-logs` |
| 系统 | 管理员与角色 | `/admin/users` |
| 系统 | 面板设置 | `/admin/manage-settings` |
| 系统 | 审计日志 | `/admin/audit-logs` |

### 5.2 节点列表

列：节点｜**状态**｜**最后心跳**（相对时间 + 颜色：2 分钟内绿 / 10 分钟内黄 / 更久红，悬停给绝对时间）｜区域｜标签｜**公网 IP (IPv4)**｜CPU｜内存｜用户｜**配置版本**（见 [3.5](#35-节点列表的配置版本--节点磁盘上的那一版)）｜通道（`meta.protocol`，如 `bridge/v1`）；「Hysteria 版本」「IPv6 出口」默认隐藏可开。
行操作：查看 / **下发指令** / **发布配置**（YAML + 变更原因）/ 编辑；批量：**批量下发指令** / 删除。

### 5.3 其它页面要点

* **配置版本**：列表带 `sha256`、状态、`is_current`（默认下发）；行操作「查看正文 / 编辑正文 / 与上一版 diff / 设为默认下发 / 回滚到此版本」。
* **指令队列**：类型、状态、下发/送达/回执时间与 `result`；可按状态/类型筛选。
* **升级发布**：版本/arch/url/sha256/**signature**/channel/灰度百分比；停用（`is_active=false`）后不再参与 `upgrade/check`。
* **审计日志**：`action`/`target_type`/`actor`/`ip`/`meta` 可筛选 —— 配置改动（含 `config.auto_rollback_skipped`、`config.applied_from_library`、`config_file.default_set`）都在这里。
* **面板设置**：面板名称/Logo/页脚、节点主域名、主题（preset + 主色 + 亮暗）、心跳间隔、离线判定、指标保留天数、**HTTP 鉴权后端 URL**（`hysteria_auth_url`，模板里的 `{{auth_url}}` 用它）。

### 5.4 实时与主题

* 6 个 Widget：`StatsOverview` / `TrafficChart` / `NodeMap` / `RecentCommands` / `NodeHealthTable` / `NodeDetail`；仪表盘按 `->poll('5s')` 刷新，同时订阅 Echo 频道做即时更新。
* 品牌与配色：`ocean` / `forest` / `sunset` 三套 preset，主色可用 ColorPicker 覆盖，亮/暗/跟随系统；实现见 [docs/THEMING.md](docs/THEMING.md)（CSS 变量是 **RGB 通道**，如 `59, 130, 246`，不是 hex）。

## 6. 权限

权限：`view nodes`、`manage nodes`、`issue commands`、`publish configs`、`manage tokens`、`manage upgrades`、`view audit`、`manage users`。

| 角色 | 权限 |
|---|---|
| `super-admin` | 全部 |
| `admin` | 全部 |
| `operator` | view/manage nodes、issue commands、publish configs、manage tokens、view audit（**不能**发布升级包、不能管用户） |
| `viewer` | view nodes、view audit（只读） |

后台不满足任一角色会直接 **403**；API 越权 403、未认证 401。Policy 共 8 个（Node / NodeConfig / NodeCommand / EnrollToken / UpgradePackage / AuditLog / NodeLog …）。

## 7. 部署与运维

### 7.1 四个进程

| 进程 | 命令 |
|---|---|
| HTTP | nginx + php-fpm（本地调试 `php artisan serve --port=8088`） |
| 队列 | `php artisan horizon` |
| WebSocket | `php artisan reverb:start --host=0.0.0.0 --port=8081` |
| 调度 | `php artisan schedule:work`（或 crontab 每分钟 `schedule:run`） |

模板：`deploy/systemd/hysteria-control-plane-{horizon,reverb,scheduler}.service`、`deploy/supervisor/hysteria-control-plane.conf`、`deploy/nginx/hysteria-control-plane.conf`。

### 7.2 关键环境变量

| 变量 | 默认 | 说明 |
|---|---|---|
| `APP_URL` / `APP_TIMEZONE` / `APP_LOCALE` | — / UTC / `zh_CN` | |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `mysql` | 生产 MySQL 8（`utf8mb4_0900_ai_ci`）；`sqlite` 见 [2.2](#22-sqlite-试用模式无-mysql) |
| `REDIS_*` / `CACHE_PREFIX` / `CACHE_STORE` / `SESSION_DRIVER` / `QUEUE_CONNECTION` / `HORIZON_PREFIX` | redis / `hysteria_cp` | 限流、防重放、队列、会话 |
| `REVERB_APP_ID/KEY/SECRET`、`REVERB_HOST/PORT/SCHEME`、`REVERB_SERVER_*`、`VITE_REVERB_*` | — | 前后端必须一致，否则 WS 连不上 |
| `AGENT_SIGNATURE_TTL` | 300 | 签名时间窗（秒） |
| `AGENT_DEFAULT_HEARTBEAT` / `AGENT_OFFLINE_THRESHOLD` | 30 / 90 | 心跳间隔 / 离线判定（秒） |
| `AGENT_MAX_PAYLOAD_BYTES` | 4194304 | 请求体上限 |
| `AGENT_MAX_METRIC_SAMPLES` / `AGENT_MAX_LOG_ENTRIES` | 500 / 500 | 单次批量上限 |
| `AGENT_COMMAND_POLL_LIMIT` / `AGENT_COMMAND_TTL_MINUTES` | 25 / 30 | 轮询上限 / 指令过期 |
| `AGENT_PUBLIC_URL` / `AGENT_WEBSOCKET_URL` | APP_URL / REVERB_HOST | 下发给节点的地址 |
| `AGENT_NONCE_PREFIX` | `agent:nonce` | 防重放 key 前缀 |
| `HYSTERIA_NODE_DOMAIN` | `example.com` | 模板里的节点主域名 |
| `HYSTERIA_CONFIG_TEMPLATE_JSON` | 空 | 覆盖默认配置模板 |
| `METRICS_RETENTION_DAYS` | 30 | 指标保留（`metrics:prune` 默认取它） |
| `TRUSTED_PROXIES` | `127.0.0.1,::1` | 反代/隧道下的真实 IP（见 [7.4](#74-节点-ip-与可信代理)） |

### 7.3 数据保留、备份、轮换

```bash
php artisan nodes:check-offline [--threshold=90]    # 每分钟（调度已带 withoutOverlapping）
php artisan metrics:prune [--days=30]               # 每天 03:30
php artisan backup:run | backup:list | backup:clean  # spatie/laravel-backup，建议入调度
```

* 审计 `audit_logs` **只增不删**；模型级变更另存 `audits`。
* 节点密钥轮换：面板节点页「轮换密钥」或 `POST /nodes/{id}/secret/rotate`；旧凭证 `revoked_at` 但不立刻失效（`signingSecrets()` 接受未撤销的全部凭证），可自行决定何时收紧。
* ⚠️ **`APP_KEY` 变了 `secret_ciphertext` 就解不开** → 换 key 前先把所有节点密钥轮换一遍。

### 7.4 节点 IP 与可信代理

部署在 Cloudflare Tunnel / nginx 后面时，不配 `TRUSTED_PROXIES` 会让 `$request->ip()` 变成代理地址（同机 cloudflared 就是 `127.0.0.1`），面板与审计全成 127.0.0.1。

* `TRUSTED_PROXIES=127.0.0.1,::1`（默认，覆盖同机隧道）；多跳填上游网段；只有应用无法被直连时才用 `*`（`X-Forwarded-For` 可伪造）；改完重启 php-fpm 生效；
* 取值优先级（`App\Support\PublicIp`）：**agent 自报 `public_ip`**（`meta.ip_source=agent`）→ 代理链推导（`proxy`）→ 观测对端（`peer`）；私网/回环**不会**覆盖已记录的公网地址；
* 面板按 **IPv4** 展示（双栈机器走 IPv6 出口时 agent 强制 tcp4 探测）；IPv6 仍在 `meta.ip_v6`，有默认隐藏列；也可以 `PATCH /nodes/{id}` 手工修正。

### 7.5 规模设计（5000+ 节点）

1. 请求路径不写库：签名校验 + nonce 检查（Redis O(1)），存活刷新按节点 **15s 门控**（测试断言「连续两次心跳只 1 次 UPDATE」）；
2. 指标一条 `INSERT … VALUES (≤500 行)`，聚合交给队列；
3. 指令/配置下发只做「一次插入 + 一次入队」，广播失败不影响主流程；
4. 每节点限流 1200 次/分，单实例覆盖 5s 心跳 + 指标 + 轮询的组合；
5. 索引：`node_metrics(node_id, recorded_at)`、`nodes(status, last_seen_at)`、`node_commands(node_id, status)`、`node_logs(node_id, recorded_at)`；
6. Web 层无状态可水平扩；Horizon / Reverb 各自独立扩；读多写少可上只读副本，时序表可换只写分区表。

## 8. 排错

| 现象 | 排查 |
|---|---|
| Agent 全部 `401 bad_signature` | 时钟漂移（NTP）；签名原文是否含了查询串；body 是否被代理改写；gzip 场景是否签的压缩后字节 |
| Agent `409 replayed_nonce` | agent 侧 nonce 没随机化（每次必须新 UUID） |
| 节点一直 offline | `AGENT_OFFLINE_THRESHOLD`（90s）与 `nodes:check-offline` 是否在跑；15s 写库门控属正常 |
| **面板发配置了，节点磁盘不动** | 看节点回执：`无法解析` → 正文坏了；`已写入但 Hysteria 未就绪` → 起不来（已自动回滚，原因在回执里）。真实现场是 `证书/私钥无法加载: open /etc/hysteria/cert.pem`（面板正文证书路径与节点实盘不一致）→ 改正文路径，或让节点上该路径真实存在 |
| 版本号疯涨 / 一堆同内容版本 | 「失败 → 自动回滚 → 再失败」循环：已加护栏（见 [3.4](#34-失败自动回滚--三道护栏)，审计 `config.auto_rollback_skipped`）；根因通常是节点环境（端口/证书） |
| 节点列表「配置版本」显示**未识别** | 节点上报的 sha256 与库里任何版本都不匹配（被面板之外改过）→ 让节点按面板同步一次，或在面板为这份内容建一版 |
| 「最后心跳」不动 | ① 节点没在心跳：旧 agent 不认识 501，需升级到带 REST 兜底的版本（打 `POST /api/v1/agent/heartbeat`）；② 15s 写库门控 + 列表 5s 轮询，看着顿一下是正常的 |
| 面板样式「少了」 | `npm run build` 没跑（`Vite manifest not found at public/build/manifest.json`）；依赖要 `npm install --include=dev` |
| 登录后 403 | 该用户没有任何面板角色（`canAccessPanel` 直接 403） |
| WebSocket 连不上 | nginx `/app` 是否转发 `Upgrade`；`REVERB_*` 与前端 `VITE_REVERB_*` 是否一致；`reverb:start` 是否在跑 |
| WS 握手 401 | `jwt_issuer`/`jwt_audience` 与 agent `internal/creds` 不一致（agent 只报 bad handshake，很难查） |
| `/docs/api`、`/horizon` 生产暴露 | 给 docs 路由加认证（`config/scramble.php` 的 `middleware`）；`/horizon` 限来源 IP 或加 Basic Auth |
| 节点 IP 全是 127.0.0.1 | 配 `TRUSTED_PROXIES`（见 [7.4](#74-节点-ip-与可信代理)） |

## 9. 测试与验收

```bash
php artisan test                            # MySQL 8（生产一致性）
php artisan test -c phpunit.sqlite.xml      # SQLite 内存库（免 MySQL）
php artisan test --testsuite=Unit           # 纯单元
python3 tests/e2e/e2e_agent_flow.py http://127.0.0.1:8088   # 真实 HTTP 端到端
```

**当前实测**（SQLite 配置）：`183 passed / 866 assertions`；E2E `30 passed / 0 failed`（对 `php artisan serve` 的 8088 跑真 HTTP，含 WebSocket 频道签名校验）。

两套 phpunit 配置只差数据库，其余（array cache、sync queue）一致；`phpunit.xml` 跑 MySQL 用于保证生产一致性（utf8mb4 排序、真实外键、严格类型）。

| 验收项 | 实现 | 验证 |
|---|---|---|
| enroll_token 3 秒内注册并出现在后台 | `NodeService::enroll()` 单事务写入 | `EnrollTest`（含耗时断言）、E2E 实测 0.05s |
| 发布配置节点 5 秒内热重载，失败自动回滚 | `ConfigService::publish()` → `PushConfigToNode` → Reverb；`acknowledge()` 失败回滚（带护栏） | `ConfigDeliveryTest`、E2E v2 失败→v3 回滚 |
| 「重启 Hysteria」指令 2 秒内送达并回执 | `CommandService::issue()` → `DispatchCommand` → `NodeCommandIssued`；REST 轮询兜底 | `CommandTest`（E2E 实测 0.13s） |
| Dashboard 5 秒刷新 | 6 个 Widget（`poll('5s')`）+ Echo | `AdminPanelTest` |
| 主题可切三套配色、亮暗正常 | `theme.css` + `Setting::cssVariables()` + 顶栏切换 | `AdminPanelTest` |
| 敏感操作入审计（actor/ip/meta） | `AuditLogger` + owen-it | `AdminApiTest`、审计日志页用例 |
| 单实例 5000+ 节点心跳 | 15s 门控 + 队列聚合 + 每节点限流 | `AgentAuthTest::test_heartbeat_write_is_throttled_for_scale` |
| 节点列表显示**实盘**配置版本 | `NodeConfigVersion::resolve()`（sha 三级反查） | `NodeConfigVersionTest`（7 用例） |
| 版本文件库与默认版本 | `ConfigFileService` + `ConfigFiles` 页 | `ConfigFileServiceTest`、`ConfigFilesPageTest` |
| 版本风暴护栏 | `ConfigService` 三道护栏 + 审计 | `ConfigVersionManagementTest` |

## 10. 目录结构

```
app/
├── Console/Commands/      nodes:check-offline、metrics:prune
├── Events/                NodeCommandIssued、NodeStatusChanged、ConfigPublished
├── Filament/
│   ├── Pages/             Dashboard、ConfigFiles（版本管理）、ManageSettings
│   ├── Resources/         Node、NodeConfig、NodeCommand、EnrollToken、
│   │                      UpgradePackage、User、AuditLog、NodeLog
│   └── Widgets/           StatsOverview、TrafficChart、NodeMap、RecentCommands、
│                          NodeHealthTable、NodeDetail
├── Http/
│   ├── Controllers/Api/Agent/    enroll·heartbeat·config·metrics·commands·logs·upgrade·diagnostics·broadcasting
│   ├── Controllers/Api/AgentV1/  hysteria-agent 契约面（enroll/token/heartbeat/config/control）
│   ├── Controllers/Api/Admin/    auth·nodes·commands·configs·metrics·enroll-tokens·upgrade-packages·stats
│   ├── Middleware/               AgentAuth（HMAC+防重放+写库门控）、DecodeAgentPayload（gzip）
│   └── Requests/ Resources/
├── Jobs/                  PushConfigToNode、DispatchCommand、AggregateMetrics
├── Models/                12 个模型（Node、ConfigFile、NodeConfig、NodeCredential…）
├── Policies/              8 个 Policy（角色 → 能力）
├── Services/              NodeService、ConfigService、ConfigFileService、CommandService、MetricsService
├── Settings/AppSettings.php
└── Support/               SignatureVerifier、HysteriaConfigBuilder、YamlDiff、NodeConfigVersion、
                           PublicIp、ColorPalette、Setting、AuditLogger、Jwt
database/
├── migrations/            20 个迁移（19 张业务/框架表）
├── settings/              设置项迁移
└── seeders/               RolePermissionSeeder、SettingsSeeder、DemoDataSeeder
deploy/                    systemd ×3、supervisor、nginx
docs/                      API / ARCHITECTURE / OPERATIONS / THEMING
tests/                     Feature（Filament / Agent / Admin）+ tests/e2e/e2e_agent_flow.py
install.sh  phpunit.xml  phpunit.sqlite.xml
```

## 11. 文档索引

| 文档 | 内容 |
|---|---|
| [docs/API.md](docs/API.md) | 两套 API 全量接口、HMAC 签名规范与失败语义、curl / Python 示例 |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | 数据模型、注册/配置/指令时序图、Redis Key 表、5000 节点规模设计 |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | 进程、排障速查、SQLite 差异、备份、密钥轮换、IP/心跳排查 |
| [docs/THEMING.md](docs/THEMING.md) | 品牌主题、三套配色、动态主色、CSS 变量 |
| OpenAPI | `/docs/api`（UI）、`/docs/api.json`（Scramble 从路由 + FormRequest 自动生成） |
