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

/** Voll qualifizierte URL (mit Schema + Host) – nötig für QR-Codes, die extern gescannt werden. */
function full_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($path);
}

/** Ziel nach dem Login: Kiosk-Rollen kommen immer direkt auf ihre eigene Oberfläche. */
function home_path(): string
{
    $user = current_user();
    if (!$user) {
        return 'public/dashboard.php';
    }
    if ($user['role'] === 'lager_terminal') {
        return 'terminal/index.php';
    }
    if ($user['role'] === 'werkstatt') {
        return 'modules/werkstatt/index.php';
    }
    return 'public/dashboard.php';
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

/** Nächste Ausleih-ID für eine Schnellausgabe (ohne Auftrag), Format SA-JAHR-NNN. */
function generate_quick_checkout_code(): string
{
    $pdo  = db();
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT code FROM quick_checkouts WHERE code LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(['SA-' . $year . '-%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/-(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    return 'SA-' . $year . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * Live berechnete Warnungen fürs Dashboard (keine eigene Tabelle nötig):
 * überfällige Rückgaben, unvollständige Rückgaben, fällige Wartungen,
 * dringende offene Defekte.
 * @return array<int, array{level:string, message:string, url:string}>
 */
function get_warnings(): array
{
    $pdo = db();
    $today = date('Y-m-d');
    $warnings = [];

    $stmt = $pdo->prepare(
        "SELECT id, order_number, title, teardown_date FROM orders
         WHERE teardown_date IS NOT NULL AND teardown_date != '' AND teardown_date < ?
           AND status NOT IN ('abgeschlossen','storniert')
         ORDER BY teardown_date"
    );
    $stmt->execute([$today]);
    foreach ($stmt as $o) {
        $warnings[] = [
            'level'   => 'danger',
            'message' => 'Rückgabe überfällig: Auftrag #' . $o['order_number'] . ' „' . $o['title']
                . '“ – Abbau war am ' . format_date($o['teardown_date']),
            'url'     => 'modules/auftraege/auftrag.php?id=' . $o['id'],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT od.device_id, od.missing_notes, d.inventory_number, d.name AS device_name, o.order_number
         FROM order_devices od
         JOIN devices d ON d.id = od.device_id
         JOIN orders o ON o.id = od.order_id
         WHERE od.status = 'zurueckgegeben' AND od.complete = 0 AND od.returned_at >= ?
         ORDER BY od.returned_at DESC"
    );
    $stmt->execute([date('Y-m-d H:i:s', strtotime('-14 days'))]);
    foreach ($stmt as $od) {
        $warnings[] = [
            'level'   => 'warn',
            'message' => 'Rückgabe unvollständig: ' . $od['inventory_number'] . ' (' . $od['device_name']
                . ') aus Auftrag #' . $od['order_number'] . ($od['missing_notes'] ? ' – fehlt: ' . $od['missing_notes'] : ''),
            'url'     => 'modules/lager/geraet.php?id=' . $od['device_id'],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT id, inventory_number, name, next_maintenance_date FROM devices
         WHERE next_maintenance_date IS NOT NULL AND next_maintenance_date != '' AND next_maintenance_date <= ?
           AND status != 'aussortiert'
         ORDER BY next_maintenance_date"
    );
    $stmt->execute([$today]);
    foreach ($stmt as $d) {
        $warnings[] = [
            'level'   => 'warn',
            'message' => 'Wartung fällig: ' . $d['inventory_number'] . ' (' . $d['name']
                . ') seit ' . format_date($d['next_maintenance_date']),
            'url'     => 'modules/lager/geraet.php?id=' . $d['id'],
        ];
    }

    $stmt = $pdo->query(
        "SELECT f.id, d.inventory_number, d.name AS device_name
         FROM defects f JOIN devices d ON d.id = f.device_id
         WHERE f.status != 'behoben' AND f.priority = 'dringend'
         ORDER BY f.created_at"
    );
    foreach ($stmt as $f) {
        $warnings[] = [
            'level'   => 'danger',
            'message' => 'Dringender Defekt: ' . $f['inventory_number'] . ' (' . $f['device_name'] . ')',
            'url'     => 'modules/defekte/defekt.php?id=' . $f['id'],
        ];
    }

    return $warnings;
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
        'in_reparatur' => 'In Reparatur (Werkstatt)',
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
        'werkstatt'              => 'Werkstatt',
        'gast'                   => 'Gast',
    ];
    return $labels[$role] ?? $role;
}

/** CSS-Klasse für Status-Badges */
function status_class(string $status): string
{
    $map = [
        'verfuegbar' => 'ok', 'ausgegeben' => 'info', 'reserviert' => 'warn',
        'defekt' => 'danger', 'wartung' => 'warn', 'in_reparatur' => 'warn',
        'verloren' => 'danger', 'aussortiert' => 'muted',
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

/** Benachrichtigung für einen Mitarbeiter anlegen (z.B. Melder bei behobenem Defekt). */
function create_notification(int $userId, string $title, string $message, string $url = ''): void
{
    db()->prepare('INSERT INTO notifications (user_id, title, message, url) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $title, $message, $url ?: null]);
}

function count_unread_notifications(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}
