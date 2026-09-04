<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
if (!has_permission('auftraege.view') && !has_permission('auftraege.view_own')) {
    require_permission('auftraege.view'); // triggers 403
}

$pdo    = db();
$user   = current_user();
$q      = input('q');
$status = input('status');
$onlyMine = input('mine') === '1';

$where  = [];
$params = [];

if (!has_permission('auftraege.view') && has_permission('auftraege.view_own')) {
    $where[] = 'o.responsible_user_id = ?';
    $params[] = $user['id'];
}
if ($onlyMine) {
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
$sql .= ' ORDER BY (o.event_date IS NULL), o.event_date DESC, o.id DESC LIMIT 300';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$statuses = ['entwurf','geplant','vorbereitung','bereit_zur_ausgabe','ausgegeben','im_einsatz','rueckgabe_ausstehend','abgeschlossen','storniert'];

$page_title = 'Aufträge';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1>Aufträge</h1>
    <div class="btn-row">
        <a href="<?= url('modules/auftraege/export.php?' . http_build_query(['q' => $q, 'status' => $status])) ?>" class="btn btn-ghost btn-sm">Excel-Export</a>
        <?php if (has_permission('auftraege.edit')): ?>
            <a href="<?= url('modules/auftraege/auftrag.php?id=new') ?>" class="btn btn-primary btn-sm">+ Neuer Auftrag</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="card card-flat">
    <div class="form-grid">
        <div class="field">
            <label>Suche</label>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Auftragsnr., Titel, Kunde …">
        </div>
        <div class="field">
            <label>Status</label>
            <select name="status">
                <option value="">Alle</option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(order_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="checkbox-row"><input type="checkbox" name="mine" value="1" <?= $onlyMine ? 'checked' : '' ?>> Nur meine Aufträge</label>
        </div>
    </div>
    <button type="submit" class="btn btn-sm">Filtern</button>
    <a href="<?= url('modules/auftraege/index.php') ?>" class="btn btn-ghost btn-sm">Zurücksetzen</a>
</form>

<?php if (!$orders): ?>
    <div class="empty-state"><div class="es-icon">▤</div>Keine Aufträge gefunden.</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead>
        <tr><th>Nr.</th><th>Titel</th><th>Kunde</th><th>Termin</th><th>Verantwortlich</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
            <tr onclick="location.href='<?= url('modules/auftraege/auftrag.php?id=' . (int)$o['id']) ?>'" style="cursor:pointer">
                <td>#<?= e($o['order_number']) ?></td>
                <td><?= e($o['title']) ?></td>
                <td><?= e($o['customer'] ?? '–') ?></td>
                <td><?= format_date($o['event_date']) ?></td>
                <td><?= e($o['responsible_name'] ?? '–') ?></td>
                <td><span class="badge badge-<?= status_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
