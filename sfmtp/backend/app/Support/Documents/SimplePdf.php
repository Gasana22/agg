<?php

namespace App\Support\Documents;

/**
 * A small hand-written PDF 1.4 writer: pages of drawing operators, the two
 * standard Helvetica fonts (/F1 regular, /F2 bold) and shared forms
 * (XObjects) placed on any page. Enough for label sheets and report tables
 * without a PDF library.
 */
final class SimplePdf
{
    public const A4_W = 595.28;

    public const A4_H = 841.89;

    /** @var array<string, array{0:string, 1:float, 2:float}> */
    private array $forms = [];

    /** @var array<int, array{0:string, 1:float, 2:float}> */
    private array $pages = [];

    public function form(string $name, string $stream, float $width, float $height): self
    {
        $this->forms[$name] = [$stream, $width, $height];

        return $this;
    }

    public function page(string $content, float $width = self::A4_W, float $height = self::A4_H): self
    {
        $this->pages[] = [$content, $width, $height];

        return $this;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function render(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $next = 5;
        $formRefs = [];
        foreach ($this->forms as $name => [$stream, $w, $h]) {
            $objects[$next] = sprintf("<< /Type /XObject /Subtype /Form /BBox [0 0 %s %s] /Length %d >>\nstream\n%s\nendstream", self::num($w), self::num($h), strlen($stream), $stream);
            $formRefs[] = "/{$name} {$next} 0 R";
            $next++;
        }
        $xobjects = $formRefs === [] ? '' : ' /XObject << '.implode(' ', $formRefs).' >>';
        $kids = [];
        foreach ($this->pages as [$content, $w, $h]) {
            [$pageId, $contentId] = [$next++, $next++];
            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>', $w, $h, $xobjects, $contentId);
            $objects[$contentId] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($this->pages).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /** A text operator at a position, in /F1 or /F2. */
    public static function text(string $font, float $size, float $x, float $y, string $text): string
    {
        return sprintf('BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET', $font, $size, $x, $y, self::escape($text));
    }

    /** Latin-1 text for the standard fonts, with PDF string escapes. */
    public static function escape(string $s): string
    {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        $s = $s === false ? '' : $s;

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
    }

    /** Trim to roughly fit a width (Helvetica averages about half an em per character). */
    public static function fit(?string $s, float $width, float $size): string
    {
        $s = trim((string) $s);
        $max = max(2, (int) floor($width / ($size * 0.52)));

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)).'…' : $s;
    }

    /** Approximate width of a string in points (for right-aligned numbers). */
    public static function width(string $s, float $size): float
    {
        return mb_strlen($s) * $size * 0.52;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
    }
}
