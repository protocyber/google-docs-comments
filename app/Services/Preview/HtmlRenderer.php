<?php

namespace App\Services\Preview;

/**
 * Renders one Docs tab as HTML whose text maps 1:1 onto Docs indexes.
 *
 * Only <span data-s data-e> elements hold document text (DOM offset + data-s
 * is the index; JS strings and Docs both count UTF-16 units). Everything else
 * that shows up as text (list markers, empty-line fillers, floating images)
 * carries data-deco and is ignored by the selection mapping. Runs are cut at
 * comment boundaries so each piece belongs to a fixed set of comments.
 */
final class HtmlRenderer
{
    private const TAGS = [
        'TITLE' => 'h1', 'SUBTITLE' => 'h2', 'HEADING_1' => 'h1', 'HEADING_2' => 'h2',
        'HEADING_3' => 'h3', 'HEADING_4' => 'h4', 'HEADING_5' => 'h5', 'HEADING_6' => 'h6',
    ];

    private const NAMED = ['NORMAL_TEXT', 'TITLE', 'SUBTITLE', 'HEADING_1', 'HEADING_2', 'HEADING_3', 'HEADING_4', 'HEADING_5', 'HEADING_6'];

    private StyleResolver $styles;

    private ListNumbering $lists;

    /** @var list<int> */
    private array $cuts;

    public function __construct(private readonly array $tab, private readonly CommentIndex $comments)
    {
        $this->styles = new StyleResolver($tab['namedStyles'] ?? []);
        $this->lists = new ListNumbering($tab['lists'] ?? []);
        $this->cuts = $comments->cuts();
    }

    public function render(): string
    {
        $ds = $this->tab['documentStyle'] ?? [];
        $pt = fn (string $key, float $default) => StyleResolver::num(StyleResolver::pt($ds[$key] ?? null) ?? $default);
        $style = 'width:'.StyleResolver::num(StyleResolver::pt($ds['pageSize']['width'] ?? null) ?? 612).'pt;'
            .'min-height:'.StyleResolver::num(StyleResolver::pt($ds['pageSize']['height'] ?? null) ?? 792).'pt;'
            .'padding:'.$pt('marginTop', 72).'pt '.$pt('marginRight', 72).'pt '.$pt('marginBottom', 72).'pt '.$pt('marginLeft', 72).'pt;';
        if ($bg = StyleResolver::color($ds['background']['color'] ?? null)) {
            $style .= 'background-color:'.$bg.';';
        }

        return '<div class="gd-page" style="'.$style.'">'.$this->stylesheet()
            .$this->content($this->tab['body']['content'] ?? []).'</div>';
    }

    /** @return list<string> */
    public function fonts(): array
    {
        return $this->styles->fonts();
    }

    private function stylesheet(): string
    {
        $css = '.gd-page{'.$this->styles->textCss($this->styles->namedText('NORMAL_TEXT')).'}';
        foreach (self::NAMED as $type) {
            $css .= '.gd-page .gd-n-'.$type.'{'.StyleResolver::paragraphCss($this->styles->namedParagraph($type))
                .$this->styles->textCss($this->styles->namedText($type)).'}';
        }

        return '<style>'.$css.'</style>';
    }

    private function content(array $content): string
    {
        $html = '';
        foreach ($content as $element) {
            if (isset($element['paragraph'])) {
                $html .= $this->paragraph($element['paragraph']);
            } elseif (isset($element['table'])) {
                $html .= $this->table($element['table']);
            } elseif (isset($element['tableOfContents'])) {
                $html .= '<div class="gd-toc">'.$this->content($element['tableOfContents']['content'] ?? []).'</div>';
            }
        }

        return $html;
    }

