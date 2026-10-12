<?php
/**
 * RSH-LS – Veranstaltung anfragen: öffentlich erreichbares Formular (keine
 * Anmeldung nötig), um eine Technik-Anfrage für eine Veranstaltung
 * einzureichen. Daraus wird noch KEIN Auftrag - das übernimmt die
 * Technikleitung/Lagerleitung/Veranstaltungsleitung bewusst erst nach
 * Prüfung über das Anfragen-Modul.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$pdo = db();
$user = current_user();
$sent = isset($_GET['sent']);

$values = [
    'requester_name' => $user['name'] ?? '',
    'requester_email' => '',
    'event_date' => '',
    'event_date_end' => '',
    'event_time' => '',
    'location' => '',
    'needs_licht' => false, 'licht_details' => '',
    'needs_ton' => false, 'ton_details' => '',
    'needs_atrium' => false, 'atrium_mode' => '',
    'technicians_needed' => false, 'technician_count' => '',
    'schedule_details' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    foreach ($values as $key => $default) {
        if (is_bool($default)) {
            $values[$key] = input($key) === '1';
        } else {
            $values[$key] = input($key);
        }
    }

    if ($values['requester_name'] === '') {
        $errors[] = 'Bitte Namen angeben.';
    }
    if ($values['requester_email'] === '' || !filter_var($values['requester_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    }
    if ($values['event_date'] === '') {
        $errors[] = 'Bitte ein Datum angeben.';
    }
    if ($values['location'] === '') {
        $errors[] = 'Bitte angeben, wo die Veranstaltung stattfindet.';
    }
    if (!$values['needs_licht'] && !$values['needs_ton'] && !$values['needs_atrium']) {
        $errors[] = 'Bitte mindestens eine benötigte Technik auswählen (Licht, Ton oder Atrium).';
    }
    if ($values['needs_licht'] && $values['licht_details'] === '') {
        $errors[] = 'Bitte bei Licht genau angeben, was benötigt wird.';
    }
    if ($values['needs_ton'] && $values['ton_details'] === '') {
        $errors[] = 'Bitte bei Ton genau angeben, was benötigt wird.';
    }
    if ($values['needs_atrium'] && !in_array($values['atrium_mode'], ['ton', 'beamer', 'beide'], true)) {
        $errors[] = 'Bitte bei Atrium auswählen: Ton, Beamer oder beides.';
    }
    if ($values['technicians_needed'] && (!ctype_digit((string)$values['technician_count']) || (int)$values['technician_count'] < 1)) {
        $errors[] = 'Bitte eine gültige Anzahl Techniker angeben.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO event_requests (requester_name, requester_email, event_date, event_date_end, event_time,
                location, needs_licht, licht_details, needs_ton, ton_details, needs_atrium, atrium_mode,
                technicians_needed, technician_count, schedule_details, submitted_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $values['requester_name'], $values['requester_email'], $values['event_date'],
            $values['event_date_end'] ?: null, $values['event_time'] ?: null, $values['location'],
            $values['needs_licht'] ? 1 : 0, $values['needs_licht'] ? $values['licht_details'] : null,
            $values['needs_ton'] ? 1 : 0, $values['needs_ton'] ? $values['ton_details'] : null,
            $values['needs_atrium'] ? 1 : 0, $values['needs_atrium'] ? $values['atrium_mode'] : null,
            $values['technicians_needed'] ? 1 : 0, $values['technicians_needed'] ? (int)$values['technician_count'] : null,
            $values['schedule_details'] ?: null, $user['id'] ?? null,
        ]);
        $requestId = (int)$pdo->lastInsertId();
        log_activity('Veranstaltungsanfrage eingereicht', 'event_request', $requestId, $values['location'] . ' am ' . $values['event_date']);

        // -- Benachrichtigungs-E-Mails je nach benötigter Technik -----------------
        // Zuordnung: Licht -> Licht-Kontakte/-Bereichsleiter; Ton (direkt oder über
        // Atrium) -> Ton-Kontakte/-Bereichsleiter; Atrium (jeder Modus) -> Sonstiges-
        // Kontakte/-Bereichsleiter; immer zusätzlich die Leitung.
        $recipients = [];
        if ($values['needs_licht']) {
            $recipients = array_merge($recipients, get_setting_emails('notify_licht_email'));
            if ($bl = get_bereichsleiter('licht')) {
                $recipients[] = $bl['email'];
            }
        }
        if ($values['needs_ton'] || ($values['needs_atrium'] && in_array($values['atrium_mode'], ['ton', 'beide'], true))) {
            $recipients = array_merge($recipients, get_setting_emails('notify_ton_email'));
            if ($bl = get_bereichsleiter('ton')) {
                $recipients[] = $bl['email'];
            }
        }
        if ($values['needs_atrium']) {
            $recipients = array_merge($recipients, get_setting_emails('notify_sonstiges_email'));
            if ($bl = get_bereichsleiter('sonstiges')) {
                $recipients[] = $bl['email'];
            }
        }
        $recipients = array_merge($recipients, get_setting_emails('notify_leitung_email'));

        $subject = 'Neue Veranstaltungsanfrage: ' . $values['location'] . ' am ' . format_date($values['event_date']);
        $body = "Neue Anfrage über das Lagersystem:\n\n" .
            "Antragsteller: {$values['requester_name']} ({$values['requester_email']})\n" .
            "Datum: {$values['event_date']}" . ($values['event_date_end'] ? ' bis ' . $values['event_date_end'] : '') .
            ($values['event_time'] ? ', ' . $values['event_time'] . ' Uhr' : '') . "\n" .
            "Ort: {$values['location']}\n" .
            ($values['needs_licht'] ? "Licht: {$values['licht_details']}\n" : '') .
            ($values['needs_ton'] ? "Ton: {$values['ton_details']}\n" : '') .
            ($values['needs_atrium'] ? "Atrium: {$values['atrium_mode']}\n" : '') .
            ($values['technicians_needed'] ? "Techniker benötigt: {$values['technician_count']}\n" : '') .
            ($values['schedule_details'] ? "\nZeitplan:\n{$values['schedule_details']}\n" : '') .
            "\nBitte im Lagersystem unter „Anfragen“ bearbeiten: " . full_url('modules/anfragen/anfrage.php?id=' . $requestId);

        foreach (array_unique(array_filter($recipients)) as $to) {
            send_mail($to, $subject, $body);
        }

        if ($user) {
            flash('success', 'Deine Anfrage wurde übermittelt. Du erhältst eine E-Mail, sobald sie bestätigt ist.');
            redirect(home_path());
        }
        redirect('modules/anfragen/neu.php?sent=1');
    }
}

$page_title = 'Veranstaltung anfragen';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="request-page">

<?php if ($sent): ?>
    <div class="request-hero">
        <div class="request-hero-icon">✓</div>
        <h1>Danke für deine Anfrage!</h1>
        <p>Sie wurde an die Technik übermittelt und wird geprüft. Sobald sie bestätigt ist, bekommst du eine E-Mail.</p>
    </div>
    <div class="btn-row" style="justify-content:center">
        <a href="<?= url('modules/anfragen/neu.php') ?>" class="btn btn-primary">Weitere Anfrage stellen</a>
    </div>
<?php else: ?>

    <div class="request-hero">
        <div class="request-hero-icon">✉</div>
        <h1>Veranstaltung anfragen</h1>
        <p>Technik für eine Veranstaltung anfragen – die Technikleitung prüft die Anfrage und meldet sich per E-Mail zurück.
            Keine Anmeldung nötig.</p>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="flash flash-error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post" class="request-form">
        <?= csrf_field() ?>

        <div class="req-section">
            <div class="req-section-head"><span class="req-ic">①</span><h2>Wer &amp; Wann</h2></div>
            <div class="form-grid">
                <div class="field"><label>Dein Name *</label><input type="text" name="requester_name" required value="<?= e($values['requester_name']) ?>"></div>
                <div class="field"><label>Deine E-Mail *</label><input type="email" name="requester_email" required value="<?= e($values['requester_email']) ?>"></div>
                <div class="field"><label>Datum *</label><input type="date" name="event_date" required value="<?= e($values['event_date']) ?>"></div>
                <div class="field"><label>Datum bis <span class="hint">(bei mehreren Tagen)</span></label><input type="date" name="event_date_end" value="<?= e($values['event_date_end']) ?>"></div>
                <div class="field"><label>Uhrzeit</label><input type="text" name="event_time" placeholder="z.B. 18:00 – 22:00" value="<?= e($values['event_time']) ?>"></div>
                <div class="field"><label>Ort *</label><input type="text" name="location" required placeholder="z.B. Atrium, Aula …" value="<?= e($values['location']) ?>"></div>
            </div>
        </div>

        <div class="req-section">
            <div class="req-section-head"><span class="req-ic">②</span><h2>Benötigte Technik *</h2></div>
            <div class="tech-options">
                <label class="tech-tile">
                    <input type="checkbox" name="needs_licht" value="1" <?= $values['needs_licht'] ? 'checked' : '' ?>>
                    <span class="tech-tile-head"><span class="tech-tile-ic">💡</span>Licht</span>
                    <textarea name="licht_details" placeholder="Was genau wird benötigt?"><?= e($values['licht_details']) ?></textarea>
                </label>
                <label class="tech-tile">
                    <input type="checkbox" name="needs_ton" value="1" <?= $values['needs_ton'] ? 'checked' : '' ?>>
                    <span class="tech-tile-head"><span class="tech-tile-ic">🔊</span>Ton</span>
                    <textarea name="ton_details" placeholder="Was genau wird benötigt?"><?= e($values['ton_details']) ?></textarea>
                </label>
                <label class="tech-tile">
                    <input type="checkbox" name="needs_atrium" value="1" <?= $values['needs_atrium'] ? 'checked' : '' ?>>
                    <span class="tech-tile-head"><span class="tech-tile-ic">◉</span>Atrium</span>
                    <select name="atrium_mode" onclick="event.stopPropagation()">
                        <option value="">– bitte wählen –</option>
                        <option value="ton" <?= $values['atrium_mode'] === 'ton' ? 'selected' : '' ?>>Ton</option>
                        <option value="beamer" <?= $values['atrium_mode'] === 'beamer' ? 'selected' : '' ?>>Beamer</option>
                        <option value="beide" <?= $values['atrium_mode'] === 'beide' ? 'selected' : '' ?>>Beides</option>
                    </select>
                </label>
            </div>
        </div>

        <div class="req-section">
            <div class="req-section-head"><span class="req-ic">③</span><h2>Sonstiges</h2></div>
            <div class="field">
                <label class="checkbox-row"><input type="checkbox" name="technicians_needed" value="1" id="technicians_needed" <?= $values['technicians_needed'] ? 'checked' : '' ?>> Techniker werden benötigt</label>
                <input type="number" name="technician_count" min="1" placeholder="Anzahl Techniker" value="<?= e((string)$values['technician_count']) ?>">
            </div>
            <div class="field">
                <label>Genauer Zeitplan <span class="hint">(optional)</span></label>
                <textarea name="schedule_details" placeholder="z.B. 16:00 Aufbau, 18:00 Einlass, 19:00 Beginn, 22:00 Ende, 23:00 Abbau"><?= e($values['schedule_details']) ?></textarea>
            </div>
        </div>

        <div class="btn-row request-submit-row">
            <button type="submit" class="btn btn-primary btn-lg">Anfrage absenden</button>
            <?php if ($user): ?><a href="<?= url(home_path()) ?>" class="btn btn-ghost">Abbrechen</a><?php endif; ?>
        </div>
    </form>
    <script>
        document.querySelectorAll('.tech-tile').forEach(function (tile) {
            var box = tile.querySelector('input[type="checkbox"]');
            var sync = function () { tile.classList.toggle('is-checked', box.checked); };
            box.addEventListener('change', sync);
            sync();
        });
    </script>

<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
