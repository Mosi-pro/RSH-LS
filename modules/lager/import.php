<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('lager.edit');
require_once __DIR__ . '/../../includes/xlsx_reader.php';

$pdo = db();
$result = null;

// Erkannte Spaltenüberschriften (kleingeschrieben, getrimmt) -> internes Feld
const IMPORT_HEADER_MAP = [
    'inventarnummer'  => 'inventory_number',
    'inv-nr'          => 'inventory_number',
    'inv.-nr.'        => 'inventory_number',
    'typ'             => 'category',
    'kategorie'       => 'category',
    'name'            => 'name',
    'bezeichnung'     => 'name',
    'stückzahl'       => 'quantity',
    'stueckzahl'      => 'quantity',
    'menge'           => 'quantity',
    'bestand'         => 'quantity',
    'lagerort'        => 'location',
    'ort'             => 'location',
    'status'          => 'status',
    'zustand'         => 'status',
    'inventurdatum'   => 'inventory_date',
];

const IMPORT_STATUS_MAP = [
    'verfügbar'   => 'verfuegbar',
    'verfuegbar'  => 'verfuegbar',
    'reserviert'  => 'reserviert',
    'ausgegeben'  => 'ausgegeben',
    'defekt'      => 'defekt',
    'wartung'     => 'wartung',
    'verloren'    => 'verloren',
    'aussortiert' => 'aussortiert',
];

function import_find_or_create_category(PDO $pdo, string $name, array &$cache): int
{
    $key = mb_strtolower(trim($name));
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare('SELECT id FROM device_categories WHERE LOWER(name) = ?');
    $stmt->execute([$key]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        $pdo->prepare('INSERT INTO device_categories (name) VALUES (?)')->execute([trim($name)]);
        $id = (int)$pdo->lastInsertId();
    }
    $cache[$key] = (int)$id;
    return (int)$id;
}