    private function paragraph(array $p): string
    {
        $own = $p['paragraphStyle'] ?? [];
        $type = (string) ($own['namedStyleType'] ?? 'NORMAL_TEXT');
        $type = in_array($type, self::NAMED, true) ? $type : 'NORMAL_TEXT';
        unset($own['namedStyleType']);

        $marker = '';
        if (isset($p['bullet'])) {
            $listId = (string) ($p['bullet']['listId'] ?? '');
            $level = (int) ($p['bullet']['nestingLevel'] ?? 0);
            $own += array_intersect_key($this->lists->level($listId, $level), array_flip(['indentStart', 'indentFirstLine']));
            $label = $this->lists->marker($listId, $level);
            $gap = (StyleResolver::pt($own['indentStart'] ?? null) ?? 0) - (StyleResolver::pt($own['indentFirstLine'] ?? null) ?? 0);
            $marker = '<span data-deco class="gd-marker" aria-hidden="true" style="'
                .$this->styles->textCss($p['bullet']['textStyle'] ?? [])
                .'min-width:'.StyleResolver::num(max(0, $gap)).'pt;">'.e($label).'</span>';
        }
        if (isset($own['indentStart']) || isset($own['indentFirstLine'])) {
            $named = $this->styles->namedParagraph($type);
            $own['indentStart'] ??= $named['indentStart'] ?? ['magnitude' => 0, 'unit' => 'PT'];
            $own['indentFirstLine'] ??= $named['indentFirstLine'] ?? ['magnitude' => 0, 'unit' => 'PT'];
        }

        $inner = '';
        foreach ($p['elements'] ?? [] as $part) {
            $inner .= $this->element($part);
        }
        if ($inner === '') {
            $inner = '<span data-deco>&#8203;</span>';
        }
        foreach ($p['positionedObjectIds'] ?? [] as $id) {
            $img = $this->image($this->tab['positionedObjects'][$id]['positionedObjectProperties']['embeddedObject'] ?? []);
            if ($img !== null) {
                $inner .= '<span data-deco class="gd-floating" aria-hidden="true">'.$img.'</span>';
            }
        }

        $tag = self::TAGS[$type] ?? 'p';

        return '<'.$tag.' class="gd-n-'.$type.'"'.self::styleAttr(StyleResolver::paragraphCss($own)).'>'
            .$marker.$inner.'</'.$tag.'>';
    }

    private function element(array $part): string
    {
        $s = (int) ($part['startIndex'] ?? 0);
        $e = (int) ($part['endIndex'] ?? $s);

        if (isset($part['textRun'])) {
            return $this->textRun($part['textRun'], $s, $e);
        }

        $class = 'gd-atom';
        $label = match (true) {
            isset($part['inlineObjectElement']) => $this->image(
                $this->tab['inlineObjects'][$part['inlineObjectElement']['inlineObjectId'] ?? '']['inlineObjectProperties']['embeddedObject'] ?? []
            ) ?? '[gambar]',
            isset($part['person']) => e('@'.($part['person']['personProperties']['name'] ?? 'orang')),
            isset($part['richLink']) => e('🔗 '.($part['richLink']['richLinkProperties']['title'] ?? 'link')),
            isset($part['footnoteReference']) => '<sup>'.e((string) ($part['footnoteReference']['footnoteNumber'] ?? '')).'</sup>',
            isset($part['equation']) => '[persamaan]',
            default => null,
        };
        if (isset($part['pageBreak'])) {
            [$class, $label] = ['gd-atom gd-pagebreak', ''];
        } elseif (isset($part['horizontalRule'])) {
            [$class, $label] = ['gd-atom gd-hr', ''];
        }

        return '<span data-s="'.$s.'" data-e="'.$e.'"'.$this->dataAttrs($s).' data-atom="1" class="'.$class.'">'
            .($label ?? '[objek]').'</span>';
    }

    private function textRun(array $run, int $s, int $e): string
    {
        $text = (string) ($run['content'] ?? '');
        if (str_ends_with($text, "\n")) {
            $text = substr($text, 0, -1);
            $e--;
        }
        if ($text === '') {
            return '';
        }

        $classes = array_keys(array_filter([
            'gd-link' => isset($run['textStyle']['link']),
            'gd-ins' => ! empty($run['suggestedInsertionIds']),
            'gd-del' => ! empty($run['suggestedDeletionIds']),
        ]));
        $tail = ($classes ? ' class="'.implode(' ', $classes).'"' : '')
            .self::styleAttr($this->styles->textCss($run['textStyle'] ?? []));

        $html = '';
        foreach ($this->pieces($s, $e) as [$ps, $pe]) {
            $piece = TextIndex::utf16Substr($text, $ps - $s, $pe - $ps);
            $html .= '<span data-s="'.$ps.'" data-e="'.$pe.'"'.$this->dataAttrs($ps).$tail.'>'
                .e(str_replace("\u{000B}", "\n", $piece)).'</span>';
        }

        return $html;
    }

