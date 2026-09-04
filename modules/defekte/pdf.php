<?php
/**
 * RSH-LS – Werkstattauftrag als PDF (Gerät, Problem, Priorität, Melder, Status)
 * mit QR-Code, der auf die digitale Detailseite verweist.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('defekte.report');
require_once __DIR__ . '/../../includes/pdf_writer.php';

$pdo = db();
$id = (int)input('id');

$stmt = $pdo->prepare(
    'SELECT f.*, d.inventory_number, d.name AS device_name, d.manufacturer, d.model,
        u.name AS reporter_name, r.name AS resolver_name
     FROM defects f
     JOIN devices d ON d.id = f.device_id
     LEFT JOIN users u ON u.id = f.reported_by
     LEFT JOIN users r ON r.id = f.resolved_by
     WHERE f.id = ?'
);
$stmt->execute([$id]);
$defect = $stmt->fetch();

if (!$defect) {
    flash('error', 'Defektmeldung nicht gefunden.');
    redirect('modules/defekte/index.php');
}

$statusLabels = ['offen' => 'Offen', 'in_bearbeitung' => 'In Bearbeitung', 'behoben' => 'Behoben'];

$pdf = new SimplePdf();
$pdf->setHeader('WERKSTATTAUFTRAG #' . $defect['id'], $defect['inventory_number'] . '  –  ' . $defect['device_name']);
$pdf->setHeaderQrUrl(full_url('modules/defekte/defekt.php?id=' . $defect['id']));
$pdf->setFooter('RSH Technik · erstellt am ' . date('d.m.Y H:i') . ' · QR-Code scannen für digitale Ansicht');

$pdf->addKeyValue('Gerät', $defect['inventory_number'] . '  ' . $defect['device_name'], 0);
if ($defect['manufacturer'] || $defect['model']) {
    $pdf->addKeyValue('Hersteller / Modell', trim($defect['manufacturer'] . ' ' . $defect['model']));
}
$pdf->addKeyValue('Priorität', ucfirst($defect['priority']));
$pdf->addKeyValue('Status', $statusLabels[$defect['status']] ?? $defect['status']);
$pdf->addKeyValue('Gemeldet von', (string)($defect['reporter_name'] ?? '–'));
$pdf->addKeyValue('Gemeldet am', format_datetime($defect['created_at']));

$pdf->addRule(16);
$pdf->addLine('PROBLEMBESCHREIBUNG', 12, true, 10);
$pdf->addSpacer(4);
foreach (explode("\n", $defect['problem']) as $line) {
    $pdf->addLine($line, 10, false, 3);
}

if ($defect['resolved_at']) {
    $pdf->addRule(16);
    $pdf->addLine('LÖSUNG', 12, true, 10);
    $pdf->addSpacer(4);
    $pdf->addKeyValue('Behoben von', (string)($defect['resolver_name'] ?? '–'));
    $pdf->addKeyValue('Behoben am', format_datetime($defect['resolved_at']));
    if ($defect['resolution_note']) {
        $pdf->addSpacer(6);
        foreach (explode("\n", $defect['resolution_note']) as $line) {
            $pdf->addLine($line, 10, false, 3);
        }
    }
} else {
    $pdf->addRule(16);
    $pdf->addLine('LÖSUNG (von der Werkstatt auszufüllen)', 12, true, 10);
    $pdf->addSpacer(30);
    $pdf->addRule(0);
    $pdf->addSpacer(20);
    $pdf->addRule(0);
}

log_activity('Werkstattauftrag als PDF exportiert', 'defect', $id, $defect['inventory_number']);

stream_pdf('werkstattauftrag_' . $defect['id'] . '.pdf', $pdf);
