<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('veranstaltungen.view');

$pdo   = db();
$user  = current_user();
$id    = input('id', 'new');
$isNew = ($id === 'new');
$event = null;
$statuses = ['geplant','vorbereitung','laeuft','abgeschlossen','storniert'];

if (!$isNew) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([(int)$id]);
    $event = $stmt->fetch();
    if (!$event) {
        flash('error', 'Veranstaltung nicht gefunden.');
        redirect('modules/veranstaltungen/index.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('veranstaltungen.edit');
    csrf_verify();
    $action = input('form_action', 'save');

    if ($action === 'delete' && !$isNew) {
        $pdo->prepare('UPDATE orders SET event_id = NULL WHERE event_id = ?')->execute([(int)$event['id']]);
        $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([(int)$event['id']]);
        log_activity('Veranstaltung gelöscht', 'event', (int)$event['id'], $event['name']);
        flash('success', 'Veranstaltung gelöscht.');
        redirect('modules/veranstaltungen/index.php');
    }

    $name = input('name');
    if ($name === '') {
        flash('error', 'Bitte einen Namen angeben.');
    } else {
        $data = [
            $name, input('event_date') ?: null, input('event_time') ?: null, input('location'),
            input('organizer'), input('contact_person'), input('setup_date') ?: null,
            input('teardown_date') ?: null, input('responsible_user_id') ?: null,
            input('status', 'geplant'), input('notes'),
        ];
        if ($isNew) {
            $stmt = $pdo->prepare(
                'INSERT INTO events (name, event_date, event_time, location, organizer, contact_person,
                    setup_date, teardown_date, responsible_user_id, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute(array_merge($data, [$user['id']]));
            $newId = (int)$pdo->lastInsertId();
            log_activity('Veranstaltung angelegt', 'event', $newId, $name);
            flash('success', 'Veranstaltung angelegt.');
            redirect('modules/veranstaltungen/veranstaltung.php?id=' . $newId);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE events SET name=?, event_date=?, event_time=?, location=?, organizer=?, contact_person=?,
                    setup_date=?, teardown_date=?, responsible_user_id=?, status=?, notes=? WHERE id=?'
            );
            $stmt->execute(array_merge($data, [(int)$event['id']]));
            log_activity('Veranstaltung bearbeitet', 'event', (int)$event['id'], $name);
            flash('success', 'Änderungen gespeichert.');
            redirect('modules/veranstaltungen/veranstaltung.php?id=' . (int)$event['id']);
        }
    }
}

$employees = $pdo->query("SELECT * FROM users WHERE active = 1 ORDER BY name")->fetchAll();
$linkedOrders = [];
if (!$isNew) {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE event_id = ? ORDER BY id DESC');
    $stmt->execute([(int)$event['id']]);
    $linkedOrders = $stmt->fetchAll();
}

$page_title = $isNew ? 'Neue Veranstaltung' : $event['name'];
require_once __DIR__ . '/../../includes/header.php';
$canEdit = has_permission('veranstaltungen.edit');
?>
<div class="section-head">
    <h1><?= $isNew ? 'Neue Veranstaltung' : e($event['name']) ?></h1>
    <?php if (!$isNew): ?><span class="badge badge-<?= status_class($event['status']) ?>"><?= e(event_status_label($event['status'])) ?></span><?php endif; ?>
</div>

<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field"><label>Name *</label><input type="text" name="name" required value="<?= e($event['name'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field"><label>Datum</label><input type="date" name="event_date" value="<?= e($event['event_date'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field"><label>Uhrzeit</label><input type="time" name="event_time" value="<?= e($event['event_time'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field"><label>Ort</label><input type="text" name="location" value="<?= e($event['location'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field"><label>Veranstalter</label><input type="text" name="organizer" value="<?= e($event['organizer'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field"><label>Ansprechpartner</label><input type="text" name="contact_person" value="<?= e($event['contact_person'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field"><label>Aufbau</label><input type="date" name="setup_date" value="<?= e($event['setup_date'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field"><label>Abbau</label><input type="date" name="teardown_date" value="<?= e($event['teardown_date'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field">
            <label>Verantwortlich</label>
            <select name="responsible_user_id" <?= $canEdit ? '' : 'disabled' ?>>
                <option value="">–</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int)$emp['id'] ?>" <?= (int)($event['responsible_user_id'] ?? 0) === (int)$emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Status</label>
            <select name="status" <?= $canEdit ? '' : 'disabled' ?>>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= e($s) ?>" <?= ($event['status'] ?? 'geplant') === $s ? 'selected' : '' ?>><?= e(event_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="field"><label>Notizen</label><textarea name="notes" <?= $canEdit ? '' : 'disabled' ?>><?= e($event['notes'] ?? '') ?></textarea></div>
    <?php if ($canEdit): ?>
    <div class="btn-row">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Anlegen' : 'Speichern' ?></button>
        <?php if (!$isNew): ?>
            <button type="submit" name="form_action" value="delete" formnovalidate class="btn btn-danger" data-confirm="Veranstaltung wirklich löschen?">Löschen</button>
        <?php endif; ?>
        <a href="<?= url('modules/veranstaltungen/index.php') ?>" class="btn btn-ghost">Abbrechen</a>
    </div>
    <?php endif; ?>
</form>

<?php if (!$isNew): ?>
<div class="section-head">
    <h2>Verknüpfte Aufträge</h2>
    <?php if (has_permission('auftraege.edit')): ?>
        <a href="<?= url('modules/auftraege/auftrag.php?id=new') ?>" class="btn btn-ghost btn-sm">+ Auftrag anlegen</a>
    <?php endif; ?>
</div>
<?php if (!$linkedOrders): ?>
    <p class="muted">Noch keine Aufträge verknüpft.</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Nr.</th><th>Titel</th><th>Termin</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($linkedOrders as $o): ?>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
