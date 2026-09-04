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
    redirect('modules/rueckgabe/index.php' . ($terminal ? '?terminal=1' : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('form_action');

    if ($action === 'return_item') {
        $deviceId = (int)input('device_id');
        $quality  = input('quality'); // complete | incomplete
        $notes    = input('missing_notes');

        $stmt = $pdo->prepare('SELECT * FROM order_devices WHERE order_id = ? AND device_id = ?');
        $stmt->execute([(int)$order['id'], $deviceId]);
        $od = $stmt->fetch();

        if ($od && $od['status'] === 'ausgegeben') {
            $complete = $quality === 'complete' ? 1 : 0;
            $pdo->prepare('UPDATE order_devices SET status = "zurueckgegeben", returned_at = NOW(), complete = ?, missing_notes = ? WHERE id = ?')
                ->execute([$complete, $complete ? null : $notes, $od['id']]);
            $pdo->prepare('UPDATE devices SET status = "verfuegbar", current_order_id = NULL WHERE id = ?')->execute([$deviceId]);

            $devStmt = $pdo->prepare('SELECT inventory_number, name FROM devices WHERE id = ?');
            $devStmt->execute([$deviceId]);
            $dev = $devStmt->fetch();
            log_activity(
                $complete ? 'Gerät vollständig zurückgegeben' : 'Gerät unvollständig zurückgegeben',
                'order', (int)$order['id'],
                $dev['inventory_number'] . ' ' . $dev['name'] . ($complete ? '' : ' – fehlt: ' . $notes)
            );
        }
        redirect('modules/rueckgabe/rueckgabe.php?order=' . urlencode($orderNumber) . ($terminal ? '&terminal=1' : ''));

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

            $openStmt = $pdo->prepare("SELECT COUNT(*) FROM order_devices WHERE order_id = ? AND status = 'ausgegeben'");
            $openStmt->execute([(int)$order['id']]);
            $open = (int)$openStmt->fetchColumn();

            if ($open > 0) {
                flash('error', 'Es sind noch nicht alle Geräte zurückgegeben (' . ($total - $open) . ' / ' . $total . ').');
            } else {
                $compStmt = $pdo->prepare("SELECT COUNT(*) FROM order_devices WHERE order_id = ? AND complete = 1");
                $compStmt->execute([(int)$order['id']]);
                $complete = (int)$compStmt->fetchColumn();
                $incomplete = $total - $complete;

                $pdo->prepare('INSERT INTO returns (order_id, returned_by, returned_at, total_devices, complete_devices, incomplete_devices) VALUES (?, ?, NOW(), ?, ?, ?)')
                    ->execute([(int)$order['id'], $employee['id'], $total, $complete, $incomplete]);
                $pdo->prepare('UPDATE order_devices SET returned_by = ? WHERE order_id = ? AND returned_by IS NULL')
                    ->execute([$employee['id'], (int)$order['id']]);
                $pdo->prepare('UPDATE orders SET status = "abgeschlossen" WHERE id = ?')->execute([(int)$order['id']]);
                log_activity('Rückgabe abgeschlossen', 'order', (int)$order['id'],
                    $total . ' Geräte, ' . $incomplete . ' unvollständig, entgegengenommen von ' . $employee['name']);
                flash('success', 'Rückgabe für Auftrag #' . $order['order_number'] . ' abgeschlossen.');
                redirect(($terminal ? 'terminal/index.php' : 'modules/auftraege/auftrag.php?id=' . (int)$order['id']));
            }
        }
    }
}

$devStmt = $pdo->prepare('SELECT od.*, d.name AS device_name, d.inventory_number FROM order_devices od
                           JOIN devices d ON d.id = od.device_id WHERE od.order_id = ? ORDER BY d.name');
$devStmt->execute([(int)$order['id']]);
$orderDevices = $devStmt->fetchAll();

$total = count($orderDevices);
$open  = count(array_filter($orderDevices, fn($d) => $d['status'] === 'ausgegeben'));
$returned = $total - $open;
$progress = $total > 0 ? round($returned / $total * 100) : 100;

$page_title = 'Rückgabe #' . $order['order_number'];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php if ($terminal): ?>
<div class="terminal-header">
    <div class="t-brand">AUFTRAG #<?= e($order['order_number']) ?></div>
    <div class="t-title"><?= e($order['title']) ?></div>
</div>
<?php else: ?>
<div class="section-head"><h1>Rückgabe – Auftrag #<?= e($order['order_number']) ?></h1></div>
<p class="muted"><?= e($order['title']) ?></p>
<?php endif; ?>

<div class="progress-bar"><div class="progress-bar-fill" style="width:<?= $progress ?>%"></div></div>
<p class="small muted"><?= $returned ?> / <?= $total ?> Geräte zurückgegeben</p>

<?php foreach ($orderDevices as $od): ?>
    <?php if ($od['status'] === 'zurueckgegeben'): ?>
        <div class="device-check <?= $od['complete'] ? 'confirmed' : 'incomplete' ?>">
            <div class="d-info">
                <strong><?= e($od['device_name']) ?></strong>
                <span><?= e($od['inventory_number']) ?> · <?= $od['complete'] ? 'vollständig' : 'nicht vollständig – ' . e($od['missing_notes']) ?></span>
            </div>
        </div>
    <?php elseif ($od['status'] === 'ausgegeben'): ?>
        <div class="device-check">
            <div class="d-info">
                <strong><?= e($od['device_name']) ?></strong>
                <span><?= e($od['inventory_number']) ?></span>
            </div>
        </div>
        <form method="post" class="card card-flat" style="margin-top:-8px;margin-bottom:14px;">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="return_item">
            <input type="hidden" name="device_id" value="<?= (int)$od['device_id'] ?>">
            <div class="field"><label>Was fehlt? (nur bei „nicht vollständig“)</label><input type="text" name="missing_notes" placeholder="z.B. Mikrofonklemme, Tasche, Kabel"></div>
            <div class="btn-row">
                <button type="submit" name="quality" value="complete" class="btn btn-primary">✓ VOLLSTÄNDIG</button>
                <button type="submit" name="quality" value="incomplete" class="btn btn-danger">✕ NICHT VOLLSTÄNDIG</button>
            </div>
        </form>
    <?php else: ?>
        <div class="device-check">
            <div class="d-info"><strong><?= e($od['device_name']) ?></strong><span><?= e($od['inventory_number']) ?> · noch nicht ausgegeben</span></div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<div class="card">
    <h3>Rückgabe abschließen</h3>
    <form method="post" class="btn-row" style="align-items:center;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="finish">
        <input type="text" name="employee_id" placeholder="Mitarbeiter-ID" inputmode="numeric" data-scan-target required style="max-width:180px">
        <button type="submit" class="btn btn-primary btn-lg" <?= $open > 0 ? 'disabled' : '' ?>>RÜCKGABE BESTÄTIGEN</button>
    </form>
</div>

<?php if ($terminal): ?>
<div class="btn-row" style="margin-top:14px;"><a href="<?= url('terminal/index.php') ?>" class="btn btn-ghost">← Zurück zum Terminal</a></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
