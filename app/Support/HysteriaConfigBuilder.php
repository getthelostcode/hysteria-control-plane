<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Node;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders a Hysteria2 server config for a node from the panel template.
 *
 * The template is plain YAML in which the placeholders below are substituted.
 * {{node_id}} {{node_name}} {{region}} {{node_domain}} {{listen_port}}
 * {{stats_secret}} {{obfs_password}} {{cert_path}} {{key_path}} {{masquerade}}
 */
final class HysteriaConfigBuilder
{
    public const DEFAULT_TEMPLATE = <<<'YAML'
    listen: :{{listen_port}}

    tls:
      cert: {{cert_path}}
      key: {{key_path}}

    obfs:
      type: salamander
      salamander:
        password: {{obfs_password}}

    auth:
      type: userpass
      userpass:

    trafficStats:
      listen: 127.0.0.1:9999
      secret: {{stats_secret}}

    masquerade:
      type: file
      file:
        dir: {{masquerade}}

    bandwidth:
      up: 1 gbps
      down: 1 gbps

    quic:
      initStreamReceiveWindow: 8388608
      maxStreamReceiveWindow: 8388608
      initConnReceiveWindow: 20971520
      maxConnReceiveWindow: 20971520
      maxIdleTimeout: 30s
      keepAlivePeriod: 10s
    YAML;

    /** @param array<string, mixed> $context */
    public static function render(Node $node, array $context = []): string
    {
        $template = (string) Setting::get(
            'default_config_template',
            self::DEFAULT_TEMPLATE,
        );

        if (trim($template) === '') {
            $template = self::DEFAULT_TEMPLATE;
        }

        $replacements = array_merge([
            '{{node_id}}' => $node->node_id,
            '{{node_name}}' => $node->name,
            '{{region}}' => (string) $node->region,
            '{{node_domain}}' => (string) Setting::get('node_domain', 'example.com'),
            '{{listen_port}}' => '443',
            '{{stats_secret}}' => $node->statsSecret(),
            '{{obfs_password}}' => $node->obfsPassword(),
            '{{cert_path}}' => '/etc/hysteria/cert.pem',
            '{{key_path}}' => '/etc/hysteria/key.pem',
            '{{masquerade}}' => '/var/www/hysteria',
        ], $context);

        $rendered = str_replace(array_keys($replacements), array_map(
            static fn ($v) => is_scalar($v) ? (string) $v : '',
            array_values($replacements)
        ), $template);

        // Normalise: the rendered config must always be valid YAML.
        $parsed = Yaml::parse($rendered);

        return Yaml::dump(is_array($parsed) ? $parsed : [], 6, 2);
    }

    /** @param array<string, mixed> $overrides */
    public static function renderWithOverrides(Node $node, array $overrides): string
    {
        $base = Yaml::parse(self::render($node));

        return Yaml::dump(self::merge($base, $overrides), 6, 2);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function merge(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (is_array($value) && isset($a[$key]) && is_array($a[$key])) {
                $a[$key] = self::merge($a[$key], $value);
                continue;
            }

            $a[$key] = $value;
        }

        return $a;
    }
}
