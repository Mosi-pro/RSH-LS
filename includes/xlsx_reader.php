<?php
/**
 * RSH-LS – Minimaler XLSX-Reader (ohne externe Bibliotheken)
 *
 * Liest das erste Arbeitsblatt einer .xlsx-Datei zeilenweise aus.
 * Nutzt nur PHP-Bordmittel (ZipArchive + DOMDocument/DOMXPath), damit
 * der Import ohne Composer/zusätzliche Pakete auf jedem PHP-Webhosting
 * läuft.
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

const XLSX_NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const XLSX_NS_RELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

function xlsx_load_xml(string $xml): ?DOMDocument
{
    $doc = new DOMDocument();
    $doc->loadXML($xml, LIBXML_NOCDATA);
    return $doc;
}

/**
 * @return array<int, array<string,string>> Liste von Zeilen, jede Zeile als
 *         [Spaltenbuchstabe => Zellwert] (nur belegte Zellen enthalten).
 * @throws RuntimeException
 */
function read_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Die PHP-Erweiterung "zip" wird für den Import benötigt.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Die Datei ist keine gültige .xlsx-Datei.');
    }

    // -- Shared Strings -------------------------------------------------
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $doc = xlsx_load_xml($sharedXml);
        if ($doc !== null) {
            $xp = new DOMXPath($doc);
            $xp->registerNamespace('a', XLSX_NS_MAIN);
            foreach ($xp->query('//a:si') as $si) {
                $text = '';
                foreach ($xp->query('.//a:t', $si) as $t) {
                    $text .= $t->textContent;
                }
                $shared[] = $text;
            }
        }
    }

    // -- Pfad zum ersten Arbeitsblatt ermitteln --------------------------
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml !== false && $relsXml !== false) {
        $wbDoc = xlsx_load_xml($workbookXml);
        $relsDoc = xlsx_load_xml($relsXml);
        if ($wbDoc !== null && $relsDoc !== null) {
            $wbXp = new DOMXPath($wbDoc);
            $wbXp->registerNamespace('a', XLSX_NS_MAIN);
            $wbXp->registerNamespace('r', XLSX_NS_RELS);
            $sheets = $wbXp->query('//a:sheets/a:sheet');
            if ($sheets && $sheets->length > 0) {
                $rId = $sheets->item(0)->getAttributeNS(XLSX_NS_RELS, 'id');
                $relsXp = new DOMXPath($relsDoc);
                foreach ($relsXp->query('//*[local-name()="Relationship"]') as $rel) {
                    if ($rel->getAttribute('Id') === $rId) {
                        $target = ltrim($rel->getAttribute('Target'), '/');
                        $sheetPath = str_starts_with($target, 'worksheets/') ? 'xl/' . $target : $target;
                        break;
                    }
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('Es konnte kein Arbeitsblatt in der Datei gefunden werden.');
    }

    $sheetDoc = xlsx_load_xml($sheetXml);
    if ($sheetDoc === null) {
        throw new RuntimeException('Das Arbeitsblatt konnte nicht gelesen werden.');
    }
    $sheetXp = new DOMXPath($sheetDoc);
    $sheetXp->registerNamespace('a', XLSX_NS_MAIN);

    $rows = [];
    foreach ($sheetXp->query('//a:sheetData/a:row') as $row) {
        $rowData = [];
        foreach ($sheetXp->query('a:c', $row) as $c) {
            $ref = $c->getAttribute('r');
            $col = preg_replace('/[0-9]+/', '', $ref);
            if ($col === '') {
                continue;
            }
            $type = $c->getAttribute('t');
            $value = '';
            $vNode = $sheetXp->query('a:v', $c)->item(0);
            if ($type === 's') {
                $idx = $vNode !== null ? (int)$vNode->textContent : -1;
                $value = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $isNode = $sheetXp->query('a:is/a:t', $c)->item(0);
                $value = $isNode !== null ? $isNode->textContent : '';
            } elseif ($vNode !== null) {
                $value = $vNode->textContent;
            }
            $rowData[$col] = $value;
        }
        if ($rowData) {
            $rows[] = $rowData;
        }
    }

    return $rows;
}

/** Excel-Datumsseriennummer (z.B. "46030") in ISO-Datum (YYYY-MM-DD) umwandeln. */
function xlsx_serial_to_date(string $serial): ?string
{
    if (!is_numeric($serial)) {
        return null;
    }
    $days = (int)round((float)$serial);
    if ($days <= 0) {
        return null;
    }
    try {
        $date = new DateTime('1899-12-30');
        $date->modify('+' . $days . ' days');
        return $date->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}
