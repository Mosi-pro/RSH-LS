<?php
/**
 * RSH-LS – Ziel eines gescannten Geräte-QR-Codes.
 * Zeigt Status, Lagerort und aktuellen Auftrag, plus Schnellaktionen.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('lager.view');

$pdo = db();
$code = input('code');

$stmt = $pdo->prepare(
    'SELECT d.*, c.name AS category_name, l.name AS location_name
     FROM devices d
     LEFT JOIN device_categories c ON c.id = d.category_id
     LEFT JOIN storage_locations l ON l.id = d.location_id
     WHERE d.inventory_number = ?'
);
$stmt->execute([$code]);
$device = $stmt->fetch();

if (!$device) {
    flash('error', 'Gerät „' . $code . '“ wurde nicht gefunden.');
    redirect('modules/lager/index.php');
}

$currentOrder = null;
if ($device['current_order_id']) {
    $s = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $s->execute([(int)$device['current_order_id']]);
    $currentOrder = $s->fetch();
}

$page_title = $device['inventory_number'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1><?= e($device['inventory_number']) ?></h1>
    <span class="badge badge-<?= status_class($device['status']) ?>"><?= e(device_status_label($device['status'])) ?></span>
</div>

<div class="card">
    <h2 class="mt-0"><?= e($device['name']) ?></h2>
    <?php if ($device['manufacturer'] || $device['model']): ?>
        <p class="muted"><?= e(trim($device['manufacturer'] . ' ' . $device['model'])) ?></p>
    <?php endif; ?>

    <div class="form-grid">
        <div class="field"><label>Kategorie</label><div><?= e($device['category_name'] ?? '–') ?></div></div>
        <div class="field"><label>Lagerort</label><div><?= e($device['location_name'] ?? '–') ?></div></div>
        <div class="field"><label>Bestand</label><div><?= $device['is_bulk'] ? (int)$device['quantity'] . ' Stk.' : '1 Stk.' ?></div></div>
    </div>

    <?php if ($currentOrder): ?>
    <div class="field">
        <label>Aktueller Auftrag</label>
        <div><a href="<?= url('modules/auftraege/auftrag.php?id=' . (int)$currentOrder['id']) ?>">#<?= e($currentOrder['order_number']) ?> – <?= e($currentOrder['title']) ?></a></div>
    </div>
    <?php endif; ?>

    <div class="btn-row" style="margin-top:16px;">
        <?php if (has_permission('ausgabe_rueckgabe.edit')): ?>
            <?php if ($currentOrder): ?>
                <a class="btn btn-primary" href="<?= url('modules/ausgabe/ausgabe.php?order=' . urlencode($currentOrder['order_number'])) ?>">AUSGEBEN</a>
            <?php else: ?>
                <a class="btn" href="<?= url('modules/ausgabe/index.php') ?>">AUSGEBEN (Auftrag wählen)</a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (has_permission('defekte.report')): ?>
            <a class="btn btn-danger" href="<?= url('modules/defekte/melden.php?device_id=' . (int)$device['id']) ?>">DEFEKT MELDEN</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= url('modules/lager/geraet.php?id=' . (int)$device['id']) ?>">Vollständige Details</a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
