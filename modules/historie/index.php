<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('historie.view');

$pdo = db();
$entityType = input('type');
$q = input('q');
$page = max(1, (int)input('page', '1'));
$perPage = 50;

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
$countStmt = $pdo->prepare(str_replace('SELECT l.*, u.name AS user_name', 'SELECT COUNT(*)', $sql));
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql .= ' ORDER BY l.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$entityTypes = ['device' => 'Gerät', 'order' => 'Auftrag', 'event' => 'Veranstaltung', 'user' => 'Mitarbeiter',
                'storage_location' => 'Lagerort', 'device_category' => 'Kategorie'];

$page_title = 'Historie';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1>Historie / Audit-Log</h1>
    <a href="<?= url('modules/historie/export.php?' . http_build_query(['q' => $q, 'type' => $entityType])) ?>" class="btn btn-ghost btn-sm">Excel-Export</a>
</div>

<form method="get" class="card card-flat">
    <div class="form-grid">
        <div class="field"><label>Suche</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Aktion, Details, Mitarbeiter …"></div>
        <div class="field">
            <label>Bereich</label>
            <select name="type">
                <option value="">Alle</option>
                <?php foreach ($entityTypes as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $entityType === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <button type="submit" class="btn btn-sm">Filtern</button>
    <a href="<?= url('modules/historie/index.php') ?>" class="btn btn-ghost btn-sm">Zurücksetzen</a>
</form>

<?php if (!$entries): ?>
    <div class="empty-state"><div class="es-icon">▥</div>Keine Einträge gefunden.</div>
<?php else: ?>
<ul class="timeline">
    <?php foreach ($entries as $h): ?>
        <li>
            <span class="t-time"><?= format_datetime($h['created_at']) ?></span>
            <span class="t-body">
                <strong><?= e($h['action']) ?></strong>
                <span class="badge badge-muted"><?= e($entityTypes[$h['entity_type']] ?? $h['entity_type']) ?></span>
                <?php if ($h['details']): ?> – <?= e($h['details']) ?><?php endif; ?>
                <?php if ($h['user_name']): ?><div class="small muted">von <?= e($h['user_name']) ?></div><?php endif; ?>
            </span>
        </li>
    <?php endforeach; ?>
</ul>

<div class="btn-row" style="margin-top:16px;">
    <?php if ($page > 1): ?>
        <a class="btn btn-sm" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Neuer</a>
    <?php endif; ?>
    <?php if ($page * $perPage < $total): ?>
        <a class="btn btn-sm" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Älter →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
