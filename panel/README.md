# Hysteria 服务商后台（Laravel 12 + Filament 3）

节点注册接收端 + 平台/服务商两级后台。配套脚本
[`register-hysteria`](https://github.com/getthelostcode/register-hysteria) 装完 Hysteria2 后会
`POST /new` 上报节点，这里收单、入账、给服务商一个能看节点与流量的面板。

```
register-hysteria/install.sh ──POST /new──▶ 本服务 ──┬─▶ /admin            平台后台（服务商 CRUD / 全局节点 / 平台账号）
        （IP + 识别码 + 端口 + secret）               └─▶ /provider/{code}  服务商后台（只看自己识别码下的节点）
```

## 1. 能力

| 能力 | 位置 |
|---|---|
| 节点注册（按运营商识别码归到服务商，同 IP 幂等） | `POST /new` |
| 节点心跳（存活 / 版本 / 流量增量） | `POST /api/v1/nodes/{id}/heartbeat` |
| 平台后台：服务商、节点、平台账号、看板（在线数 / 今日 / 近 7 天 / 14 天趋势） | `/admin` |
| 服务商后台：我的节点、启停、重置 traffic secret、客户端配置片段、看板 | `/provider/{code}` |
| 多租户隔离：Filament 原生 tenancy（`Provider.code` 作 slug），跨租户 URL 一律 404 | 服务商面板 |

首版只做**节点接入 + 节点/流量看板**；终端用户、套餐、计费不在范围内。

## 2. 快速开始

```bash
composer install
cp .env.example .env && php artisan key:generate

# SQLite（最省事）
touch database/database.sqlite && php artisan migrate --seed

# 或 MySQL 8：
# mysql -uroot -e "CREATE DATABASE hysteria_provider DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
# 改 .env 的 DB_CONNECTION=mysql + DB_* 后 php artisan migrate --seed

php artisan filament:assets        # 发布面板前端资产（缺这步页面样式会 404）
php artisan serve --port=8099
```

| 面板 | 地址 | 演示账号 |
|---|---|---|
| 平台后台 | http://127.0.0.1:8099/admin | `admin@panel.test` / `Admin123456` |
| 服务商后台 | http://127.0.0.1:8099/provider/4f13b9d93a8afb6e98283702 | `provider-a@panel.test` / `Provider123456` |

> 演示账号来自 `PlatformSeeder`，生产请立刻改密码（`/admin/platform-accounts`）。

## 3. 接口

### 3.1 节点注册 `POST /new`

```json
{
  "ip": "103.47.186.35",
  "obfs_password": "4f13b9d93a8afb6e98283702",
  "traffic_port": 10948,
  "traffic_secret": "<48 位 hex>",
  "hostname": "japan2.3ai.best",
  "version": "v2.13.0"
}
```

- `obfs_password` 就是**运营商识别码**：用它匹配 `providers.code`，节点归到该服务商
- 同一服务商下按 `ip` 幂等：重复注册刷新端口/secret，并**重新签发 token**（旧 token 立即失效）
- 响应（新建 201 / 更新 200）：

```json
{"ok":true,"node_id":4,"provider":"4f13b9d93a8afb6e98283702","status":"active",
 "node_token":"nd_xxx","heartbeat_url":"http://<host>/api/v1/nodes/4/heartbeat"}
```

| 错误 | 状态码 | 说明 |
|---|---|---|
| `unknown_code` | 422 | 识别码未登记 |
| `provider_suspended` | 403 | 服务商被停用 |
| `unauthorized` | 401 | 配置了 `REGISTER_SHARED_SECRET` 但没带 `X-Register-Secret` |
| 校验失败 | 422 | 字段缺失/格式错 |

`traffic_secret` 用 Laravel `encrypted` cast 落库，响应不回显；`node_token` 只存 sha256，明文只在注册响应出现一次。

### 3.2 心跳 `POST /api/v1/nodes/{id}/heartbeat`

```
Authorization: Bearer nd_xxx
{"version":"v2.13.1","online_users":7,"tx":1048576,"rx":2097152}
```

`tx`/`rx` 是**增量字节**，累加到当天 `node_traffic_daily`；存活写库按 `HEARTBEAT_WRITE_GATE`（默认 15s）节流，版本变化立即写。

### 3.3 健康检查

`GET /up` → 200（Laravel 内置）

## 4. 数据表

| 表 | 关键字段 | 说明 |
|---|---|---|
| `providers` | `code`(唯一) `name` `status` | 服务商；`code` = 运营商识别码 = 租户 slug |
| `provider_users` | `provider_id` `email` `password` | 服务商后台账号（`provider` guard） |
| `admins` | `email` `password` | 平台后台账号（`admin` guard） |
| `nodes` | `provider_id` `ip` `obfs_password` `traffic_port` `traffic_secret`(加密) `api_token_hash` `version` `status` `last_seen_at` `meta` | 唯一键 `(provider_id, ip)` |
| `node_traffic_daily` | `node_id` `date` `tx_bytes` `rx_bytes` `online_users` | 唯一键 `(node_id, date)`，`date` 存 `Y-m-d` 字符串 |

**在线判定**：`status=active` 且 `last_seen_at` 在 `NODE_ONLINE_TTL`（默认 90s）内。

## 5. 缓存键（跨进程共享状态）

| Key | TTL | 用途 |
|---|---|---|
| `node:online:{node_id}` | `NODE_ONLINE_TTL` | 在线副本，给面板以外的消费方（agent / 告警）快速判断 |
| `node:hb_gate:{node_id}` | `HEARTBEAT_WRITE_GATE` | 心跳写库节流闸门（`Cache::add` 抢锁） |

默认 `CACHE_STORE=database`；换成 `redis` 即落到 Redis（键名不变）。

## 6. 时序

```
install.sh          /new                 DB                      面板
   │  POST 节点信息   │                   │                        │
   ├─────────────────▶ 按 code 找 provider │                        │
   │                  ├──────────────────▶│ upsert nodes           │
   │                  │  签发 node_token   │                        │
   │◀─────────────────┤                   │        /provider/{code}/nodes
   │                  │                   │◀───────────────────────┤ 读节点 + 在线判定
   │                  │                   │                        │
agent 心跳 ──Bearer──▶ /heartbeat ──gate──▶ last_seen_at / 流量累加 ─▶ 面板看板
```

## 7. 节点侧对接

```bash
# 在 register-hysteria 仓库里
./install.sh --code <运营商识别码>                              # 上报到默认 https://hysteria.ipx.sarl/new
REG_API=http://127.0.0.1:8099/new ./install.sh --code <code>    # 指向本服务
```

服务端换域名只改 `REG_API`，脚本侧不用动。注册返回的 `node_token` 需要写进节点侧 agent 才能打心跳
（当前 `register-hysteria` 未保存 token，属已知缺口）。

## 8. 测试

```bash
php artisan test     # 20 用例 / 70 断言（SQLite 内存库，见 phpunit.xml）
```

覆盖：注册入账与幂等、未知识别码/停用服务商、共享密钥、心跳鉴权与流量累加、禁用节点拒绝、
租户隔离（列表不可见 + 跨租户 URL 404）、两个面板登录页与页面渲染、跨面板账号互斥。

## 9. 部署

```bash
DB_CONNECTION=sqlite bash install.sh                          # 或 DB_CONNECTION=mysql DB_PASSWORD=xxx bash install.sh
bash install.sh --no-services                                 # 只装应用，不写 systemd
APP_DIR=/opt/hpp APP_PORT=8099 bash install.sh                # 自定义目录/端口
```

`install.sh` 会：检查 PHP(≥8.2) 与扩展 → 装 composer（缺 `php` 命令时先补 `/usr/local/bin/php` 别名；composer 存在但跑不起来时只用
`php composer.phar` 显式调用，绝不裸调）→ 复制代码到 `APP_DIR` → 写 `.env`/`APP_KEY` → 建库 → `migrate --seed` →
发布 Filament 资产 → 写 systemd 单元（`artisan serve` 绑 `127.0.0.1:APP_PORT`）→ 验证 `/up`、`/admin/login`、`/provider/login`。可重复执行（种子幂等）。

生产建议把 `artisan serve` 换成 php-fpm + nginx（`root public/`，`try_files $uri /index.php?$query_string`），
反代 / Cloudflare Tunnel 后面要设 `TRUSTED_PROXIES`（默认 `127.0.0.1,::1`），否则节点 IP 会记成代理地址。

## 10. 排错

| 现象 | 原因 / 处理 |
|---|---|
| `composer --version` 无输出、`timeout: failed to run command 'composer'` | 机器上只有 `php8.2` 没有 `php` 命令，而 composer 的 shebang 是 `#!/usr/bin/env php` —— 文件在也执行不了。`install.sh` 现在会自动建 `/usr/local/bin/php` 别名，并改成 `php /usr/local/bin/composer.phar` 显式调用（不再裸调 composer） |
| 面板样式全丢 | 没跑 `php artisan filament:assets` |
| `/new` 返回 422 `unknown_code` | `obfs_password` 与 `providers.code` 不一致；先在 `/admin/providers` 建服务商 |
| 节点注册了但显示离线 | 还没心跳；`last_seen_at` 超 90s 即离线，可调 `NODE_ONLINE_TTL` |
| 服务商登录后 404 | URL 必须带自己的识别码 `/provider/{code}`；跨租户会被 Filament 统一 404 |
| 平台账号进不去服务商面板 | 两个面板用不同 guard，属预期行为 |
| 心跳 401 | token 只在注册响应出现一次；重跑注册会重新签发 |
| 心跳 403 `node_disabled` | 面板里禁用过该节点 |
| 流量不涨 | 心跳 `tx`/`rx` 必须是**增量**字节，不是累计值 |

## 11. 技术栈

Laravel 12.70 · Filament 3.3.57 · PHP 8.2 · MySQL 8 / SQLite · Redis（可选，默认 database cache）

## License

MIT
