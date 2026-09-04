<?php
/**
 * RSH-LS – Werkstatt: Gerät auschecken (Lösung eintragen, Status festlegen).
 * Verknüpfte Defekte werden auf "behoben" gesetzt und der Melder erhält
 * eine Benachrichtigung mit der Lösung.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('werkstatt.access');

$pdo = db();
$deviceId = (int)input('device_id');

$stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ?');
$stmt->execute([$deviceId]);
$device = $stmt->fetch();

if (!$device) {
    flash('error', 'Gerät nicht gefunden.');
    redirect('modules/werkstatt/index.php');
}
if ($device['status'] !== 'in_reparatur') {
    flash('info', $device['inventory_number'] . ' befindet sich nicht in der Werkstatt.');
    redirect('modules/werkstatt/index.php');
}

$openDefects = $pdo->prepare("SELECT * FROM defects WHERE device_id = ? AND status = 'in_bearbeitung' ORDER BY created_at");
$openDefects->execute([$deviceId]);
$openDefects = $openDefects->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $resolution = input('resolution_note');
    $newStatus = input('status', 'verfuegbar');
    if (!in_array($newStatus, ['verfuegbar', 'defekt', 'verloren', 'aussortiert'], true)) {
        $newStatus = 'verfuegbar';
    }

    $user = current_user();

    $pdo->prepare('UPDATE devices SET status = ? WHERE id = ?')->execute([$newStatus, $deviceId]);

    foreach ($openDefects as $defect) {
        $pdo->prepare('UPDATE defects SET status = "behoben", resolution_note = ?, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$resolution ?: null, $user['id'], $defect['id']]);

        if ($defect['reported_by']) {
            create_notification(
                (int)$defect['reported_by'],
                'Defekt behoben: ' . $device['inventory_number'],
                $device['inventory_number'] . ' – ' . $device['name'] . "\nFehler: " . $defect['problem']
                    . ($resolution ? "\nLösung: " . $resolution : ''),
                'modules/defekte/defekt.php?id=' . $defect['id']
            );
        }
    }

    log_activity(
        'Aus Werkstatt entlassen',
        'device',
        $deviceId,
        $device['inventory_number'] . ' ' . $device['name'] . ' → ' . device_status_label($newStatus)
            . ($resolution ? ' – ' . $resolution : '')
    );

    flash('success', $device['inventory_number'] . ' aus der Werkstatt entlassen.');
    redirect('modules/werkstatt/index.php');
}

$page_title = 'Auschecken: ' . $device['inventory_number'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head"><h1>Auschecken – <?= e($device['inventory_number']) ?> · <?= e($device['name']) ?></h1></div>

<?php if ($openDefects): ?>
<div class="card card-flat">
    <h3 class="mt-0">Gemeldete Probleme</h3>
    <ul>
        <?php foreach ($openDefects as $d): ?>
            <li><?= e($d['problem']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="field">
        <label>Lösung / Bemerkung</label>
        <textarea name="resolution_note" placeholder="Was wurde gemacht?"></textarea>
    </div>
    <div class="field">
        <label>Status nach der Werkstatt</label>
        <select name="status">
            <option value="verfuegbar">Verfügbar (repariert)</option>
            <option value="defekt">Weiterhin defekt</option>
            <option value="verloren">Verloren</option>
            <option value="aussortiert">Aussortiert</option>
        </select>
    </div>
    <div class="btn-row">
        <button type="submit" class="btn btn-primary">Gerät auschecken</button>
        <a href="<?= url('modules/werkstatt/index.php') ?>" class="btn btn-ghost">Abbrechen</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
