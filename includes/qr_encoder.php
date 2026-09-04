<?php
/**
 * RSH-LS – Minimaler QR-Code-Encoder (Byte-Modus, Versionen 1-6, ECC-Level M mit
 * Fallback auf L, fester Maskierung 0) – keine externen Bibliotheken, ausreichend
 * für kurze URLs (Geräte-/Defekt-Detailseiten).
 *
 * Liefert eine quadratische Matrix (bool) plus Größe; das Zeichnen übernimmt der
 * Aufrufer (z.B. als gefüllte Rechtecke im PDF-Writer).
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

// -- GF(256) Tabellen (Generatorpolynom 0x11D) -----------------------------
function qr_gf_tables(): array
{
    static $exp = null, $log = null;
    if ($exp !== null) {
        return [$exp, $log];
    }
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
    return [$exp, $log];
}

function qr_gf_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    [$exp, $log] = qr_gf_tables();
    return $exp[$log[$a] + $log[$b]];
}

/** Reed-Solomon Generatorpolynom vom Grad $degree (Koeffizienten hoechster Grad zuerst). */
function qr_rs_generator_poly(int $degree): array
{
    $poly = [1];
    [$exp, ] = qr_gf_tables();
    for ($i = 0; $i < $degree; $i++) {
        $newPoly = array_fill(0, count($poly) + 1, 0);
        for ($j = 0; $j < count($poly); $j++) {
            $newPoly[$j] ^= $poly[$j];
            $newPoly[$j + 1] ^= qr_gf_mul($poly[$j], $exp[$i]);
        }
        $poly = $newPoly;
    }
    return $poly;
}

/** @param int[] $data @return int[] ECC-Codewords */
function qr_rs_encode(array $data, int $eccCount): array
{
    $gen = qr_rs_generator_poly($eccCount);
    $buf = array_merge($data, array_fill(0, $eccCount, 0));
    for ($i = 0; $i < count($data); $i++) {
        $coef = $buf[$i];
        if ($coef === 0) {
            continue;
        }
        for ($j = 0; $j < count($gen); $j++) {
            $buf[$i + $j] ^= qr_gf_mul($gen[$j], $coef);
        }
    }
    return array_slice($buf, count($data), $eccCount);
}

/** Block-Struktur Tabelle (Versionen 1-6) je ECC-Level: [eccPerBlock, [ [blocks,dataLen], ... ] ] */
function qr_block_table(): array
{
    return [
        'L' => [
            1 => [7,  [[1, 19]]],
            2 => [10, [[1, 34]]],
            3 => [15, [[1, 55]]],
            4 => [20, [[1, 80]]],
            5 => [26, [[1, 108]]],
            6 => [18, [[2, 68]]],
        ],
        'M' => [
            1 => [10, [[1, 16]]],
            2 => [16, [[1, 28]]],
            3 => [26, [[1, 44]]],
            4 => [18, [[2, 32]]],
            5 => [24, [[2, 43]]],
            6 => [16, [[4, 27]]],
        ],
    ];
}

/** Restbits nach dem Codewort-Strom, je Version (1-6). */
function qr_remainder_bits(): array
{
    return [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7];
}

function qr_total_data_codewords(int $version, string $level): int
{
    $table = qr_block_table();
    [, $groups] = $table[$level][$version];
    $total = 0;
    foreach ($groups as [$blocks, $len]) {
        $total += $blocks * $len;
    }
    return $total;
}

/**
 * Waehlt die kleinste Version/ECC-Level (bevorzugt M, sonst L), die $byteLen Bytes
 * im Byte-Modus (8-Bit Zaehler, Versionen 1-9) aufnehmen kann.
 * @return array{0:int,1:string}|null
 */
function qr_choose_version(int $byteLen): ?array
{
    foreach ([1, 2, 3, 4, 5, 6] as $v) {
        $capM = qr_total_data_codewords($v, 'M');
        // 4 Bit Mode + 8 Bit Count = 12 Bit = 1.5 Byte Overhead, worst case 2 Byte nach Rundung
        if ($byteLen <= $capM - 2) {
            return [$v, 'M'];
        }
    }
    foreach ([1, 2, 3, 4, 5, 6] as $v) {
        $capL = qr_total_data_codewords($v, 'L');
        if ($byteLen <= $capL - 2) {
            return [$v, 'L'];
        }
    }
    return null;
}

