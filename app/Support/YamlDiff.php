<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal line based diff (LCS) used by the config comparison UI.
 */
final class YamlDiff
{
    /**
     * @return array<int, array{type: string, line: string, old: ?int, new: ?int}>
     */
    public static function compare(string $old, string $new): array
    {
        $a = $old === '' ? [] : explode("\n", $old);
        $b = $new === '' ? [] : explode("\n", $new);

        $n = count($a);
        $m = count($b);
        $lcs = [];

        for ($i = 0; $i <= $n; $i++) {
            $lcs[$i] = array_fill(0, $m + 1, 0);
        }

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $rows = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $rows[] = ['type' => 'same', 'line' => $a[$i], 'old' => $i + 1, 'new' => $j + 1];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $rows[] = ['type' => 'removed', 'line' => $a[$i], 'old' => $i + 1, 'new' => null];
                $i++;
            } else {
                $rows[] = ['type' => 'added', 'line' => $b[$j], 'old' => null, 'new' => $j + 1];
                $j++;
            }
        }

        while ($i < $n) {
            $rows[] = ['type' => 'removed', 'line' => $a[$i], 'old' => $i + 1, 'new' => null];
            $i++;
        }

        while ($j < $m) {
            $rows[] = ['type' => 'added', 'line' => $b[$j], 'old' => null, 'new' => $j + 1];
            $j++;
        }

        return $rows;
    }

    /** @return array{added: int, removed: int, same: int, html: string} */
    public static function stats(string $old, string $new): array
    {
        $rows = self::compare($old, $new);
        $html = '';

        foreach ($rows as $row) {
            $prefix = match ($row['type']) {
                'added' => '+',
                'removed' => '-',
                default => ' ',
            };
            $class = match ($row['type']) {
                'added' => 'diff-added',
                'removed' => 'diff-removed',
                default => 'diff-same',
            };
            $html .= sprintf(
                '<div class="%s">%s %s</div>',
                $class,
                $prefix,
                e($row['line'])
            );
        }

        return [
            'added' => count(array_filter($rows, static fn ($r) => $r['type'] === 'added')),
            'removed' => count(array_filter($rows, static fn ($r) => $r['type'] === 'removed')),
            'same' => count(array_filter($rows, static fn ($r) => $r['type'] === 'same')),
            'html' => $html,
        ];
    }
}
