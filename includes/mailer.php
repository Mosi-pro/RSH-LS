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

/** Schickt eine E-Mail mit einem einzelnen Dateianhang (z.B. die Auftrags-PDF). */
function send_mail_with_attachment(
    string $to,
    string $subject,
    string $body,
    string $filename,
    string $attachmentContent,
    string $mimeType = 'application/pdf'
): bool {
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $boundary = 'rshls-' . bin2hex(random_bytes(16));
    $headers = "From: " . APP_NAME . " <no-reply@{$host}>\r\n" .
               "MIME-Version: 1.0\r\n" .
               "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $body . "\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: {$mimeType}; name=\"" . addcslashes($filename, '"') . "\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: attachment; filename=\"" . addcslashes($filename, '"') . "\"\r\n\r\n";
    $message .= chunk_split(base64_encode($attachmentContent));
    $message .= "--{$boundary}--";

    $ok = @mail($to, $encodedSubject, $message, $headers);
    if (!$ok) {
        error_log('RSH-LS: Mailversand mit Anhang an ' . $to . ' fehlgeschlagen (Betreff: ' . $subject . ')');
    }
    return $ok;
}
