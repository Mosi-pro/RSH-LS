<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/pdf_writer.php';

$pdo = db();
$user = current_user();
$id = (int)input('id');

$stmt = $pdo->prepare('SELECT o.*, u.name AS responsible_name FROM orders o
                        LEFT JOIN users u ON u.id = o.responsible_user_id WHERE o.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    flash('error', 'Auftrag nicht gefunden.');
    redirect('modules/auftraege/index.php');
}
if (!has_permission('auftraege.view') && (int)$order['responsible_user_id'] !== (int)$user['id']) {
    require_permission('auftraege.view'); // 403
}

$itemsStmt = $pdo->prepare(
    'SELECT oi.*, d.name AS device_name, d.inventory_number, d.is_bulk
     FROM order_items oi JOIN devices d ON d.id = oi.device_id
     WHERE oi.order_id = ? ORDER BY d.is_bulk, d.name'
);
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$pdf = new SimplePdf();
$pdf->addLine('AUFTRAG #' . $order['order_number'], 18, true, 0);
$pdf->addLine($order['title'], 13, false, 6);
$pdf->addSpacer(14);

$pdf->addLine('Kunde/Veranstalter: ' . ($order['customer'] ?: '-'), 10, false, 2);
$pdf->addLine('Ansprechpartner: ' . ($order['contact_person'] ?: '-'), 10, false, 2);
$pdf->addLine('Veranstaltungsort: ' . ($order['location'] ?: '-'), 10, false, 2);
$pdf->addLine('Veranstaltungsdatum: ' . format_date($order['event_date']), 10, false, 2);
$pdf->addLine('Aufbau: ' . format_date($order['setup_date']), 10, false, 2);
$pdf->addLine('Abbau: ' . format_date($order['teardown_date']), 10, false, 2);
$pdf->addLine('Verantwortlich: ' . ($order['responsible_name'] ?: '-'), 10, false, 2);
$pdf->addLine('Status: ' . order_status_label($order['status']), 10, false, 2);
$pdf->addSpacer(16);

$pdf->addLine('GEPLANTE TECHNIK (' . count($items) . ' Positionen)', 12, true, 0);
$pdf->addSpacer(6);

if (!$items) {
    $pdf->addLine('Keine Technik zugeordnet.', 10, false, 2);
} else {
    foreach ($items as $it) {
        $label = $it['is_bulk']
            ? $it['quantity'] . ' x ' . $it['device_name']
            : $it['inventory_number'] . '  ' . $it['device_name'];
        $pdf->addLine('[ ]  ' . $label, 10, false, 4);
        if ($it['note']) {
            $pdf->addLine('       ' . $it['note'], 9, false, 1);
        }
    }
}

if ($order['description']) {
    $pdf->addSpacer(16);
    $pdf->addLine('BESCHREIBUNG', 12, true, 0);
    $pdf->addSpacer(4);
    foreach (explode("\n", $order['description']) as $line) {
        $pdf->addLine($line, 10, false, 2);
    }
}

log_activity('Auftragszettel als PDF exportiert', 'order', $id, '#' . $order['order_number']);

stream_pdf('auftrag_' . $order['order_number'] . '.pdf', $pdf);
