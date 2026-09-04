<?php
/**
 * RSH-LS – Druckbares Einzeletikett für ein Gerät (QR + Inventarnummer + Name).
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
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Etikett <?= e($device['inventory_number']) ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
    body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #fff; color: #000; }
    .label {
        width: 60mm; padding: 8px; border: 1px solid #999; border-radius: 4px;
        display: flex; align-items: center; gap: 10px;
    }
    .label .qr { flex: 0 0 auto; }
    .label .info { min-width: 0; }
    .label .inv { font-weight: 700; font-size: 14px; }
    .label .name { font-size: 11px; line-height: 1.3; word-break: break-word; }
    .toolbar { margin-bottom: 20px; }
    @media print {
        .toolbar { display: none; }
        body { padding: 0; }
    }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Drucken</button></div>
<div class="label">
    <div class="qr" id="qrcode"></div>
    <div class="info">
        <div class="inv"><?= e($device['inventory_number']) ?></div>
        <div class="name"><?= e($device['name']) ?></div>
    </div>
</div>
<script>
new QRCode(document.getElementById("qrcode"), {
    text: <?= json_encode($scanUrl) ?>,
    width: 70,
    height: 70
});
</script>
</body>
</html>
