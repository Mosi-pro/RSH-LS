# RSH Technik Lager System (RSH-LS)

Internes Lager-, Auftrags- und Veranstaltungsverwaltungssystem der RSH Technik AG.
PHP 8.x + MySQL/MariaDB, ohne Framework, lauffähig auf normalem PHP-Webhosting.

Grundprinzip: **Alles, was das Lager verlässt, gehört zu einem Auftrag.**

## Umfang dieser Version (MVP)

1. Login (Mitarbeiter-ID, ohne Passwort)
2. Dashboard
3. Lager / Geräte
4. Kategorien & Lagerorte
5. Aufträge (inkl. Technik-Zusammenstellung, Reservierung, Status)
6. Veranstaltungen
7. Ausgabe (Lager-Terminal)
8. Rückgabe (Lager-Terminal, vollständig/unvollständig)
9. Mitarbeiterverwaltung
10. Historie / Audit-Log
11. Globale Suche

Bewusst **nicht** enthalten (spätere Ausbaustufen): QR-/Barcode-Erzeugung (Scanner-Eingabe
per Tastatur-Emulation wird bereits unterstützt), Inventur, Defekt-/Wartungsverwaltung,
Reports/Statistiken, PDF/Excel-Export, Benachrichtigungen.

## Setup

1. Datenbank anlegen und `sql/database.sql` importieren (enthält Beispiel-Mitarbeiter
   `198`, `203`, `245`, `999`).
2. Umgebungsvariablen setzen (z.B. über die Hosting-Oberfläche oder eine `.env`-Lösung
   des Hosters – `includes/config.php` liest sie über `getenv()`):
   - `RSH_DB_HOST`, `RSH_DB_NAME`, `RSH_DB_USER`, `RSH_DB_PASS`
   - `RSH_ENV` (`production` oder `development`)
   - `RSH_BASE_URL` (nur nötig, falls die Anwendung nicht im Domain-Root liegt)
3. Document Root auf das Projekt-Root-Verzeichnis zeigen lassen (nicht auf `public/`,
   da `modules/`, `terminal/` und `api/` ebenfalls direkt aufgerufen werden).
4. `uploads/` beschreibbar machen (für zukünftige Bild-Uploads).
5. Aufruf von `/public/index.php` bzw. `/` leitet automatisch zum Login weiter.

## Ordnerstruktur

```
/public      Login, Dashboard, Suche
/includes    Bootstrap, DB, Auth, Rechte, Helper, Layout
/modules     Lager, Aufträge, Veranstaltungen, Ausgabe, Rückgabe, Mitarbeiter, Historie
/terminal    Große Touch-Oberfläche für das Lager-Tablet
/api         JSON-Endpunkte (Suche)
/admin       Zentrale Verwaltungsseite (nur Technikleitung)
/assets      CSS/JS
/uploads     Zukünftige Datei-Uploads
/sql         Datenbankschema
```

## Sicherheitskonzept

- Login ausschließlich über die Mitarbeiter-ID – alle Berechtigungen werden serverseitig
  anhand der in der Session gespeicherten Rolle geprüft (`includes/permissions.php`),
  niemals anhand von Client-Eingaben.
- PDO mit Prepared Statements für sämtliche Datenbankzugriffe.
- CSRF-Token auf allen datenverändernden Formularen.
- Session-Regeneration beim Login, automatischer Logout nach Inaktivität
  (Lager-Terminal-Rolle bleibt bewusst länger angemeldet).
- `includes/`, `sql/` und PHP-Ausführung in `uploads/` sind serverseitig gesperrt
  (`.htaccess`) bzw. tragen einen Direktzugriffs-Schutz in jeder Datei.
- Jede relevante Aktion wird unveränderlich im Audit-Log (`activity_log`) protokolliert.

## Rollen

| Rolle                  | Rechte                          |
|-------------------------|----------------------------------|
| Technikleitung          | alles                            |
| Lagerleitung             | Lager + Aufträge                 |
| Mitarbeiter              | eigene Buchungen                 |
| Veranstaltungsleitung   | Veranstaltungen + Aufträge       |
| Lager-Terminal            | Ausgabe / Rückgabe               |
| Gast                      | nur freigegebene Informationen  |

## Barcode-/QR-Scanner

Eingabefelder mit `data-scan-target` (z.B. Mitarbeiter-ID- und Auftragsnummer-Felder am
Terminal) lösen bei einem Enter-Zeichen automatisch das umgebende Formular aus – das
deckt die typische Tastatur-Emulation von USB-/Bluetooth-Scannern bereits ab.
