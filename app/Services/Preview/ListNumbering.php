<?php

namespace App\Services\Preview;

/**
 * List markers as Docs shows them. Counters are per list id (they continue
 * across other lists in between) and reset for deeper levels when a
 * shallower item appears. Call marker() once per bullet paragraph, in order.
 */
final class ListNumbering
{
    private const BULLET = '●';

    /** @var array<string, array<int, int>> */
    private array $counters = [];

    public function __construct(private readonly array $lists) {}

    public function level(string $listId, int $level): array
    {
        return $this->lists[$listId]['listProperties']['nestingLevels'][$level] ?? [];
    }

    public function marker(string $listId, int $level): string
    {
        $counters = $this->counters[$listId] ?? [];
        $counters[$level] = ($counters[$level] ?? 0) + 1;
        foreach (array_keys($counters) as $deeper) {
            if ($deeper > $level) {
                unset($counters[$deeper]);
            }
        }
        $this->counters[$listId] = $counters;

        $spec = $this->level($listId, $level);
        if (isset($spec['glyphSymbol']) && $spec['glyphSymbol'] !== '') {
            return (string) $spec['glyphSymbol'];
        }
        $type = $spec['glyphType'] ?? 'GLYPH_TYPE_UNSPECIFIED';
        if ($type === 'GLYPH_TYPE_UNSPECIFIED' || $type === 'NONE') {
            return $type === 'NONE' ? '' : self::BULLET;
        }

        $format = (string) ($spec['glyphFormat'] ?? '%'.$level.'.');

        return (string) preg_replace_callback('/%(\d)/', function (array $m) use ($listId, $counters): string {
            $l = (int) $m[1];
            $value = $counters[$l] ?? 1;

            return self::glyph($this->level($listId, $l)['glyphType'] ?? 'DECIMAL', $value);
        }, $format);
    }

    public static function roman(int $n): string
    {
        $map = ['m' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400, 'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40, 'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1];
        $out = '';
        foreach ($map as $sym => $val) {
            while ($n >= $val) {
                $out .= $sym;
                $n -= $val;
            }
        }

        return $out;
    }

    private static function glyph(string $type, int $n): string
    {
        return match ($type) {
            'ALPHA' => self::alpha($n),
            'UPPER_ALPHA' => strtoupper(self::alpha($n)),
            'ROMAN' => self::roman($n),
            'UPPER_ROMAN' => strtoupper(self::roman($n)),
            'ZERO_DECIMAL' => str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            default => (string) $n,
        };
    }

    private static function alpha(int $n): string
    {
        $out = '';
        while ($n > 0) {
            $n--;
            $out = chr(97 + $n % 26).$out;
            $n = intdiv($n, 26);
        }

        return $out;
    }
}
