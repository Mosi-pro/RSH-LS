<?php
/**
 * RSH-LS – Einstellungen: Benachrichtigungs-E-Mail-Adressen für neue
 * Veranstaltungsanfragen (nur Technikleitung).
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

$fields = [
    'notify_licht_email'     => 'Licht',
    'notify_ton_email'       => 'Ton',
    'notify_sonstiges_email' => 'Sonstiges (Atrium/Raumbuchung)',
    'notify_leitung_email'   => 'Leitung',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($fields as $key => $label) {
        $value = trim(input($key));
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Ungültige E-Mail-Adresse bei „' . $label . '“.');
            redirect('admin/einstellungen.php');
        }
    }
    foreach ($fields as $key => $label) {
        set_setting($key, trim(input($key)));
    }
    log_activity('Einstellungen geändert', 'settings', null, 'Benachrichtigungs-E-Mails aktualisiert');
    flash('success', 'Einstellungen gespeichert.');
    redirect('admin/einstellungen.php');
}

$page_title = 'Einstellungen';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="section-head"><h1>Einstellungen</h1></div>

<div class="card" style="max-width:520px;">
    <h3 class="mt-0">Benachrichtigungs-E-Mails für Veranstaltungsanfragen</h3>
    <p class="muted small">Diese Adressen werden informiert, sobald jemand über das Formular „Veranstaltung anfragen“
        eine neue Anfrage einreicht - je nachdem, welche Technik benötigt wird. Die Leitung wird immer informiert.</p>
    <form method="post">
        <?= csrf_field() ?>
        <?php foreach ($fields as $key => $label): ?>
            <div class="field">
                <label><?= e($label) ?></label>
                <input type="email" name="<?= e($key) ?>" value="<?= e(get_setting($key)) ?>" placeholder="name@rsh-technik.com">
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
