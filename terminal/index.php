<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('ausgabe_rueckgabe.edit');

$user = current_user();
$page_title = 'Lager-Terminal';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="terminal-header">
    <div class="t-brand">RSH TECHNIK</div>
    <div class="t-title">LAGER-TERMINAL</div>
    <div class="t-status">● Angemeldet als <?= e($user['name']) ?></div>
</div>

<div class="terminal-actions">
    <a class="terminal-tile" href="<?= url('modules/ausgabe/index.php?terminal=1') ?>">
        <span class="t-icon">📦</span> AUSGABE
    </a>
    <a class="terminal-tile" href="<?= url('modules/rueckgabe/index.php?terminal=1') ?>">
        <span class="t-icon">↩</span> RÜCKGABE
    </a>
    <a class="terminal-tile" href="<?= url('modules/ausgabe/schnell.php?terminal=1') ?>">
        <span class="t-icon">⚡</span> SCHNELLAUSGABE
    </a>
    <a class="terminal-tile" href="<?= url('modules/lager/index.php') ?>">
        <span class="t-icon">🔎</span> LAGER SUCHEN
    </a>
    <?php if ($user['role'] !== 'lager_terminal'): ?>
    <a class="terminal-tile" href="<?= url('public/dashboard.php') ?>">
        <span class="t-icon">⌂</span> ZUM DASHBOARD
    </a>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
