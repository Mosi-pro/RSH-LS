<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('inventur.view');

$pdo = db();
$id = (int)input('id');

$stmt = $pdo->prepare('SELECT i.*, l.name AS location_name FROM inventories i
                        LEFT JOIN storage_locations l ON l.id = i.location_id WHERE i.id = ?');
$stmt->execute([$id]);
$inventory = $stmt->fetch();

if (!$inventory) {
    flash('error', 'Inventur nicht gefunden.');
    redirect('modules/inventur/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('inventur.edit');
    csrf_verify();
    $action = input('form_action');
    $user = current_user();

    if ($action === 'mark_found' && $inventory['status'] === 'laufend') {
        $deviceId = (int)input('device_id');
        $code = input('code');

        if ($code !== '' && !$deviceId) {
            $d = $pdo->prepare('SELECT id FROM devices WHERE inventory_number = ?');
            $d->execute([$code]);
            $deviceId = (int)$d->fetchColumn();
        }

        if ($deviceId) {
            $item = $pdo->prepare('SELECT * FROM inventory_items WHERE inventory_id = ? AND device_id = ?');
            $item->execute([$id, $deviceId]);
            $row = $item->fetch();
            if ($row) {
                $pdo->prepare('UPDATE inventory_items SET found = 1, checked_at = CURRENT_TIMESTAMP, checked_by = ? WHERE id = ?')
                    ->execute([$user['id'], $row['id']]);
            } elseif ($code !== '') {
                flash('error', '„' . $code . '“ gehört nicht zu dieser Inventur.');
            }
        }
        redirect('modules/inventur/inventur.php?id=' . $id);

    } elseif ($action === 'unmark' && $inventory['status'] === 'laufend') {
        $deviceId = (int)input('device_id');
        $pdo->prepare('UPDATE inventory_items SET found = NULL, checked_at = NULL, checked_by = NULL WHERE inventory_id = ? AND device_id = ?')
            ->execute([$id, $deviceId]);
        redirect('modules/inventur/inventur.php?id=' . $id);

    } elseif ($action === 'finish' && $inventory['status'] === 'laufend') {
        $pdo->prepare('UPDATE inventory_items SET found = 0 WHERE inventory_id = ? AND found IS NULL')->execute([$id]);
        $pdo->prepare('UPDATE inventories SET status = "abgeschlossen", completed_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);

        $total = (int)$pdo->query('SELECT COUNT(*) FROM inventory_items WHERE inventory_id = ' . $id)->fetchColumn();
        $found = (int)$pdo->query('SELECT COUNT(*) FROM inventory_items WHERE inventory_id = ' . $id . ' AND found = 1')->fetchColumn();
        log_activity('Inventur abgeschlossen', 'inventory', $id, $found . ' / ' . $total . ' gefunden');
        flash('success', 'Inventur abgeschlossen: ' . $found . ' / ' . $total . ' gefunden.');
        redirect('modules/inventur/inventur.php?id=' . $id);

    } elseif ($action === 'mark_lost') {
        $deviceId = (int)input('device_id');
        $pdo->prepare('UPDATE devices SET status = "verloren", current_order_id = NULL WHERE id = ?')->execute([$deviceId]);
        $devStmt = $pdo->prepare('SELECT inventory_number, name FROM devices WHERE id = ?');
        $devStmt->execute([$deviceId]);
        $dev = $devStmt->fetch();
        log_activity('Als verloren markiert (Inventur)', 'device', $deviceId, $dev['inventory_number'] . ' ' . $dev['name']);
        flash('success', $dev['inventory_number'] . ' als verloren markiert.');
        redirect('modules/inventur/inventur.php?id=' . $id);
    }
}

$items = $pdo->prepare(
    'SELECT ii.*, d.inventory_number, d.name AS device_name, d.status AS device_status
     FROM inventory_items ii JOIN devices d ON d.id = ii.device_id
     WHERE ii.inventory_id = ? ORDER BY d.inventory_number'
);
$items->execute([$id]);
$items = $items->fetchAll();

$total = count($items);
$found = count(array_filter($items, fn($i) => $i['found'] === '1' || $i['found'] === 1));
$missing = count(array_filter($items, fn($i) => $i['found'] === '0' || $i['found'] === 0));
$open = $total - $found - $missing;
$progress = $total > 0 ? round(($found + $missing) / $total * 100) : 100;

$page_title = $inventory['label'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1><?= e($inventory['label']) ?></h1>
    <span class="badge badge-<?= $inventory['status'] === 'abgeschlossen' ? 'ok' : 'warn' ?>"><?= $inventory['status'] === 'abgeschlossen' ? 'Abgeschlossen' : 'Läuft' ?></span>
</div>

<?php if ($inventory['status'] === 'abgeschlossen'): ?>
<div class="stat-grid">
    <div class="stat-card"><div class="stat-value"><?= $total ?></div><div class="stat-label">Sollbestand</div></div>
    <div class="stat-card"><div class="stat-value"><?= $found ?></div><div class="stat-label">Gefunden</div></div>
    <div class="stat-card"><div class="stat-value"><?= $missing ?></div><div class="stat-label">Fehlend</div></div>
</div>
<?php else: ?>
<div class="progress-bar"><div class="progress-bar-fill" style="width:<?= $progress ?>%"></div></div>
<p class="small muted"><?= $found + $missing ?> / <?= $total ?> geprüft (<?= $found ?> gefunden)</p>

<?php if (has_permission('inventur.edit')): ?>
<form method="post" class="card card-flat">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="mark_found">
    <div class="field">
        <label>Inventarnummer scannen/eingeben</label>
        <input type="text" name="code" data-autofocus data-scan-target placeholder="RSH-0042">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Als gefunden markieren</button>
</form>

<form method="post" class="btn-row" style="margin-bottom:16px;">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="finish">
    <button type="submit" class="btn btn-danger" data-confirm="Inventur abschließen? Noch nicht geprüfte Geräte gelten dann als fehlend.">Inventur abschließen</button>
</form>
<?php endif; ?>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead><tr><th>Inv.-Nr.</th><th>Gerät</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= e($it['inventory_number']) ?></td>
                <td><?= e($it['device_name']) ?></td>
                <td>
                    <?php if ($it['found'] === '1' || $it['found'] === 1): ?>
                        <span class="badge badge-ok">Gefunden</span>
                    <?php elseif ($it['found'] === '0' || $it['found'] === 0): ?>
                        <span class="badge badge-danger">Fehlend</span>
                    <?php else: ?>
                        <span class="badge badge-muted">Offen</span>
                    <?php endif; ?>
                </td>
                <td class="text-right">
                    <?php if (has_permission('inventur.edit') && $inventory['status'] === 'laufend'): ?>
                        <?php if ($it['found'] === '1' || $it['found'] === 1): ?>
                            <form method="post" style="display:inline"><?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="unmark">
                                <input type="hidden" name="device_id" value="<?= (int)$it['device_id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm">rückgängig</button>
                            </form>
                        <?php else: ?>
                            <form method="post" style="display:inline"><?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="mark_found">
                                <input type="hidden" name="device_id" value="<?= (int)$it['device_id'] ?>">
                                <button type="submit" class="btn btn-sm">gefunden</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (has_permission('inventur.edit') && $inventory['status'] === 'abgeschlossen' && ($it['found'] === '0' || $it['found'] === 0) && $it['device_status'] !== 'verloren'): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Gerät als verloren markieren?');"><?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="mark_lost">
                            <input type="hidden" name="device_id" value="<?= (int)$it['device_id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Als verloren markieren</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
