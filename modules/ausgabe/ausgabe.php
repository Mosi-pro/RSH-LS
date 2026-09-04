<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('ausgabe_rueckgabe.edit');

$pdo = db();
$terminal = input('terminal') === '1';
$orderNumber = input('order');

$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ?');
$stmt->execute([$orderNumber]);
$order = $stmt->fetch();

if (!$order) {
    flash('error', 'Auftrag „' . $orderNumber . '“ wurde nicht gefunden.');
    redirect('modules/ausgabe/index.php' . ($terminal ? '?terminal=1' : ''));
}

if (in_array($order['status'], ['ausgegeben', 'im_einsatz', 'rueckgabe_ausstehend', 'abgeschlossen', 'storniert'], true)) {
    flash('info', 'Auftrag #' . $order['order_number'] . ' ist bereits ausgegeben oder abgeschlossen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('form_action');

    if ($action === 'confirm_item') {
        $deviceId = (int)input('device_id');
        $stmt = $pdo->prepare('SELECT * FROM order_devices WHERE order_id = ? AND device_id = ?');
        $stmt->execute([(int)$order['id'], $deviceId]);
        $od = $stmt->fetch();
        if ($od && $od['status'] === 'reserviert') {
            $pdo->prepare('UPDATE order_devices SET status = "ausgegeben", checked_out_at = NOW() WHERE id = ?')->execute([$od['id']]);
            $pdo->prepare('UPDATE devices SET status = "ausgegeben" WHERE id = ?')->execute([$deviceId]);
            $devStmt = $pdo->prepare('SELECT inventory_number, name FROM devices WHERE id = ?');
            $devStmt->execute([$deviceId]);
            $dev = $devStmt->fetch();
            log_activity('Gerät ausgegeben', 'order', (int)$order['id'], $dev['inventory_number'] . ' ' . $dev['name']);
        }
        redirect('modules/ausgabe/ausgabe.php?order=' . urlencode($orderNumber) . ($terminal ? '&terminal=1' : ''));

    } elseif ($action === 'undo_item') {
        $deviceId = (int)input('device_id');
        $stmt = $pdo->prepare('SELECT * FROM order_devices WHERE order_id = ? AND device_id = ?');
        $stmt->execute([(int)$order['id'], $deviceId]);
        $od = $stmt->fetch();
        if ($od && $od['status'] === 'ausgegeben' && $order['status'] !== 'ausgegeben') {
            $pdo->prepare('UPDATE order_devices SET status = "reserviert", checked_out_at = NULL WHERE id = ?')->execute([$od['id']]);
            $pdo->prepare('UPDATE devices SET status = "reserviert" WHERE id = ?')->execute([$deviceId]);
        }
        redirect('modules/ausgabe/ausgabe.php?order=' . urlencode($orderNumber) . ($terminal ? '&terminal=1' : ''));

    } elseif ($action === 'finish') {
        $employeeId = preg_replace('/\D/', '', input('employee_id'));
        $empStmt = $pdo->prepare('SELECT * FROM users WHERE employee_id = ? AND active = 1');
        $empStmt->execute([$employeeId]);
        $employee = $empStmt->fetch();

        if (!$employee) {
            flash('error', 'Unbekannte Mitarbeiter-ID.');
        } else {
            $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM order_devices WHERE order_id = ?');
            $totalStmt->execute([(int)$order['id']]);
            $total = (int)$totalStmt->fetchColumn();

            $doneStmt = $pdo->prepare("SELECT COUNT(*) FROM order_devices WHERE order_id = ? AND status = 'ausgegeben'");
            $doneStmt->execute([(int)$order['id']]);
            $done = (int)$doneStmt->fetchColumn();

            if ($total > 0 && $done < $total) {
                flash('error', 'Es sind noch nicht alle Positionen bestätigt (' . $done . ' / ' . $total . ').');
            } else {
                $pdo->prepare('UPDATE order_devices SET checked_out_by = ? WHERE order_id = ? AND status = "ausgegeben" AND checked_out_by IS NULL')
                    ->execute([$employee['id'], (int)$order['id']]);
                $pdo->prepare('INSERT INTO checkouts (order_id, issued_by, issued_at, item_count) VALUES (?, ?, NOW(), ?)')
                    ->execute([(int)$order['id'], $employee['id'], $total]);
                $pdo->prepare('UPDATE orders SET status = "ausgegeben" WHERE id = ?')->execute([(int)$order['id']]);
                log_activity('Auftrag ausgegeben', 'order', (int)$order['id'], $total . ' Positionen, ausgegeben an ' . $employee['name']);
                flash('success', 'Ausgabe für Auftrag #' . $order['order_number'] . ' abgeschlossen.');
                redirect(($terminal ? 'terminal/index.php' : 'modules/auftraege/auftrag.php?id=' . (int)$order['id']));
            }
        }
    }
}

$devStmt = $pdo->prepare('SELECT od.*, d.name AS device_name, d.inventory_number FROM order_devices od
                           JOIN devices d ON d.id = od.device_id WHERE od.order_id = ? ORDER BY d.name');
$devStmt->execute([(int)$order['id']]);
$orderDevices = $devStmt->fetchAll();

$bulkStmt = $pdo->prepare('SELECT oi.*, d.name AS device_name FROM order_items oi
                            JOIN devices d ON d.id = oi.device_id WHERE oi.order_id = ? AND d.is_bulk = 1');
$bulkStmt->execute([(int)$order['id']]);
$bulkItems = $bulkStmt->fetchAll();

$total = count($orderDevices);
$done  = count(array_filter($orderDevices, fn($d) => $d['status'] === 'ausgegeben'));
$progress = $total > 0 ? round($done / $total * 100) : 100;

$page_title = 'Ausgabe #' . $order['order_number'];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php if ($terminal): ?>
<div class="terminal-header">
    <div class="t-brand">AUFTRAG #<?= e($order['order_number']) ?></div>
    <div class="t-title"><?= e($order['title']) ?></div>
</div>
<?php else: ?>
<div class="section-head"><h1>Ausgabe – Auftrag #<?= e($order['order_number']) ?></h1></div>
<p class="muted"><?= e($order['title']) ?></p>
<?php endif; ?>

<div class="progress-bar"><div class="progress-bar-fill" style="width:<?= $progress ?>%"></div></div>
<p class="small muted"><?= $done ?> / <?= $total ?> Positionen bestätigt</p>

<?php foreach ($orderDevices as $od): ?>
    <div class="device-check <?= $od['status'] === 'ausgegeben' ? 'confirmed' : '' ?>">
        <div class="d-info">
            <strong><?= e($od['device_name']) ?></strong>
            <span><?= e($od['inventory_number']) ?></span>
        </div>
        <?php if ($od['status'] === 'ausgegeben'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="form_action" value="undo_item">
                <input type="hidden" name="device_id" value="<?= (int)$od['device_id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm">✓ Mitgenommen · rückgängig</button>
            </form>
        <?php else: ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="form_action" value="confirm_item">
                <input type="hidden" name="device_id" value="<?= (int)$od['device_id'] ?>">
                <button type="submit" class="btn btn-primary">✓ MITGENOMMEN</button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if ($bulkItems): ?>
<div class="card card-flat">
    <h3>Mengenartikel (keine Einzelbestätigung)</h3>
    <ul>
        <?php foreach ($bulkItems as $bi): ?>
            <li><?= (int)$bi['quantity'] ?> × <?= e($bi['device_name']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <h3>Ausgabe abschließen</h3>
    <form method="post" class="btn-row" style="align-items:center;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="finish">
        <input type="text" name="employee_id" placeholder="Mitarbeiter-ID" inputmode="numeric" data-scan-target required style="max-width:180px">
        <button type="submit" class="btn btn-primary btn-lg" <?= ($total > 0 && $done < $total) ? 'disabled' : '' ?>>AUSGABE BESTÄTIGEN</button>
    </form>
</div>

<?php if ($terminal): ?>
<div class="btn-row" style="margin-top:14px;"><a href="<?= url('terminal/index.php') ?>" class="btn btn-ghost">← Zurück zum Terminal</a></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
