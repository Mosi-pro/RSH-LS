<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('defekte.report');

$pdo = db();
$id = (int)input('id');

$stmt = $pdo->prepare(
    'SELECT f.*, d.inventory_number, d.name AS device_name, d.id AS device_id,
        u.name AS reporter_name, r.name AS resolver_name
     FROM defects f
     JOIN devices d ON d.id = f.device_id
     LEFT JOIN users u ON u.id = f.reported_by
     LEFT JOIN users r ON r.id = f.resolved_by
     WHERE f.id = ?'
);
$stmt->execute([$id]);
$defect = $stmt->fetch();

if (!$defect) {
    flash('error', 'Defektmeldung nicht gefunden.');
    redirect('modules/defekte/index.php');
}

$canManage = has_permission('defekte.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('defekte.manage');
    csrf_verify();
    $newStatus = input('status');
    $note = input('resolution_note');

    if (!in_array($newStatus, ['offen', 'in_bearbeitung', 'behoben'], true)) {
        flash('error', 'Ungültiger Status.');
    } else {
        $user = current_user();
        if ($newStatus === 'behoben') {
            $pdo->prepare('UPDATE defects SET status = ?, resolution_note = ?, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$newStatus, $note ?: null, $user['id'], $id]);
            // Gerät nur reaktivieren, wenn es noch als "defekt" markiert ist
            $pdo->prepare('UPDATE devices SET status = "verfuegbar" WHERE id = ? AND status = "defekt"')
                ->execute([$defect['device_id']]);
        } else {
            $pdo->prepare('UPDATE defects SET status = ?, resolution_note = ? WHERE id = ?')
                ->execute([$newStatus, $note ?: null, $id]);
        }
        log_activity('Defektstatus geändert', 'defect', $id, $defect['inventory_number'] . ': ' . $defect['status'] . ' → ' . $newStatus);
        flash('success', 'Status aktualisiert.');
        redirect('modules/defekte/defekt.php?id=' . $id);
    }
}

$page_title = 'Defekt #' . $defect['id'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1><?= e($defect['inventory_number']) ?> – <?= e($defect['device_name']) ?></h1>
    <span class="badge badge-<?= $defect['status'] === 'behoben' ? 'ok' : ($defect['status'] === 'in_bearbeitung' ? 'info' : 'danger') ?>"><?= e(str_replace('_', ' ', ucfirst($defect['status']))) ?></span>
</div>

<div class="card">
    <div class="field"><label>Problem</label><div><?= nl2br(e($defect['problem'])) ?></div></div>
    <div class="form-grid">
        <div class="field"><label>Priorität</label><div><?= e(ucfirst($defect['priority'])) ?></div></div>
        <div class="field"><label>Gemeldet von</label><div><?= e($defect['reporter_name'] ?? '–') ?></div></div>
        <div class="field"><label>Gemeldet am</label><div><?= format_datetime($defect['created_at']) ?></div></div>
    </div>
    <?php if ($defect['resolved_at']): ?>
        <div class="form-grid">
            <div class="field"><label>Behoben von</label><div><?= e($defect['resolver_name'] ?? '–') ?></div></div>
            <div class="field"><label>Behoben am</label><div><?= format_datetime($defect['resolved_at']) ?></div></div>
        </div>
        <?php if ($defect['resolution_note']): ?>
            <div class="field"><label>Lösung / Bemerkung</label><div><?= nl2br(e($defect['resolution_note'])) ?></div></div>
        <?php endif; ?>
    <?php endif; ?>
    <a href="<?= url('modules/lager/geraet.php?id=' . (int)$defect['device_id']) ?>" class="btn btn-ghost btn-sm">Zum Gerät</a>
</div>

<?php if ($canManage): ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <h3 class="mt-0">Status ändern</h3>
    <div class="field">
        <label>Status</label>
        <select name="status">
            <option value="offen" <?= $defect['status'] === 'offen' ? 'selected' : '' ?>>Offen</option>
            <option value="in_bearbeitung" <?= $defect['status'] === 'in_bearbeitung' ? 'selected' : '' ?>>In Bearbeitung</option>
            <option value="behoben" <?= $defect['status'] === 'behoben' ? 'selected' : '' ?>>Behoben</option>
        </select>
    </div>
    <div class="field">
        <label>Lösung / Bemerkung</label>
        <textarea name="resolution_note"><?= e($defect['resolution_note'] ?? '') ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Speichern</button>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
