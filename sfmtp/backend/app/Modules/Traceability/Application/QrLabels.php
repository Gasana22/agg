<?php

namespace App\Modules\Traceability\Application;

use App\Modules\Traceability\Domain\Models\TraceQrCode;
use App\Support\Documents\SimplePdf;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR images and printable label sheets. Sheets are small hand-written PDFs
 * on A4 label stock with each QR drawn once as vector squares and placed on
 * its labels, so they print sharp at any size and need no PDF library.
 */
class QrLabels
{
    /** A4 label stock: columns, rows and page margins in points. */
    public const TEMPLATES = [
        'a4_3x8' => ['label' => 'A4, 24 labels (3 × 8, about 70 × 34 mm)', 'cols' => 3, 'rows' => 8, 'mx' => 20, 'my' => 30],
        'a4_2x7' => ['label' => 'A4, 14 labels (2 × 7, about 99 × 39 mm)', 'cols' => 2, 'rows' => 7, 'mx' => 20, 'my' => 30],
        'a4_4x10' => ['label' => 'A4, 40 labels (4 × 10, about 50 × 28 mm)', 'cols' => 4, 'rows' => 10, 'mx' => 15, 'my' => 25],
    ];

    public const MAX_LABELS = 2000;

    public function __construct(private readonly Publishing $publishing) {}

    public function svg(TraceQrCode $qr, int $size = 240): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))->writeString($qr->url(), 'UTF-8', ErrorCorrectionLevel::M());
    }

    /**
     * Labels for one QR code.
     *
     * @param  array{product:?string, farm:?string}  $text
     */
    public function pdf(TraceQrCode $qr, array $text, int $copies = 24, string $template = 'a4_3x8'): string
    {
        return $this->sheet([['url' => $qr->url(), 'code' => $qr->code, 'product' => $text['product'] ?? null, 'farm' => $text['farm'] ?? null, 'copies' => $copies]], $template);
    }

    /**
     * Labels for QR codes of this farm, each with only what its batch's
     * latest approval made public.
     *
     * @param  array<int, array{0:TraceQrCode, 1:int}>  $codes  QR code and copies
     */
    public function forCodes(array $codes, string $template = 'a4_3x8'): string
    {
        return $this->sheet(array_map(function (array $pair) {
            [$qr, $copies] = $pair;
            $payload = $this->publishing->latestApproval($qr->loadMissing('batch')->batch)?->payload ?? [];

            return ['url' => $qr->url(), 'code' => $qr->code, 'product' => $payload['product']['name'] ?? null, 'farm' => $payload['farm'] ?? null, 'copies' => $copies];
        }, $codes), $template);
    }

    /**
     * Labels for many QR codes in one print run, in order.
     *
     * @param  array<int, array{url:string, code:string, product:?string, farm:?string, copies:int}>  $labels
     */
    public function sheet(array $labels, string $template = 'a4_3x8'): string
    {
        $t = self::TEMPLATES[$template] ?? self::TEMPLATES['a4_3x8'];
        $perPage = $t['cols'] * $t['rows'];
        $labelW = (SimplePdf::A4_W - 2 * $t['mx']) / $t['cols'];
        $labelH = (SimplePdf::A4_H - 2 * $t['my']) / $t['rows'];
        $scale = min(1.0, $labelH / 97.7, $labelW / 185);   // 1 on the 3 × 8 stock
        $pad = 12 * $scale;
        $qrSize = $labelH - 2 * $pad;

        $doc = new SimplePdf;
        $slots = [];
        foreach (array_values($labels) as $i => $label) {
            $matrix = Encoder::encode($label['url'], ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING)->getMatrix();
            $n = $matrix->getWidth();
            $rects = [];
            for ($r = 0; $r < $n; $r++) {
                for ($c = 0; $c < $n; $c++) {
                    if ($matrix->get($c, $r) === 1) {
                        $rects[] = sprintf('%d %d 1.02 1.02 re', $c, $n - 1 - $r);
                    }
                }
            }
            $doc->form("QR{$i}", '0 g '.implode(' ', $rects).' f', $n, $n);
            for ($k = 0; $k < max(1, $label['copies']) && count($slots) < self::MAX_LABELS; $k++) {
                $slots[] = [$i, $n, $label];
            }
        }

        foreach (array_chunk($slots, $perPage) as $page) {
            $ops = [];
            foreach ($page as $pos => [$i, $n, $label]) {
                $x = $t['mx'] + ($pos % $t['cols']) * $labelW;
                $y = SimplePdf::A4_H - $t['my'] - (intdiv($pos, $t['cols']) + 1) * $labelH;
                // Cut guide
                $ops[] = sprintf('0.85 G 0.3 w %.2F %.2F %.2F %.2F re S 0 G', $x, $y, $labelW, $labelH);
                $module = $qrSize / $n;
                $ops[] = sprintf('q %.4F 0 0 %.4F %.2F %.2F cm /QR%d Do Q', $module, $module, $x + $pad, $y + $pad, $i);
                // Text beside the QR
                $tx = $x + $pad + $qrSize + 8 * $scale;
                $width = $labelW - $qrSize - 2 * $pad - 12 * $scale;
                $lines = [
                    ['F2', 8.5 * $scale, SimplePdf::fit($label['product'] ?? 'Traceable product', $width, 8.5 * $scale)],
                    ['F1', 7 * $scale, SimplePdf::fit($label['farm'] ?? '', $width, 7 * $scale)],
                    ['F1', 6.5 * $scale, 'Scan to see where it'],
                    ['F1', 6.5 * $scale, 'came from'],
                    ['F2', 8 * $scale, $label['code']],
                ];
                $ty = $y + $labelH - 18 * $scale;
                foreach ($lines as [$font, $size, $line]) {
                    if ($line !== '') {
                        $ops[] = SimplePdf::text($font, $size, $tx, $ty, $line);
                    }
                    $ty -= $size + 3.5 * $scale;
                }
            }
            $doc->page(implode("\n", $ops));
        }

        return $doc->render();
    }
}
