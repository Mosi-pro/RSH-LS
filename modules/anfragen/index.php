<?php
/**
 * RSH-LS – Anfragen: eingereichte Veranstaltungsanfragen, die noch zu einem
 * Auftrag gemacht (oder abgelehnt) werden müssen.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('auftraege.edit');

$pdo = db();
$status = input('status', 'offen');

$sql = 'SELECT er.*, u.name AS submitted_by_name FROM event_requests er
        LEFT JOIN users u ON u.id = er.submitted_by';
$params = [];
if ($status !== '') {
    $sql .= ' WHERE er.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY er.created_at DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$openCount = (int)$pdo->query("SELECT COUNT(*) FROM event_requests WHERE status = 'offen'")->fetchColumn();

$page_title = 'Anfragen';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head"><h1>Anfragen</h1></div>

<div class="tabs">
    <a class="tab <?= $status === 'offen' ? 'active' : '' ?>" href="<?= url('modules/anfragen/index.php?status=offen') ?>">Offen (<?= $openCount ?>)</a>
    <a class="tab <?= $status === 'bearbeitet' ? 'active' : '' ?>" href="<?= url('modules/anfragen/index.php?status=bearbeitet') ?>">Bearbeitet</a>
    <a class="tab <?= $status === 'abgelehnt' ? 'active' : '' ?>" href="<?= url('modules/anfragen/index.php?status=abgelehnt') ?>">Abgelehnt</a>
    <a class="tab <?= $status === '' ? 'active' : '' ?>" href="<?= url('modules/anfragen/index.php?status=') ?>">Alle</a>
</div>

<?php if (!$requests): ?>
    <div class="empty-state"><div class="es-icon">📥</div>Keine Anfragen in dieser Ansicht.</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Datum</th><th>Ort</th><th>Antragsteller</th><th>Benötigt</th><th>Eingereicht</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <?php
            $needs = [];
            if ($r['needs_licht']) { $needs[] = 'Licht'; }
            if ($r['needs_ton']) { $needs[] = 'Ton'; }
            if ($r['needs_atrium']) { $needs[] = 'Atrium (' . $r['atrium_mode'] . ')'; }
            ?>
            <tr onclick="location.href='<?= url('modules/anfragen/anfrage.php?id=' . (int)$r['id']) ?>'" style="cursor:pointer">
                <td><?= format_date($r['event_date']) ?><?= $r['event_date_end'] ? ' – ' . format_date($r['event_date_end']) : '' ?></td>
                <td><?= e($r['location']) ?></td>
                <td><?= e($r['requester_name']) ?></td>
                <td><?= e(implode(', ', $needs)) ?></td>
                <td><?= format_datetime($r['created_at']) ?></td>
                <td><span class="badge badge-<?= $r['status'] === 'offen' ? 'danger' : ($r['status'] === 'bearbeitet' ? 'ok' : 'muted') ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