function import_find_or_create_location(PDO $pdo, string $name, array &$cache): int
{
    $key = mb_strtolower(trim($name));
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare('SELECT id FROM storage_locations WHERE LOWER(name) = ? AND parent_id IS NULL');
    $stmt->execute([$key]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        $pdo->prepare('INSERT INTO storage_locations (name) VALUES (?)')->execute([trim($name)]);
        $id = (int)$pdo->lastInsertId();
    }
    $cache[$key] = (int)$id;
    return (int)$id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Bitte eine Datei auswählen.');
    } elseif (!preg_match('/\.xlsx$/i', $_FILES['file']['name'])) {
        flash('error', 'Bitte eine .xlsx-Datei hochladen.');
    } else {
        try {
            $rows = read_xlsx_rows($_FILES['file']['tmp_name']);
        } catch (Throwable $e) {
            flash('error', 'Datei konnte nicht gelesen werden: ' . $e->getMessage());
            $rows = null;
        }

        if ($rows !== null) {
            if (count($rows) < 2) {
                flash('error', 'Die Datei enthält keine Datenzeilen.');
            } else {
                $headerRow = array_shift($rows);
                $colMap = [];
                foreach ($headerRow as $col => $text) {
                    $key = strtolower(trim((string)$text));
                    if (isset(IMPORT_HEADER_MAP[$key])) {
                        $colMap[$col] = IMPORT_HEADER_MAP[$key];
                    }
                }
                $mappedFields = array_values($colMap);

                if (!in_array('inventory_number', $mappedFields, true) || !in_array('name', $mappedFields, true)) {
                    flash('error', 'Die Datei muss mindestens die Spalten "Inventarnummer" und "Name" enthalten.');
                } else {
                    $created = 0;
                    $updated = 0;
                    $skipped = [];
                    $categoryCache = [];
                    $locationCache = [];

                    $pdo->beginTransaction();

                    foreach ($rows as $row) {
                        $data = [];
                        foreach ($colMap as $col => $field) {
                            $data[$field] = isset($row[$col]) ? trim((string)$row[$col]) : '';
                        }

                        $invNumber = $data['inventory_number'] ?? '';
                        $name = $data['name'] ?? '';

                        if ($invNumber === '') {
                            continue; // vollständig leere Zeile
                        }
                        if ($name === '') {
                            $skipped[] = $invNumber . ' – kein Name angegeben';
                            continue;
                        }

                        $categoryId = null;
                        if (!empty($data['category'])) {
                            $categoryId = import_find_or_create_category($pdo, $data['category'], $categoryCache);
                        }
                        $locationId = null;
                        if (!empty($data['location'])) {
                            $locationId = import_find_or_create_location($pdo, $data['location'], $locationCache);
                        }

                        $quantity = 1;
                        if (!empty($data['quantity']) && is_numeric($data['quantity'])) {
                            $quantity = max(1, (int)round((float)$data['quantity']));
                        }
                        $isBulk = $quantity > 1 ? 1 : 0;

                        $statusKey = mb_strtolower($data['status'] ?? '');
                        $status = IMPORT_STATUS_MAP[$statusKey] ?? 'verfuegbar';

                        $notes = null;
                        if (!empty($data['inventory_date'])) {
                            $iso = xlsx_serial_to_date($data['inventory_date']);
                            if ($iso) {
                                $notes = 'Inventur (Import): ' . format_date($iso);
                            }
                        }

                        $check = $pdo->prepare('SELECT id FROM devices WHERE inventory_number = ?');
                        $check->execute([$invNumber]);
                        $existingId = $check->fetchColumn();

                        if ($existingId) {
                            $stmt = $pdo->prepare(
                                'UPDATE devices SET name=?, category_id=?, location_id=?, status=?, quantity=?, is_bulk=?,
                                    notes = COALESCE(?, notes) WHERE id=?'
                            );
                            $stmt->execute([$name, $categoryId, $locationId, $status, $quantity, $isBulk, $notes, $existingId]);
                            log_activity('Gerät per Import aktualisiert', 'device', (int)$existingId, $invNumber . ' ' . $name);
                            $updated++;
                        } else {
                            $stmt = $pdo->prepare(
                                'INSERT INTO devices (inventory_number, name, category_id, location_id, status, quantity, is_bulk, notes)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                            );
                            $stmt->execute([$invNumber, $name, $categoryId, $locationId, $status, $quantity, $isBulk, $notes]);
                            $newId = (int)$pdo->lastInsertId();
                            log_activity('Gerät per Import angelegt', 'device', $newId, $invNumber . ' ' . $name);
                            $created++;
                        }
                    }

                    $pdo->commit();

                    log_activity(
                        'Lager-Import durchgeführt',
                        'device',
                        null,
                        $created . ' angelegt, ' . $updated . ' aktualisiert, ' . count($skipped) . ' übersprungen'
                    );

                    $result = ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
                    flash(
                        'success',
                        $created . ' Geräte angelegt, ' . $updated . ' aktualisiert'
                        . (count($skipped) ? ', ' . count($skipped) . ' übersprungen' : '') . '.'
                    );
                }
            }
        }
    }
}

$page_title = 'Geräte importieren';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="section-head">
    <h1>Geräte aus Excel importieren</h1>
    <a href="<?= url('modules/lager/index.php') ?>" class="btn btn-ghost btn-sm">← Zurück zum Lager</a>
</div>

<div class="card card-flat">
    <h3>Erwartetes Format (.xlsx)</h3>
    <p class="small muted">
        Erste Zeile = Überschriften. Erkannt werden die Spalten
        <strong>Inventarnummer</strong>, <strong>TYP</strong> (Kategorie), <strong>Name</strong>,
        <strong>Stückzahl</strong>, <strong>Lagerort</strong>, <strong>Status</strong>
        (Verfügbar/Reserviert/Ausgegeben/Defekt/Wartung/Verloren/Aussortiert) und optional
        <strong>Inventurdatum</strong>. Reihenfolge der Spalten ist egal.
    </p>
    <p class="small muted">
        Existiert eine Inventarnummer bereits, wird das Gerät aktualisiert statt doppelt angelegt.
        Kategorien und Lagerorte, die noch nicht existieren, werden automatisch angelegt.
        Zeilen ohne Name werden übersprungen.
    </p>
</div>

<form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>
    <div class="field">
        <label>XLSX-Datei</label>
        <input type="file" name="file" accept=".xlsx" required>
    </div>
    <button type="submit" class="btn btn-primary">Importieren</button>
</form>

<?php if ($result && $result['skipped']): ?>
<div class="card">
    <h3>Übersprungene Zeilen (<?= count($result['skipped']) ?>)</h3>
    <ul class="small muted">
        <?php foreach ($result['skipped'] as $s): ?>
            <li><?= e($s) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
