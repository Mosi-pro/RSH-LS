<?php
/**
 * RSH-LS – Authentifizierung
 *
 * Anmeldung erfolgt bewusst einfach über die Mitarbeiter-ID (ohne Passwort).
 * Die eigentliche Berechtigungsprüfung passiert ausschließlich serverseitig
 * (siehe permissions.php) – die Mitarbeiter-ID selbst gewährt im Browser
 * keinerlei Rechte.
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $timeout = ($_SESSION['user']['role'] ?? '') === 'lager_terminal'
        ? TERMINAL_SESSION_TIMEOUT
        : SESSION_TIMEOUT;

    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

/** Login anhand der Mitarbeiter-ID. Gibt bei Erfolg true zurück. */
function attempt_login(string $employeeId): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE employee_id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$employeeId]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    // Session-ID nach Login regenerieren (Session Fixation vorbeugen)
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'          => (int)$user['id'],
        'employee_id' => $user['employee_id'],
        'name'        => $user['name'],
        'role'        => $user['role'],
    ];
    $_SESSION['last_activity'] = time();

    log_activity('Anmeldung', 'user', (int)$user['id'], $user['name'] . ' hat sich angemeldet');

    return true;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('public/login.php');
    }
}

function logout(): void
{
    $user = current_user();
    if ($user) {
        log_activity('Abmeldung', 'user', $user['id'], $user['name'] . ' hat sich abgemeldet');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
