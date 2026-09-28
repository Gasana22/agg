<?php
/*
 * A small QR code generator (byte mode, error correction level M,
 * versions 1–10) that draws an SVG. Enough for scan links of up to
 * about 200 characters. No library needed.
 */

final class Qr
{
    // Per version (M level): [total codewords, EC codewords per block, [blocks in group 1, data cw each], [blocks in group 2, data cw each]]
    private const TABLE = [
        1 => [26, 10, [1, 16], [0, 0]], 2 => [44, 16, [1, 28], [0, 0]], 3 => [70, 26, [1, 44], [0, 0]], 4 => [100, 18, [2, 32], [0, 0]],
        5 => [134, 24, [2, 43], [0, 0]], 6 => [172, 16, [4, 27], [0, 0]], 7 => [196, 18, [4, 31], [0, 0]], 8 => [242, 22, [2, 38], [2, 39]],
        9 => [292, 22, [3, 36], [2, 37]], 10 => [346, 26, [4, 43], [1, 44]],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    private array $m = [];
    private array $fixed = [];
    private int $size = 0;

    public static function svg(string $text, int $scale = 4): string
    {
        $qr = new self();
        $grid = $qr->encode($text);
        $n = count($grid);
        $q = 4; // quiet zone
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($grid[$y][$x]) {
                    $path .= 'M' . ($x + $q) . ' ' . ($y + $q) . 'h1v1h-1z';
                }
            }
        }
        $dim = $n + 2 * $q;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . ($dim * $scale) . '" height="' . ($dim * $scale) . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    public function encode(string $text): array
    {
        $bytes = array_values(unpack('C*', $text));
        $len = count($bytes);
        $version = 0;
        foreach (self::TABLE as $v => $t) {
            $capacity = $t[2][0] * $t[2][1] + $t[3][0] * $t[3][1];
            if ($len + 2 + ($v >= 10 ? 1 : 0) <= $capacity) {
                $version = $v;
                break;
            }
        }
        if (!$version) {
            throw new InvalidArgumentException('Text too long for a QR code.');
        }
        [$total, $ecPer, $g1, $g2] = self::TABLE[$version];
        $dataCw = $g1[0] * $g1[1] + $g2[0] * $g2[1];

        // Mode (byte), count, data, terminator, padding.
        $bits = '0100' . str_pad(decbin($len), $version >= 10 ? 16 : 8, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $dataCw * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $data = array_map('bindec', str_split($bits, 8));
        for ($i = 0; count($data) < $dataCw; $i++) {
            $data[] = $i % 2 ? 0x11 : 0xEC;
        }

        // Blocks with Reed-Solomon error correction, interleaved.
        $blocks = [];
        $pos = 0;
        foreach ([$g1, $g2] as [$count, $size]) {
            for ($b = 0; $b < $count; $b++) {
                $d = array_slice($data, $pos, $size);
                $pos += $size;
                $blocks[] = [$d, self::rs($d, $ecPer)];
            }
        }
        $final = [];
        $maxData = max(array_map(fn ($b) => count($b[0]), $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $b) {
                if (isset($b[0][$i])) {
                    $final[] = $b[0][$i];
                }
            }
        }
        for ($i = 0; $i < $ecPer; $i++) {
            foreach ($blocks as $b) {
                $final[] = $b[1][$i];
            }
        }
        $stream = '';
        foreach ($final as $cw) {
            $stream .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $stream .= str_repeat('0', [0, 0, 7, 7, 7, 7, 7, 0, 0, 0, 0][$version]);

        // Try the eight masks, keep the one with the lowest penalty.
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->base($version);
            $this->place($stream, $mask);
            $this->format($mask);
            if ($version >= 7) {
                $this->versionInfo($version);
            }
            $score = $this->penalty();
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $this->m;
            }
        }
        return $best;
    }