    /** @return list<array{int,int}> [start, end) pieces of [$s, $e) cut at comment boundaries */
    private function pieces(int $s, int $e): array
    {
        $points = [$s];
        foreach ($this->cuts as $c) {
            if ($c > $s && $c < $e) {
                $points[] = $c;
            }
        }
        $points[] = $e;
        $out = [];
        for ($i = 0; $i < count($points) - 1; $i++) {
            $out[] = [$points[$i], $points[$i + 1]];
        }

        return $out;
    }

    private function dataAttrs(int $s): string
    {
        $open = $this->comments->openIdsAt($s);
        $resolved = $this->comments->resolvedIdsAt($s);

        return ($open ? ' data-c="'.e(implode(' ', $open)).'"' : '')
            .($resolved ? ' data-r="'.e(implode(' ', $resolved)).'"' : '');
    }

    private function image(array $embedded): ?string
    {
        $uri = (string) ($embedded['imageProperties']['contentUri'] ?? '');
        if (! str_starts_with($uri, 'https://')) {
            return null;
        }
        $width = StyleResolver::pt($embedded['size']['width'] ?? null);

        return '<img src="'.e($uri).'" alt="" draggable="false"'.($width ? ' style="width:'.StyleResolver::num($width).'pt"' : '').'>';
    }

    private function table(array $table): string
    {
        $html = '<table class="gd-table">';
        $cols = $table['tableStyle']['tableColumnProperties'] ?? [];
        if ($cols) {
            $html .= '<colgroup>';
            foreach ($cols as $col) {
                $w = ($col['widthType'] ?? '') === 'FIXED_WIDTH' ? StyleResolver::pt($col['width'] ?? null) : null;
                $html .= $w ? '<col style="width:'.StyleResolver::num($w).'pt">' : '<col>';
            }
            $html .= '</colgroup>';
        }

        $covered = [];
        foreach ($table['tableRows'] ?? [] as $r => $row) {
            $html .= '<tr>';
            foreach ($row['tableCells'] ?? [] as $c => $cell) {
                if (isset($covered["$r,$c"])) {
                    continue;
                }
                $style = $cell['tableCellStyle'] ?? [];
                $rs = max(1, (int) ($style['rowSpan'] ?? 1));
                $cs = max(1, (int) ($style['columnSpan'] ?? 1));
                for ($i = 0; $i < $rs; $i++) {
                    for ($j = 0; $j < $cs; $j++) {
                        if ($i || $j) {
                            $covered[($r + $i).','.($c + $j)] = true;
                        }
                    }
                }
                $html .= '<td'.($rs > 1 ? ' rowspan="'.$rs.'"' : '').($cs > 1 ? ' colspan="'.$cs.'"' : '')
                    .self::styleAttr(self::cellCss($style)).'>'.$this->content($cell['content'] ?? []).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    private static function cellCss(array $s): string
    {
        $css = '';
        if ($bg = StyleResolver::color($s['backgroundColor'] ?? null)) {
            $css .= 'background-color:'.$bg.';';
        }
        foreach (['Top', 'Bottom', 'Left', 'Right'] as $side) {
            if (isset($s['border'.$side])) {
                $css .= 'border-'.strtolower($side).':'.StyleResolver::border($s['border'.$side]).';';
            }
            if (isset($s['padding'.$side])) {
                $css .= 'padding-'.strtolower($side).':'.StyleResolver::num(StyleResolver::pt($s['padding'.$side]) ?? 0).'pt;';
            }
        }
        $css .= match ($s['contentAlignment'] ?? '') {
            'MIDDLE' => 'vertical-align:middle;',
            'BOTTOM' => 'vertical-align:bottom;',
            default => '',
        };

        return $css;
    }

    /**
     * Not e(): it would turn the quotes of font-family:'X' into &#039; and the
     * tests pin raw CSS. Values are already safe (sanitized font names, hex
     * colours, numbers); only attribute-breaking characters are escaped.
     */
    private static function styleAttr(string $css): string
    {
        return $css === '' ? '' : ' style="'.str_replace(['"', '<', '>'], ['&quot;', '&lt;', '&gt;'], $css).'"';
    }
}
