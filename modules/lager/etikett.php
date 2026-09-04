<?php
/**
 * RSH-LS – Druckbares Etikett für ein Gerät (QR + Inventarnummer + Name).
 * Bei Mengenartikeln (Menge > 1) wird für jedes physische Stück ein eigenes Etikett gedruckt.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('lager.view');

$pdo = db();
$id = (int)input('id');

$stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ?');
$stmt->execute([$id]);
$device = $stmt->fetch();

if (!$device) {
    flash('error', 'Gerät nicht gefunden.');
    redirect('modules/lager/index.php');
}

$scanUrl = full_url('modules/lager/scan.php?code=' . urlencode($device['inventory_number']));
$copies = max(1, (int)$device['quantity']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Etikett <?= e($device['inventory_number']) ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
    body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #fff; color: #000; }
    .toolbar { margin-bottom: 20px; }
    .grid { display: flex; flex-wrap: wrap; gap: 6mm; }
    .label {
        width: 60mm; padding: 8px; border: 1px solid #999; border-radius: 4px;
        display: flex; align-items: center; gap: 10px; page-break-inside: avoid;
    }
    .label .qr { flex: 0 0 auto; }
    .label .info { min-width: 0; }
    .label .inv { font-weight: 700; font-size: 14px; }
    .label .name { font-size: 11px; line-height: 1.3; word-break: break-word; }
    .label .copy { font-size: 9px; color: #666; }
    @media print {
        .toolbar { display: none; }
        body { padding: 0; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">Drucken</button>
    <?php if ($copies > 1): ?><span><?= $copies ?> Etiketten (Bestand)</span><?php endif; ?>
</div>
<div class="grid">
    <?php for ($n = 1; $n <= $copies; $n++): ?>
        <div class="label">
            <div class="qr" id="qrcode<?= $n ?>"></div>
            <div class="info">
                <div class="inv"><?= e($device['inventory_number']) ?></div>
                <div class="name"><?= e($device['name']) ?></div>
                <?php if ($copies > 1): ?><div class="copy">Stück <?= $n ?> / <?= $copies ?></div><?php endif; ?>
            </div>
        </div>
    <?php endfor; ?>
</div>
<script>
var scanUrl = <?= json_encode($scanUrl) ?>;
for (var n = 1; n <= <?= $copies ?>; n++) {
    new QRCode(document.getElementById('qrcode' + n), { text: scanUrl, width: 70, height: 70 });
}
</script>
</body>
</html>
