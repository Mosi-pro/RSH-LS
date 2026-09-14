<?php
/**
 * RSH-LS – Rückgabe einer Schnellausgabe (Ausleihe ohne Auftrag) per Ausleih-ID.
 * Spiegelt modules/rueckgabe/rueckgabe.php, nur gegen quick_checkouts /
 * quick_checkout_items statt orders / order_devices. Mengenartikel werden
 * hier - genau wie bei Aufträgen - nur informativ angezeigt, nicht einzeln
 * zurückgenommen.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('ausgabe_rueckgabe.edit');

$pdo = db();
$terminal = input('terminal') === '1';
$checkoutCode = input('checkout');

$stmt = $pdo->prepare('SELECT * FROM quick_checkouts WHERE code = ?');
$stmt->execute([$checkoutCode]);
$checkout = $stmt->fetch();

if (!$checkout) {
    flash('error', 'Ausleihe „' . $checkoutCode . '“ wurde nicht gefunden.');
    redirect('modules/rueckgabe/index.php' . ($terminal ? '?terminal=1' : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('form_action');

    if ($action === 'return_item') {
        $deviceId = (int)input('device_id');
        $stmt = $pdo->prepare(
            'SELECT qci.*, d.is_bulk FROM quick_checkout_items qci JOIN devices d ON d.id = qci.device_id
             WHERE qci.quick_checkout_id = ? AND qci.device_id = ? AND qci.returned_at IS NULL'
        );
        $stmt->execute([(int)$checkout['id'], $deviceId]);
        $item = $stmt->fetch();

        if ($item && !$item['is_bulk']) {
            $pdo->prepare('UPDATE quick_checkout_items SET returned_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$item['id']]);
            $pdo->prepare('UPDATE devices SET status = "verfuegbar" WHERE id = ?')->execute([$deviceId]);
            $devStmt = $pdo->prepare('SELECT inventory_number, name FROM devices WHERE id = ?');
            $devStmt->execute([$deviceId]);
            $dev = $devStmt->fetch();
            log_activity('Schnellausgabe zurückgegeben', 'quick_checkout', (int)$checkout['id'], $dev['inventory_number'] . ' ' . $dev['name'] . ' (' . $checkout['code'] . ')');
        }
        redirect('modules/rueckgabe/schnell.php?checkout=' . urlencode($checkoutCode) . ($terminal ? '&terminal=1' : ''));

    } elseif ($action === 'scan_confirm') {
        $code = trim(input('code'));
        $devStmt = $pdo->prepare('SELECT id, inventory_number, name FROM devices WHERE inventory_number = ?');
        $devStmt->execute([$code]);
        $dev = $devStmt->fetch();

        if (!$dev) {
            flash('error', '„' . $code . '“ wurde nicht gefunden.');
        } else {
            $stmt = $pdo->prepare(
                'SELECT qci.*, d.is_bulk FROM quick_checkout_items qci JOIN devices d ON d.id = qci.device_id
                 WHERE qci.quick_checkout_id = ? AND qci.device_id = ?'
            );
            $stmt->execute([(int)$checkout['id'], $dev['id']]);
            $item = $stmt->fetch();

            if (!$item) {
                flash('error', $dev['inventory_number'] . ' gehört nicht zu dieser Ausleihe.');
            } elseif ($item['returned_at']) {
                flash('info', $dev['inventory_number'] . ' ist bereits zurückgegeben.');
            } elseif ($item['is_bulk']) {
                flash('info', $dev['inventory_number'] . ' ist ein Mengenartikel und wird nicht einzeln zurückgenommen.');
            } else {
                $pdo->prepare('UPDATE quick_checkout_items SET returned_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$item['id']]);
                $pdo->prepare('UPDATE devices SET status = "verfuegbar" WHERE id = ?')->execute([$dev['id']]);
                log_activity('Schnellausgabe zurückgegeben (Scan)', 'quick_checkout', (int)$checkout['id'], $dev['inventory_number'] . ' ' . $dev['name'] . ' (' . $checkout['code'] . ')');
                flash('success', $dev['inventory_number'] . ' – ' . $dev['name'] . ' zurückgenommen.');
            }
        }
        redirect('modules/rueckgabe/schnell.php?checkout=' . urlencode($checkoutCode) . ($terminal ? '&terminal=1' : ''));

    } elseif ($action === 'finish') {
        $employeeId = preg_replace('/\D/', '', input('employee_id'));
        $empStmt = $pdo->prepare('SELECT * FROM users WHERE employee_id = ? AND active = 1');
        $empStmt->execute([$employeeId]);
        $employee = $empStmt->fetch();

        if (!$employee) {
            flash('error', 'Unbekannte Mitarbeiter-ID.');
            redirect('modules/rueckgabe/schnell.php?checkout=' . urlencode($checkoutCode) . ($terminal ? '&terminal=1' : ''));
        }

        $openStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM quick_checkout_items qci JOIN devices d ON d.id = qci.device_id
             WHERE qci.quick_checkout_id = ? AND d.is_bulk = 0 AND qci.returned_at IS NULL'
        );
        $openStmt->execute([(int)$checkout['id']]);
        $open = (int)$openStmt->fetchColumn();

        if ($open > 0) {
            flash('error', 'Es sind noch nicht alle Geräte zurückgegeben.');
            redirect('modules/rueckgabe/schnell.php?checkout=' . urlencode($checkoutCode) . ($terminal ? '&terminal=1' : ''));
        }

        $pdo->prepare('UPDATE quick_checkout_items SET returned_by = ? WHERE quick_checkout_id = ? AND returned_by IS NULL')
            ->execute([$employee['id'], (int)$checkout['id']]);
        $pdo->prepare('UPDATE quick_checkouts SET status = "zurueckgegeben", returned_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([(int)$checkout['id']]);
        log_activity('Schnellausgabe-Rückgabe abgeschlossen', 'quick_checkout', (int)$checkout['id'], $checkout['code'] . ', entgegengenommen von ' . $employee['name']);
        flash('success', 'Rückgabe für Ausleihe ' . $checkout['code'] . ' abgeschlossen.');
        redirect($terminal ? 'terminal/index.php' : 'modules/rueckgabe/index.php');
    }
}

$itemsStmt = $pdo->prepare(
    'SELECT qci.*, d.name AS device_name, d.inventory_number, d.is_bulk
     FROM quick_checkout_items qci JOIN devices d ON d.id = qci.device_id
     WHERE qci.quick_checkout_id = ? ORDER BY d.is_bulk, d.name'
);
$itemsStmt->execute([(int)$checkout['id']]);
$items = $itemsStmt->fetchAll();

$singleItems = array_values(array_filter($items, fn($i) => !$i['is_bulk']));
$bulkItems   = array_values(array_filter($items, fn($i) => $i['is_bulk']));

$total    = count($singleItems);
$open     = count(array_filter($singleItems, fn($i) => !$i['returned_at']));
$returned = $total - $open;
$progress = $total > 0 ? round($returned / $total * 100) : 100;

$empStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$empStmt->execute([$checkout['employee_id']]);
$employeeName = $empStmt->fetchColumn();

$page_title = 'Rückgabe ' . $checkout['code'];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php if ($terminal): ?>
<div class="terminal-header">
    <div class="t-brand">AUSLEIHE <?= e($checkout['code']) ?></div>
    <div class="t-title"><?= e($employeeName ?: '–') ?></div>
</div>
<?php else: ?>
<div class="section-head"><h1>Rückgabe – Ausleihe <?= e($checkout['code']) ?></h1></div>
<p class="muted">Ausgegeben an <?= e($employeeName ?: '–') ?> · ohne Auftrag</p>
<?php endif; ?>

<div class="progress-bar"><div class="progress-bar-fill" style="width:<?= $progress ?>%"></div></div>
<p class="small muted"><?= $returned ?> / <?= $total ?> Geräte zurückgegeben</p>

<?php if ($open > 0): ?>
<form method="post" class="card card-flat">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="scan_confirm">
    <?php if ($terminal): ?><input type="hidden" name="terminal" value="1"><?php endif; ?>
    <div class="field">
        <label>Gerät scannen / Inventarnummer eingeben</label>
        <input type="text" id="scan-code" name="code" placeholder="RSH-0042" data-autofocus data-scan-target>
    </div>
    <div class="btn-row">
        <button type="submit" class="btn btn-primary btn-sm">Bestätigen</button>
        <button type="button" class="btn btn-ghost btn-sm" data-camera-scan-for="scan-code">📷 Kamera</button>
    </div>
</form>
<?php endif; ?>

<?php foreach ($singleItems as $it): ?>
    <?php if ($it['returned_at']): ?>
        <div class="device-check confirmed">
            <div class="d-info">
                <strong><?= e($it['device_name']) ?></strong>
                <span><?= e($it['inventory_number']) ?> · zurückgegeben</span>
            </div>
        </div>
    <?php else: ?>
        <div class="device-check">
            <div class="d-info">
                <strong><?= e($it['device_name']) ?></strong>
                <span><?= e($it['inventory_number']) ?></span>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="return_item">
                <?php if ($terminal): ?><input type="hidden" name="terminal" value="1"><?php endif; ?>
                <input type="hidden" name="device_id" value="<?= (int)$it['device_id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm">✓ Zurückgenommen</button>
            </form>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($bulkItems): ?>
<div class="card card-flat">
    <h3>Mengenartikel (keine Einzelrücknahme)</h3>
    <ul>
        <?php foreach ($bulkItems as $bi): ?>
            <li><?= (int)$bi['quantity'] ?> × <?= e($bi['device_name']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <h3>Rückgabe abschließen</h3>
    <form method="post" class="btn-row" style="align-items:center;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="finish">
        <?php if ($terminal): ?><input type="hidden" name="terminal" value="1"><?php endif; ?>
        <input type="text" name="employee_id" placeholder="Mitarbeiter-ID" inputmode="numeric" data-scan-target required style="max-width:180px">
        <button type="submit" class="btn btn-primary btn-lg" <?= $open > 0 ? 'disabled' : '' ?>>RÜCKGABE BESTÄTIGEN</button>
    </form>
</div>

<?php if ($terminal): ?>
<div class="btn-row" style="margin-top:14px;"><a href="<?= url('terminal/index.php') ?>" class="btn btn-ghost">← Zurück zum Terminal</a></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
