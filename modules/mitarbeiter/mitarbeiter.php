<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('mitarbeiter.view');

$pdo   = db();
$id    = input('id', 'new');
$isNew = ($id === 'new');
$employee = null;
$roles = ['technikleitung','lagerleitung','mitarbeiter','veranstaltungsleitung','lager_terminal','gast'];

if (!$isNew) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int)$id]);
    $employee = $stmt->fetch();
    if (!$employee) {
        flash('error', 'Mitarbeiter nicht gefunden.');
        redirect('modules/mitarbeiter/index.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('mitarbeiter.edit');
    csrf_verify();
    $action = input('form_action', 'save');

    if ($action === 'delete' && !$isNew) {
        $pdo->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([(int)$employee['id']]);
        log_activity('Mitarbeiter deaktiviert', 'user', (int)$employee['id'], $employee['name']);
        flash('success', 'Mitarbeiter wurde deaktiviert.');
        redirect('modules/mitarbeiter/index.php');
    }

    $empId = preg_replace('/\D/', '', input('employee_id'));
    $name  = input('name');
    $role  = input('role', 'mitarbeiter');
    $active = input('active') === '1' ? 1 : 0;

    if ($empId === '' || $name === '' || !in_array($role, $roles, true)) {
        flash('error', 'Bitte Mitarbeiter-ID, Name und Rolle angeben.');
    } else {
        if ($isNew) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE employee_id = ?');
            $check->execute([$empId]);
            if ((int)$check->fetchColumn() > 0) {
                flash('error', 'Mitarbeiter-ID ' . $empId . ' ist bereits vergeben.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (employee_id, name, role, active, notes) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$empId, $name, $role, $active, input('notes')]);
                $newId = (int)$pdo->lastInsertId();
                log_activity('Mitarbeiter angelegt', 'user', $newId, $name . ' (' . $empId . ')');
                flash('success', 'Mitarbeiter angelegt.');
                redirect('modules/mitarbeiter/mitarbeiter.php?id=' . $newId);
            }
        } else {
            $stmt = $pdo->prepare('UPDATE users SET employee_id=?, name=?, role=?, active=?, notes=? WHERE id=?');
            $stmt->execute([$empId, $name, $role, $active, input('notes'), (int)$employee['id']]);
            log_activity('Mitarbeiter bearbeitet', 'user', (int)$employee['id'], $name);
            flash('success', 'Änderungen gespeichert.');
            redirect('modules/mitarbeiter/mitarbeiter.php?id=' . (int)$employee['id']);
        }
    }
}

$myOrders = [];
$myDevices = [];
if (!$isNew) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE responsible_user_id = ? AND status NOT IN ('abgeschlossen','storniert') ORDER BY event_date");
    $stmt->execute([(int)$employee['id']]);
    $myOrders = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT d.*, o.order_number FROM devices d
                            JOIN order_devices od ON od.device_id = d.id AND od.status = 'ausgegeben'
                            JOIN orders o ON o.id = od.order_id
                            WHERE od.checked_out_by = ? ORDER BY d.name");
    $stmt->execute([(int)$employee['id']]);
    $myDevices = $stmt->fetchAll();
}

$canEdit = has_permission('mitarbeiter.edit');
$page_title = $isNew ? 'Neuer Mitarbeiter' : $employee['name'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1><?= $isNew ? 'Neuer Mitarbeiter' : e($employee['name']) . ' (' . e($employee['employee_id']) . ')' ?></h1>
    <?php if (!$isNew): ?><span class="badge badge-<?= $employee['active'] ? 'ok' : 'muted' ?>"><?= $employee['active'] ? 'Aktiv' : 'Inaktiv' ?></span><?php endif; ?>
</div>

<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field"><label>Mitarbeiter-ID *</label><input type="text" name="employee_id" inputmode="numeric" required value="<?= e($employee['employee_id'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field"><label>Name *</label><input type="text" name="name" required value="<?= e($employee['name'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field">
            <label>Rolle / Berechtigungsgruppe</label>
            <select name="role" <?= $canEdit ? '' : 'disabled' ?>>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= e($r) ?>" <?= ($employee['role'] ?? 'mitarbeiter') === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="checkbox-row"><input type="checkbox" name="active" value="1" <?= ($employee['active'] ?? 1) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> Aktiv</label>
        </div>
    </div>
    <div class="field"><label>Bemerkungen</label><textarea name="notes" <?= $canEdit ? '' : 'disabled' ?>><?= e($employee['notes'] ?? '') ?></textarea></div>
    <?php if ($canEdit): ?>
    <div class="btn-row">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Anlegen' : 'Speichern' ?></button>
        <?php if (!$isNew): ?>
            <button type="submit" name="form_action" value="delete" formnovalidate class="btn btn-danger" data-confirm="Mitarbeiter deaktivieren?">Deaktivieren</button>
        <?php endif; ?>
        <a href="<?= url('modules/mitarbeiter/index.php') ?>" class="btn btn-ghost">Abbrechen</a>
    </div>
    <?php endif; ?>
</form>

<?php if (!$isNew): ?>
<div class="section-head"><h2>Aktuelle Aufträge</h2></div>
<?php if (!$myOrders): ?>
    <p class="muted">Keine offenen Aufträge.</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Nr.</th><th>Titel</th><th>Termin</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($myOrders as $o): ?>
            <tr onclick="location.href='<?= url('modules/auftraege/auftrag.php?id=' . (int)$o['id']) ?>'" style="cursor:pointer">
                <td>#<?= e($o['order_number']) ?></td>
                <td><?= e($o['title']) ?></td>
                <td><?= format_date($o['event_date']) ?></td>
                <td><span class="badge badge-<?= status_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="section-head"><h2>Aktuell ausgegebene Geräte</h2></div>
<?php if (!$myDevices): ?>
    <p class="muted">Aktuell keine Geräte ausgegeben.</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Inv.-Nr.</th><th>Gerät</th><th>Auftrag</th></tr></thead>
        <tbody>
        <?php foreach ($myDevices as $d): ?>
            <tr onclick="location.href='<?= url('modules/lager/geraet.php?id=' . (int)$d['id']) ?>'" style="cursor:pointer">
                <td><?= e($d['inventory_number']) ?></td>
                <td><?= e($d['name']) ?></td>
                <td>#<?= e($d['order_number']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
