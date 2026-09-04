<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
if (!has_permission('auftraege.view') && !has_permission('auftraege.view_own')) {
    require_permission('auftraege.view');
}

$pdo   = db();
$user  = current_user();
$id    = input('id', 'new');
$isNew = ($id === 'new');
$order = null;

if (!$isNew) {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([(int)$id]);
    $order = $stmt->fetch();
    if (!$order) {
        flash('error', 'Auftrag nicht gefunden.');
        redirect('modules/auftraege/index.php');
    }
    if (!has_permission('auftraege.view') && (int)$order['responsible_user_id'] !== (int)$user['id']) {
        require_permission('auftraege.view'); // 403
    }
}

$statuses = ['entwurf','geplant','vorbereitung','bereit_zur_ausgabe','ausgegeben','im_einsatz','rueckgabe_ausstehend','abgeschlossen','storniert'];
$canEdit  = has_permission('auftraege.edit');

// ---------------------------------------------------------------
// POST-Aktionen
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        require_permission('auftraege.edit');
    }
    csrf_verify();
    $action = input('form_action');

    if ($action === 'create') {
        $title = input('title');
        if ($title === '') {
            flash('error', 'Bitte einen Auftragsnamen angeben.');
        } else {
            $orderNumber = generate_order_number();
            $stmt = $pdo->prepare(
                'INSERT INTO orders (order_number, title, description, customer, contact_person, location,
                    event_date, setup_date, teardown_date, responsible_user_id, status, event_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "entwurf", ?, ?)'
            );
            $stmt->execute([
                $orderNumber, $title, input('description'), input('customer'), input('contact_person'),
                input('location'), input('event_date') ?: null, input('setup_date') ?: null,
                input('teardown_date') ?: null, input('responsible_user_id') ?: null,
                input('event_id') ?: null, $user['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            log_activity('Auftrag erstellt', 'order', $newId, '#' . $orderNumber . ' ' . $title);
            flash('success', 'Auftrag #' . $orderNumber . ' wurde angelegt.');
            redirect('modules/auftraege/auftrag.php?id=' . $newId);
        }
    } elseif ($action === 'update' && !$isNew) {
        $stmt = $pdo->prepare(
            'UPDATE orders SET title=?, description=?, customer=?, contact_person=?, location=?, event_date=?,
                setup_date=?, teardown_date=?, responsible_user_id=?, event_id=? WHERE id=?'
        );
        $stmt->execute([
            input('title'), input('description'), input('customer'), input('contact_person'), input('location'),
            input('event_date') ?: null, input('setup_date') ?: null, input('teardown_date') ?: null,
            input('responsible_user_id') ?: null, input('event_id') ?: null, (int)$order['id'],
        ]);
        log_activity('Auftrag bearbeitet', 'order', (int)$order['id'], input('title'));
        flash('success', 'Änderungen gespeichert.');
        redirect('modules/auftraege/auftrag.php?id=' . (int)$order['id']);

    } elseif ($action === 'set_status' && !$isNew) {
        $newStatus = input('status');
        if (in_array($newStatus, $statuses, true)) {
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$newStatus, (int)$order['id']]);
            log_activity('Statuswechsel', 'order', (int)$order['id'], order_status_label($order['status']) . ' → ' . order_status_label($newStatus));
            flash('success', 'Status geändert auf „' . order_status_label($newStatus) . '“.');
        }
        redirect('modules/auftraege/auftrag.php?id=' . (int)$order['id'] . '&tab=uebersicht');

    } elseif ($action === 'add_item' && !$isNew) {
        $deviceId = (int)input('device_id');
        $qty = max(1, (int)input('quantity', '1'));
        $note = input('note');

        $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ?');
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch();

        if (!$device) {
            flash('error', 'Gerät nicht gefunden.');
        } else {
            if (!$device['is_bulk']) {
                $qty = 1;
                $check = $pdo->prepare('SELECT COUNT(*) FROM order_devices WHERE order_id = ? AND device_id = ?');
                $check->execute([(int)$order['id'], $deviceId]);
                if ((int)$check->fetchColumn() > 0) {
                    flash('error', $device['inventory_number'] . ' ist bereits in diesem Auftrag.');
                    redirect('modules/auftraege/auftrag.php?id=' . (int)$order['id'] . '&tab=technik');
                }
                if ($device['status'] !== 'verfuegbar') {
                    flash('error', $device['inventory_number'] . ' ist aktuell nicht verfügbar (' . device_status_label($device['status']) . ').');
                    redirect('modules/auftraege/auftrag.php?id=' . (int)$order['id'] . '&tab=technik');
                }
            }

            $pdo->prepare('INSERT INTO order_items (order_id, device_id, quantity, note) VALUES (?, ?, ?, ?)')
                ->execute([(int)$order['id'], $deviceId, $qty, $note]);

            if (!$device['is_bulk']) {
                $pdo->prepare('INSERT INTO order_devices (order_id, device_id, status) VALUES (?, ?, "reserviert")')
                    ->execute([(int)$order['id'], $deviceId]);
                $pdo->prepare('UPDATE devices SET status = "reserviert", current_order_id = ? WHERE id = ?')
                    ->execute([(int)$order['id'], $deviceId]);
                log_activity('Für Auftrag reserviert', 'device', $deviceId, $device['inventory_number'] . ' für Auftrag #' . $order['order_number']);
            }
            log_activity('Technik hinzugefügt', 'order', (int)$order['id'], $qty . ' × ' . $device['name']);
            flash('success', $device['name'] . ' wurde hinzugefügt.');
        }
        redirect('modules/auftraege/auftrag.php?id=' . (int)$order['id'] . '&tab=technik');

    } elseif ($action === 'remove_item' && !$isNew) {
        $itemId = (int)input('item_id');
        $stmt = $pdo->prepare('SELECT * FROM order_items WHERE id = ? AND order_id = ?');
        $stmt->execute([$itemId, (int)$order['id']]);
        $item = $stmt->fetch();
        if ($item) {
            $devStmt = $pdo->prepare('SELECT * FROM devices WHERE id = ?');
            $devStmt->execute([$item['device_id']]);
            $device = $devStmt->fetch();

            $pdo->prepare('DELETE FROM order_items WHERE id = ?')->execute([$itemId]);

            if ($device && !$device['is_bulk']) {
                $pdo->prepare('DELETE FROM order_devices WHERE order_id = ? AND device_id = ?')
                    ->execute([(int)$order['id'], $device['id']]);
                if ($device['status'] === 'reserviert' && (int)$device['current_order_id'] === (int)$order['id']) {
                    $pdo->prepare('UPDATE devices SET status = "verfuegbar", current_order_id = NULL WHERE id = ?')
                        ->execute([$device['id']]);
                    log_activity('Reservierung aufgehoben', 'device', $device['id'], $device['inventory_number']);
                }
            }
            log_activity('Technik entfernt', 'order', (int)$order['id'], $device['name'] ?? ('Position #' . $itemId));
            flash('success', 'Position entfernt.');
        }
        redirect('modules/auftraege/auftrag.php?id=' . (int)$order['id'] . '&tab=technik');

    } elseif ($action === 'delete' && !$isNew) {
        $pdo->prepare('UPDATE devices SET status = "verfuegbar", current_order_id = NULL WHERE current_order_id = ?')->execute([(int)$order['id']]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([(int)$order['id']]);
        log_activity('Auftrag gelöscht', 'order', (int)$order['id'], '#' . $order['order_number']);
        flash('success', 'Auftrag gelöscht.');
        redirect('modules/auftraege/index.php');
    }
}

