<?php
/**
 * RSH-LS – Minimaler PDF-Writer (ohne externe Bibliotheken)
 *
 * Erzeugt einfache, mehrseitige Text-PDFs (Auftragszettel/Packlisten)
 * direkt aus PHP – nur Standard-Helvetica, keine Bilder/Grafiken nötig.
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

class SimplePdf
{
    /** @var array<int, array{text:string,size:int,bold:bool,gapBefore:float}> */
    private array $lines = [];

    public function addLine(string $text, int $size = 11, bool $bold = false, float $gapBefore = 2): void
    {
        $this->lines[] = ['text' => $text, 'size' => $size, 'bold' => $bold, 'gapBefore' => $gapBefore];
    }

    public function addSpacer(float $height = 8): void
    {
        $this->lines[] = ['text' => '', 'size' => 0, 'bold' => false, 'gapBefore' => $height];
    }

    private function pdfEscape(string $s): string
    {
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
        $marginTop = 792;
        $marginBottom = 50;

        $pages = [];
        $page = [];
        $y = $marginTop;

        foreach ($this->lines as $line) {
            $y -= $line['gapBefore'];
            if ($line['text'] === '') {
                continue;
            }
            if ($y < $marginBottom) {
                $pages[] = $page;
                $page = [];
                $y = $marginTop;
            }
            $page[] = ['x' => $marginX, 'y' => $y, 'text' => $line['text'], 'size' => $line['size'], 'bold' => $line['bold']];
            $y -= $line['size'] * 1.35;
        }
        $pages[] = $page;

        return $this->buildPdf($pages, $pageWidth, $pageHeight);
    }

    /** @param array<int, array<int, array{x:float,y:float,text:string,size:int,bold:bool}>> $pages */
    private function buildPdf(array $pages, float $w, float $h): string
    {
        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $nextObjNum = 5;
        $pageObjNums = [];

        foreach ($pages as $pageLines) {
            $pageNum = $nextObjNum++;
            $contentNum = $nextObjNum++;
            $pageObjNums[] = $pageNum;

            $stream = "BT\n";
            foreach ($pageLines as $l) {
                $font = $l['bold'] ? '/F2' : '/F1';
                $stream .= sprintf("%s %d Tf\n", $font, $l['size']);
                $stream .= sprintf("1 0 0 1 %.2F %.2F Tm\n", $l['x'], $l['y']);
                $stream .= '(' . $this->pdfEscape($l['text']) . ") Tj\n";
            }
            $stream .= "ET";

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
