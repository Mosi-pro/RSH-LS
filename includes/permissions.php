<?php
/**
 * RSH-LS – Rollen & Berechtigungen
 *
 * Alle Berechtigungen werden ausschließlich serverseitig geprüft.
 * Rollenrechte gemäß Spezifikation:
 *   technikleitung          -> alles
 *   lagerleitung             -> Lager + Aufträge
 *   mitarbeiter               -> eigene Buchungen
 *   veranstaltungsleitung    -> Veranstaltungen + Aufträge
 *   lager_terminal            -> Ausgabe / Rückgabe
 *   gast                      -> nur freigegebene Informationen
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

const PERM_ALL = '*';

const ROLE_PERMISSIONS = [
    'technikleitung' => ['*'],
    'lagerleitung' => [
        'lager.view', 'lager.edit',
        'auftraege.view', 'auftraege.edit',
        'veranstaltungen.view',
        'ausgabe_rueckgabe.edit',
        'mitarbeiter.view',
        'historie.view',
        'inventur.view', 'inventur.edit',
        'defekte.report', 'defekte.manage',
        'reports.view',
    ],
    'mitarbeiter' => [
        'lager.view',
        'auftraege.view', 'auftraege.view_own',
        'veranstaltungen.view',
        'ausgabe_rueckgabe.edit',
        'defekte.report',
    ],
    'veranstaltungsleitung' => [
        'lager.view',
        'auftraege.view', 'auftraege.edit',
        'veranstaltungen.view', 'veranstaltungen.edit',
        'mitarbeiter.view',
        'defekte.report',
    ],
    'lager_terminal' => [
        'lager.view',
        'auftraege.view',
        'ausgabe_rueckgabe.edit',
        'defekte.report',
    ],
    'gast' => [
        'lager.view',
        'veranstaltungen.view',
    ],
];

function has_permission(string $permission): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    $perms = ROLE_PERMISSIONS[$user['role']] ?? [];
    return in_array(PERM_ALL, $perms, true) || in_array($permission, $perms, true);
}

function require_permission(string $permission): void
{
    require_login();
    if (!has_permission($permission)) {
        http_response_code(403);
        require ROOT_PATH . '/includes/header.php';
        echo '<div class="page"><div class="card"><h1>Kein Zugriff</h1>'
           . '<p>Für diesen Bereich fehlt Ihnen die Berechtigung. Bei Fragen wenden Sie sich an die Technikleitung.</p></div></div>';
        require ROOT_PATH . '/includes/footer.php';
        exit;
    }
}

function is_admin(): bool
{
    $user = current_user();
    return $user && $user['role'] === 'technikleitung';
}