// ---------------------------------------------------------------
// Neuer Auftrag – Formular
// ---------------------------------------------------------------
if ($isNew) {
    require_permission('auftraege.edit');
    $employees = $pdo->query("SELECT * FROM users WHERE active = 1 ORDER BY name")->fetchAll();
    $events = $pdo->query('SELECT * FROM events ORDER BY event_date DESC LIMIT 100')->fetchAll();

    $page_title = 'Neuer Auftrag';
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <h1>Neuer Auftrag</h1>
    <form method="post" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="create">
        <div class="form-grid">
            <div class="field"><label>Auftragsname *</label><input type="text" name="title" required></div>
            <div class="field"><label>Kunde / Veranstalter</label><input type="text" name="customer"></div>
            <div class="field"><label>Ansprechpartner</label><input type="text" name="contact_person"></div>
            <div class="field"><label>Veranstaltungsort</label><input type="text" name="location"></div>
            <div class="field"><label>Veranstaltungsdatum</label><input type="date" name="event_date"></div>
            <div class="field"><label>Aufbau</label><input type="date" name="setup_date"></div>
            <div class="field"><label>Abbau</label><input type="date" name="teardown_date"></div>
            <div class="field">
                <label>Verantwortlich</label>
                <select name="responsible_user_id">
                    <option value="">–</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= (int)$emp['id'] ?>" <?= (int)$emp['id'] === (int)$user['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?> (<?= e($emp['employee_id']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Veranstaltung</label>
                <select name="event_id">
                    <option value="">– kein Bezug –</option>
                    <?php foreach ($events as $ev): ?>
                        <option value="<?= (int)$ev['id'] ?>"><?= e($ev['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field"><label>Beschreibung</label><textarea name="description"></textarea></div>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Auftrag anlegen</button>
            <a href="<?= url('modules/auftraege/index.php') ?>" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
    <?php
    exit;
}

// ---------------------------------------------------------------
// Bestehender Auftrag – Ansicht
// ---------------------------------------------------------------
$tab = input('tab', 'uebersicht');

$itemsStmt = $pdo->prepare('SELECT oi.*, d.name AS device_name, d.inventory_number, d.is_bulk, d.status AS device_status
                             FROM order_items oi JOIN devices d ON d.id = oi.device_id
                             WHERE oi.order_id = ? ORDER BY d.is_bulk, d.name');
$itemsStmt->execute([(int)$order['id']]);
$items = $itemsStmt->fetchAll();

$devicesStmt = $pdo->prepare('SELECT od.*, d.name AS device_name, d.inventory_number
                               FROM order_devices od JOIN devices d ON d.id = od.device_id
                               WHERE od.order_id = ? ORDER BY d.name');
$devicesStmt->execute([(int)$order['id']]);
$orderDevices = $devicesStmt->fetchAll();

$historyStmt = $pdo->prepare('SELECT l.*, u.name AS user_name FROM activity_log l LEFT JOIN users u ON u.id = l.user_id
                               WHERE l.entity_type = "order" AND l.entity_id = ? ORDER BY l.created_at DESC LIMIT 100');
$historyStmt->execute([(int)$order['id']]);
$history = $historyStmt->fetchAll();

$employees = $pdo->query("SELECT * FROM users WHERE active = 1 ORDER BY name")->fetchAll();
$events = $pdo->query('SELECT * FROM events ORDER BY event_date DESC LIMIT 100')->fetchAll();
$availableDevices = $pdo->query("SELECT * FROM devices WHERE status = 'verfuegbar' OR is_bulk = 1 ORDER BY name LIMIT 500")->fetchAll();

$page_title = '#' . $order['order_number'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1>Auftrag #<?= e($order['order_number']) ?> – <?= e($order['title']) ?></h1>
    <span class="badge badge-<?= status_class($order['status']) ?>"><?= e(order_status_label($order['status'])) ?></span>
</div>

<div class="tabs">
    <a class="tab <?= $tab === 'uebersicht' ? 'active' : '' ?>" href="<?= url('modules/auftraege/auftrag.php?id=' . (int)$order['id'] . '&tab=uebersicht') ?>">Übersicht</a>
    <a class="tab <?= $tab === 'technik' ? 'active' : '' ?>" href="<?= url('modules/auftraege/auftrag.php?id=' . (int)$order['id'] . '&tab=technik') ?>">Technik (<?= count($items) ?>)</a>
    <a class="tab <?= $tab === 'verlauf' ? 'active' : '' ?>" href="<?= url('modules/auftraege/auftrag.php?id=' . (int)$order['id'] . '&tab=verlauf') ?>">Verlauf</a>
    <a class="tab" href="<?= url('modules/auftraege/pdf.php?id=' . (int)$order['id']) ?>" target="_blank" rel="noopener">Als PDF ↓</a>
    <?php if (has_permission('ausgabe_rueckgabe.edit')): ?>
    <a class="tab" href="<?= url('modules/ausgabe/ausgabe.php?order=' . e($order['order_number'])) ?>">Ausgabe →</a>
    <a class="tab" href="<?= url('modules/rueckgabe/rueckgabe.php?order=' . e($order['order_number'])) ?>">Rückgabe →</a>
    <?php endif; ?>
</div>

<?php if ($tab === 'uebersicht'): ?>

    <?php if ($canEdit): ?>
    <div class="card card-flat">
        <h3>Status ändern</h3>
        <form method="post" class="btn-row" style="align-items:center">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="set_status">
            <select name="status">
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= e($s) ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= e(order_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm">Übernehmen</button>
        </form>
    </div>
    <?php endif; ?>

    <form method="post" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="update">
        <div class="form-grid">
            <div class="field"><label>Auftragsname *</label><input type="text" name="title" required value="<?= e($order['title']) ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
            <div class="field"><label>Kunde / Veranstalter</label><input type="text" name="customer" value="<?= e($order['customer'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
            <div class="field"><label>Ansprechpartner</label><input type="text" name="contact_person" value="<?= e($order['contact_person'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
            <div class="field"><label>Veranstaltungsort</label><input type="text" name="location" value="<?= e($order['location'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
            <div class="field"><label>Veranstaltungsdatum</label><input type="date" name="event_date" value="<?= e($order['event_date'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
            <div class="field"><label>Aufbau</label><input type="date" name="setup_date" value="<?= e($order['setup_date'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
            <div class="field"><label>Abbau</label><input type="date" name="teardown_date" value="<?= e($order['teardown_date'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
            <div class="field">
                <label>Verantwortlich</label>
                <select name="responsible_user_id" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="">–</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= (int)$emp['id'] ?>" <?= (int)$order['responsible_user_id'] === (int)$emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?> (<?= e($emp['employee_id']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Veranstaltung</label>
                <select name="event_id" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="">– kein Bezug –</option>
                    <?php foreach ($events as $ev): ?>
                        <option value="<?= (int)$ev['id'] ?>" <?= (int)$order['event_id'] === (int)$ev['id'] ? 'selected' : '' ?>><?= e($ev['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field"><label>Beschreibung</label><textarea name="description" <?= $canEdit ? '' : 'disabled' ?>><?= e($order['description'] ?? '') ?></textarea></div>
        <?php if ($canEdit): ?>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Speichern</button>
            <button type="submit" name="form_action" value="delete" formnovalidate class="btn btn-danger" data-confirm="Auftrag #<?= e($order['order_number']) ?> wirklich löschen? Reservierungen werden aufgehoben.">Auftrag löschen</button>
        </div>
        <?php endif; ?>
    </form>

<?php elseif ($tab === 'technik'): ?>

    <?php if ($canEdit): ?>
    <div class="card card-flat">
        <h3>Technik hinzufügen</h3>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="add_item">
            <div class="field">
                <label>Gerät</label>
                <select name="device_id" required>
                    <option value="">– Gerät wählen –</option>
                    <?php foreach ($availableDevices as $d): ?>
                        <option value="<?= (int)$d['id'] ?>"><?= e($d['inventory_number']) ?> – <?= e($d['name']) ?><?= $d['is_bulk'] ? ' (Bestand: ' . (int)$d['quantity'] . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Menge (nur bei Mengenartikeln)</label><input type="number" name="quantity" min="1" value="1"></div>
            <div class="field"><label>Bemerkung</label><input type="text" name="note"></div>
            <div class="field" style="justify-content:flex-end"><button type="submit" class="btn btn-primary">Hinzufügen</button></div>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="empty-state"><div class="es-icon">▤</div>Noch keine Technik zusammengestellt.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Gerät</th><th>Menge</th><th>Bemerkung</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= $it['is_bulk'] ? '' : e($it['inventory_number']) . ' – ' ?><?= e($it['device_name']) ?></td>
                    <td><?= (int)$it['quantity'] ?><?= $it['is_bulk'] ? ' Stk.' : '' ?></td>
                    <td><?= e($it['note'] ?? '') ?></td>
                    <td><?= $it['is_bulk'] ? '<span class="badge badge-muted">Mengenartikel</span>' : '<span class="badge badge-' . status_class($it['device_status']) . '">' . e(device_status_label($it['device_status'])) . '</span>' ?></td>
                    <td class="text-right">
                        <?php if ($canEdit): ?>
                        <form method="post" onsubmit="return confirm('Position entfernen?');" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="remove_item">
                            <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm">Entfernen</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php elseif ($tab === 'verlauf'): ?>

    <?php if (!$history): ?>
        <p class="muted">Noch keine Einträge.</p>
    <?php else: ?>
    <ul class="timeline">
        <?php foreach ($history as $h): ?>
            <li>
                <span class="t-time"><?= format_datetime($h['created_at']) ?></span>
                <span class="t-body"><strong><?= e($h['action']) ?></strong><?php if ($h['details']): ?> – <?= e($h['details']) ?><?php endif; ?>
                    <?php if ($h['user_name']): ?><div class="small muted">von <?= e($h['user_name']) ?></div><?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
