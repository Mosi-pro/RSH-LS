<?php
/**
 * RSH-LS – Schnellausgabe: Geräte direkt scannen und ausbuchen, ohne dass
 * dafür ein Auftrag angelegt wird. Der "Warenkorb" lebt nur in der Session;
 * beim Abschluss werden die Geräte direkt auf "ausgegeben" gesetzt und ein
 * PDF-Beleg erzeugt – es entsteht keine neue Datenbanktabelle/-spalte und
 * kein Auftrag.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('ausgabe_rueckgabe.edit');
require_once __DIR__ . '/../../includes/pdf_writer.php';

$pdo = db();
$user = current_user();
$terminal = input('terminal') === '1';
$tSuffix = $terminal ? '?terminal=1' : '';

if (!isset($_SESSION['schnell_cart']) || !is_array($_SESSION['schnell_cart'])) {
    $_SESSION['schnell_cart'] = []; // device_id => Menge
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('form_action');

    if ($action === 'clear') {
        $_SESSION['schnell_cart'] = [];
        flash('info', 'Liste geleert.');
        redirect('modules/ausgabe/schnell.php' . $tSuffix);
    }

    if ($action === 'remove') {
        unset($_SESSION['schnell_cart'][(int)input('device_id')]);
        redirect('modules/ausgabe/schnell.php' . $tSuffix);
    }

    if ($action === 'scan') {
        $code = trim(input('code'));
        $qty  = max(1, (int)input('quantity', '1'));

        if ($code === '') {
            flash('error', 'Bitte eine Inventarnummer scannen oder eingeben.');
            redirect('modules/ausgabe/schnell.php' . $tSuffix);
        }

        $devStmt = $pdo->prepare('SELECT * FROM devices WHERE inventory_number = ?');
        $devStmt->execute([$code]);
        $device = $devStmt->fetch();

        if (!$device) {
            flash('error', '„' . $code . '“ wurde nicht gefunden.');
            redirect('modules/ausgabe/schnell.php' . $tSuffix);
        }
        if (!$device['is_bulk'] && $device['status'] !== 'verfuegbar') {
            flash('error', $device['inventory_number'] . ' ist aktuell nicht verfügbar (' . device_status_label($device['status']) . ').');
            redirect('modules/ausgabe/schnell.php' . $tSuffix);
        }
        if (!$device['is_bulk'] && isset($_SESSION['schnell_cart'][$device['id']])) {
            flash('info', $device['inventory_number'] . ' ist bereits auf der Liste.');
            redirect('modules/ausgabe/schnell.php' . $tSuffix);
        }

        if ($device['is_bulk']) {
            $_SESSION['schnell_cart'][$device['id']] = ($_SESSION['schnell_cart'][$device['id']] ?? 0) + $qty;
        } else {
            $_SESSION['schnell_cart'][$device['id']] = 1;
        }
        flash('success', $device['inventory_number'] . ' – ' . $device['name'] . ' hinzugefügt.');
        redirect('modules/ausgabe/schnell.php' . $tSuffix);
    }

    if ($action === 'finish') {
        if (!$_SESSION['schnell_cart']) {
            flash('error', 'Liste ist leer.');
            redirect('modules/ausgabe/schnell.php' . $tSuffix);
        }

        $employeeId = preg_replace('/\D/', '', input('employee_id'));
        $empStmt = $pdo->prepare('SELECT * FROM users WHERE employee_id = ? AND active = 1');
        $empStmt->execute([$employeeId]);
        $employee = $empStmt->fetch();

        if (!$employee) {
            flash('error', 'Unbekannte Mitarbeiter-ID.');
            redirect('modules/ausgabe/schnell.php' . $tSuffix);
        }

        $deviceIds = array_keys($_SESSION['schnell_cart']);
        $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM devices WHERE id IN ($placeholders)");
        $stmt->execute($deviceIds);
        $devicesById = [];
        foreach ($stmt->fetchAll() as $d) {
            $devicesById[$d['id']] = $d;
        }

        // Nochmal prüfen - zwischen Scannen und Abschließen kann sich der Status geändert haben.
        $invalid = [];
        foreach ($_SESSION['schnell_cart'] as $deviceId => $qty) {
            $d = $devicesById[$deviceId] ?? null;
            if (!$d || (!$d['is_bulk'] && $d['status'] !== 'verfuegbar')) {
                $invalid[] = $d ? $d['inventory_number'] : ('Gerät #' . $deviceId);
            }
        }
        if ($invalid) {
            flash('error', 'Nicht mehr verfügbar: ' . implode(', ', $invalid) . ' – bitte von der Liste entfernen.');
            redirect('modules/ausgabe/schnell.php' . $tSuffix);
        }

        // Ausleihe anlegen - kein Auftrag, aber eine eigene, trackbare Ausleih-ID,
        // damit sich die Geräte später über die Rückgabe wiederfinden lassen.
        $code = generate_quick_checkout_code();
        $pdo->prepare('INSERT INTO quick_checkouts (code, employee_id, issued_by, status) VALUES (?, ?, ?, "offen")')
            ->execute([$code, $employee['id'], $user['id']]);
        $checkoutId = (int)$pdo->lastInsertId();

        $pdf = new SimplePdf();
        $pdf->setHeader('SCHNELLAUSGABE', 'Ausleih-ID ' . $code);
        $pdf->setFooter('RSH Technik · erstellt am ' . date('d.m.Y H:i') . ' · zur Rückgabe die Ausleih-ID angeben');
        $pdf->addKeyValue('Ausleih-ID', $code, 0);
        $pdf->addKeyValue('Ausgegeben an', $employee['name'] . ' (' . $employee['employee_id'] . ')');
        $pdf->addKeyValue('Ausgegeben von', $user['name']);
        $pdf->addKeyValue('Datum', date('d.m.Y H:i'));
        $pdf->addRule(16);
        $pdf->addLine('GERÄTE  ·  ' . count($_SESSION['schnell_cart']) . ' Positionen', 12, true, 10);
        $pdf->addSpacer(6);

        foreach ($_SESSION['schnell_cart'] as $deviceId => $qty) {
            $d = $devicesById[$deviceId];
            $label = $d['is_bulk'] ? ((int)$qty) . ' × ' . $d['name'] : $d['inventory_number'] . '   ' . $d['name'];
            $pdf->addLine($label, 10, false, 6, ['checkbox' => true]);

            $pdo->prepare('INSERT INTO quick_checkout_items (quick_checkout_id, device_id, quantity, is_bulk) VALUES (?, ?, ?, ?)')
                ->execute([$checkoutId, $d['id'], (int)$qty, $d['is_bulk'] ? 1 : 0]);

            if (!$d['is_bulk']) {
                $pdo->prepare('UPDATE devices SET status = "ausgegeben" WHERE id = ?')->execute([$d['id']]);
            }
            log_activity('Schnellausgabe (ohne Auftrag)', 'device', (int)$d['id'], $d['inventory_number'] . ' ' . $d['name'] . ' an ' . $employee['name'] . ' (' . $code . ')');
        }
        log_activity('Schnellausgabe erstellt', 'quick_checkout', $checkoutId, $code . ' – ' . count($_SESSION['schnell_cart']) . ' Positionen an ' . $employee['name']);

        $_SESSION['schnell_cart'] = [];
        stream_pdf('schnellausgabe_' . $code . '.pdf', $pdf);
    }
}

$cartItems = [];
if ($_SESSION['schnell_cart']) {
    $deviceIds = array_keys($_SESSION['schnell_cart']);
    $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE id IN ($placeholders)");
    $stmt->execute($deviceIds);
    foreach ($stmt->fetchAll() as $d) {
        $cartItems[] = ['device' => $d, 'qty' => $_SESSION['schnell_cart'][$d['id']]];
    }
}

$page_title = 'Schnellausgabe';
require_once __DIR__ . '/../../includes/header.php';
?>
<?php if ($terminal): ?>
<div class="terminal-header">
    <div class="t-brand">RSH TECHNIK</div>
    <div class="t-title">SCHNELLAUSGABE</div>
    <div class="t-status">● <?= count($cartItems) ?> auf der Liste</div>
</div>
<?php else: ?>
<div class="section-head"><h1>Schnellausgabe</h1></div>
<p class="muted">Geräte direkt ausbuchen, ohne einen Auftrag anzulegen – es entsteht kein Auftragsdatensatz,
    stattdessen gibt's beim Abschluss einen PDF-Ausgabebeleg zum Ausdrucken/Abheften.</p>
<?php endif; ?>

<form method="post" class="card card-flat">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="scan">
    <?php if ($terminal): ?><input type="hidden" name="terminal" value="1"><?php endif; ?>
    <?php if ($terminal): ?>
        <label class="small muted" style="display:block;text-align:center;margin-bottom:8px;">GERÄT SCANNEN</label>
        <input type="text" id="scan-code" name="code" class="terminal-input" placeholder="RSH-0042" data-autofocus data-scan-target autofocus>
        <button type="submit" class="btn btn-primary btn-block btn-lg">HINZUFÜGEN</button>
        <div class="btn-row" style="margin-top:10px;justify-content:center;">
            <button type="button" class="btn btn-ghost" data-camera-scan-for="scan-code">📷 Kamera</button>
        </div>
    <?php else: ?>
    <div class="form-grid">
        <div class="field">
            <label>Gerät scannen / Inventarnummer eingeben</label>
            <input type="text" id="scan-code" name="code" placeholder="RSH-0042" data-autofocus data-scan-target>
        </div>
        <div class="field"><label>Menge (nur bei Mengenartikeln)</label><input type="number" name="quantity" min="1" value="1"></div>
    </div>
    <div class="btn-row">
        <button type="submit" class="btn btn-primary btn-sm">Hinzufügen</button>
        <button type="button" class="btn btn-ghost btn-sm" data-camera-scan-for="scan-code">📷 Kamera</button>
    </div>
    <?php endif; ?>
</form>

<?php if (!$cartItems): ?>
    <div class="empty-state"><div class="es-icon">▤</div>Noch keine Geräte gescannt.</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Inv.-Nr.</th><th>Gerät</th><th>Menge</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cartItems as $ci): $d = $ci['device']; ?>
            <tr>
                <td><?= $d['is_bulk'] ? '–' : e($d['inventory_number']) ?></td>
                <td><?= e($d['name']) ?></td>
                <td><?= (int)$ci['qty'] ?><?= $d['is_bulk'] ? ' Stk.' : '' ?></td>
                <td class="text-right">
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="remove">
                        <?php if ($terminal): ?><input type="hidden" name="terminal" value="1"><?php endif; ?>
                        <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">Entfernen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>Ausgabe abschließen</h3>
    <form method="post" class="btn-row" style="align-items:center;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="finish">
        <?php if ($terminal): ?><input type="hidden" name="terminal" value="1"><?php endif; ?>
        <input type="text" name="employee_id" placeholder="Mitarbeiter-ID" inputmode="numeric" data-scan-target required style="max-width:180px">
        <button type="submit" class="btn btn-primary btn-lg">PDF ERZEUGEN & AUSGEBEN</button>
    </form>
    <form method="post" onsubmit="return confirm('Liste wirklich leeren?');">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="clear">
        <?php if ($terminal): ?><input type="hidden" name="terminal" value="1"><?php endif; ?>
        <button type="submit" class="btn btn-ghost btn-sm">Liste leeren</button>
    </form>
</div>
<?php endif; ?>

<?php if ($terminal): ?>
<div class="btn-row" style="margin-top:20px;justify-content:center;"><a href="<?= url('terminal/index.php') ?>" class="btn btn-ghost">← Zurück</a></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