/** Baut den kompletten Codewort-Strom (Daten+ECC, interleaved) fuer Byte-Modus. @return int[] */
function qr_build_codewords(string $data, int $version, string $level): array
{
    $bytes = array_values(unpack('C*', $data));
    $len = count($bytes);

    $bits = '0100'; // Byte-Modus
    $bits .= str_pad(decbin($len), 8, '0', STR_PAD_LEFT); // Zaehler (Versionen 1-9: 8 Bit)
    foreach ($bytes as $b) {
        $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    }

    $totalDataCodewords = qr_total_data_codewords($version, $level);
    $capacityBits = $totalDataCodewords * 8;

    $termLen = min(4, $capacityBits - strlen($bits));
    if ($termLen > 0) {
        $bits .= str_repeat('0', $termLen);
    }
    while (strlen($bits) % 8 !== 0) {
        $bits .= '0';
    }

    $codewords = [];
    foreach (str_split($bits, 8) as $byteStr) {
        $codewords[] = bindec($byteStr);
    }

    $pad = [0xEC, 0x11];
    $i = 0;
    while (count($codewords) < $totalDataCodewords) {
        $codewords[] = $pad[$i % 2];
        $i++;
    }

    // -- In Bloecke aufteilen, RS-ECC berechnen, interleaven -----------------
    [$eccPerBlock, $groups] = qr_block_table()[$level][$version];
    $blocks = [];
    $pos = 0;
    foreach ($groups as [$count, $dataLen]) {
        for ($b = 0; $b < $count; $b++) {
            $blockData = array_slice($codewords, $pos, $dataLen);
            $pos += $dataLen;
            $blocks[] = ['data' => $blockData, 'ecc' => qr_rs_encode($blockData, $eccPerBlock)];
        }
    }

    $maxDataLen = max(array_map(fn($blk) => count($blk['data']), $blocks));
    $interleaved = [];
    for ($i = 0; $i < $maxDataLen; $i++) {
        foreach ($blocks as $blk) {
            if ($i < count($blk['data'])) {
                $interleaved[] = $blk['data'][$i];
            }
        }
    }
    for ($i = 0; $i < $eccPerBlock; $i++) {
        foreach ($blocks as $blk) {
            $interleaved[] = $blk['ecc'][$i];
        }
    }

    return $interleaved;
}

function qr_bch_encode(int $data, int $dataBits, int $eccBits, int $poly): int
{
    $value = $data << $eccBits;
    for ($i = $dataBits - 1; $i >= 0; $i--) {
        if ($value & (1 << ($i + $eccBits))) {
            $value ^= $poly << $i;
        }
    }
    return ($data << $eccBits) | $value;
}

/** @return int[] 15-Bit Format-Info Bits (MSB zuerst) fuer gegebenes ECC-Level+Maske. */
function qr_format_bits(string $level, int $mask): array
{
    $eccBits = ['L' => 0b01, 'M' => 0b00, 'Q' => 0b11, 'H' => 0b10][$level];
    $data5 = ($eccBits << 3) | $mask;
    $formatValue = qr_bch_encode($data5, 5, 10, 0b10100110111);
    $formatValue ^= 0b101010000010010;
    $bits = [];
    for ($i = 14; $i >= 0; $i--) {
        $bits[] = ($formatValue >> $i) & 1;
    }
    return $bits;
}

/**
 * Erzeugt die QR-Matrix fuer $text.
 * @return array{size:int, matrix: bool[][]} matrix[row][col] = true (dunkel)
 */
