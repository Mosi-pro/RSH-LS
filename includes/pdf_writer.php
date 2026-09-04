<?php
/**
 * RSH-LS – Minimaler PDF-Writer (ohne externe Bibliotheken)
 *
 * Erzeugt mehrseitige PDFs (Auftragszettel/Packlisten) direkt aus PHP –
 * mit dunklem Kopfband, Trennlinien, echten Checkbox-Kästchen und
 * Fußzeile mit Seitenzahl. Nur Standard-Helvetica, keine Bilder/externen
 * Ressourcen nötig.
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

require_once __DIR__ . '/qr_encoder.php';

class SimplePdf
{
    /** @var array<int, array{text:string,size:int,bold:bool,gapBefore:float,checkbox:bool,rule:bool,color:array}> */
    private array $lines = [];
    private string $headerTitle = '';
    private string $headerSubtitle = '';
    private string $footerText = '';
    private ?array $headerQr = null;

    public function setHeader(string $title, string $subtitle = ''): void
    {
        $this->headerTitle = $title;
        $this->headerSubtitle = $subtitle;
    }

    /** Zeigt oben rechts im Kopfband einen QR-Code, der auf $url verweist (digitale Ansicht). */
    public function setHeaderQrUrl(string $url): void
    {
        $this->headerQr = qr_encode($url);
    }

    public function setFooter(string $text): void
    {
        $this->footerText = $text;
    }

    /**
     * @param array{color?:array{0:float,1:float,2:float}, checkbox?:bool} $opts
     */
    public function addLine(string $text, int $size = 11, bool $bold = false, float $gapBefore = 2, array $opts = []): void
    {
        $this->lines[] = [
            'text'      => $text,
            'size'      => $size,
            'bold'      => $bold,
            'gapBefore' => $gapBefore,
            'checkbox'  => $opts['checkbox'] ?? false,
            'rule'      => false,
            'color'     => $opts['color'] ?? [0.09, 0.10, 0.13],
        ];
    }

    public function addSpacer(float $height = 8): void
    {
        $this->lines[] = ['text' => '', 'size' => 0, 'bold' => false, 'gapBefore' => $height, 'checkbox' => false, 'rule' => false, 'color' => [0, 0, 0]];
    }

    /** Zweifarbige "Label: Wert"-Zeile (gedämpftes Label + dunkler Wert), wie auf einem Formular. */
    public function addKeyValue(string $label, string $value, float $gapBefore = 4): void
    {
        $this->lines[] = [
            'text' => '', 'size' => 0, 'bold' => false, 'gapBefore' => $gapBefore, 'checkbox' => false, 'rule' => false, 'color' => [0, 0, 0],
            'runs' => [
                ['text' => mb_strtoupper($label), 'size' => 8, 'bold' => false, 'color' => [0.5, 0.52, 0.57], 'dx' => 0],
                ['text' => $value !== '' ? $value : '–', 'size' => 10, 'bold' => false, 'color' => [0.09, 0.10, 0.13], 'dx' => 115],
            ],
            'lineHeight' => 15,
        ];
    }

    /** Dünne horizontale Trennlinie über die volle Breite. */
    public function addRule(float $gapBefore = 8): void
    {
        $this->lines[] = ['text' => '', 'size' => 0, 'bold' => false, 'gapBefore' => $gapBefore, 'checkbox' => false, 'rule' => true, 'color' => [0, 0, 0]];
    }

    private function pdfEscape(string $s): string
    {
        // Häufige Unicode-Satzzeichen, die es nicht nach ISO-8859-1 (WinAnsi) schaffen,
        // vorab durch Latin-1-Äquivalente ersetzen, statt sie als "?" darzustellen.
        $s = strtr($s, [
            "\u{2013}" => '-', "\u{2014}" => '-',
            "\u{2018}" => "'", "\u{2019}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2026}" => '...',
        ]);
        $s = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        if ($s === false) {
            $s = '';
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    public function render(): string
    {
        $pageWidth = 595.28;
        $pageHeight = 841.89;
        $marginX = 50;
        $headerHeight = 66;
        $footerReserve = 45;
        $marginTop = $pageHeight - $headerHeight - 26;
        $marginBottom = $footerReserve;

        $pages = [];
        $page = [];
        $y = $marginTop;

        foreach ($this->lines as $line) {
            $y -= $line['gapBefore'];

            if ($line['rule']) {
                if ($y < $marginBottom) {
                    $pages[] = $page;
                    $page = [];
                    $y = $marginTop;
                }
                $page[] = ['type' => 'rule', 'x1' => $marginX, 'x2' => $pageWidth - $marginX, 'y' => $y];
                $y -= 2;
                continue;
            }

            if (isset($line['runs'])) {
                if ($y < $marginBottom) {
                    $pages[] = $page;
                    $page = [];
                    $y = $marginTop;
                }
                $runs = [];
                foreach ($line['runs'] as $r) {
                    $runs[] = ['x' => $marginX + $r['dx'], 'y' => $y, 'text' => $r['text'], 'size' => $r['size'], 'bold' => $r['bold'], 'color' => $r['color']];
                }
                $page[] = ['type' => 'multitext', 'runs' => $runs];
                $y -= $line['lineHeight'];
                continue;
            }

            if ($line['text'] === '') {
                continue;
            }
            if ($y < $marginBottom) {
                $pages[] = $page;
                $page = [];
                $y = $marginTop;
            }

            $indent = $line['checkbox'] ? 16 : 0;
            $entry = [
                'type' => 'text', 'x' => $marginX + $indent, 'y' => $y,
                'text' => $line['text'], 'size' => $line['size'], 'bold' => $line['bold'], 'color' => $line['color'],
            ];
            if ($line['checkbox']) {
                $entry['checkbox'] = true;
                $entry['checkboxX'] = $marginX;
                $entry['checkboxY'] = $y - 1;
            }
            $page[] = $entry;
            $y -= $line['size'] * 1.35;
        }
        $pages[] = $page;

        return $this->buildPdf($pages, $pageWidth, $pageHeight, $marginX, $headerHeight);
    }

    /** Zeichnet den QR-Code (weiße Ruhezone + schwarze Module) oben rechts im Kopfband. */
    private function renderQrGraphics(array $qr, float $w, float $h, float $marginX): string
    {
        $size = $qr['size'];
        $matrix = $qr['matrix'];
        $quiet = 3;
        $boxSize = 44.0;
        $moduleSize = $boxSize / ($size + 2 * $quiet);
        $boxX = $w - $marginX - $boxSize;
        $boxY = $h - 8 - $boxSize;

        $g = "1 1 1 rg\n";
        $g .= sprintf("%.2F %.2F %.2F %.2F re f\n", $boxX, $boxY, $boxSize, $boxSize);
        $g .= "0 0 0 rg\n";
        for ($row = 0; $row < $size; $row++) {
            $col = 0;
            while ($col < $size) {
                if (!$matrix[$row][$col]) {
                    $col++;
                    continue;
                }
                $runStart = $col;
                while ($col < $size && $matrix[$row][$col]) {
                    $col++;
                }
                $runLen = $col - $runStart;
                $rx = $boxX + ($quiet + $runStart) * $moduleSize;
                $ry = $boxY + $boxSize - ($quiet + $row + 1) * $moduleSize;
                $g .= sprintf("%.3F %.3F %.3F %.3F re f\n", $rx, $ry, $moduleSize * $runLen, $moduleSize);
            }
        }
        return $g;
    }

    /** @param array<int, array<int, array<string,mixed>>> $pages */
    private function buildPdf(array $pages, float $w, float $h, float $marginX, float $headerHeight): string
    {
        $totalPages = count($pages);

        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $nextObjNum = 5;
        $pageObjNums = [];
        $pageIndex = 0;

        foreach ($pages as $pageEntries) {
            $pageIndex++;
            $pageNum = $nextObjNum++;
            $contentNum = $nextObjNum++;
            $pageObjNums[] = $pageNum;

            // -- Grafik: Kopfband, Häkchen-Kästchen, Trennlinien, Fußzeilenlinie --
            $graphics = sprintf("%.3F %.3F %.3F rg\n", 0.106, 0.118, 0.141);
            $graphics .= sprintf("0 %.2F %.2F %.2F re f\n", $h - $headerHeight, $w, $headerHeight);

            if ($pageIndex === 1 && $this->headerQr !== null) {
                $graphics .= $this->renderQrGraphics($this->headerQr, $w, $h, $marginX);
            }

            foreach ($pageEntries as $e) {
                if ($e['type'] === 'rule') {
                    $graphics .= "0.82 0.83 0.86 RG\n0.75 w\n";
                    $graphics .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $e['x1'], $e['y'], $e['x2'], $e['y']);
                } elseif (!empty($e['checkbox'])) {
                    $graphics .= "0.3 0.32 0.37 RG\n0.9 w\n";
                    $graphics .= sprintf("%.2F %.2F 9 9 re S\n", $e['checkboxX'], $e['checkboxY']);
                }
            }

            $graphics .= "0.85 0.86 0.89 RG\n0.75 w\n";
            $graphics .= sprintf("%.2F 34 m %.2F 34 l S\n", $marginX, $w - $marginX);

            // -- Text: Kopfzeile, Inhalt, Fußzeile --
            $text = "BT\n";
            $text .= "1 1 1 rg\n/F2 17 Tf\n";
            $text .= sprintf("1 0 0 1 %.2F %.2F Tm\n", $marginX, $h - 32);
            $text .= '(' . $this->pdfEscape($this->headerTitle) . ") Tj\n";
            if ($this->headerSubtitle !== '') {
                $text .= "0.7 0.79 1 rg\n/F1 10 Tf\n";
                $text .= sprintf("1 0 0 1 %.2F %.2F Tm\n", $marginX, $h - 49);
                $text .= '(' . $this->pdfEscape($this->headerSubtitle) . ") Tj\n";
            }

            foreach ($pageEntries as $e) {
                if ($e['type'] === 'multitext') {
                    foreach ($e['runs'] as $r) {
                        $c = $r['color'];
                        $text .= sprintf("%.3F %.3F %.3F rg\n", $c[0], $c[1], $c[2]);
                        $font = $r['bold'] ? '/F2' : '/F1';
                        $text .= sprintf("%s %d Tf\n", $font, $r['size']);
                        $text .= sprintf("1 0 0 1 %.2F %.2F Tm\n", $r['x'], $r['y']);
                        $text .= '(' . $this->pdfEscape($r['text']) . ") Tj\n";
                    }
                    continue;
                }
                if ($e['type'] !== 'text') {
                    continue;
                }
                $c = $e['color'];
                $text .= sprintf("%.3F %.3F %.3F rg\n", $c[0], $c[1], $c[2]);
                $font = $e['bold'] ? '/F2' : '/F1';
                $text .= sprintf("%s %d Tf\n", $font, $e['size']);
                $text .= sprintf("1 0 0 1 %.2F %.2F Tm\n", $e['x'], $e['y']);
                $text .= '(' . $this->pdfEscape($e['text']) . ") Tj\n";
            }

            $text .= "0.5 0.5 0.53 rg\n/F1 8 Tf\n";
            if ($this->footerText !== '') {
                $text .= sprintf("1 0 0 1 %.2F 22 Tm\n", $marginX);
                $text .= '(' . $this->pdfEscape($this->footerText) . ") Tj\n";
            }
            $pageLabel = 'Seite ' . $pageIndex . ' von ' . $totalPages;
            $pageLabelX = $w - $marginX - (strlen($pageLabel) * 4.3);
            $text .= sprintf("1 0 0 1 %.2F 22 Tm\n", $pageLabelX);
            $text .= '(' . $this->pdfEscape($pageLabel) . ") Tj\n";
            $text .= "ET";

            $stream = $graphics . $text;
            $objects[$contentNum] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $objects[$pageNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . $w . ' ' . $h . "] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents " . $contentNum . " 0 R >>";
        }

        $kidsStr = implode(' ', array_map(fn($n) => $n . ' 0 R', $pageObjNums));
        $objects[2] = "<< /Type /Pages /Kids [" . $kidsStr . "] /Count " . count($pageObjNums) . " >>";

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }

        $maxNum = max(array_keys($objects));
        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxNum + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxNum; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxNum + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefStart . "\n%%EOF";

        return $pdf;
    }
}

/** Baut das PDF und sendet es direkt zur Anzeige/zum Download. Beendet das Skript. */
function stream_pdf(string $filename, SimplePdf $pdf, bool $inline = true): void
{
    $content = $pdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}
