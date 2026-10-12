<?php
/**
 * RSH-LS – Anfrage-Detail: Prüfen, ablehnen oder daraus einen Auftrag erstellen.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('auftraege.edit');

$pdo = db();
$user = current_user();
$id = (int)input('id');

$stmt = $pdo->prepare('SELECT er.*, u.name AS submitted_by_name FROM event_requests er
                        LEFT JOIN users u ON u.id = er.submitted_by WHERE er.id = ?');
$stmt->execute([$id]);
$request = $stmt->fetch();

if (!$request) {
    flash('error', 'Anfrage nicht gefunden.');
    redirect('modules/anfragen/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('form_action');

    if ($action === 'reject') {
        $pdo->prepare("UPDATE event_requests SET status = 'abgelehnt' WHERE id = ?")->execute([$id]);
        log_activity('Anfrage abgelehnt', 'event_request', $id, $request['location'] . ' am ' . $request['event_date']);
        flash('success', 'Anfrage abgelehnt.');
        redirect('modules/anfragen/index.php');
    }
}

$orderLink = null;
if ($request['order_id']) {
    $orderLink = 'modules/auftraege/auftrag.php?id=' . (int)$request['order_id'];
}

$page_title = 'Anfrage';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1><?= e($request['location']) ?> – <?= format_date($request['event_date']) ?></h1>
    <span class="badge badge-<?= $request['status'] === 'offen' ? 'danger' : ($request['status'] === 'bearbeitet' ? 'ok' : 'muted') ?>"><?= e(ucfirst($request['status'])) ?></span>
</div>

<div class="card">
    <div class="form-grid">
        <div class="field"><label>Antragsteller</label><div><?= e($request['requester_name']) ?></div></div>
        <div class="field"><label>E-Mail</label><div><?= e($request['requester_email']) ?></div></div>
        <div class="field"><label>Datum</label><div><?= format_date($request['event_date']) ?><?= $request['event_date_end'] ? ' – ' . format_date($request['event_date_end']) : '' ?></div></div>
        <div class="field"><label>Uhrzeit</label><div><?= e($request['event_time'] ?? '–') ?></div></div>
        <div class="field"><label>Ort</label><div><?= e($request['location']) ?></div></div>
        <div class="field"><label>Eingereicht</label><div><?= format_datetime($request['created_at']) ?> von <?= e($request['submitted_by_name'] ?? '–') ?></div></div>
    </div>

    <h3>Benötigte Technik</h3>
    <?php if ($request['needs_licht']): ?><div class="field"><label>Licht</label><div><?= nl2br(e($request['licht_details'])) ?></div></div><?php endif; ?>
    <?php if ($request['needs_ton']): ?><div class="field"><label>Ton</label><div><?= nl2br(e($request['ton_details'])) ?></div></div><?php endif; ?>
    <?php if ($request['needs_atrium']): ?><div class="field"><label>Atrium</label><div><?= e(ucfirst($request['atrium_mode'])) ?></div></div><?php endif; ?>
    <?php if ($request['technicians_needed']): ?><div class="field"><label>Techniker</label><div><?= (int)$request['technician_count'] ?></div></div><?php endif; ?>
    <?php if ($request['schedule_details']): ?><div class="field"><label>Zeitplan</label><div><?= nl2br(e($request['schedule_details'])) ?></div></div><?php endif; ?>

    <div class="btn-row" style="margin-top:16px;">
        <?php if ($orderLink): ?>
            <a href="<?= url($orderLink) ?>" class="btn btn-primary">Zum Auftrag →</a>
        <?php elseif ($request['status'] === 'offen'): ?>
            <a href="<?= url('modules/auftraege/auftrag.php?id=new&from_request=' . $id) ?>" class="btn btn-primary">Auftrag erstellen</a>
            <form method="post" onsubmit="return confirm('Anfrage wirklich ablehnen?');" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="reject">
                <button type="submit" class="btn btn-danger">Ablehnen</button>
            </form>
        <?php endif; ?>
        <a href="<?= url('modules/anfragen/index.php') ?>" class="btn btn-ghost">← Zurück</a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
