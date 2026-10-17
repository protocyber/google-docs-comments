<?php

namespace App\Services;

use Google\Service\Docs\Document;
use Google\Service\Docs\ParagraphElement;
use Google\Service\Docs\StructuralElement;

/**
 * Spike: render a Google Doc as selectable HTML whose text maps 1:1 onto
 * Docs API indexes.
 *
 * Every text run becomes <span data-s data-e> holding exactly the run's text,
 * so a DOM text offset (JS strings are UTF-16, like Docs indexes) plus data-s
 * is the document index. Non-text elements (images, chips, page breaks) are
 * "atoms": one index wide, rendered with data-atom so the client snaps a
 * selection boundary that falls inside them to their start or end.
 */
class GoogleDocsPreview
{
    private const HEADINGS = [
        'TITLE' => 'h1', 'SUBTITLE' => 'h2',
        'HEADING_1' => 'h1', 'HEADING_2' => 'h2', 'HEADING_3' => 'h3',
        'HEADING_4' => 'h4', 'HEADING_5' => 'h5', 'HEADING_6' => 'h6',
    ];

    public static function render(Document $document): string
    {
        return self::renderContent($document, $document->getBody()->getContent() ?? []);
    }

    /**
     * Text of the document between two indexes, as the Docs API counts them.
     */
    public static function textBetween(Document $document, int $start, int $end): string
    {
        $out = '';
        foreach (self::runs($document->getBody()->getContent() ?? []) as [$s, $e, $text]) {
            if ($e <= $start || $s >= $end) {
                continue;
            }
            $from = max($start, $s) - $s;
            $length = min($end, $e) - $s - $from;
            $out .= self::utf16Substr($text, $from, $length);
        }

        return $out;
    }

    /**
     * @param  list<StructuralElement>  $content
     */
    private static function renderContent(Document $document, array $content): string
    {
        $html = '';
        foreach ($content as $element) {
            if ($paragraph = $element->getParagraph()) {
                $html .= self::renderParagraph($document, $paragraph);
            } elseif ($table = $element->getTable()) {
                $html .= '<table class="gd-table">';
                foreach ($table->getTableRows() ?? [] as $row) {
                    $html .= '<tr>';
                    foreach ($row->getTableCells() ?? [] as $cell) {
                        $html .= '<td>'.self::renderContent($document, $cell->getContent() ?? []).'</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</table>';
            } elseif ($element->getTableOfContents()) {
                $html .= '<p class="gd-note">[Daftar isi tidak ditampilkan]</p>';
            }
            // sectionBreak: nothing to show
        }

        return $html;
    }

    private static function renderParagraph(Document $document, $paragraph): string
    {
        $style = $paragraph->getParagraphStyle()?->getNamedStyleType() ?? 'NORMAL_TEXT';
        $tag = self::HEADINGS[$style] ?? 'p';
        $attrs = '';

        if ($bullet = $paragraph->getBullet()) {
            $level = (int) $bullet->getNestingLevel();
            $attrs = ' class="gd-li" style="margin-left:'.(1.5 + 1.5 * $level).'em"';
        }

        $inner = '';
        foreach ($paragraph->getElements() ?? [] as $part) {
            $inner .= self::renderElement($document, $part);
        }

        return "<{$tag}{$attrs}>".($inner === '' ? '&#8203;' : $inner)."</{$tag}>";
    }

    private static function renderElement(Document $document, ParagraphElement $part): string
    {
        $s = (int) $part->getStartIndex();
        $e = (int) $part->getEndIndex();

        if ($run = $part->getTextRun()) {
            // The paragraph's own newline is not rendered (the block element
            // ends the line instead); it stays selectable as the gap between
            // paragraphs, which the client maps to data-e of the last run.
            $text = $run->getContent();
            if (str_ends_with($text, "\n")) {
                $text = substr($text, 0, -1);
                $e--;
            }
            if ($text === '') {
                return '';
            }

            $classes = [];
            $ts = $run->getTextStyle();
            if ($ts?->getBold()) {
                $classes[] = 'b';
            }
            if ($ts?->getItalic()) {
                $classes[] = 'i';
            }
            if ($ts?->getUnderline()) {
                $classes[] = 'u';
            }
            if ($ts?->getLink()) {
                $classes[] = 'a';
            }
            if ($ts?->getBackgroundColor()) {
                $classes[] = 'hl';
            }

            // U+000B is a soft line break in Docs: same length, rendered as newline.
            $text = str_replace("\u{000B}", "\n", $text);

            return sprintf('<span data-s="%d" data-e="%d"%s>%s</span>', $s, $e,
                $classes ? ' class="'.implode(' ', $classes).'"' : '', e($text));
        }

        $label = match (true) {
            (bool) $part->getInlineObjectElement() => self::image($document, $part->getInlineObjectElement()->getInlineObjectId()),
            (bool) $part->getPerson() => e('@'.($part->getPerson()->getPersonProperties()?->getName() ?? 'orang')),
            (bool) $part->getRichLink() => e('🔗 '.($part->getRichLink()->getRichLinkProperties()?->getTitle() ?? 'link')),
            (bool) $part->getPageBreak() => '⤓ page break',
            (bool) $part->getHorizontalRule() => '<hr>',
            (bool) $part->getFootnoteReference() => '<sup>'.e($part->getFootnoteReference()->getFootnoteNumber()).'</sup>',
            (bool) $part->getEquation() => '[persamaan]',
            default => '[objek]',
        };

        return sprintf('<span data-s="%d" data-e="%d" data-atom="1" class="gd-atom">%s</span>', $s, $e, $label);
    }

    private static function image(Document $document, string $id): string
    {
        $object = $document->getInlineObjects()[$id] ?? null;
        $uri = $object?->getInlineObjectProperties()?->getEmbeddedObject()?->getImageProperties()?->getContentUri();

        return $uri ? '<img src="'.e($uri).'" alt="" draggable="false">' : '[gambar]';
    }

    /**
     * Text runs of a content tree (recursing into tables), as [start, end, text].
     *
     * @return \Generator<array{int, int, string}>
     */
    private static function runs(array $content): \Generator
    {
        foreach ($content as $element) {
            if ($paragraph = $element->getParagraph()) {
                foreach ($paragraph->getElements() ?? [] as $part) {
                    $s = (int) $part->getStartIndex();
                    $e = (int) $part->getEndIndex();
                    yield [$s, $e, $part->getTextRun()
                        ? $part->getTextRun()->getContent()
                        : str_repeat("\u{FFFC}", $e - $s)];
                }
            } elseif ($table = $element->getTable()) {
                foreach ($table->getTableRows() ?? [] as $row) {
                    foreach ($row->getTableCells() ?? [] as $cell) {
                        yield from self::runs($cell->getContent() ?? []);
                    }
                }
            }
        }
    }

    private static function utf16Substr(string $text, int $from, int $length): string
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return mb_convert_encoding(substr($utf16, $from * 2, $length * 2), 'UTF-8', 'UTF-16LE');
    }
}
