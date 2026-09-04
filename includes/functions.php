<?php
/**
 * RSH-LS – Hilfsfunktionen
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

/** HTML-sicher ausgeben */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Absolute URL relativ zur Anwendung erzeugen */
function url(string $path): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/** Flash-Meldungen über die Session */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function format_date(?string $date): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '–';
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : '–';
}

function format_datetime(?string $datetime): string
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '–';
    }
    $ts = strtotime($datetime);
    return $ts ? date('d.m.Y H:i', $ts) : '–';
}

/** Nächste freie Inventarnummer, z.B. RSH-0042 */
function generate_inventory_number(): string
{
    $pdo = db();
    for ($i = 0; $i < 50; $i++) {
        $stmt = $pdo->query('SELECT MAX(CAST(SUBSTR(inventory_number, ' . (strlen(INVENTORY_PREFIX) + 1) . ') AS INTEGER)) AS max_num FROM devices');
        $max = (int)($stmt->fetch()['max_num'] ?? 0);
        $candidate = INVENTORY_PREFIX . str_pad((string)($max + 1 + $i), INVENTORY_DIGITS, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE inventory_number = ?');
        $check->execute([$candidate]);
        if ((int)$check->fetchColumn() === 0) {
            return $candidate;
        }
    }
    return INVENTORY_PREFIX . uniqid();
}

/** Nächste Auftragsnummer im Format JAHR-NNN, z.B. 2026-041 */
function generate_order_number(): string
{
    $pdo  = db();
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT order_number FROM orders WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$year . '-%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/-(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    return $year . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/** Aktion im Audit-Log protokollieren */
function log_activity(string $action, string $entityType, ?int $entityId = null, ?string $details = null): void
{
    $user = current_user();
    $stmt = db()->prepare(
        'INSERT INTO activity_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['id'] ?? null, $action, $entityType, $entityId, $details]);
}

/** Lesbare Labels für Status-Enums */
function device_status_label(string $status): string
{
    $labels = [
        'verfuegbar'  => 'Verfügbar',
        'reserviert'  => 'Reserviert',
        'ausgegeben'  => 'Ausgegeben',
        'defekt'      => 'Defekt',
        'wartung'     => 'Wartung',
        'verloren'    => 'Verloren',
        'aussortiert' => 'Aussortiert',
    ];
    return $labels[$status] ?? $status;
}

function order_status_label(string $status): string
{
    $labels = [
        'entwurf'               => 'Entwurf',
        'geplant'                => 'Geplant',
        'vorbereitung'           => 'Vorbereitung',
        'bereit_zur_ausgabe'     => 'Bereit zur Ausgabe',
        'ausgegeben'             => 'Ausgegeben',
        'im_einsatz'             => 'Im Einsatz',
        'rueckgabe_ausstehend'   => 'Rückgabe ausstehend',
        'abgeschlossen'          => 'Abgeschlossen',
        'storniert'              => 'Storniert',
    ];
    return $labels[$status] ?? $status;
}

function event_status_label(string $status): string
{
    $labels = [
        'geplant'       => 'Geplant',
        'vorbereitung'  => 'Vorbereitung',
        'laeuft'        => 'Läuft',
        'abgeschlossen' => 'Abgeschlossen',
        'storniert'     => 'Storniert',
    ];
    return $labels[$status] ?? $status;
}

function role_label(string $role): string
{
    $labels = [
        'technikleitung'        => 'Technikleitung',
        'lagerleitung'           => 'Lagerleitung',
        'mitarbeiter'            => 'Mitarbeiter',
        'veranstaltungsleitung'  => 'Veranstaltungsleitung',
        'lager_terminal'         => 'Lager-Terminal',
        'gast'                   => 'Gast',
    ];
    return $labels[$role] ?? $role;
}

/** CSS-Klasse für Status-Badges */
function status_class(string $status): string
{
    $map = [
        'verfuegbar' => 'ok', 'ausgegeben' => 'info', 'reserviert' => 'warn',
        'defekt' => 'danger', 'wartung' => 'warn', 'verloren' => 'danger', 'aussortiert' => 'muted',
        'entwurf' => 'muted', 'geplant' => 'info', 'vorbereitung' => 'warn',
        'bereit_zur_ausgabe' => 'info', 'im_einsatz' => 'ok', 'rueckgabe_ausstehend' => 'warn',
        'abgeschlossen' => 'ok', 'storniert' => 'danger',
        'laeuft' => 'ok',
    ];
    return $map[$status] ?? 'muted';
}

function input(string $key, $default = ''): string
{
    return trim((string)($_POST[$key] ?? $_GET[$key] ?? $default));
}
