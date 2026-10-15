# Hysteria2 节点管理控制平面 (Hysteria2 Control Plane)

Laravel 11 + Filament v3 构建的 Hysteria2 **节点管理控制平面**：为 `hysteria-agent`
节点提供注册、配置下发、指令推送、指标/日志接收 API，并给出一个带自定义品牌主题、
实时仪表盘的运维后台。

```
┌──────────────────────────────────────────────────────────────────────────┐
│                       Laravel Control Plane                              │
│                                                                          │
│  ┌──────────────┐   ┌────────────────┐   ┌──────────────────────────┐    │
│  │ REST API     │   │ Reverb / WS    │   │ Filament Admin (v3)      │    │
│  │ /api/agent/* │   │ private-node.* │   │ 自定义品牌主题 + 5s 轮询   │    │
│  │ HMAC-SHA256  │   │ private-fleet  │   │ + Echo 实时推送           │    │
│  └──────┬───────┘   └───────┬────────┘   └────────────┬─────────────┘    │
│         │                   │                         │                  │
│  ┌──────▼───────────────────▼─────────────────────────▼─────────────┐    │
│  │ Service Layer: NodeService / ConfigService / CommandService /     │    │
│  │                MetricsService / HysteriaConfigBuilder / Audit     │    │
│  └──────┬───────────────────┬─────────────────────────┬─────────────┘    │
│         │                   │                         │                  │
│  ┌──────▼──────┐    ┌───────▼───────┐        ┌────────▼──────────┐       │
│  │ MySQL 8     │    │ Redis         │        │ Queue (Horizon)   │       │
│  │ 19 张表     │    │ 限流/防重放/缓存│        │ 配置推送/指令/聚合 │       │
│  └─────────────┘    └───────────────┘        └───────────────────┘       │
└──────────────────────────────────────────────────────────────────────────┘
```

## 配置版本管理（面板）

**配置管理 → 版本管理**（`/admin/config-versions`）：所有节点、所有版本的总表。

* 右上角**「创建版本」**：选节点 → 填正文（留空用面板模板渲染）→ 勾「设为默认下发版本」→ 变更原因；
* **「默认版本」列是可点的开关**：打开即把该版设为该节点的默认下发版本（走 `ConfigService::setCurrent()`，
  节点下次同步/轮询即取走）；关掉会提示「必须有且只有一版默认」并保持原值。无发布权限的角色开关禁用；
* **时间列**：发布时间、**修改时间**（`updated_at`）、生效时间（悬停看精确时间），另有可切换的创建时间列；
* 筛选：按节点、按状态、以及「只看默认下发版本 / 只看非默认版本」；
* 行操作：查看正文 / 编辑正文 / 设为默认下发 / 回滚到此版本 / 对比。

以下是各处的细节：

节点 → 配置版本，每一版都可以：

* **查看正文**：行操作「查看正文」/ 点进详情，直接看 YAML 与 sha256；
* **修改正文**：行操作「编辑正文」就地改（sha256 会重算，写历史与审计）。改的是「默认下发」那一版时，
  节点下一次同步/轮询就会取走新内容（agent 会带上本机配置的 sha256，所以版本号没变也认得出来）；
* **指定默认下发哪一版**：任意版本行操作「设为默认下发」——不新生成版本，只是把下发指针挪过去，
  节点下次同步即生效（想保留历史、追加新版本则用「回滚到此版本」）；
* **发布新版本**：右上角「新建」。新发布的版本会自动成为默认下发的那一版。

命令行等价操作：

```bash
curl -H "Authorization: Bearer $TOKEN" .../nodes/{node_id}/configs            # 列表（看 is_current）
curl -H "Authorization: Bearer $TOKEN" .../nodes/{node_id}/configs/3          # 看第 3 版正文
curl -X PATCH -H "Authorization: Bearer $TOKEN" -d '{"content_yaml":"..."}' \
     .../nodes/{node_id}/configs/3                                           # 改第 3 版正文
curl -X POST -H "Authorization: Bearer $TOKEN" -d '{"reason":"就下这版"}' \
     .../nodes/{node_id}/configs/3/activate                                  # 指定下发第 3 版
```

