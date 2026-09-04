<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('lager.view');

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
$sql .= ' ORDER BY d.inventory_number ASC LIMIT 300';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll();

$categories = $pdo->query('SELECT * FROM device_categories ORDER BY name')->fetchAll();

$page_title = 'Lager';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1>Lager – Geräte</h1>
    <div class="btn-row">
        <a href="<?= url('modules/lager/lagerorte.php') ?>" class="btn btn-ghost btn-sm">Lagerorte</a>
        <a href="<?= url('modules/lager/kategorien.php') ?>" class="btn btn-ghost btn-sm">Kategorien</a>
        <?php if (has_permission('lager.edit')): ?>
            <a href="<?= url('modules/lager/import.php') ?>" class="btn btn-ghost btn-sm">Aus Excel importieren</a>
            <a href="<?= url('modules/lager/geraet.php?id=new') ?>" class="btn btn-primary btn-sm">+ Neues Gerät</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="card card-flat">
    <div class="form-grid">
        <div class="field">
            <label>Suche</label>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Name, Inventarnr., Hersteller …">
        </div>
        <div class="field">
            <label>Status</label>
            <select name="status">
                <option value="">Alle</option>
                <?php foreach (['verfuegbar','reserviert','ausgegeben','defekt','wartung','verloren','aussortiert'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(device_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Kategorie</label>
            <select name="category">
                <option value="">Alle</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $category == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <button type="submit" class="btn btn-sm">Filtern</button>
    <a href="<?= url('modules/lager/index.php') ?>" class="btn btn-ghost btn-sm">Zurücksetzen</a>
</form>

<?php if (!$devices): ?>
    <div class="empty-state"><div class="es-icon">▣</div>Keine Geräte gefunden.</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Inv.-Nr.</th><th>Name</th><th>Kategorie</th><th>Lagerort</th><th>Bestand</th><th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
            <tr onclick="location.href='<?= url('modules/lager/geraet.php?id=' . (int)$d['id']) ?>'" style="cursor:pointer">
                <td><?= e($d['inventory_number']) ?></td>
                <td><?= e($d['name']) ?><?php if ($d['manufacturer']): ?><div class="small muted"><?= e($d['manufacturer']) ?> <?= e($d['model']) ?></div><?php endif; ?></td>
                <td><?= e($d['category_name'] ?? '–') ?></td>
                <td><?= e($d['location_name'] ?? '–') ?></td>
                <td><?= $d['is_bulk'] ? (int)$d['quantity'] . ' Stk.' : '1 Stk.' ?></td>
                <td><span class="badge badge-<?= status_class($d['status']) ?>"><?= e(device_status_label($d['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