function qr_encode(string $text): array
{
    $data = $text;
    $chosen = qr_choose_version(strlen($data));
    if ($chosen === null) {
        throw new RuntimeException('QR-Daten zu lang (max. ~130 Zeichen).');
    }
    [$version, $level] = $chosen;
    $size = 17 + 4 * $version;

    $codewords = qr_build_codewords($data, $version, $level);
    $bitStream = '';
    foreach ($codewords as $cw) {
        $bitStream .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    }
    $bitStream .= str_repeat('0', qr_remainder_bits()[$version]);

    $module = array_fill(0, $size, array_fill(0, $size, false)); // Modulwert (nach Maskierung)
    $reserved = array_fill(0, $size, array_fill(0, $size, false)); // true = Funktionsbereich, nicht ueberschreiben

    $setFinder = function (int $r0, int $c0) use (&$module, &$reserved, $size) {
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $r = $r0 + $dr;
                $c = $c0 + $dc;
                if ($r < 0 || $c < 0 || $r >= $size || $c >= $size) {
                    continue;
                }
                $reserved[$r][$c] = true;
                if ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6) {
                    $isBorder = ($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6);
                    $isCenter = ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4);
                    $module[$r][$c] = $isBorder || $isCenter;
                } else {
                    $module[$r][$c] = false; // Separator (weiss)
                }
            }
        }
    };
    $setFinder(0, 0);
    $setFinder(0, $size - 7);
    $setFinder($size - 7, 0);

    // Timing-Pattern
    for ($i = 8; $i < $size - 8; $i++) {
        $reserved[6][$i] = true;
        $module[6][$i] = ($i % 2 === 0);
        $reserved[$i][6] = true;
        $module[$i][6] = ($i % 2 === 0);
    }

    // Alignment-Pattern (Versionen 2-6: genau eines)
    if ($version >= 2) {
        $center = $size - 7; // fuer v2-6 einzige gueltige Position
        for ($dr = -2; $dr <= 2; $dr++) {
            for ($dc = -2; $dc <= 2; $dc++) {
                $r = $center + $dr;
                $c = $center + $dc;
                $reserved[$r][$c] = true;
                $isBorder = (abs($dr) === 2 || abs($dc) === 2);
                $isCenter = ($dr === 0 && $dc === 0);
                $module[$r][$c] = $isBorder || $isCenter;
            }
        }
    }

    // Dunkles Modul (immer dunkel)
    $module[4 * $version + 9][8] = true;
    $reserved[4 * $version + 9][8] = true;

    // Format-Info Bereiche reservieren (Werte werden erst nach Maskenwahl gesetzt)
    for ($i = 0; $i <= 8; $i++) {
        $reserved[8][$i] = true;
        $reserved[$i][8] = true;
    }
    for ($i = 0; $i < 8; $i++) {
        $reserved[8][$size - 1 - $i] = true;
        $reserved[$size - 1 - $i][8] = true;
    }
    $reserved[8][8] = true;

    // -- Daten platzieren (Zick-Zack, unterste rechte Ecke beginnend) --------
    $bitIndex = 0;
    $bitLen = strlen($bitStream);
    $upward = true;
    $col = $size - 1;
    while ($col > 0) {
        if ($col === 6) {
            $col--;
        }
        for ($k = 0; $k < $size; $k++) {
            $row = $upward ? ($size - 1 - $k) : $k;
            foreach ([$col, $col - 1] as $c) {
                if ($reserved[$row][$c]) {
                    continue;
                }
                $bit = $bitIndex < $bitLen ? ($bitStream[$bitIndex] === '1') : false;
                $bitIndex++;
                // Maske 0: (row+col) % 2 === 0 -> invertieren
                $masked = (($row + $c) % 2 === 0) ? !$bit : $bit;
                $module[$row][$c] = $masked;
            }
        }
        $upward = !$upward;
        $col -= 2;
    }

    // Format-Info (Maske fest = 0) eintragen
    $fmtBits = qr_format_bits($level, 0);
    $firstCopy = [[8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],[7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8]];
    $secondCopy = [
        [$size-1,8],[$size-2,8],[$size-3,8],[$size-4,8],[$size-5,8],[$size-6,8],[$size-7,8],
        [8,$size-8],[8,$size-7],[8,$size-6],[8,$size-5],[8,$size-4],[8,$size-3],[8,$size-2],[8,$size-1],
    ];
    for ($i = 0; $i < 15; $i++) {
        [$r, $c] = $firstCopy[$i];
        $module[$r][$c] = (bool)$fmtBits[$i];
        [$r, $c] = $secondCopy[$i];
        $module[$r][$c] = (bool)$fmtBits[$i];
    }

    return ['size' => $size, 'matrix' => $module];
}
