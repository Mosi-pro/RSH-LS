<?php
/**
 * RSH-LS – Schnellausgabe: Geräte direkt vom Dashboard aus scannen und
 * ausbuchen, ohne vorher manuell einen Auftrag anzulegen.
 *
 * Im Hintergrund entsteht dabei trotzdem ein ganz normaler, schlanker
 * Auftrag (gleiche Tabellen wie bei jedem anderen Auftrag) – dadurch bleiben
 * Schema, Rückgabe-Ablauf, Historie und Etiketten (Inventarnummern)
 * unverändert; es gibt keine neue Tabelle und keine Migration.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('ausgabe_rueckgabe.edit');

$pdo = db();
$user = current_user();
$orderId = (int)input('order');
$order = null;

if ($orderId) {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order || $order['status'] !== 'entwurf') {
        flash('error', 'Diese Schnellausgabe wurde nicht gefunden oder ist bereits abgeschlossen.');
        redirect('modules/ausgabe/schnell.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('form_action');

    if ($action === 'cancel' && $order) {
        $pdo->prepare('UPDATE devices SET status = "verfuegbar", current_order_id = NULL WHERE current_order_id = ?')
            ->execute([(int)$order['id']]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([(int)$order['id']]);
        log_activity('Schnellausgabe abgebrochen', 'order', (int)$order['id'], '#' . $order['order_number']);
        flash('info', 'Schnellausgabe abgebrochen, Reservierungen wieder aufgehoben.');
        redirect('modules/ausgabe/schnell.php');
    }

    if ($action === 'scan') {
        $code = trim(input('code'));
        $qty  = max(1, (int)input('quantity', '1'));
        $back = 'modules/ausgabe/schnell.php' . ($order ? '?order=' . (int)$order['id'] : '');

        if ($code === '') {
            flash('error', 'Bitte eine Inventarnummer scannen oder eingeben.');
            redirect($back);
        }

        $devStmt = $pdo->prepare('SELECT * FROM devices WHERE inventory_number = ?');
        $devStmt->execute([$code]);
        $device = $devStmt->fetch();

        if (!$device) {
            flash('error', '„' . $code . '“ wurde nicht gefunden.');
            redirect($back);
        }
        if (!$device['is_bulk'] && $device['status'] !== 'verfuegbar') {
            flash('error', $device['inventory_number'] . ' ist aktuell nicht verfügbar (' . device_status_label($device['status']) . ').');
            redirect($back);
        }

        // Auftrag erst beim ersten gescannten Gerät anlegen (kein leerer Auftrag,
        // falls jemand die Seite nur aus Versehen öffnet).
        if (!$order) {
            $orderNumber = generate_order_number();
            $purpose = trim(input('purpose'));
            $title = $purpose !== '' ? $purpose : ('Schnellausgabe ' . date('d.m.Y H:i'));
            $pdo->prepare(
                'INSERT INTO orders (order_number, title, responsible_user_id, status, created_by)
                 VALUES (?, ?, ?, "entwurf", ?)'
            )->execute([$orderNumber, $title, $user['id'], $user['id']]);
            $orderId = (int)$pdo->lastInsertId();
            log_activity('Schnellausgabe gestartet', 'order', $orderId, '#' . $orderNumber . ' ' . $title);
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
        }

        if (!$device['is_bulk']) {
            $dupe = $pdo->prepare('SELECT COUNT(*) FROM order_devices WHERE order_id = ? AND device_id = ?');
            $dupe->execute([(int)$order['id'], $device['id']]);
            if ((int)$dupe->fetchColumn() > 0) {
                flash('info', $device['inventory_number'] . ' ist bereits in dieser Schnellausgabe.');
                redirect('modules/ausgabe/schnell.php?order=' . (int)$order['id']);
            }
        }

        $pdo->prepare('INSERT INTO order_items (order_id, device_id, quantity, note) VALUES (?, ?, ?, ?)')
            ->execute([(int)$order['id'], $device['id'], $device['is_bulk'] ? $qty : 1, null]);

        if (!$device['is_bulk']) {
            $pdo->prepare('INSERT INTO order_devices (order_id, device_id, status) VALUES (?, ?, "reserviert")')
                ->execute([(int)$order['id'], $device['id']]);
            $pdo->prepare('UPDATE devices SET status = "reserviert", current_order_id = ? WHERE id = ?')
                ->execute([(int)$order['id'], $device['id']]);
        }
        log_activity('Für Schnellausgabe hinzugefügt', 'device', (int)$device['id'], $device['inventory_number'] . ' – ' . $device['name']);
        flash('success', $device['inventory_number'] . ' – ' . $device['name'] . ' hinzugefügt.');
        redirect('modules/ausgabe/schnell.php?order=' . (int)$order['id']);
    }
}

$items = [];
if ($order) {
    $itemsStmt = $pdo->prepare(
        'SELECT oi.*, d.name AS device_name, d.inventory_number, d.is_bulk
         FROM order_items oi JOIN devices d ON d.id = oi.device_id
         WHERE oi.order_id = ? ORDER BY d.is_bulk, d.name'
    );
    $itemsStmt->execute([(int)$order['id']]);
    $items = $itemsStmt->fetchAll();
}

$page_title = 'Schnellausgabe';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head"><h1>Schnellausgabe</h1></div>
<p class="muted">Geräte direkt ausbuchen, ohne vorher einen Auftrag anzulegen. Im Hintergrund wird dafür
    automatisch ein schlanker Auftrag „<?= e($order['order_number'] ?? 'neu') ?>“ geführt, damit Rückgabe
    und Historie wie gewohnt funktionieren.</p>

<form method="post" class="card card-flat">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="scan">
    <?php if ($order): ?><input type="hidden" name="order" value="<?= (int)$order['id'] ?>"><?php endif; ?>
    <?php if (!$order): ?>
    <div class="field">
        <label>Zweck (optional)</label>
        <input type="text" name="purpose" placeholder="z.B. Ersatzgerät für Kunde XY">
    </div>
    <?php endif; ?>
    <div class="form-grid">
        <div class="field">
            <label>Gerät scannen / Inventarnummer eingeben</label>
            <input type="text" id="scan-code" name="code" placeholder="RSH-0042" data-autofocus data-scan-target>
        </div>
        <div class="field"><label>Menge (nur bei Mengenartikeln)</label><input type="number" name="quantity" min="1" value="1"></div>
    </div>
    <div class="btn-row">
        <button type="submit" class="btn btn-primary btn-sm">Hinzufügen</button>
        <button type="button" class="btn btn-ghost btn-sm" data-camera-scan-for="scan-code">📷 Kamera</button>
    </div>
</form>

<?php if ($order): ?>
    <?php if (!$items): ?>
        <div class="empty-state"><div class="es-icon">▤</div>Noch keine Geräte gescannt.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Inv.-Nr.</th><th>Gerät</th><th>Menge</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= $it['is_bulk'] ? '–' : e($it['inventory_number']) ?></td>
                    <td><?= e($it['device_name']) ?></td>
                    <td><?= (int)$it['quantity'] ?><?= $it['is_bulk'] ? ' Stk.' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="btn-row" style="margin-top:16px;">
        <a href="<?= url('modules/ausgabe/ausgabe.php?order=' . urlencode($order['order_number'])) ?>" class="btn btn-primary btn-lg" <?= !$items ? 'aria-disabled="true" style="pointer-events:none;opacity:.5"' : '' ?>>Weiter zur Ausgabe →</a>
        <form method="post" onsubmit="return confirm('Schnellausgabe abbrechen? Alle Reservierungen werden aufgehoben.');" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="cancel">
            <button type="submit" class="btn btn-ghost">Abbrechen</button>
        </form>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
