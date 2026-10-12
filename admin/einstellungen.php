<?php
/**
 * RSH-LS – Einstellungen: Benachrichtigungs-E-Mail-Adressen für neue
 * Veranstaltungsanfragen + Bereichsleiter-Zuweisung (nur Technikleitung).
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

$pdo = db();

$emailFields = [
    'notify_licht_email'     => 'Licht',
    'notify_ton_email'       => 'Ton',
    'notify_sonstiges_email' => 'Sonstiges (Atrium/Raumbuchung)',
    'notify_leitung_email'   => 'Leitung',
];
$bereichsleiterFields = [
    'bereichsleiter_licht_user_id'     => 'Bereichsleiter Licht',
    'bereichsleiter_ton_user_id'       => 'Bereichsleiter Ton',
    'bereichsleiter_sonstiges_user_id' => 'Bereichsleiter Sonstiges',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('form_action', 'save');

    if ($action === 'test_mail') {
        $to = trim(input('test_mail_to'));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Bitte eine gültige E-Mail-Adresse für die Test-Mail angeben.');
        } elseif (send_mail($to, 'Test-E-Mail von ' . APP_NAME, 'Diese Test-E-Mail wurde über die Einstellungsseite ausgelöst. Wenn du sie erhältst, funktioniert der Mailversand.')) {
            flash('success', 'Test-E-Mail wurde an ' . $to . ' übergeben. Prüfe den Posteingang (und Spam-Ordner) in ein paar Minuten.');
        } else {
            flash('error', 'Der Server hat die Test-E-Mail nicht angenommen. Siehe Fehlerprotokoll (error_log) für Details.');
        }
        redirect('admin/einstellungen.php');
    }

    $errors = [];
    foreach ($emailFields as $key => $label) {
        $raw = trim(input($key));
        foreach (preg_split('/[,;]+/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && !filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Ungültige E-Mail-Adresse bei „' . $label . '“: ' . $part;
            }
        }
    }
    if ($errors) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        redirect('admin/einstellungen.php');
    }

    foreach ($emailFields as $key => $label) {
        set_setting($key, trim(input($key)));
    }
    foreach ($bereichsleiterFields as $key => $label) {
        set_setting($key, input($key));
    }
    log_activity('Einstellungen geändert', 'settings', null, 'Benachrichtigungs-E-Mails/Bereichsleiter aktualisiert');
    flash('success', 'Einstellungen gespeichert.');
    redirect('admin/einstellungen.php');
}

$users = $pdo->query("SELECT * FROM users WHERE active = 1 ORDER BY name")->fetchAll();

$page_title = 'Einstellungen';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="section-head"><h1>Einstellungen</h1></div>

<div class="card" style="max-width:620px;">
    <h3 class="mt-0">Benachrichtigungs-E-Mails für Veranstaltungsanfragen</h3>
    <p class="muted small">Diese Adressen werden informiert, sobald jemand über das Formular „Veranstaltung anfragen“
        eine neue Anfrage einreicht - je nachdem, welche Technik benötigt wird. Die Leitung wird immer informiert.
        Mehrere Adressen können durch Komma getrennt eingetragen werden.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="save">
        <?php foreach ($emailFields as $key => $label): ?>
            <div class="field">
                <label><?= e($label) ?></label>
                <input type="text" name="<?= e($key) ?>" value="<?= e(get_setting($key)) ?>" placeholder="name@rsh-technik.com, weitere@rsh-technik.com">
            </div>
        <?php endforeach; ?>

        <h3>Bereichsleiter</h3>
        <p class="muted small">Zusätzlich zu den E-Mail-Adressen oben kann pro Bereich ein Mitarbeiter als
            Bereichsleiter festgelegt werden. Dieser wird bei neuen Anfragen zusätzlich benachrichtigt und bekommt
            die Auftrags-PDF automatisch per Mail, sobald er als Verantwortlicher für einen Auftrag eingetragen wird
            (dafür muss für ihn unter „Mitarbeiter“ eine E-Mail-Adresse hinterlegt sein).</p>
        <?php foreach ($bereichsleiterFields as $key => $label): ?>
            <div class="field">
                <label><?= e($label) ?></label>
                <select name="<?= e($key) ?>">
                    <option value="">– keiner –</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (int)get_setting($key) === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= e($u['name']) ?> (<?= e($u['employee_id']) ?>)<?= empty($u['email']) ? ' – keine E-Mail hinterlegt!' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</div>

<div class="card" style="max-width:620px;">
    <h3 class="mt-0">Mailversand testen</h3>
    <p class="muted small">Bekommst du keine Benachrichtigungen? Schick dir hiermit eine Test-E-Mail, um zu prüfen,
        ob der Server E-Mails überhaupt zustellt.</p>
    <form method="post" class="btn-row" style="align-items:center">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="test_mail">
        <input type="email" name="test_mail_to" placeholder="deine@email.de" required style="flex:1; min-width:220px;">
        <button type="submit" class="btn btn-sm">Test-Mail senden</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
