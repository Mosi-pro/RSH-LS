<?php
/**
 * RSH-LS – Verwaltung
 * Zentraler Einstieg für administrative Funktionen (nur Technikleitung).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if (!is_admin()) {
    http_response_code(403);
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="card"><h1>Kein Zugriff</h1><p>Die Verwaltung ist der Technikleitung vorbehalten.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$page_title = 'Verwaltung';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Verwaltung</h1>
<p class="muted">Zentrale administrative Funktionen der Technikleitung.</p>

<div class="stat-grid">
    <a class="stat-card" href="<?= url('modules/mitarbeiter/index.php') ?>">
        <div class="stat-label">Mitarbeiter &amp; Rollen</div>
    </a>
    <a class="stat-card" href="<?= url('modules/lager/kategorien.php') ?>">
        <div class="stat-label">Kategorien</div>
    </a>
    <a class="stat-card" href="<?= url('modules/lager/lagerorte.php') ?>">
        <div class="stat-label">Lagerorte</div>
    </a>
    <a class="stat-card" href="<?= url('modules/historie/index.php') ?>">
        <div class="stat-label">Historie / Audit-Log</div>
    </a>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
