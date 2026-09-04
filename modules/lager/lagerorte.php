<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('lager.view');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('lager.edit');
    csrf_verify();
    $action = input('form_action');

    if ($action === 'create') {
        $name = input('name');
        $parent = input('parent_id') ?: null;
        if ($name !== '') {
            $pdo->prepare('INSERT INTO storage_locations (name, parent_id) VALUES (?, ?)')->execute([$name, $parent]);
            log_activity('Lagerort angelegt', 'storage_location', (int)$pdo->lastInsertId(), $name);
            flash('success', 'Lagerort angelegt.');
        }
    } elseif ($action === 'delete') {
        $id = (int)input('id');
        $pdo->prepare('DELETE FROM storage_locations WHERE id = ?')->execute([$id]);
        log_activity('Lagerort gelöscht', 'storage_location', $id);
        flash('success', 'Lagerort gelöscht.');
    }
    redirect('modules/lager/lagerorte.php');
}

$locations = $pdo->query('SELECT l.*, p.name AS parent_name FROM storage_locations l
                           LEFT JOIN storage_locations p ON p.id = l.parent_id ORDER BY p.name IS NULL DESC, p.name, l.name')->fetchAll();
$allLocations = $pdo->query('SELECT * FROM storage_locations ORDER BY name')->fetchAll();

$page_title = 'Lagerorte';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1>Lagerorte</h1>
    <a href="<?= url('modules/lager/index.php') ?>" class="btn btn-ghost btn-sm">← Zurück zum Lager</a>
</div>

<?php if (has_permission('lager.edit')): ?>
<form method="post" class="card card-flat">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="create">
    <div class="form-grid">
        <div class="field">
            <label>Name (z.B. Technikraum, Regal A, Fach 03)</label>
            <input type="text" name="name" required>
        </div>
        <div class="field">
            <label>Übergeordneter Ort</label>
            <select name="parent_id">
                <option value="">– kein übergeordneter Ort –</option>
                <?php foreach ($allLocations as $l): ?>
                    <option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Hinzufügen</button>
</form>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead><tr><th>Ort</th><th>Übergeordnet</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($locations as $l): ?>
            <tr>
                <td><?= e($l['name']) ?></td>
                <td><?= e($l['parent_name'] ?? '–') ?></td>
                <td class="text-right">
                    <?php if (has_permission('lager.edit')): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Lagerort löschen?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">Löschen</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
