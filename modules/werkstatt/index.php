<?php
/**
 * RSH-LS – Werkstatt: eigene Oberfläche, nur für Technikleitung + Werkstatt-Account.
 * Scannt man ein Gerät, das noch nicht in der Werkstatt ist, wird es eingebucht
 * (Reparatur aufgenommen). Scannt man ein bereits eingebuchtes Gerät erneut,
 * geht es weiter zur Auscheck-Seite (Lösung eintragen, Status festlegen).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('werkstatt.access');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code = trim(input('code'));

    $devStmt = $pdo->prepare('SELECT * FROM devices WHERE inventory_number = ?');
    $devStmt->execute([$code]);
    $device = $devStmt->fetch();

    if (!$device) {
        flash('error', '„' . $code . '“ wurde nicht gefunden.');
        redirect('modules/werkstatt/index.php');
    }

    if ($device['status'] === 'in_reparatur') {
        redirect('modules/werkstatt/checkout.php?device_id=' . (int)$device['id']);
    }

    // -- Check-in: Gerät wird in der Werkstatt aufgenommen -------------------
    $user = current_user();

    $openDefect = $pdo->prepare("SELECT id FROM defects WHERE device_id = ? AND status = 'offen' ORDER BY created_at DESC LIMIT 1");
    $openDefect->execute([$device['id']]);
    $defectId = $openDefect->fetchColumn();
    if ($defectId) {
        $pdo->prepare("UPDATE defects SET status = 'in_bearbeitung' WHERE id = ?")->execute([$defectId]);
    }

    $pdo->prepare('UPDATE devices SET status = "in_reparatur" WHERE id = ?')->execute([$device['id']]);
    log_activity('In Werkstatt aufgenommen', 'device', (int)$device['id'], $device['inventory_number'] . ' ' . $device['name'] . ' von ' . $user['name']);
    flash('success', $device['inventory_number'] . ' – ' . $device['name'] . ' in der Werkstatt aufgenommen.');
    redirect('modules/werkstatt/index.php');
}

$inRepair = $pdo->query(
    "SELECT d.*, (SELECT problem FROM defects f WHERE f.device_id = d.id AND f.status = 'in_bearbeitung' ORDER BY f.created_at DESC LIMIT 1) AS problem
     FROM devices d WHERE d.status = 'in_reparatur' ORDER BY d.updated_at DESC"
)->fetchAll();

$recentlyFixed = $pdo->query(
    "SELECT f.*, d.inventory_number, d.name AS device_name, u.name AS resolver_name
     FROM defects f JOIN devices d ON d.id = f.device_id LEFT JOIN users u ON u.id = f.resolved_by
     WHERE f.status = 'behoben' ORDER BY f.resolved_at DESC LIMIT 10"
)->fetchAll();

$page_title = 'Werkstatt';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head"><h1>Werkstatt</h1></div>

<form method="post" class="card card-flat">
    <?= csrf_field() ?>
    <div class="field">
        <label>Gerät scannen / Inventarnummer eingeben</label>
        <input type="text" id="scan-code" name="code" placeholder="RSH-0042" data-autofocus data-scan-target>
    </div>
    <div class="btn-row">
        <button type="submit" class="btn btn-primary btn-sm">Einbuchen / Weiter</button>
        <button type="button" class="btn btn-ghost btn-sm" data-camera-scan-for="scan-code">📷 Kamera</button>
    </div>
    <p class="small muted">Erstes Scannen nimmt das Gerät in die Reparatur auf. Nochmaliges Scannen desselben
        Geräts öffnet die Auscheck-Seite (Lösung eintragen, Status festlegen).</p>
</form>

<div class="section-head"><h2>Aktuell in der Werkstatt (<?= count($inRepair) ?>)</h2></div>
<?php if (!$inRepair): ?>
    <div class="empty-state"><div class="es-icon">🔧</div>Aktuell befindet sich kein Gerät in der Werkstatt.</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Inv.-Nr.</th><th>Gerät</th><th>Problem</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($inRepair as $d): ?>
            <tr>
                <td><?= e($d['inventory_number']) ?></td>
                <td><?= e($d['name']) ?></td>
                <td><?= e($d['problem'] ?? '–') ?></td>
                <td class="text-right"><a href="<?= url('modules/werkstatt/checkout.php?device_id=' . (int)$d['id']) ?>" class="btn btn-sm">Auschecken</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="section-head"><h2>Zuletzt behoben</h2></div>
<?php if (!$recentlyFixed): ?>
    <p class="muted">Noch keine abgeschlossenen Reparaturen.</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Inv.-Nr.</th><th>Gerät</th><th>Behoben von</th><th>Datum</th></tr></thead>
        <tbody>
        <?php foreach ($recentlyFixed as $f): ?>
            <tr onclick="location.href='<?= url('modules/defekte/defekt.php?id=' . (int)$f['id']) ?>'" style="cursor:pointer">
                <td><?= e($f['inventory_number']) ?></td>
                <td><?= e($f['device_name']) ?></td>
                <td><?= e($f['resolver_name'] ?? '–') ?></td>
                <td><?= format_datetime($f['resolved_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
