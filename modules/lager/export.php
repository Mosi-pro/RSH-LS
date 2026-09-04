<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('lager.view');
require_once __DIR__ . '/../../includes/xlsx_writer.php';

$pdo = db();

$q        = input('q');
$status   = input('status');
$category = input('category');

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(d.name LIKE ? OR d.inventory_number LIKE ? OR d.manufacturer LIKE ? OR d.model LIKE ? OR d.serial_number LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($status !== '') {
    $where[] = 'd.status = ?';
    $params[] = $status;
}
if ($category !== '') {
    $where[] = 'd.category_id = ?';
    $params[] = $category;
}

$sql = 'SELECT d.*, c.name AS category_name, l.name AS location_name
        FROM devices d
        LEFT JOIN device_categories c ON c.id = d.category_id
        LEFT JOIN storage_locations l ON l.id = d.location_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY d.inventory_number ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$headers = ['Inventarnummer', 'Name', 'Kategorie', 'Hersteller', 'Modell', 'Lagerort', 'Status', 'Menge', 'Seriennummer', 'Bemerkungen'];
$rows = [];
foreach ($stmt as $d) {
    $rows[] = [
        $d['inventory_number'],
        $d['name'],
        $d['category_name'],
        $d['manufacturer'],
        $d['model'],
        $d['location_name'],
        device_status_label($d['status']),
        (int)$d['quantity'],
        $d['serial_number'],
        $d['notes'],
    ];
}

stream_xlsx('lager_' . date('Y-m-d') . '.xlsx', $headers, $rows, 'Lager');
