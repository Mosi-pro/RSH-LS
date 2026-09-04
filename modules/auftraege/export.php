<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
if (!has_permission('auftraege.view') && !has_permission('auftraege.view_own')) {
    require_permission('auftraege.view');
}
require_once __DIR__ . '/../../includes/xlsx_writer.php';

$pdo  = db();
$user = current_user();

$q      = input('q');
$status = input('status');

$where  = [];
$params = [];

if (!has_permission('auftraege.view') && has_permission('auftraege.view_own')) {
    $where[] = 'o.responsible_user_id = ?';
    $params[] = $user['id'];
}
if ($q !== '') {
    $where[] = '(o.order_number LIKE ? OR o.title LIKE ? OR o.customer LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($status !== '') {
    $where[] = 'o.status = ?';
    $params[] = $status;
}

$sql = 'SELECT o.*, u.name AS responsible_name FROM orders o LEFT JOIN users u ON u.id = o.responsible_user_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (o.event_date IS NULL), o.event_date DESC, o.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$headers = ['Auftragsnr.', 'Titel', 'Kunde', 'Veranstaltungsdatum', 'Aufbau', 'Abbau', 'Verantwortlich', 'Status'];
$rows = [];
foreach ($stmt as $o) {
    $rows[] = [
        $o['order_number'],
        $o['title'],
        $o['customer'],
        format_date($o['event_date']),
        format_date($o['setup_date']),
        format_date($o['teardown_date']),
        $o['responsible_name'],
        order_status_label($o['status']),
    ];
}

stream_xlsx('auftraege_' . date('Y-m-d') . '.xlsx', $headers, $rows, 'Aufträge');
