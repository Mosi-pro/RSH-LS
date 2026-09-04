<?php
/**
 * RSH-LS – Such-API (JSON)
 * GET /api/search.php?q=SM58
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht angemeldet.']);
    exit;
}

$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
$result = ['devices' => [], 'orders' => []];

if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';

    if (has_permission('lager.view')) {
        $stmt = $pdo->prepare('SELECT id, inventory_number, name, status FROM devices
                                WHERE inventory_number LIKE ? OR name LIKE ? OR manufacturer LIKE ? OR model LIKE ? OR serial_number LIKE ?
                                ORDER BY name LIMIT 15');
        $stmt->execute([$like, $like, $like, $like, $like]);
        $result['devices'] = $stmt->fetchAll();
    }

    if (has_permission('auftraege.view') || has_permission('auftraege.view_own')) {
        $sql = 'SELECT id, order_number, title, status FROM orders WHERE (order_number LIKE ? OR title LIKE ?)';
        $params = [$like, $like];
        if (!has_permission('auftraege.view')) {
            $sql .= ' AND responsible_user_id = ?';
            $params[] = current_user()['id'];
        }
        $sql .= ' ORDER BY id DESC LIMIT 15';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result['orders'] = $stmt->fetchAll();
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
