<?php
/**
 * RSH-LS – Minimaler Mailversand (PHP mail(), kein SMTP/Composer nötig).
 * Fehler beim Versand werden geloggt, brechen den Ablauf aber nie ab -
 * E-Mail-Zustellung ist hier immer "best effort".
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

/** Schickt eine einfache Text-E-Mail. Gibt true zurück, wenn mail() die Annahme bestätigt hat. */
function send_mail(string $to, string $subject, string $body): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $headers = "From: " . APP_NAME . " <no-reply@{$host}>\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n";
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

    $ok = @mail($to, $encodedSubject, $body, $headers);
    if (!$ok) {
        error_log('RSH-LS: Mailversand an ' . $to . ' fehlgeschlagen (Betreff: ' . $subject . ')');
    }
    return $ok;
}