## 快速开始

```bash
git clone https://github.com/getthelostcode/hysteria-control-plane.git
cd hysteria-control-plane
sudo bash install.sh                 # 依赖检查 + 建库 + 迁移 + 种子 + 构建主题 + systemd
php artisan serve --port=8088        # 本地快速体验
```

安装脚本会打印面板地址、API 文档地址和默认账号。常用参数：

| 参数 | 说明 |
|---|---|
| `--no-seed` | 不写入演示机群数据 |
| `--no-services` | 不安装/启动 systemd 单元 |
| `--upgrade` | 升级已有部署（composer 不带 dev、迁移、重新构建前端） |

环境变量：`DB_HOST DB_PORT DB_USER DB_PASSWORD DB_NAME APP_URL ADMIN_EMAIL ADMIN_PASSWORD SERVICE_USER`。

默认入口：

| 入口 | 地址 |
|---|---|
| 管理后台 | `/admin`（默认 `admin@example.com` / `password`） |
| OpenAPI UI | `/docs/api`（机器可读：`/docs/api.json`） |
| Agent API | `/api/agent/v1/*`（原生） / `/api/v1/agent/*`（hysteria-agent 契约兼容面） |
| 管理 API | `/api/admin/v1/*`（Sanctum Bearer） |
| WebSocket | `wss://<host>/app/<REVERB_APP_KEY>` |

## SQLite 试用模式（无需 MySQL）

不想装 MySQL 也能跑通全部功能：迁移/种子/接口/面板主题都已在 SQLite 上验证。

```bash
# 1) 测试：内存 SQLite，89 用例 / 360 断言
php artisan test -c phpunit.sqlite.xml

# 2) 整套环境：SQLite 文件库 + Redis
DB_CONNECTION=sqlite bash install.sh          # 生成 database/database.sqlite
php artisan serve --port=8097                 # 面板 /admin，文档 /docs/api
```

* `phpunit.sqlite.xml` 只切数据库，其余（array cache、sync queue）与 `phpunit.xml` 一致；
  默认 `phpunit.xml` 仍跑 MySQL，用于保证生产一致性（utf8mb4 排序规则、真实外键、严格类型）。
* SQLite 下 `json` 列落成 TEXT、没有 MySQL 的长度/精度校验，**上线前请在 MySQL 8 上再跑一遍**
  `php artisan test`（当前两套配置都是全绿）。

## 验收标准对照

| 验收项 | 实现 | 验证方式 |
|---|---|---|
| enroll_token 3 秒内注册并出现在后台 | `EnrollController` + `NodeService::enroll()` 单事务写入 | `tests/Feature/Agent/EnrollTest`（含耗时断言）、`tests/e2e/e2e_agent_flow.py`（实测 0.05s） |
| 发布配置节点 5 秒内热重载，失败自动回滚 | `ConfigService::publish()` → `PushConfigToNode` job → Reverb 推送；`acknowledge()` 失败时自动回滚 | `ConfigDeliveryTest::test_failed_apply_triggers_automatic_rollback`、E2E v2 失败→v3 回滚 |
| “重启 Hysteria” 指令 2 秒内送达并回执 | `CommandService::issue()` → `DispatchCommand` → `NodeCommandIssued`；REST 轮询兜底 `commands/poll` | `CommandTest::test_command_round_trip_in_under_two_seconds`（E2E 实测 0.13s） |
| Dashboard 地图/图表/状态 5 秒刷新 | `StatsOverviewWidget` / `TrafficChartWidget` / `NodeMapWidget` / `RecentCommandsWidget` / `NodeHealthTable`（`$pollingInterval='5s'`）+ Echo 推送 | `AdminPanelTest::test_dashboard_renders_the_key_widgets_with_initial_data` |
| 主题可切换 ocean/forest/sunset 并即时生效，亮暗正常 | `resources/css/filament/admin/theme.css` + `Setting::cssVariables()` + 顶栏切换器 | `AdminPanelTest::test_settings_page_persists_brand_and_theme_preset` |
| 敏感操作写入 audit_logs（actor/ip/meta） | `App\Support\AuditLogger` + owen-it/laravel-auditing | `AdminApiTest::test_sensitive_operations_are_all_audited_with_actor_and_ip` |
| 单实例 5000+ 节点心跳 | 心跳写库按节点 15s 门控；指标聚合走队列；每节点独立限流 | `AgentAuthTest::test_heartbeat_write_is_throttled_for_scale` |
| 完整 OpenAPI 文档 + Feature 测试 | Scramble 自动生成（31 条路径）+ 89 个测试 / 360 断言 | `/docs/api.json`、`php artisan test` |

