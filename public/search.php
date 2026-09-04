<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$pdo = db();
$q = input('q');

$devices = $orders = $events = $employees = [];

if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';

    if (has_permission('lager.view')) {
        $stmt = $pdo->prepare('SELECT * FROM devices WHERE inventory_number LIKE ? OR name LIKE ? OR manufacturer LIKE ?
                                OR model LIKE ? OR serial_number LIKE ? ORDER BY name LIMIT 25');
        $stmt->execute([$like, $like, $like, $like, $like]);
        $devices = $stmt->fetchAll();
    }

    if (has_permission('auftraege.view') || has_permission('auftraege.view_own')) {
        $sql = 'SELECT * FROM orders WHERE (order_number LIKE ? OR title LIKE ? OR customer LIKE ?)';
        $params = [$like, $like, $like];
        if (!has_permission('auftraege.view')) {
            $sql .= ' AND responsible_user_id = ?';
            $params[] = current_user()['id'];
        }
        $sql .= ' ORDER BY id DESC LIMIT 25';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
    }

    if (has_permission('veranstaltungen.view')) {
        $stmt = $pdo->prepare('SELECT * FROM events WHERE name LIKE ? OR location LIKE ? OR organizer LIKE ? ORDER BY event_date DESC LIMIT 25');
        $stmt->execute([$like, $like, $like]);
        $events = $stmt->fetchAll();
    }

    if (has_permission('mitarbeiter.view')) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE name LIKE ? OR employee_id LIKE ? ORDER BY name LIMIT 25');
        $stmt->execute([$like, $like]);
        $employees = $stmt->fetchAll();
    }
}

$page_title = 'Suche';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Suche: „<?= e($q) ?>“</h1>

<?php if (mb_strlen($q) < 2): ?>
    <p class="muted">Bitte mindestens 2 Zeichen eingeben.</p>
<?php elseif (!$devices && !$orders && !$events && !$employees): ?>
    <div class="empty-state"><div class="es-icon">🔎</div>Keine Treffer gefunden.</div>
<?php endif; ?>

<?php if ($devices): ?>
    <div class="section-head"><h2>Geräte</h2></div>
    <div class="table-wrap">
        <table><thead><tr><th>Inv.-Nr.</th><th>Name</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($devices as $d): ?>
            <tr onclick="location.href='<?= url('modules/lager/geraet.php?id=' . (int)$d['id']) ?>'" style="cursor:pointer">
                <td><?= e($d['inventory_number']) ?></td><td><?= e($d['name']) ?></td>
                <td><span class="badge badge-<?= status_class($d['status']) ?>"><?= e(device_status_label($d['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
<?php endif; ?>

<?php if ($orders): ?>
    <div class="section-head"><h2>Aufträge</h2></div>
    <div class="table-wrap">
        <table><thead><tr><th>Nr.</th><th>Titel</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($orders as $o): ?>
            <tr onclick="location.href='<?= url('modules/auftraege/auftrag.php?id=' . (int)$o['id']) ?>'" style="cursor:pointer">
                <td>#<?= e($o['order_number']) ?></td><td><?= e($o['title']) ?></td>
                <td><span class="badge badge-<?= status_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
<?php endif; ?>

<?php if ($events): ?>
    <div class="section-head"><h2>Veranstaltungen</h2></div>
    <div class="table-wrap">
        <table><thead><tr><th>Name</th><th>Datum</th></tr></thead><tbody>
        <?php foreach ($events as $ev): ?>
            <tr onclick="location.href='<?= url('modules/veranstaltungen/veranstaltung.php?id=' . (int)$ev['id']) ?>'" style="cursor:pointer">
                <td><?= e($ev['name']) ?></td><td><?= format_date($ev['event_date']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
<?php endif; ?>

<?php if ($employees): ?>
    <div class="section-head"><h2>Mitarbeiter</h2></div>
    <div class="table-wrap">
        <table><thead><tr><th>ID</th><th>Name</th></tr></thead><tbody>
        <?php foreach ($employees as $emp): ?>
            <tr onclick="location.href='<?= url('modules/mitarbeiter/mitarbeiter.php?id=' . (int)$emp['id']) ?>'" style="cursor:pointer">
                <td><?= e($emp['employee_id']) ?></td><td><?= e($emp['name']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
