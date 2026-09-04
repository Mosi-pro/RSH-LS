<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('defekte.report');

$pdo = db();
$tab = input('tab', 'defekte');
$status = input('status');

if ($tab === 'wartung') {
    $sql = "SELECT d.* FROM devices d
            WHERE d.next_maintenance_date IS NOT NULL AND d.next_maintenance_date != ''
              AND d.status != 'aussortiert'
            ORDER BY d.next_maintenance_date ASC LIMIT 300";
    $devices = $pdo->query($sql)->fetchAll();
} else {
    $where = [];
    $params = [];
    if ($status !== '') {
        $where[] = 'f.status = ?';
        $params[] = $status;
    }
    $sql = 'SELECT f.*, d.inventory_number, d.name AS device_name, u.name AS reporter_name
            FROM defects f
            JOIN devices d ON d.id = f.device_id
            LEFT JOIN users u ON u.id = f.reported_by';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY (f.status = "behoben"), f.created_at DESC LIMIT 300';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $defects = $stmt->fetchAll();
}

$page_title = 'Defekte & Wartung';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head"><h1>Defekte & Wartung</h1></div>

<div class="tabs">
    <a class="tab <?= $tab === 'defekte' ? 'active' : '' ?>" href="<?= url('modules/defekte/index.php?tab=defekte') ?>">Defekte</a>
    <a class="tab <?= $tab === 'wartung' ? 'active' : '' ?>" href="<?= url('modules/defekte/index.php?tab=wartung') ?>">Wartung</a>
</div>

<?php if ($tab === 'wartung'): ?>

    <?php
    $today = date('Y-m-d');
    $overdue = array_filter($devices, fn($d) => $d['next_maintenance_date'] < $today);
    $upcoming = array_filter($devices, fn($d) => $d['next_maintenance_date'] >= $today);
    ?>
    <?php if (!$devices): ?>
        <div class="empty-state"><div class="es-icon">⚠</div>Keine Wartungstermine hinterlegt. Im Gerät unter „Wartung“ eintragen.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Inv.-Nr.</th><th>Gerät</th><th>Letzte Wartung</th><th>Nächste Wartung</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($devices as $d): ?>
                <tr onclick="location.href='<?= url('modules/lager/geraet.php?id=' . (int)$d['id']) ?>'" style="cursor:pointer">
                    <td><?= e($d['inventory_number']) ?></td>
                    <td><?= e($d['name']) ?></td>
                    <td><?= format_date($d['last_maintenance_date']) ?></td>
                    <td><?= format_date($d['next_maintenance_date']) ?></td>
                    <td><?php if ($d['next_maintenance_date'] < $today): ?><span class="badge badge-danger">Fällig</span><?php else: ?><span class="badge badge-info">Geplant</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php else: ?>

    <form method="get" class="card card-flat">
        <input type="hidden" name="tab" value="defekte">
        <div class="form-grid">
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="">Alle</option>
                    <option value="offen" <?= $status === 'offen' ? 'selected' : '' ?>>Offen</option>
                    <option value="in_bearbeitung" <?= $status === 'in_bearbeitung' ? 'selected' : '' ?>>In Bearbeitung</option>
                    <option value="behoben" <?= $status === 'behoben' ? 'selected' : '' ?>>Behoben</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-sm">Filtern</button>
    </form>

    <?php if (!$defects): ?>
        <div class="empty-state"><div class="es-icon">⚠</div>Keine Defektmeldungen gefunden.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Inv.-Nr.</th><th>Gerät</th><th>Problem</th><th>Priorität</th><th>Gemeldet von</th><th>Datum</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($defects as $f): ?>
                <tr onclick="location.href='<?= url('modules/defekte/defekt.php?id=' . (int)$f['id']) ?>'" style="cursor:pointer">
                    <td><?= e($f['inventory_number']) ?></td>
                    <td><?= e($f['device_name']) ?></td>
                    <td><?= e(mb_strimwidth($f['problem'], 0, 60, '…')) ?></td>
                    <td><span class="badge badge-<?= $f['priority'] === 'dringend' ? 'danger' : ($f['priority'] === 'niedrig' ? 'muted' : 'warn') ?>"><?= e(ucfirst($f['priority'])) ?></span></td>
                    <td><?= e($f['reporter_name'] ?? '–') ?></td>
                    <td><?= format_datetime($f['created_at']) ?></td>
                    <td><span class="badge badge-<?= $f['status'] === 'behoben' ? 'ok' : ($f['status'] === 'in_bearbeitung' ? 'info' : 'danger') ?>"><?= e(str_replace('_', ' ', ucfirst($f['status']))) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
