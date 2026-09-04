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
            $pdo->prepare('INSERT INTO device_categories (name, parent_id) VALUES (?, ?)')->execute([$name, $parent]);
            log_activity('Kategorie angelegt', 'device_category', (int)$pdo->lastInsertId(), $name);
            flash('success', 'Kategorie angelegt.');
        }
    } elseif ($action === 'delete') {
        $id = (int)input('id');
        $pdo->prepare('DELETE FROM device_categories WHERE id = ?')->execute([$id]);
        log_activity('Kategorie gelöscht', 'device_category', $id);
        flash('success', 'Kategorie gelöscht.');
    }
    redirect('modules/lager/kategorien.php');
}

$categories = $pdo->query('SELECT c.*, p.name AS parent_name FROM device_categories c
                            LEFT JOIN device_categories p ON p.id = c.parent_id ORDER BY p.name IS NULL DESC, p.name, c.name')->fetchAll();
$allCategories = $pdo->query('SELECT * FROM device_categories ORDER BY name')->fetchAll();

$page_title = 'Kategorien';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1>Kategorien</h1>
    <a href="<?= url('modules/lager/index.php') ?>" class="btn btn-ghost btn-sm">← Zurück zum Lager</a>
</div>

<?php if (has_permission('lager.edit')): ?>
<form method="post" class="card card-flat">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="create">
    <div class="form-grid">
        <div class="field">
            <label>Name (z.B. Licht, Ton, Video)</label>
            <input type="text" name="name" required>
        </div>
        <div class="field">
            <label>Übergeordnete Kategorie</label>
            <select name="parent_id">
                <option value="">– keine –</option>
                <?php foreach ($allCategories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Hinzufügen</button>
</form>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead><tr><th>Kategorie</th><th>Übergeordnet</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
            <tr>
                <td><?= e($c['name']) ?></td>
                <td><?= e($c['parent_name'] ?? '–') ?></td>
                <td class="text-right">
                    <?php if (has_permission('lager.edit')): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Kategorie löschen?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
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
