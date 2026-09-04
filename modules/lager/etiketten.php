<?php
/**
 * RSH-LS – Druckbarer Stapel von Etiketten (nutzt dieselben Filter wie die Lagerliste).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('lager.view');

$pdo = db();

$q        = input('q');
$status   = input('status');
$category = input('category');

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(d.name LIKE ? OR d.inventory_number LIKE ? OR d.manufacturer LIKE ? OR d.model LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($status !== '') {
    $where[] = 'd.status = ?';
    $params[] = $status;
}
if ($category !== '') {
    $where[] = 'd.category_id = ?';
    $params[] = $category;
}

$sql = 'SELECT d.* FROM devices d';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY d.inventory_number ASC LIMIT 500';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll();

// Bei Mengenartikeln bekommt jedes physische Stück ein eigenes Etikett.
$labels = [];
foreach ($devices as $d) {
    $copies = max(1, (int)$d['quantity']);
    for ($n = 1; $n <= $copies; $n++) {
        $labels[] = ['inventory_number' => $d['inventory_number'], 'name' => $d['name'], 'copy' => $n, 'copies' => $copies];
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Etiketten drucken</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
    body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #fff; color: #000; }
    .toolbar { margin-bottom: 20px; }
    .grid { display: flex; flex-wrap: wrap; gap: 6mm; }
    .label {
        width: 60mm; padding: 8px; border: 1px solid #999; border-radius: 4px;
        display: flex; align-items: center; gap: 10px; page-break-inside: avoid;
    }
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
    <button onclick="window.print()">Alle drucken</button>
    <span><?= count($labels) ?> Etiketten (<?= count($devices) ?> Geräte)</span>
</div>
<div class="grid">
    <?php foreach ($labels as $i => $l): ?>
        <div class="label">
            <div class="qr" id="qr<?= $i ?>"></div>
            <div class="info">
                <div class="inv"><?= e($l['inventory_number']) ?></div>
                <div class="name"><?= e($l['name']) ?></div>
                <?php if ($l['copies'] > 1): ?><div class="copy">Stück <?= $l['copy'] ?> / <?= $l['copies'] ?></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<script>
var labels = <?= json_encode(array_map(function ($l) {
    return ['inv' => $l['inventory_number']];
}, $labels)) ?>;
var base = <?= json_encode(full_url('modules/lager/scan.php?code=')) ?>;
labels.forEach(function (l, i) {
    new QRCode(document.getElementById('qr' + i), { text: base + encodeURIComponent(l.inv), width: 60, height: 60 });
});
</script>
</body>
</html>
