<?php
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
    $problem = input('problem');
    $priority = input('priority', 'normal');
    if (!in_array($priority, ['niedrig', 'normal', 'dringend'], true)) {
        $priority = 'normal';
    }

    if ($problem === '') {
        flash('error', 'Bitte das Problem kurz beschreiben.');
    } else {
        $user = current_user();
        $pdo->prepare('INSERT INTO defects (device_id, reported_by, problem, priority) VALUES (?, ?, ?, ?)')
            ->execute([$deviceId, $user['id'], $problem, $priority]);
        $defectId = (int)$pdo->lastInsertId();

        $pdo->prepare('UPDATE devices SET status = "defekt" WHERE id = ?')->execute([$deviceId]);

        log_activity('Defekt gemeldet', 'device', $deviceId, $device['inventory_number'] . ' – ' . $problem);
        log_activity('Defektmeldung erstellt', 'defect', $defectId, $device['inventory_number'] . ' (' . $priority . ')');

        $werkstattUsers = $pdo->query("SELECT id FROM users WHERE role = 'werkstatt' AND active = 1")->fetchAll();
        foreach ($werkstattUsers as $w) {
            create_notification(
                (int)$w['id'],
                'Neuer Werkstattauftrag: ' . $device['inventory_number'],
                $device['name'] . ' – ' . $problem . ' (Priorität: ' . ucfirst($priority) . ')',
                'modules/defekte/defekt.php?id=' . $defectId
            );
        }

        flash('success', 'Defekt für ' . $device['inventory_number'] . ' gemeldet.');
        redirect('modules/defekte/defekt.php?id=' . $defectId);
    }
}

$page_title = 'Defekt melden';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head"><h1>Defekt melden</h1></div>

<div class="card">
    <h3 class="mt-0"><?= e($device['inventory_number']) ?> – <?= e($device['name']) ?></h3>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label>Problem *</label>
            <textarea name="problem" required placeholder="z.B. Mikrofonkabel beschädigt"></textarea>
        </div>
        <div class="field">
            <label>Priorität</label>
            <select name="priority">
                <option value="niedrig">Niedrig</option>
                <option value="normal" selected>Normal</option>
                <option value="dringend">Dringend</option>
            </select>
        </div>
        <div class="btn-row">
            <button type="submit" class="btn btn-danger">Meldung erstellen</button>
            <a href="<?= url('modules/lager/geraet.php?id=' . (int)$device['id']) ?>" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
