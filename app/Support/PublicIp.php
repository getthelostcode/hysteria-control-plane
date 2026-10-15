<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * 节点的「公网地址」判定与取值。
 *
 * 背景（真实故障）：控制面部署在 Cloudflare Tunnel 后面，cloudflared 就在同一台机器上，
 * 而项目没有配置 TrustProxies —— 于是 `$request->ip()` 永远是 127.0.0.1，面板里所有节点
 * 的 IP 列都显示 127.0.0.1。除了难看，它也直接毁掉了「按 IP 排障」这个用途。
 *
 * 取值优先级（见 resolve()）：agent 自报 > 代理链推导 > 观测到的对端地址。
 * agent 自报之所以排第一，是因为节点常经 NAT/隧道接入（桥接模式下 Laravel 只看到 Go 控制面
 * 的 127.0.0.1），只有节点自己知道出口公网地址。
 */
final class PublicIp
{
    /**
     * 是否「可用于展示的公网地址」。
     *
     * 只排除真正会造成误判的：私网（10/8、172.16/12、192.168/16、fc00::/7）、回环（127/8、::1）、
     * 链路本地（169.254/16、fe80::/10）、未指定地址与多播。
     * 特意**不**排除文档网段（192.0.2.0/24、198.51.100.0/24、203.0.113.0/24）：它们在测试与
     * 内部环境里被当作示例公网地址使用，排除掉会让「agent 上报的公网 IP」被无声丢弃。
     */
    public static function isPublic(?string $ip): bool
    {
        $ip = is_string($ip) ? trim($ip) : '';

        if ($ip === '') {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        // 回环 / 未指定 / 多播 / 链路本地（按二进制前缀判断，v4 与 v6 统一处理）
        $firstByte = ord($packed[0]);

        if ($firstByte === 127 || $firstByte === 0) {          // 127/8、0.0.0.0
            return false;
        }

        if ($firstByte >= 224) {                                // 224/4 多播、240/4 保留
            return false;
        }

        if (strlen($packed) === 4 && $packed[0] === chr(169) && $packed[1] === chr(254)) {
            return false;                                        // 169.254.0.0/16 链路本地
        }

        if (strlen($packed) === 16 && ($packed[0] === chr(0xfe) && (ord($packed[1]) & 0xc0) === 0x80)) {
            return false;                                        // fe80::/10
        }

        if (strlen($packed) === 16 && $packed === inet_pton('::1')) {
            return false;
        }

        return true;
    }

    /**
     * 从请求推导客户端公网地址。
     *
     * X-Forwarded-For 由 Laravel 的 TrustProxies 中间件在「对端是可信代理」时才解析，
     * 所以 `$request->ip()` 是安全的首选。CF-Connecting-IP 不经过 TrustProxies，
     * 只在请求确实来自可信代理时才采信，否则任何人都能伪造它。
     */
    public static function fromRequest(Request $request): ?string
    {
        $ip = (string) $request->ip();

        if (self::isPublic($ip)) {
            return $ip;
        }

        // CF-Connecting-IP 不经过 Laravel 的 TrustProxies 解析，任何人都能伪造；
        // 只有当 TCP 对端确实在我们配置的可信代理列表里时才采信。
        if (self::isFromTrustedProxy($request)) {
            $cloudflare = trim((string) $request->header('CF-Connecting-IP', ''));

            if (self::isPublic($cloudflare)) {
                return $cloudflare;
            }
        }

        return null;
    }

    /** 对端是否在 config('agent.trusted_proxies') 里（支持单个 IP、CIDR、以及 '*'）。 */
    public static function isFromTrustedProxy(Request $request): bool
    {
        $peer = (string) $request->server->get('REMOTE_ADDR', '');

        if ($peer === '') {
            return false;
        }

        $configured = config('agent.trusted_proxies', '');
        $proxies = is_array($configured) ? $configured : explode(',', (string) $configured);

        foreach ($proxies as $proxy) {
            $proxy = trim($proxy);

            if ($proxy === '') {
                continue;
            }

            if ($proxy === '*') {
                return true;
            }

            if (str_contains($proxy, '/')) {
                if (IpUtils::checkIp($peer, [$proxy])) {
                    return true;
                }

                continue;
            }

            if ($peer === $proxy) {
                return true;
            }
        }

        return false;
    }

    /**
     * 决定节点要显示的地址。
     *
     * @return array{ip: ?string, source: string} source ∈ agent|proxy|peer
     */
    public static function resolve(?string $reported, ?string $observed): array
    {
        $reported = self::isPublic($reported) ? $reported : null;

        if ($reported !== null) {
            return ['ip' => $reported, 'source' => 'agent'];
        }

        if (self::isPublic($observed)) {
            return ['ip' => $observed, 'source' => 'proxy'];
        }

        // 都不像公网地址（私网/回环/空）：仍然记下来，但标记来源，便于面板区分
        // 「这是节点自报的公网 IP」还是「这只是我们看到的对端地址」。
        return ['ip' => $observed, 'source' => 'peer'];
    }
}
