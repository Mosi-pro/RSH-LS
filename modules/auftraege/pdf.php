<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

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

$pdf = build_order_pdf($order);

log_activity('Auftragszettel als PDF exportiert', 'order', $id, '#' . $order['order_number']);

stream_pdf('auftrag_' . $order['order_number'] . '.pdf', $pdf);
