<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('inventur.view');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('inventur.edit');
    csrf_verify();

    $locationId = input('location_id') ?: null;
    $locationName = null;
    if ($locationId) {
        $l = $pdo->prepare('SELECT name FROM storage_locations WHERE id = ?');
        $l->execute([$locationId]);
        $locationName = $l->fetchColumn();
    }
    $label = 'Inventur ' . date('d.m.Y') . ($locationName ? ' – ' . $locationName : ' – Gesamtes Lager');

    $user = current_user();
    $pdo->prepare('INSERT INTO inventories (label, location_id, status, started_by) VALUES (?, ?, "laufend", ?)')
        ->execute([$label, $locationId, $user['id']]);
    $inventoryId = (int)$pdo->lastInsertId();

    $sql = "SELECT id FROM devices WHERE status != 'aussortiert'";
    $params = [];
    if ($locationId) {
        $sql .= ' AND location_id = ?';
        $params[] = $locationId;
    }
    $devices = $pdo->prepare($sql);
    $devices->execute($params);

    $insertItem = $pdo->prepare('INSERT INTO inventory_items (inventory_id, device_id) VALUES (?, ?)');
    foreach ($devices->fetchAll(PDO::FETCH_COLUMN) as $deviceId) {
        $insertItem->execute([$inventoryId, $deviceId]);
    }

    log_activity('Inventur gestartet', 'inventory', $inventoryId, $label);
    redirect('modules/inventur/inventur.php?id=' . $inventoryId);
}

$inventories = $pdo->query(
    "SELECT i.*, u.name AS started_by_name, l.name AS location_name,
        (SELECT COUNT(*) FROM inventory_items ii WHERE ii.inventory_id = i.id) AS total,
        (SELECT COUNT(*) FROM inventory_items ii WHERE ii.inventory_id = i.id AND ii.found = 1) AS found
     FROM inventories i
     LEFT JOIN users u ON u.id = i.started_by
     LEFT JOIN storage_locations l ON l.id = i.location_id
     ORDER BY i.id DESC LIMIT 100"
)->fetchAll();

$locations = $pdo->query('SELECT * FROM storage_locations ORDER BY name')->fetchAll();

$page_title = 'Inventur';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head"><h1>Inventur</h1></div>

<?php if (has_permission('inventur.edit')): ?>
<form method="post" class="card card-flat">
    <?= csrf_field() ?>
    <h3>Neue Inventur starten</h3>
    <div class="form-grid">
        <div class="field">
            <label>Bereich (optional)</label>
            <select name="location_id">
                <option value="">Gesamtes Lager</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Inventur starten</button>
</form>
<?php endif; ?>

<?php if (!$inventories): ?>
    <div class="empty-state"><div class="es-icon">▤</div>Noch keine Inventur durchgeführt.</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Bezeichnung</th><th>Bereich</th><th>Gestartet von</th><th>Gestartet am</th><th>Fortschritt</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($inventories as $inv): ?>
            <tr onclick="location.href='<?= url('modules/inventur/inventur.php?id=' . (int)$inv['id']) ?>'" style="cursor:pointer">
                <td><?= e($inv['label']) ?></td>
                <td><?= e($inv['location_name'] ?? 'Gesamtes Lager') ?></td>
                <td><?= e($inv['started_by_name'] ?? '–') ?></td>
                <td><?= format_datetime($inv['started_at']) ?></td>
                <td><?= (int)$inv['found'] ?> / <?= (int)$inv['total'] ?></td>
                <td><span class="badge badge-<?= $inv['status'] === 'abgeschlossen' ? 'ok' : 'warn' ?>"><?= $inv['status'] === 'abgeschlossen' ? 'Abgeschlossen' : 'Läuft' ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
