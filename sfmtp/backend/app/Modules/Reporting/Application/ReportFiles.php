<?php

namespace App\Modules\Reporting\Application;

use App\Support\Documents\SimplePdf;
use RuntimeException;
use ZipArchive;

/**
 * Writes a run standard report (StandardReports::run) as CSV, Excel or PDF.
 * All three carry the same columns, rows and totals as the on-screen
 * preview; only the presentation differs.
 *
 * - CSV: UTF-8 with a byte-order mark (Excel opens it correctly), plain
 *   decimals, and text cells starting with = + - @ prefixed with an
 *   apostrophe so a spreadsheet never runs them as formulas.
 * - XLSX: a minimal SpreadsheetML workbook written with ZipArchive; money
 *   and numbers are real numbers with a number format, the header row is
 *   bold and frozen.
 * - PDF: a table on A4 (landscape when wide), with the farm, period and
 *   page numbers on every page.
 */
class ReportFiles
{
    public function write(array $report, string $format, string $farmName): string
    {
        return match ($format) {
            'csv' => $this->csv($report),
            'xlsx' => $this->xlsx($report),
            'pdf' => $this->pdf($report, $farmName),
        };
    }

    public function extension(string $format): string
    {
        return $format;
    }

    public function csv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_column($report['columns'], 'label'), ',', '"', '');
        foreach ($report['rows'] as $row) {
            fputcsv($out, $this->csvRow($report['columns'], $row), ',', '"', '');
        }
        if ($report['totals'] !== null) {
            $totals = $report['totals'];
            $first = $report['columns'][0]['key'];
            $totals[$first] ??= 'Total';
            fputcsv($out, $this->csvRow($report['columns'], $totals), ',', '"', '');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    private function csvRow(array $columns, array $row): array
    {
        return array_map(function ($c) use ($row) {
            $v = $row[$c['key']] ?? null;
            if ($v === null) {
                return '';
            }
            if (in_array($c['type'], ['text', 'date', 'datetime'], true) && is_string($v) && preg_match('/^[=+\-@\t\r]/', $v)) {
                return "'".$v;
            }

            return (string) $v;
        }, $columns);
    }

    public function xlsx(array $report): string
    {
        $columns = $report['columns'];
        $cell = function (int $col, int $row, mixed $value, string $type, bool $bold = false) {
            $ref = self::colName($col).$row;
            if ($value === null || $value === '') {
                return $bold ? "<c r=\"{$ref}\" s=\"1\"/>" : '';
            }
            if (! $bold && in_array($type, ['money', 'number', 'integer', 'percent'], true) && is_numeric($value)) {
                $style = ['money' => 2, 'number' => 3, 'integer' => 4, 'percent' => 5][$type];

                return "<c r=\"{$ref}\" s=\"{$style}\"><v>".(0 + $value).'</v></c>';
            }
            if ($bold && in_array($type, ['money', 'number', 'integer'], true) && is_numeric($value)) {
                $style = ['money' => 6, 'number' => 7, 'integer' => 8][$type];

                return "<c r=\"{$ref}\" s=\"{$style}\"><v>".(0 + $value).'</v></c>';
            }

            return "<c r=\"{$ref}\" t=\"inlineStr\"".($bold ? ' s="1"' : '').'><is><t xml:space="preserve">'.self::xml((string) $value).'</t></is></c>';
        };

        $rows = [];
        $r = 1;
        $rows[] = "<row r=\"{$r}\">".implode('', array_map(fn ($i) => $cell($i, 1, $columns[$i]['label'], 'text', true), array_keys($columns))).'</row>';
        foreach ($report['rows'] as $data) {
            $r++;
            $rows[] = "<row r=\"{$r}\">".implode('', array_map(fn ($i) => $cell($i, $r, $data[$columns[$i]['key']] ?? null, $columns[$i]['type']), array_keys($columns))).'</row>';
        }
        if ($report['totals'] !== null) {
            $r++;
            $totals = $report['totals'];
            $totals[$columns[0]['key']] ??= 'Total';
            $rows[] = "<row r=\"{$r}\">".implode('', array_map(fn ($i) => $cell($i, $r, $totals[$columns[$i]['key']] ?? null, $columns[$i]['type'], true), array_keys($columns))).'</row>';
        }

        $widths = implode('', array_map(function ($i) use ($columns, $report) {
            $len = mb_strlen($columns[$i]['label']);
            foreach (array_slice($report['rows'], 0, 200) as $row) {
                $len = max($len, mb_strlen((string) ($row[$columns[$i]['key']] ?? '')));
            }
            $w = min(60, max(8, $len + 2));

            return '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
        }, array_keys($columns)));

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            ."<cols>{$widths}</cols><sheetData>".implode('', $rows).'</sheetData></worksheet>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.###"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="9">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            .'<xf numFmtId="165" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            .'<xf numFmtId="1" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            .'</cellXfs></styleSheet>';

        $name = self::xml(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $report['title']), 0, 31));
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                ."<sheets><sheet name=\"{$name}\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>",
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
            'xl/styles.xml' => $styles,
        ];

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the workbook.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function pdf(array $report, string $farmName): string
    {
        $columns = $report['columns'];
        $landscape = count($columns) > 6;
        [$pw, $ph] = $landscape ? [SimplePdf::A4_H, SimplePdf::A4_W] : [SimplePdf::A4_W, SimplePdf::A4_H];
        $margin = 36;
        $size = count($columns) > 10 ? 6.5 : 7.5;
        $lineH = $size + 5;
        $usable = $pw - 2 * $margin;

        // Column widths in proportion to their longest value, within limits.
        $weights = array_map(function ($c) use ($report) {
            $len = mb_strlen($c['label']);
            foreach (array_slice($report['rows'], 0, 300) as $row) {
                $len = max($len, mb_strlen((string) ($row[$c['key']] ?? '')));
            }

            return min(40, max(5, $len));
        }, $columns);
        $total = array_sum($weights);
        $widths = array_map(fn ($w) => $usable * $w / $total, $weights);
        $numeric = array_map(fn ($c) => in_array($c['type'], ['money', 'number', 'integer', 'percent'], true), $columns);

        $params = $report['params'];
        $subtitle = implode(' · ', array_filter([
            $farmName,
            isset($params['from'], $params['to']) ? "{$params['from']} to {$params['to']}" : null,
            isset($params['days']) ? "next {$params['days']} days" : null,
            in_array('money', array_column($columns, 'type'), true) ? "amounts in {$report['currency']}" : null,
        ]));

        $format = function ($v, $c) {
            if ($v === null || $v === '') {
                return '';
            }
            if ($c['type'] === 'money' && is_numeric($v)) {
                return number_format((float) $v, 2);
            }
            if ($c['type'] === 'percent' && is_numeric($v)) {
                return round((float) $v * 100, 1).'%';
            }
            if (in_array($c['type'], ['number', 'integer'], true) && is_numeric($v)) {
                $d = str_contains((string) $v, '.') ? strlen(explode('.', (string) $v)[1]) : 0;

                return number_format((float) $v, $d);
            }

            return (string) $v;
        };

        $rowOps = function (array $row, float $y, bool $bold) use ($columns, $widths, $numeric, $margin, $size, $format) {
            $ops = [];
            $x = $margin;
            foreach ($columns as $i => $c) {
                $text = SimplePdf::fit($format($row[$c['key']] ?? null, $c), $widths[$i] - 4, $size);
                $tx = $numeric[$i] ? $x + $widths[$i] - 2 - SimplePdf::width($text, $size) : $x + 2;
                if ($text !== '') {
                    $ops[] = SimplePdf::text($bold ? 'F2' : 'F1', $size, $tx, $y, $text);
                }
                $x += $widths[$i];
            }

            return $ops;
        };

        $doc = new SimplePdf;
        $pages = [];
        $ops = [];
        $y = 0;
        $newPage = function () use (&$ops, &$y, &$pages, $ph, $margin, $report, $subtitle, $columns, $rowOps, $lineH, $usable) {
            if ($ops !== []) {
                $pages[] = $ops;
            }
            $ops = [];
            $y = $ph - $margin;
            $ops[] = SimplePdf::text('F2', 13, $margin, $y - 10, $report['title']);
            $ops[] = SimplePdf::text('F1', 8, $margin, $y - 24, $subtitle);
            $y -= 44;
            $ops[] = sprintf('0.93 g %.2F %.2F %.2F %.2F re f 0 g', $margin, $y - 4, $usable, $lineH + 2);
            $ops = array_merge($ops, $rowOps(array_combine(array_column($columns, 'key'), array_column($columns, 'label')), $y, true));
            $y -= $lineH + 2;
        };
        $newPage();
        foreach ($report['rows'] as $n => $row) {
            if ($y < $margin + 2 * $lineH) {
                $newPage();
            }
            if ($n % 2 === 1) {
                $ops[] = sprintf('0.975 g %.2F %.2F %.2F %.2F re f 0 g', $margin, $y - 4, $usable, $lineH);
            }
            $ops = array_merge($ops, $rowOps($row, $y, false));
            $y -= $lineH;
        }
        if ($report['rows'] === []) {
            $ops[] = SimplePdf::text('F1', $size, $margin + 2, $y, 'No rows for these parameters.');
            $y -= $lineH;
        }
        if ($report['totals'] !== null) {
            if ($y < $margin + 2 * $lineH) {
                $newPage();
            }
            $totals = $report['totals'];
            $totals[$columns[0]['key']] ??= 'Total';
            $ops[] = sprintf('0.6 G 0.5 w %.2F %.2F m %.2F %.2F l S 0 G', $margin, $y + $lineH - 3, $margin + $usable, $y + $lineH - 3);
            $ops = array_merge($ops, $rowOps($totals, $y, true));
        }
        $pages[] = $ops;

        $count = count($pages);
        foreach ($pages as $i => $page) {
            $footer = sprintf('Generated %s · %s rows · page %d of %d', substr($report['generated_at'], 0, 16).' UTC', number_format($report['row_count']), $i + 1, $count);
            $page[] = '0.4 g '.SimplePdf::text('F1', 7, $margin, $margin - 14, $footer).' 0 g';
            $doc->page(implode("\n", $page), $pw, $ph);
        }

        return $doc->render();
    }

    private static function colName(int $i): string
    {
        $name = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + ($i - 1) % 26).$name;
        }

        return $name;
    }

    private static function xml(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
