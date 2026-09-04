<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('veranstaltungen.view');

$pdo = db();
$q = input('q');

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(e.name LIKE ? OR e.location LIKE ? OR e.organizer LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$sql = 'SELECT e.*, u.name AS responsible_name,
        (SELECT COUNT(*) FROM orders o WHERE o.event_id = e.id) AS order_count
        FROM events e LEFT JOIN users u ON u.id = e.responsible_user_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (e.event_date IS NULL), e.event_date DESC, e.id DESC LIMIT 300';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

$page_title = 'Veranstaltungen';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1>Veranstaltungen</h1>
    <?php if (has_permission('veranstaltungen.edit')): ?>
        <a href="<?= url('modules/veranstaltungen/veranstaltung.php?id=new') ?>" class="btn btn-primary btn-sm">+ Neue Veranstaltung</a>
    <?php endif; ?>
</div>

<form method="get" class="card card-flat">
    <div class="form-grid">
        <div class="field"><label>Suche</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Name, Ort, Veranstalter …"></div>
    </div>
    <button type="submit" class="btn btn-sm">Filtern</button>
</form>

<?php if (!$events): ?>
    <div class="empty-state"><div class="es-icon">◉</div>Keine Veranstaltungen gefunden.</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Name</th><th>Datum</th><th>Ort</th><th>Verantwortlich</th><th>Aufträge</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($events as $ev): ?>
            <tr onclick="location.href='<?= url('modules/veranstaltungen/veranstaltung.php?id=' . (int)$ev['id']) ?>'" style="cursor:pointer">
                <td><?= e($ev['name']) ?></td>
                <td><?= format_date($ev['event_date']) ?></td>
                <td><?= e($ev['location'] ?? '–') ?></td>
                <td><?= e($ev['responsible_name'] ?? '–') ?></td>
                <td><?= (int)$ev['order_count'] ?></td>
                <td><span class="badge badge-<?= status_class($ev['status']) ?>"><?= e(event_status_label($ev['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
