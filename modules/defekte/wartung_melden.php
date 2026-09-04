<?php
/**
 * RSH-LS – Wartung melden: kennzeichnet ein Gerät als wartungsbedürftig
 * (Status "Wartung"), unabhängig von einem Defekt.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('defekte.report');

$pdo = db();
$deviceId = (int)input('device_id');

$stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ?');
$stmt->execute([$deviceId]);
$device = $stmt->fetch();

if (!$device) {
    flash('error', 'Gerät nicht gefunden.');
    redirect('modules/lager/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $note = input('note');

    $nextMaintenance = $device['next_maintenance_date'] ?: date('Y-m-d');
    $newNotes = trim(($device['notes'] ? $device['notes'] . "\n" : '') . 'Wartung gemeldet (' . date('d.m.Y') . ')' . ($note ? ': ' . $note : ''));

    $pdo->prepare('UPDATE devices SET status = "wartung", next_maintenance_date = ?, notes = ? WHERE id = ?')
        ->execute([$nextMaintenance, $newNotes, $deviceId]);

    log_activity('Wartung gemeldet', 'device', $deviceId, $device['inventory_number'] . ' ' . $device['name'] . ($note ? ' – ' . $note : ''));

    flash('success', $device['inventory_number'] . ' als wartungsbedürftig markiert.');
    redirect('modules/lager/geraet.php?id=' . $deviceId);
}

$page_title = 'Wartung melden';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head"><h1>Wartung melden</h1></div>

<div class="card">
    <h3 class="mt-0"><?= e($device['inventory_number']) ?> – <?= e($device['name']) ?></h3>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label>Bemerkung (optional)</label>
            <textarea name="note" placeholder="z.B. jährliche Sicherheitsprüfung fällig"></textarea>
        </div>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Wartung melden</button>
            <a href="<?= url('modules/lager/geraet.php?id=' . (int)$device['id']) ?>" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
