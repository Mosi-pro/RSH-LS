<?php
/**
 * RSH-LS – Minimaler XLSX-Writer (ohne externe Bibliotheken)
 *
 * Erzeugt eine einfache, einblättrige .xlsx-Datei direkt aus PHP-Arrays
 * (nur ZipArchive, kein Composer/keine externe Bibliothek nötig).
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

function xlsx_col_letter(int $n): string
{
    $letter = '';
    $n++;
    while ($n > 0) {
        $rem = ($n - 1) % 26;
        $letter = chr(65 + $rem) . $letter;
        $n = intdiv($n - 1, 26);
    }
    return $letter;
}

function xlsx_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * @param string[] $headers Spaltenüberschriften
 * @param array<int, array<int, string|int|float|null>> $rows Datenzeilen (Werte in Spaltenreihenfolge)
 * @return string Binärinhalt einer gültigen .xlsx-Datei
 */
function build_xlsx(array $headers, array $rows, string $sheetName = 'Tabelle1'): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Die PHP-Erweiterung "zip" wird für den Export benötigt.');
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

    $allRows = array_merge([$headers], $rows);
    foreach ($allRows as $rIdx => $row) {
        $rowNum = $rIdx + 1;
        $sheetXml .= '<row r="' . $rowNum . '">';
        foreach (array_values($row) as $cIdx => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $ref = xlsx_col_letter($cIdx) . $rowNum;
            if (is_int($value) || is_float($value)) {
                $sheetXml .= '<c r="' . $ref . '"><v>' . xlsx_escape((string)$value) . '</v></c>';
            } else {
                $sheetXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                    . xlsx_escape((string)$value) . '</t></is></c>';
            }
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsx_escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'rshxlsx');
    $zip = new ZipArchive();
    $zip->open($tmpFile, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $content = file_get_contents($tmpFile);
    unlink($tmpFile);
    return $content;
}

/** Baut die XLSX-Datei und sendet sie direkt als Download. Beendet das Skript. */
function stream_xlsx(string $filename, array $headers, array $rows, string $sheetName = 'Tabelle1'): void
{
    $content = build_xlsx($headers, $rows, $sheetName);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: private');
    echo $content;
    exit;
}
