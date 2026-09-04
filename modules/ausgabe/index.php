<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('ausgabe_rueckgabe.edit');

$terminal = input('terminal') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $orderNumber = preg_replace('/[^0-9\-]/', '', input('order_number'));
    if ($orderNumber !== '') {
        redirect('modules/ausgabe/ausgabe.php?order=' . urlencode($orderNumber) . ($terminal ? '&terminal=1' : ''));
    }
}

$page_title = 'Ausgabe';
require_once __DIR__ . '/../../includes/header.php';
?>
<?php if ($terminal): ?>
<div class="terminal-header">
    <div class="t-brand">RSH TECHNIK</div>
    <div class="t-title">AUSGABE</div>
</div>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="terminal" value="1">
    <label class="small muted" style="display:block;text-align:center;margin-bottom:8px;">AUFTRAGSNUMMER</label>
    <input type="text" name="order_number" class="terminal-input" placeholder="2026-041" data-autofocus data-scan-target autofocus required>
    <button type="submit" class="btn btn-primary btn-block btn-lg">WEITER</button>
</form>
<div class="btn-row" style="margin-top:20px;justify-content:center;">
    <a href="<?= url('terminal/index.php') ?>" class="btn btn-ghost">← Zurück</a>
</div>
<?php else: ?>
<h1>Ausgabe</h1>
<div class="card" style="max-width:420px;">
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label>Auftragsnummer</label>
            <input type="text" name="order_number" placeholder="2026-041" required data-autofocus>
        </div>
        <button type="submit" class="btn btn-primary">Weiter</button>
    </form>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
