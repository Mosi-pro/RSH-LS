<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('lager.view');

$pdo = db();
$id  = input('id', 'new');
$isNew = ($id === 'new');
$device = null;

if (!$isNew) {
    $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ?');
    $stmt->execute([(int)$id]);
    $device = $stmt->fetch();
    if (!$device) {
        flash('error', 'Gerät nicht gefunden.');
        redirect('modules/lager/index.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('lager.edit');
    csrf_verify();

    $action = input('form_action', 'save');

    if ($action === 'delete' && !$isNew) {
        $pdo->prepare('DELETE FROM devices WHERE id = ?')->execute([(int)$device['id']]);
        log_activity('Gerät gelöscht', 'device', (int)$device['id'], $device['inventory_number'] . ' ' . $device['name']);
        flash('success', 'Gerät wurde gelöscht.');
        redirect('modules/lager/index.php');
    }

    $data = [
        'name'            => input('name'),
        'category_id'     => input('category_id') ?: null,
        'manufacturer'    => input('manufacturer'),
        'model'           => input('model'),
        'serial_number'   => input('serial_number'),
        'location_id'     => input('location_id') ?: null,
        'condition_note'  => input('condition_note'),
        'status'          => input('status', 'verfuegbar'),
        'purchase_date'   => input('purchase_date') ?: null,
        'purchase_price'  => input('purchase_price') !== '' ? (float)input('purchase_price') : null,
        'description'     => input('description'),
        'accessories'     => input('accessories'),
        'is_bulk'         => input('is_bulk') === '1' ? 1 : 0,
        'quantity'        => max(1, (int)input('quantity', '1')),
        'barcode'         => input('barcode'),
        'notes'           => input('notes'),
        'last_maintenance_date' => input('last_maintenance_date') ?: null,
        'next_maintenance_date' => input('next_maintenance_date') ?: null,
    ];

    if ($data['name'] === '') {
        flash('error', 'Bitte einen Gerätenamen angeben.');
    } else {
        if ($isNew) {
            $invNumber = input('inventory_number') ?: generate_inventory_number();
            $stmt = $pdo->prepare(
                'INSERT INTO devices (inventory_number, name, category_id, manufacturer, model, serial_number,
                    location_id, condition_note, status, purchase_date, purchase_price, description, accessories,
                    is_bulk, quantity, barcode, notes, last_maintenance_date, next_maintenance_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $invNumber, $data['name'], $data['category_id'], $data['manufacturer'], $data['model'],
                $data['serial_number'], $data['location_id'], $data['condition_note'], $data['status'],
                $data['purchase_date'], $data['purchase_price'], $data['description'], $data['accessories'],
                $data['is_bulk'], $data['quantity'], $data['barcode'], $data['notes'],
                $data['last_maintenance_date'], $data['next_maintenance_date'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            log_activity('Gerät angelegt', 'device', $newId, $invNumber . ' ' . $data['name']);
            flash('success', 'Gerät ' . $invNumber . ' wurde angelegt.');
            redirect('modules/lager/geraet.php?id=' . $newId);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE devices SET name=?, category_id=?, manufacturer=?, model=?, serial_number=?, location_id=?,
                    condition_note=?, status=?, purchase_date=?, purchase_price=?, description=?, accessories=?,
                    is_bulk=?, quantity=?, barcode=?, notes=?, last_maintenance_date=?, next_maintenance_date=?
                 WHERE id=?'
            );
            $stmt->execute([
                $data['name'], $data['category_id'], $data['manufacturer'], $data['model'], $data['serial_number'],
                $data['location_id'], $data['condition_note'], $data['status'], $data['purchase_date'],
                $data['purchase_price'], $data['description'], $data['accessories'], $data['is_bulk'],
                $data['quantity'], $data['barcode'], $data['notes'],
                $data['last_maintenance_date'], $data['next_maintenance_date'], (int)$device['id'],
            ]);
            log_activity('Gerät bearbeitet', 'device', (int)$device['id'], $device['inventory_number'] . ' ' . $data['name']);
            flash('success', 'Änderungen gespeichert.');
            redirect('modules/lager/geraet.php?id=' . (int)$device['id']);
        }
    }
}

$categories = $pdo->query('SELECT * FROM device_categories ORDER BY name')->fetchAll();
$locations  = $pdo->query('SELECT * FROM storage_locations ORDER BY name')->fetchAll();

$history = [];
$currentOrder = null;
if (!$isNew) {
    $stmt = $pdo->prepare('SELECT l.*, u.name AS user_name FROM activity_log l LEFT JOIN users u ON u.id = l.user_id
                            WHERE l.entity_type = "device" AND l.entity_id = ? ORDER BY l.created_at DESC LIMIT 50');
    $stmt->execute([(int)$device['id']]);
    $history = $stmt->fetchAll();

    if ($device['current_order_id']) {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([(int)$device['current_order_id']]);
        $currentOrder = $stmt->fetch();
    }
}

$page_title = $isNew ? 'Neues Gerät' : $device['inventory_number'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1><?= $isNew ? 'Neues Gerät' : e($device['inventory_number']) . ' – ' . e($device['name']) ?></h1>
    <div class="btn-row" style="align-items:center;">
        <?php if (!$isNew): ?><span class="badge badge-<?= status_class($device['status']) ?>"><?= e(device_status_label($device['status'])) ?></span><?php endif; ?>
        <?php if (!$isNew && has_permission('defekte.report')): ?>
            <a href="<?= url('modules/defekte/melden.php?device_id=' . (int)$device['id']) ?>" class="btn btn-danger btn-sm">Defekt melden</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isNew && $currentOrder): ?>
<div class="card">
    <h3>Aktueller Auftrag</h3>
    <a href="<?= url('modules/auftraege/auftrag.php?id=' . (int)$currentOrder['id']) ?>">#<?= e($currentOrder['order_number']) ?> – <?= e($currentOrder['title']) ?></a>
</div>
<?php endif; ?>

<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?php if ($isNew): ?>
        <div class="field">
            <label>Inventarnummer</label>
            <input type="text" name="inventory_number" placeholder="automatisch, z.B. <?= e(generate_inventory_number()) ?>">
            <span class="hint">Leer lassen für automatische Vergabe.</span>
        </div>
        <?php endif; ?>
        <div class="field">
            <label>Name *</label>
            <input type="text" name="name" required value="<?= e($device['name'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Kategorie</label>
            <select name="category_id">
                <option value="">–</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ($device['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Hersteller</label>
            <input type="text" name="manufacturer" value="<?= e($device['manufacturer'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Modell</label>
            <input type="text" name="model" value="<?= e($device['model'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Seriennummer</label>
            <input type="text" name="serial_number" value="<?= e($device['serial_number'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Lagerort</label>
            <select name="location_id">
                <option value="">–</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= (int)$l['id'] ?>" <?= ($device['location_id'] ?? '') == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Status</label>
            <select name="status">
                <?php foreach (['verfuegbar','reserviert','ausgegeben','defekt','wartung','verloren','aussortiert'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= ($device['status'] ?? 'verfuegbar') === $s ? 'selected' : '' ?>><?= e(device_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Zustand / Bemerkung Zustand</label>
            <input type="text" name="condition_note" value="<?= e($device['condition_note'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Anschaffungsdatum</label>
            <input type="date" name="purchase_date" value="<?= e($device['purchase_date'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Anschaffungspreis (€)</label>
            <input type="number" step="0.01" name="purchase_price" value="<?= e($device['purchase_price'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Barcode</label>
            <input type="text" name="barcode" value="<?= e($device['barcode'] ?? '') ?>">
        </div>
        <div class="field">
            <label class="checkbox-row"><input type="checkbox" name="is_bulk" value="1" <?= !empty($device['is_bulk']) ? 'checked' : '' ?>> Mengenartikel (Verbrauchsmaterial)</label>
        </div>
        <div class="field">
            <label>Menge / Bestand</label>
            <input type="number" min="1" name="quantity" value="<?= e($device['quantity'] ?? '1') ?>">
        </div>
        <div class="field">
            <label>Letzte Wartung</label>
            <input type="date" name="last_maintenance_date" value="<?= e($device['last_maintenance_date'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Nächste Wartung</label>
            <input type="date" name="next_maintenance_date" value="<?= e($device['next_maintenance_date'] ?? '') ?>">
        </div>
    </div>

    <div class="field">
        <label>Beschreibung</label>
        <textarea name="description"><?= e($device['description'] ?? '') ?></textarea>
    </div>
    <div class="field">
        <label>Zubehör</label>
        <textarea name="accessories"><?= e($device['accessories'] ?? '') ?></textarea>
    </div>
    <div class="field">
        <label>Bemerkungen</label>
        <textarea name="notes"><?= e($device['notes'] ?? '') ?></textarea>
    </div>

    <?php if (has_permission('lager.edit')): ?>
    <div class="btn-row">
        <button type="submit" name="form_action" value="save" class="btn btn-primary">Speichern</button>
        <?php if (!$isNew): ?>
            <button type="submit" name="form_action" value="delete" class="btn btn-danger" data-confirm="Gerät <?= e($device['inventory_number']) ?> wirklich löschen?">Löschen</button>
        <?php endif; ?>
        <a href="<?= url('modules/lager/index.php') ?>" class="btn btn-ghost">Abbrechen</a>
    </div>
    <?php endif; ?>
</form>

<?php if (!$isNew): ?>
<div class="card">
    <h3>QR-Code</h3>
    <div id="qrcode"></div>
    <p class="small muted">Scannen öffnet die Schnellansicht mit Status, Lagerort und Schnellaktionen.</p>
    <a href="<?= url('modules/lager/etikett.php?id=' . (int)$device['id']) ?>" class="btn btn-sm" target="_blank" rel="noopener">Etikett drucken</a>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById("qrcode"), {
    text: <?= json_encode(full_url('modules/lager/scan.php?code=' . urlencode($device['inventory_number']))) ?>,
    width: 160,
    height: 160
});
</script>

<div class="section-head"><h2>Gerätehistorie</h2></div>
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