## 技术栈

| 模块 | 选型 |
|---|---|
| 框架 | Laravel 11.57（PHP ≥ 8.2；本项目在 PHP 8.2 上开发并验证） |
| 后台 | Filament v3.3 + 自定义 Vite 主题 |
| 认证 | Agent: HMAC-SHA256 中间件；后台/管理 API: Sanctum + Filament 会话 |
| 实时 | Laravel Reverb + Laravel Echo（`private-node.{node_id}` / `private-fleet`） |
| 队列 | Redis + Horizon |
| 数据库 | MySQL 8（`utf8mb4_0900_ai_ci`），测试库独立 |
| 权限 | spatie/laravel-permission（super-admin / admin / operator / viewer） |
| 设置 | spatie/laravel-settings（`App\Settings\AppSettings`） |
| 审计 | `audit_logs`（业务审计）+ owen-it/laravel-auditing（模型级） |
| 备份 | spatie/laravel-backup |
| 文档 | dedoc/scramble（OpenAPI 3.1，自动从路由 + FormRequest 生成） |

## 目录结构

```
app/
├── Console/Commands/      CheckOfflineNodes, PruneMetrics
├── Events/                NodeCommandIssued, NodeStatusChanged, ConfigPublished
├── Filament/
│   ├── Pages/             Dashboard, ManageSettings
│   ├── Resources/         Node / NodeConfig / NodeCommand / EnrollToken /
│   │                      UpgradePackage / User / AuditLog / NodeLog
│   └── Widgets/           StatsOverview, TrafficChart, NodeMap, RecentCommands,
│                          NodeHealthTable, NodeDetail
├── Http/
│   ├── Controllers/Api/Agent/   enroll·heartbeat·config·metrics·commands·logs·upgrade·diagnostics·ws-auth
│   ├── Controllers/Api/Admin/   auth·nodes·commands·configs·metrics·enroll-tokens·upgrade-packages·stats
│   ├── Middleware/AgentAuth.php           HMAC 校验 + 防重放 + 写库门控
│   ├── Middleware/DecodeAgentPayload.php  gzip 解包
│   ├── Requests/                          FormRequest 校验
│   └── Resources/                         API JSON 资源
├── Jobs/                  PushConfigToNode, DispatchCommand, AggregateMetrics
├── Models/                11 个 Eloquent 模型
├── Policies/              8 个 Policy（角色 → 能力）
├── Services/              NodeService, ConfigService, CommandService, MetricsService
├── Settings/AppSettings.php
└── Support/               SignatureVerifier, HysteriaConfigBuilder, YamlDiff,
                           ColorPalette, Setting, AuditLogger
```

## 文档

| 文档 | 内容 |
|---|---|
| [docs/API.md](docs/API.md) | Agent / Admin API 全量接口、签名算法、curl/示例 |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | 时序图、Redis Key 表、数据模型、规模设计 |
| [docs/THEMING.md](docs/THEMING.md) | 品牌主题、三套配色、动态主色、CSS 变量 |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | 部署、队列、备份、审计、排障 |

## 测试

```bash
php artisan test                            # MySQL 8（生产一致性）：89 tests / 360 assertions
php artisan test -c phpunit.sqlite.xml      # SQLite 内存库（免 MySQL）：89 tests / 360 assertions
php artisan test --testsuite=Unit           # 纯单元测试
python3 tests/e2e/e2e_agent_flow.py http://127.0.0.1:8088   # 真实 HTTP 端到端，30 项断言
```

E2E 脚本需要与 `php artisan serve` 配合运行，并读取 `REVERB_APP_SECRET` / `REVERB_APP_KEY`
校验 WebSocket 频道签名是否正确。
