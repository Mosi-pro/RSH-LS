<?php
/**
 * RSH-LS – Baut den Auftragszettel (PDF) aus einer Auftrags-Zeile.
 * Von modules/auftraege/pdf.php (Anzeige/Download) UND vom automatischen
 * E-Mail-Versand bei Auftragserstellung genutzt.
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

require_once __DIR__ . '/pdf_writer.php';

function build_order_pdf(array $order): SimplePdf
{
    $pdo = db();

    $itemsStmt = $pdo->prepare(
        'SELECT oi.*, d.name AS device_name, d.inventory_number, d.is_bulk
         FROM order_items oi JOIN devices d ON d.id = oi.device_id
         WHERE oi.order_id = ? ORDER BY d.is_bulk, d.name'
    );
    $itemsStmt->execute([(int)$order['id']]);
    $items = $itemsStmt->fetchAll();

    $pdf = new SimplePdf();
    $pdf->setHeader('AUFTRAG #' . $order['order_number'], $order['title']);
    $pdf->setFooter('RSH Technik · erstellt am ' . date('d.m.Y H:i'));

    $pdf->addKeyValue('Kunde / Veranstalter', (string)$order['customer'], 0);
    $pdf->addKeyValue('Ansprechpartner', (string)$order['contact_person']);
    $pdf->addKeyValue('Technik-Kontakt', (string)($order['technical_contact'] ?: ''));
    $pdf->addKeyValue('Veranstaltungsort', (string)$order['location']);
    $pdf->addKeyValue('Veranstaltungsdatum', format_date($order['event_date']));
    $pdf->addKeyValue('Aufbau', format_date($order['setup_date']));
    $pdf->addKeyValue('Abbau', format_date($order['teardown_date']));
    if (!empty($order['responsible_name'])) {
        $pdf->addKeyValue('Verantwortlich', (string)$order['responsible_name']);
    }
    $pdf->addKeyValue('Status', order_status_label($order['status']));
    if ($order['technician_count']) {
        $pdf->addKeyValue('Anzahl Techniker', (string)(int)$order['technician_count']);
    }

    if (!empty($order['assigned_students'])) {
        $pdf->addRule(14);
        $pdf->addLine('EINGESETZTE PERSONEN', 12, true, 10);
        $pdf->addSpacer(4);
        foreach (explode("\n", $order['assigned_students']) as $line) {
            $pdf->addLine($line, 10, false, 3);
        }
    }

    if (!empty($order['schedule_details'])) {
        $pdf->addRule(14);
        $pdf->addLine('ZEITPLAN', 12, true, 10);
        $pdf->addSpacer(4);
        foreach (explode("\n", $order['schedule_details']) as $line) {
            $pdf->addLine($line, 10, false, 3);
        }
    }

    $pdf->addRule(14);
    $pdf->addLine('GEPLANTE TECHNIK  ·  ' . count($items) . ' Positionen', 12, true, 10);
    $pdf->addSpacer(6);

    if (!$items) {
        $pdf->addLine('Keine Technik zugeordnet.', 10, false, 4);
    } else {
        foreach ($items as $it) {
            $label = $it['is_bulk']
                ? $it['quantity'] . ' × ' . $it['device_name']
                : $it['inventory_number'] . '   ' . $it['device_name'];
            $pdf->addLine($label, 10, false, 6, ['checkbox' => true]);
            if ($it['note']) {
                $pdf->addLine($it['note'], 8.5, false, 1, ['color' => [0.5, 0.52, 0.57]]);
            }
        }
    }

    if ($order['description']) {
        $pdf->addRule(16);
        $pdf->addLine('BESCHREIBUNG', 12, true, 10);
        $pdf->addSpacer(4);
        foreach (explode("\n", $order['description']) as $line) {
            $pdf->addLine($line, 10, false, 3);
        }
    }

    if (!empty($order['request_id'])) {
        $reqStmt = $pdo->prepare('SELECT * FROM event_requests WHERE id = ?');
        $reqStmt->execute([(int)$order['request_id']]);
        $origRequest = $reqStmt->fetch();
        if ($origRequest) {
            $pdf->addRule(16);
            $pdf->addLine('ANFRAGE-DETAILS', 12, true, 10);
            $pdf->addSpacer(4);
            $pdf->addKeyValue('Antragsteller', $origRequest['requester_name'] . ' (' . $origRequest['requester_email'] . ')');
            if ($origRequest['needs_licht']) {
                $pdf->addKeyValue('Licht', (string)$origRequest['licht_details']);
            }
            if ($origRequest['needs_ton']) {
                $pdf->addKeyValue('Ton', (string)$origRequest['ton_details']);
            }
            if ($origRequest['needs_atrium']) {
                $pdf->addKeyValue('Atrium', ucfirst((string)$origRequest['atrium_mode']));
            }
        }
    }

    return $pdf;
}
