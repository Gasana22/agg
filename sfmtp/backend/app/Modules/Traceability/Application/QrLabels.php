<?php

namespace App\Modules\Traceability\Application;

use App\Modules\Traceability\Domain\Models\TraceQrCode;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR images and printable label sheets. Labels are a small hand-written PDF
 * (A4, 3 × 8 labels) with the QR drawn as vector squares, so they print
 * sharp at any size and need no PDF library.
 */
class QrLabels
{
    private const PAGE_W = 595.28;

    private const PAGE_H = 841.89;

    private const COLS = 3;

    private const ROWS = 8;

    private const MARGIN_X = 20;

    private const MARGIN_Y = 30;

    public function svg(TraceQrCode $qr, int $size = 240): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))->writeString($qr->url(), 'UTF-8', ErrorCorrectionLevel::M());
    }

    /**
     * @param  array{product:?string, farm:?string}  $text
     */
    public function pdf(TraceQrCode $qr, array $text, int $copies = 24): string
    {
        $matrix = Encoder::encode($qr->url(), ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING)->getMatrix();
        $n = $matrix->getWidth();
        $perPage = self::COLS * self::ROWS;
        $labelW = (self::PAGE_W - 2 * self::MARGIN_X) / self::COLS;
        $labelH = (self::PAGE_H - 2 * self::MARGIN_Y) / self::ROWS;
        $qrSize = $labelH - 24;   // leaves a quiet zone of about four modules
        $module = $qrSize / $n;

        $pages = [];
        for ($start = 0; $start < $copies; $start += $perPage) {
            $ops = [];
            for ($i = 0; $i < min($perPage, $copies - $start); $i++) {
                $x = self::MARGIN_X + ($i % self::COLS) * $labelW;
                $y = self::PAGE_H - self::MARGIN_Y - (intdiv($i, self::COLS) + 1) * $labelH;
                // Cut guide
                $ops[] = sprintf('0.85 G 0.3 w %.2F %.2F %.2F %.2F re S 0 G', $x, $y, $labelW, $labelH);
                // The QR, drawn once as a form and placed on each label.
                $qx = $x + 12;
                $qy = $y + 12;
                $ops[] = sprintf('q %.4F 0 0 %.4F %.2F %.2F cm /QR Do Q', $module, $module, $qx, $qy);
                // Text beside the QR
                $tx = $qx + $qrSize + 8;
                $width = $labelW - $qrSize - 28;
                $lines = [
                    ['F2', 8.5, self::fit($text['product'] ?? 'Traceable product', $width, 8.5)],
                    ['F1', 7, self::fit($text['farm'] ?? '', $width, 7)],
                    ['F1', 6.5, 'Scan to see where it'],
                    ['F1', 6.5, 'came from'],
                    ['F2', 8, $qr->code],
                ];
                $ty = $y + $labelH - 18;
                foreach ($lines as [$font, $size, $line]) {
                    if ($line !== '') {
                        $ops[] = sprintf('BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET', $font, $size, $tx, $ty, self::escape($line));
                    }
                    $ty -= $size + 3.5;
                }
            }
            $pages[] = implode("\n", $ops);
        }

        $rects = [];
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($matrix->get($c, $r) === 1) {
                    $rects[] = sprintf('%d %d 1.02 1.02 re', $c, $n - 1 - $r);
                }
            }
        }

        return self::document($pages, '0 g '.implode(' ', $rects).' f', $n);
    }

    /** @param  array<int, string>  $pages content streams */
    private static function document(array $pages, string $qr, int $n): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[5] = "<< /Type /XObject /Subtype /Form /BBox [0 0 {$n} {$n}] /Length ".strlen($qr)." >>\nstream\n{$qr}\nendstream";
        $kids = [];
        $next = 6;
        $pageObjects = [];
        foreach ($pages as $content) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = "{$pageId} 0 R";
            $pageObjects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /QR 5 0 R >> >> /Contents %d 0 R >>', self::PAGE_W, self::PAGE_H, $contentId);
            $pageObjects[$contentId] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects += $pageObjects;
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /** Latin-1 text for the standard fonts, with PDF string escapes. */
    private static function escape(string $s): string
    {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s) ?: preg_replace('/[^\x20-\x7E]/', '?', $s);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    /** Trim to roughly fit a width (Helvetica averages about half an em per character). */
    private static function fit(?string $s, float $width, float $size): string
    {
        $s = trim((string) $s);
        $max = max(4, (int) floor($width / ($size * 0.52)));

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)).'…' : $s;
    }
}
