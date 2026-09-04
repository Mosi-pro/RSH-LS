<?php
/**
 * RSH-LS – Seitenkopf / Layout-Rahmen
 * Erwartet optional: $page_title (string)
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

$user        = current_user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$isTerminal  = isset($_GET['terminal']) || strpos($currentPath, '/terminal/') !== false;

function nav_active(string $needle, string $currentPath): string
{
    return strpos($currentPath, $needle) !== false ? ' active' : '';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= isset($page_title) ? e($page_title) . ' – ' : '' ?><?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body class="<?= $isTerminal ? 'terminal-mode' : '' ?>">
<?php if ($user && !$isTerminal): ?>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">RSH</span>
            <span class="brand-name">TECHNIK</span>
        </div>
        <nav class="nav-main">
            <a href="<?= url('public/dashboard.php') ?>" class="nav-item<?= nav_active('dashboard.php', $currentPath) ?>"><span class="ic">⌂</span> Dashboard</a>
            <?php if (has_permission('lager.view')): ?>
            <a href="<?= url('modules/lager/index.php') ?>" class="nav-item<?= nav_active('/lager/', $currentPath) ?>"><span class="ic">▣</span> Lager</a>
            <?php endif; ?>
            <?php if (has_permission('auftraege.view') || has_permission('auftraege.view_own')): ?>
            <a href="<?= url('modules/auftraege/index.php') ?>" class="nav-item<?= nav_active('/auftraege/', $currentPath) ?>"><span class="ic">▤</span> Aufträge</a>
            <?php endif; ?>
            <?php if (has_permission('veranstaltungen.view')): ?>
            <a href="<?= url('modules/veranstaltungen/index.php') ?>" class="nav-item<?= nav_active('/veranstaltungen/', $currentPath) ?>"><span class="ic">◉</span> Veranstaltungen</a>
            <?php endif; ?>
            <?php if (has_permission('ausgabe_rueckgabe.edit')): ?>
            <a href="<?= url('terminal/index.php') ?>" class="nav-item<?= nav_active('/ausgabe/', $currentPath) . nav_active('/rueckgabe/', $currentPath) ?>"><span class="ic">⇄</span> Ausgabe / Rückgabe</a>
            <?php endif; ?>
            <?php if (has_permission('inventur.view')): ?>
            <a href="<?= url('modules/inventur/index.php') ?>" class="nav-item<?= nav_active('/inventur/', $currentPath) ?>"><span class="ic">▦</span> Inventur</a>
            <?php endif; ?>
            <?php if (has_permission('defekte.report')): ?>
            <a href="<?= url('modules/defekte/index.php') ?>" class="nav-item<?= nav_active('/defekte/', $currentPath) ?>"><span class="ic">⚠</span> Defekte & Wartung</a>
            <?php endif; ?>
            <?php if (has_permission('werkstatt.access')): ?>
            <a href="<?= url('modules/werkstatt/index.php') ?>" class="nav-item<?= nav_active('/werkstatt/', $currentPath) ?>"><span class="ic">🔧</span> Werkstatt</a>
            <?php endif; ?>
            <?php if (has_permission('mitarbeiter.view')): ?>
            <a href="<?= url('modules/mitarbeiter/index.php') ?>" class="nav-item<?= nav_active('/mitarbeiter/', $currentPath) ?>"><span class="ic">♙</span> Mitarbeiter</a>
            <?php endif; ?>
            <?php if (has_permission('reports.view')): ?>
            <a href="<?= url('modules/reports/index.php') ?>" class="nav-item<?= nav_active('/reports/', $currentPath) ?>"><span class="ic">📊</span> Auswertungen</a>
            <?php endif; ?>
            <?php if (has_permission('historie.view') || is_admin()): ?>
            <a href="<?= url('modules/historie/index.php') ?>" class="nav-item<?= nav_active('/historie/', $currentPath) ?>"><span class="ic">▥</span> Historie</a>
            <?php endif; ?>
            <?php if (is_admin()): ?>
            <a href="<?= url('admin/index.php') ?>" class="nav-item<?= nav_active('/admin/', $currentPath) ?>"><span class="ic">⚙</span> Verwaltung</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-chip">
                <span class="user-avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
                <span class="user-meta">
                    <span class="user-name"><?= e($user['name']) ?></span>
                    <span class="user-role"><?= e(role_label($user['role'])) ?></span>
                </span>
            </div>
            <a href="<?= url('public/logout.php') ?>" class="nav-logout">Abmelden</a>
        </div>
    </aside>

    <div class="main-col">
        <header class="topbar">
            <form class="search-form" action="<?= url('public/search.php') ?>" method="get">
                <input type="search" name="q" placeholder="Suche: Inventarnr., Gerät, Auftrag, Mitarbeiter …" value="<?= e($_GET['q'] ?? '') ?>">
            </form>
            <div class="topbar-spacer"></div>
            <?php $unread = count_unread_notifications($user['id']); ?>
            <a href="<?= url('public/notifications.php') ?>" class="notif-bell" title="Benachrichtigungen">
                🔔
                <?php if ($unread > 0): ?><span class="notif-badge"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
            </a>
        </header>
        <main class="page">
            <?php foreach (get_flashes() as $flash): ?>
                <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
<?php elseif ($isTerminal): ?>
    <main class="terminal-page">
<?php else: ?>
    <main class="bare-page">
        <?php foreach (get_flashes() as $flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
<?php endif; ?>
