<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('reports.view');

$pdo = db();

// -- Lagerstatistik ---------------------------------------------------
$statusCounts = [];
foreach ($pdo->query('SELECT status, COUNT(*) AS c FROM devices GROUP BY status') as $row) {
    $statusCounts[$row['status']] = (int)$row['c'];
}
$totalDevices = array_sum($statusCounts);
$activeDevices = $totalDevices - ($statusCounts['aussortiert'] ?? 0);
$auslastung = $activeDevices > 0
    ? round((($statusCounts['ausgegeben'] ?? 0) + ($statusCounts['reserviert'] ?? 0)) / $activeDevices * 100)
    : 0;

// -- Meist ausgeliehene Geräte -----------------------------------------
$topDevices = $pdo->query(
    "SELECT d.inventory_number, d.name, COUNT(*) AS c
     FROM order_devices od JOIN devices d ON d.id = od.device_id
     GROUP BY od.device_id ORDER BY c DESC LIMIT 10"
)->fetchAll();

// -- Häufigste Defekte --------------------------------------------------
$topDefects = $pdo->query(
    "SELECT d.inventory_number, d.name, COUNT(*) AS c
     FROM defects f JOIN devices d ON d.id = f.device_id
     GROUP BY f.device_id ORDER BY c DESC LIMIT 10"
)->fetchAll();

// -- Aufträge pro Monat (letzte 12 Monate) -------------------------------
$ordersByMonth = $pdo->query(
    "SELECT strftime('%Y-%m', created_at) AS ym, COUNT(*) AS c
     FROM orders GROUP BY ym ORDER BY ym DESC LIMIT 12"
)->fetchAll();

// -- Fehlende Geräte ------------------------------------------------------
$lostDevices = $pdo->query("SELECT inventory_number, name FROM devices WHERE status = 'verloren' ORDER BY name")->fetchAll();

// -- Inventurabweichungen --------------------------------------------------
$inventoryStats = $pdo->query(
    "SELECT i.id, i.label, i.completed_at,
        (SELECT COUNT(*) FROM inventory_items ii WHERE ii.inventory_id = i.id) AS total,
        (SELECT COUNT(*) FROM inventory_items ii WHERE ii.inventory_id = i.id AND ii.found = 0) AS missing
     FROM inventories i WHERE i.status = 'abgeschlossen'
     ORDER BY i.completed_at DESC LIMIT 10"
)->fetchAll();

$page_title = 'Auswertungen';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head"><h1>Auswertungen</h1></div>

<div class="section-head"><h2>Lager</h2></div>
<div class="stat-grid">
    <div class="stat-card"><div class="stat-value"><?= $totalDevices ?></div><div class="stat-label">Geräte gesamt</div></div>
    <div class="stat-card"><div class="stat-value"><?= $statusCounts['verfuegbar'] ?? 0 ?></div><div class="stat-label">Verfügbar</div></div>
    <div class="stat-card"><div class="stat-value"><?= $statusCounts['ausgegeben'] ?? 0 ?></div><div class="stat-label">Ausgegeben</div></div>
    <div class="stat-card"><div class="stat-value"><?= $statusCounts['defekt'] ?? 0 ?></div><div class="stat-label">Defekt</div></div>
    <div class="stat-card"><div class="stat-value"><?= $statusCounts['verloren'] ?? 0 ?></div><div class="stat-label">Verloren</div></div>
    <div class="stat-card"><div class="stat-value"><?= $auslastung ?>%</div><div class="stat-label">Auslastung</div></div>
</div>

<div class="section-head"><h2>Meist ausgeliehene Geräte</h2></div>
<?php if (!$topDevices): ?>
    <p class="muted">Noch keine Ausgaben erfasst.</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Inv.-Nr.</th><th>Gerät</th><th>Ausgaben</th></tr></thead>
        <tbody>
        <?php foreach ($topDevices as $d): ?>
            <tr><td><?= e($d['inventory_number']) ?></td><td><?= e($d['name']) ?></td><td><?= (int)$d['c'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="section-head"><h2>Häufigste Defekte</h2></div>
<?php if (!$topDefects): ?>
    <p class="muted">Noch keine Defekte erfasst.</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Inv.-Nr.</th><th>Gerät</th><th>Meldungen</th></tr></thead>
        <tbody>
        <?php foreach ($topDefects as $d): ?>
            <tr><td><?= e($d['inventory_number']) ?></td><td><?= e($d['name']) ?></td><td><?= (int)$d['c'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="section-head"><h2>Aufträge pro Monat</h2></div>
<?php if (!$ordersByMonth): ?>
    <p class="muted">Noch keine Aufträge erfasst.</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Monat</th><th>Aufträge</th></tr></thead>
        <tbody>
        <?php foreach ($ordersByMonth as $m): ?>
            <tr><td><?= e($m['ym']) ?></td><td><?= (int)$m['c'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="section-head"><h2>Fehlende Geräte</h2></div>
<?php if (!$lostDevices): ?>
    <p class="muted">Keine als verloren markierten Geräte.</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Inv.-Nr.</th><th>Gerät</th></tr></thead>
        <tbody>
        <?php foreach ($lostDevices as $d): ?>
            <tr><td><?= e($d['inventory_number']) ?></td><td><?= e($d['name']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="section-head"><h2>Inventurabweichungen</h2></div>
<?php if (!$inventoryStats): ?>
    <p class="muted">Noch keine abgeschlossene Inventur.</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Inventur</th><th>Abgeschlossen</th><th>Sollbestand</th><th>Fehlend</th></tr></thead>
        <tbody>
        <?php foreach ($inventoryStats as $i): ?>
            <tr onclick="location.href='<?= url('modules/inventur/inventur.php?id=' . (int)$i['id']) ?>'" style="cursor:pointer">
                <td><?= e($i['label']) ?></td>
                <td><?= format_datetime($i['completed_at']) ?></td>
                <td><?= (int)$i['total'] ?></td>
                <td><?= (int)$i['missing'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
