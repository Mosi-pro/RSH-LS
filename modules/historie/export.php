<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('historie.view');
require_once __DIR__ . '/../../includes/xlsx_writer.php';

$pdo = db();
$entityType = input('type');
$q = input('q');

$where = [];
$params = [];
if ($entityType !== '') {
    $where[] = 'l.entity_type = ?';
    $params[] = $entityType;
}
if ($q !== '') {
    $where[] = '(l.action LIKE ? OR l.details LIKE ? OR u.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$sql = 'SELECT l.*, u.name AS user_name FROM activity_log l LEFT JOIN users u ON u.id = l.user_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY l.created_at DESC LIMIT 5000';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$headers = ['Datum', 'Mitarbeiter', 'Aktion', 'Bereich', 'Details'];
$rows = [];
foreach ($stmt as $h) {
    $rows[] = [
        format_datetime($h['created_at']),
        $h['user_name'],
        $h['action'],
        $h['entity_type'],
        $h['details'],
    ];
}

stream_xlsx('historie_' . date('Y-m-d') . '.xlsx', $headers, $rows, 'Historie');