    private static function gfTables(): array
    {
        static $t = null;
        if ($t === null) {
            $exp = array_fill(0, 512, 0);
            $log = array_fill(0, 256, 0);
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D;
                }
            }
            for ($i = 255; $i < 512; $i++) {
                $exp[$i] = $exp[$i - 255];
            }
            $t = [$exp, $log];
        }
        return $t;
    }

    private static function rs(array $data, int $ec): array
    {
        [$exp, $log] = self::gfTables();
        $gen = [1];
        for ($i = 0; $i < $ec; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $g) {
                $next[$j] ^= $g;
                $next[$j + 1] ^= $g ? $exp[$log[$g] + $i] : 0;
            }
            $gen = $next;
        }
        $res = array_merge($data, array_fill(0, $ec, 0));
        for ($i = 0; $i < count($data); $i++) {
            $coef = $res[$i];
            if ($coef !== 0) {
                foreach ($gen as $j => $g) {
                    if ($g) {
                        $res[$i + $j] ^= $exp[$log[$g] + $log[$coef]];
                    }
                }
            }
        }
        return array_slice($res, count($data));
    }

    private function base(int $version): void
    {
        $n = $this->size = 17 + 4 * $version;
        $this->m = array_fill(0, $n, array_fill(0, $n, false));
        $this->fixed = array_fill(0, $n, array_fill(0, $n, false));
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$fx, $fy]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $x = $fx + $dx;
                    $y = $fy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) {
                        continue;
                    }
                    $on = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6 && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                    $this->set($x, $y, $on);
                }
            }
        }
        $al = self::ALIGN[$version];
        foreach ($al as $ay) {
            foreach ($al as $ax) {
                if ($this->fixed[$ay][$ax]) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        for ($i = 8; $i < $n - 8; $i++) {
            $this->set($i, 6, $i % 2 === 0);
            $this->set(6, $i, $i % 2 === 0);
        }
        $this->set(8, $n - 8, true); // dark module
        // Reserve format and version areas.
        for ($i = 0; $i < 9; $i++) {
            $this->reserve($i, 8);
            $this->reserve(8, $i);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->reserve($n - 1 - $i, 8);
            $this->reserve(8, $n - 1 - $i);
        }
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $this->reserve($n - 11 + $j, $i);
                    $this->reserve($i, $n - 11 + $j);
                }
            }
        }
    }

    private function set(int $x, int $y, bool $on): void
    {
        $this->m[$y][$x] = $on;
        $this->fixed[$y][$x] = true;
    }

    private function reserve(int $x, int $y): void
    {
        $this->fixed[$y][$x] = true;
    }

    private function place(string $bits, int $mask): void
    {
        $n = $this->size;
        $i = 0;
        $up = true;
        for ($x = $n - 1; $x > 0; $x -= 2) {
            if ($x === 6) {
                $x--;
            }
            for ($k = 0; $k < $n; $k++) {
                $y = $up ? $n - 1 - $k : $k;
                for ($c = 0; $c < 2; $c++) {
                    $xx = $x - $c;
                    if ($this->fixed[$y][$xx]) {
                        continue;
                    }
                    $bit = $i < strlen($bits) && $bits[$i] === '1';
                    $i++;
                    $this->m[$y][$xx] = ($bit !== self::maskBit($mask, $xx, $y));
                }
            }
            $up = !$up;
        }
    }

    private static function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
        };
    }

    private function format(int $mask): void
    {
        $data = (0b00 << 3) | $mask; // level M = 00
        $v = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ($v & (1 << $i)) {
                $v ^= 0x537 << ($i - 10);
            }
        }
        $f = (($data << 10) | $v) ^ 0x5412;
        $n = $this->size;
        for ($i = 0; $i < 15; $i++) {
            $bit = (($f >> $i) & 1) === 1;
            // Around the top-left finder.
            if ($i < 6) {
                $this->m[$i][8] = $bit;
            } elseif ($i < 8) {
                $this->m[$i + 1][8] = $bit;
            } else {
                $this->m[8][14 - $i + ($i === 8 ? 1 : 0)] = $bit;
            }
            // The copy next to the other finders.
            if ($i < 8) {
                $this->m[8][$n - 1 - $i] = $bit;
            } else {
                $this->m[$n - 15 + $i][8] = $bit;
            }
        }
        $this->m[$n - 8][8] = true;
    }

    private function versionInfo(int $version): void
    {
        $v = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if ($v & (1 << $i)) {
                $v ^= 0x1F25 << ($i - 12);
            }
        }
        $bits = ($version << 12) | $v;
        $n = $this->size;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $a = intdiv($i, 3);
            $b = $i % 3 + $n - 11;
            $this->m[$b][$a] = $bit;
            $this->m[$a][$b] = $bit;
        }
    }

    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->m;
        $score = 0;
        for ($y = 0; $y < $n; $y++) {
            foreach ([true, false] as $rows) {
                $run = 1;
                for ($x = 1; $x < $n; $x++) {
                    $a = $rows ? $m[$y][$x] : $m[$x][$y];
                    $b = $rows ? $m[$y][$x - 1] : $m[$x - 1][$y];
                    if ($a === $b) {
                        $run++;
                    } else {
                        $score += $run >= 5 ? $run - 2 : 0;
                        $run = 1;
                    }
                }
                $score += $run >= 5 ? $run - 2 : 0;
            }
        }
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $dark = 0;
        foreach ($m as $row) {
            $dark += count(array_filter($row));
        }
        $score += intdiv(abs($dark * 20 - $n * $n * 10), $n * $n) * 10;
        return $score;
    }
}
