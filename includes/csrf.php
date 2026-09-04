<?php
/**
 * RSH-LS – CSRF-Schutz
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Ungültige oder abgelaufene Anfrage (CSRF). Bitte Seite neu laden und erneut versuchen.');
    }
}
