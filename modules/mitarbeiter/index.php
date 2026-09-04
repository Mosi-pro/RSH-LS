<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('mitarbeiter.view');

$pdo = db();
$q = input('q');

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE ? OR employee_id LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like);
}
$sql = 'SELECT * FROM users';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY active DESC, name ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$page_title = 'Mitarbeiter';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1>Mitarbeiter</h1>
    <?php if (has_permission('mitarbeiter.edit')): ?>
        <a href="<?= url('modules/mitarbeiter/mitarbeiter.php?id=new') ?>" class="btn btn-primary btn-sm">+ Neuer Mitarbeiter</a>
    <?php endif; ?>
</div>

<form method="get" class="card card-flat">
    <div class="form-grid">
        <div class="field"><label>Suche</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Name oder Mitarbeiter-ID"></div>
    </div>
    <button type="submit" class="btn btn-sm">Filtern</button>
</form>

<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>Name</th><th>Rolle</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($employees as $emp): ?>
            <tr onclick="location.href='<?= url('modules/mitarbeiter/mitarbeiter.php?id=' . (int)$emp['id']) ?>'" style="cursor:pointer">
                <td><?= e($emp['employee_id']) ?></td>
                <td><?= e($emp['name']) ?></td>
                <td><?= e(role_label($emp['role'])) ?></td>
                <td><span class="badge badge-<?= $emp['active'] ? 'ok' : 'muted' ?>"><?= $emp['active'] ? 'Aktiv' : 'Inaktiv' ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
