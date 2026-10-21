<?php

namespace App\Services\Preview;

/**
 * Docs style inheritance (NORMAL_TEXT -> named style -> own style) and the
 * mapping of Docs paragraph/text styles to CSS. Collects non-web-safe font
 * families used by text so the page can load them from Google Fonts.
 */
final class StyleResolver
{
    private const WEB_SAFE = ['arial', 'helvetica', 'times new roman', 'courier new', 'georgia', 'verdana', 'trebuchet ms', 'tahoma', 'comic sans ms', 'impact'];

    private const ALIGN = ['START' => 'start', 'CENTER' => 'center', 'END' => 'end', 'JUSTIFIED' => 'justify'];

    /** Docs "single" line spacing relative to font size. */
    private const SINGLE_LINE = 1.15;

    /** @var array<string, array{text: array, paragraph: array}> */
    private array $named = [];

    /** @var array<string, true> */
    private array $fonts = [];

    public function __construct(array $namedStyles)
    {
        foreach ($namedStyles['styles'] ?? [] as $style) {
            $this->named[(string) ($style['namedStyleType'] ?? '')] = [
                'text' => $style['textStyle'] ?? [],
                'paragraph' => $style['paragraphStyle'] ?? [],
            ];
        }
    }

    public function namedParagraph(string $type): array
    {
        return array_replace($this->named['NORMAL_TEXT']['paragraph'] ?? [], $type === 'NORMAL_TEXT' ? [] : ($this->named[$type]['paragraph'] ?? []));
    }

    public function namedText(string $type): array
    {
        return array_replace($this->named['NORMAL_TEXT']['text'] ?? [], $type === 'NORMAL_TEXT' ? [] : ($this->named[$type]['text'] ?? []));
    }

    public static function paragraphCss(array $s): string
    {
        $css = '';
        if (isset(self::ALIGN[$s['alignment'] ?? ''])) {
            $css .= 'text-align:'.self::ALIGN[$s['alignment']].';';
        }
        if (($s['direction'] ?? '') === 'RIGHT_TO_LEFT') {
            $css .= 'direction:rtl;';
        }
        if (isset($s['lineSpacing'])) {
            $css .= 'line-height:'.self::num($s['lineSpacing'] / 100 * self::SINGLE_LINE).';';
        }
        foreach (['spaceAbove' => 'margin-top', 'spaceBelow' => 'margin-bottom', 'indentStart' => 'margin-inline-start', 'indentEnd' => 'margin-inline-end'] as $key => $prop) {
            if (array_key_exists($key, $s)) {
                $css .= $prop.':'.self::num(self::pt($s[$key]) ?? 0).'pt;';
            }
        }
        if (array_key_exists('indentFirstLine', $s)) {
            $css .= 'text-indent:'.self::num((self::pt($s['indentFirstLine']) ?? 0) - (self::pt($s['indentStart'] ?? null) ?? 0)).'pt;';
        }
        if ($bg = self::color($s['shading']['backgroundColor'] ?? null)) {
            $css .= 'background-color:'.$bg.';';
        }
        foreach (['Top', 'Bottom', 'Left', 'Right'] as $side) {
            if (isset($s['border'.$side])) {
                $b = $s['border'.$side];
                $css .= 'border-'.strtolower($side).':'.self::border($b).';';
                if (isset($b['padding'])) {
                    $css .= 'padding-'.strtolower($side).':'.self::num(self::pt($b['padding']) ?? 0).'pt;';
                }
            }
        }

        return $css;
    }

    public function textCss(array $s): string
    {
        $css = '';
        if (isset($s['weightedFontFamily']['fontFamily'])) {
            $family = trim((string) preg_replace('/[^\p{L}\p{N} \-]+/u', '', (string) $s['weightedFontFamily']['fontFamily']));
            $family = mb_substr($family, 0, 64);
            if ($family !== '') {
                $css .= "font-family:'".$family."',Arial,sans-serif;";
                if (! in_array(mb_strtolower($family), self::WEB_SAFE, true)) {
                    $this->fonts[$family] = true;
                }
            }
        }
        if (array_key_exists('bold', $s)) {
            $css .= 'font-weight:'.($s['bold'] ? 700 : 400).';';
        } elseif (isset($s['weightedFontFamily']['weight']) && (int) $s['weightedFontFamily']['weight'] !== 400) {
            $css .= 'font-weight:'.(int) $s['weightedFontFamily']['weight'].';';
        }
        if (! empty($s['italic'])) {
            $css .= 'font-style:italic;';
        }
        $deco = array_keys(array_filter(['underline' => ! empty($s['underline']), 'line-through' => ! empty($s['strikethrough'])]));
        if ($deco) {
            $css .= 'text-decoration:'.implode(' ', $deco).';';
        }
        if (isset($s['fontSize'])) {
            $css .= 'font-size:'.self::num(self::pt($s['fontSize']) ?? 11).'pt;';
        }
        if ($c = self::color($s['foregroundColor'] ?? null)) {
            $css .= 'color:'.$c.';';
        }
        if ($c = self::color($s['backgroundColor'] ?? null)) {
            $css .= 'background-color:'.$c.';';
        }
        if (! empty($s['smallCaps'])) {
            $css .= 'font-variant:small-caps;';
        }
        $css .= match ($s['baselineOffset'] ?? '') {
            'SUPERSCRIPT' => 'vertical-align:super;font-size:.75em;',
            'SUBSCRIPT' => 'vertical-align:sub;font-size:.75em;',
            default => '',
        };

        return $css;
    }

    /** @return list<string> */
    public function fonts(): array
    {
        $fonts = array_keys($this->fonts);
        sort($fonts);

        return $fonts;
    }

    /** OptionalColor -> #rrggbb; null when absent or transparent. */
    public static function color(?array $optional): ?string
    {
        if (! isset($optional['color']) || ! is_array($optional['color'])) {
            return null;
        }
        $rgb = $optional['color']['rgbColor'] ?? [];
        $hex = fn ($v) => str_pad(dechex((int) round(max(0, min(1, (float) $v)) * 255)), 2, '0', STR_PAD_LEFT);

        return '#'.$hex($rgb['red'] ?? 0).$hex($rgb['green'] ?? 0).$hex($rgb['blue'] ?? 0);
    }

    /** Dimension (always PT in Docs) -> points; omitted magnitude means 0. */
    public static function pt(?array $dimension): ?float
    {
        return $dimension === null ? null : (float) ($dimension['magnitude'] ?? 0);
    }

    public static function border(?array $border): string
    {
        $width = self::pt($border['width'] ?? null) ?? 0;
        if ($width <= 0) {
            return 'none';
        }
        $dash = match ($border['dashStyle'] ?? '') {
            'DOT' => 'dotted',
            'DASH' => 'dashed',
            default => 'solid',
        };

        return self::num($width).'pt '.$dash.' '.(self::color($border['color'] ?? null) ?? '#000000');
    }

    public static function num(float|int $v): string
    {
        $s = rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        return $s === '-0' || $s === '' ? '0' : $s;
    }
}
