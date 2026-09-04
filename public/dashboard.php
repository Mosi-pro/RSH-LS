<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$user = current_user();
if (in_array($user['role'], ['lager_terminal', 'werkstatt'], true)) {
    redirect(home_path());
}

$pdo = db();

$deviceCount = (int)$pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
$openOrders  = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('abgeschlossen','storniert')")->fetchColumn();
$pendingReturns = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'rueckgabe_ausstehend'")->fetchColumn();

$statusCounts = [];
foreach ($pdo->query('SELECT status, COUNT(*) AS c FROM devices GROUP BY status') as $row) {
    $statusCounts[$row['status']] = (int)$row['c'];
}

$ordersSql = "SELECT o.*, u.name AS responsible_name FROM orders o
     LEFT JOIN users u ON u.id = o.responsible_user_id
     WHERE o.status NOT IN ('abgeschlossen','storniert')";
$ordersParams = [];
if (!has_permission('auftraege.view') && has_permission('auftraege.view_own')) {
    $ordersSql .= ' AND o.responsible_user_id = ?';
    $ordersParams[] = $user['id'];
}
$ordersSql .= ' ORDER BY (o.event_date IS NULL), o.event_date ASC, o.id DESC LIMIT 8';
$stmt = $pdo->prepare($ordersSql);
$stmt->execute($ordersParams);
$recentOrders = $stmt->fetchAll();

$hour = (int)date('H');
$greeting = $hour < 11 ? 'Guten Morgen' : ($hour < 18 ? 'Guten Tag' : 'Guten Abend');

$canSeeWarnings = has_permission('lager.edit') || has_permission('auftraege.edit');
$warnings = $canSeeWarnings ? get_warnings() : [];

$page_title = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<h1><?= e($greeting) ?>, <?= e($user['name']) ?></h1>

<?php if ($warnings): ?>
<div class="card">
    <h3 class="mt-0">⚠ Warnungen (<?= count($warnings) ?>)</h3>
    <ul class="timeline">
        <?php foreach ($warnings as $w): ?>
            <li>
                <span class="t-time"><span class="badge badge-<?= $w['level'] ?>">&nbsp;</span></span>
                <span class="t-body"><a href="<?= url($w['url']) ?>"><?= e($w['message']) ?></a></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $deviceCount ?></div>
        <div class="stat-label">Geräte</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $openOrders ?></div>
        <div class="stat-label">Offene Aufträge</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $pendingReturns ?></div>
        <div class="stat-label">Rückgaben ausstehend</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $statusCounts['defekt'] ?? 0 ?></div>
        <div class="stat-label">Defekte Geräte</div>
    </div>
</div>

<div class="section-head">
    <h2>Aktuelle Aufträge</h2>
    <a href="<?= url('modules/auftraege/index.php') ?>" class="btn btn-ghost btn-sm">Alle Aufträge →</a>
</div>

<?php if (!$recentOrders): ?>
    <div class="empty-state"><div class="es-icon">▤</div>Keine offenen Aufträge.</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead>
        <tr><th>Nr.</th><th>Titel</th><th>Termin</th><th>Verantwortlich</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recentOrders as $o): ?>
            <tr onclick="location.href='<?= url('modules/auftraege/auftrag.php?id=' . (int)$o['id']) ?>'" style="cursor:pointer">
                <td>#<?= e($o['order_number']) ?></td>
                <td><?= e($o['title']) ?></td>
                <td><?= format_date($o['event_date']) ?></td>
                <td><?= e($o['responsible_name'] ?? '–') ?></td>
                <td><span class="badge badge-<?= status_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="section-head"><h2>Schnellzugriff</h2></div>
<div class="btn-row">
    <?php if (has_permission('lager.view')): ?><a class="btn" href="<?= url('modules/lager/index.php') ?>">Lager</a><?php endif; ?>
    <?php if (has_permission('auftraege.edit')): ?><a class="btn" href="<?= url('modules/auftraege/auftrag.php?id=new') ?>">Neuer Auftrag</a><?php endif; ?>
    <?php if (has_permission('veranstaltungen.view')): ?><a class="btn" href="<?= url('modules/veranstaltungen/index.php') ?>">Veranstaltungen</a><?php endif; ?>
    <?php if (has_permission('ausgabe_rueckgabe.edit')): ?><a class="btn" href="<?= url('terminal/index.php') ?>">Ausgabe / Rückgabe</a><?php endif; ?>
    <?php if (has_permission('inventur.view')): ?><a class="btn" href="<?= url('modules/inventur/index.php') ?>">Inventur</a><?php endif; ?>
    <?php if (has_permission('defekte.report')): ?><a class="btn" href="<?= url('modules/defekte/index.php') ?>">Defekte & Wartung</a><?php endif; ?>
    <?php if (has_permission('reports.view')): ?><a class="btn" href="<?= url('modules/reports/index.php') ?>">Auswertungen</a><?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
