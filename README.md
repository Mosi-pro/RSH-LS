# RSH Technik Lager System (RSH-LS)

Internes Lager-, Auftrags- und Veranstaltungsverwaltungssystem der RSH Technik AG.
PHP 8.x, ohne Framework, lauffähig auf normalem PHP-Webhosting. Alle Daten werden in
**einer einzigen SQLite-Datei** (`data/rsh-ls.sqlite`) gespeichert – kein separater
Datenbankserver, keine Zugangsdaten, kein manueller SQL-Import nötig.

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

1. Alle Projektdateien per FTP/SFTP auf den Webspace hochladen.
2. Document Root auf das Projekt-Root-Verzeichnis zeigen lassen (nicht auf `public/`,
   da `modules/`, `terminal/` und `api/` ebenfalls direkt aufgerufen werden).
3. `data/` und `uploads/` beschreibbar machen (Verzeichnisrechte 755/775).
4. Fertig. Beim allerersten Aufruf legt die Anwendung `data/rsh-ls.sqlite` automatisch
   an – inklusive Schema und Beispiel-Mitarbeitern (`198`, `203`, `245`, `999`).

Optional per Umgebungsvariable (Hosting-Panel) oder `includes/config.local.php`
(nicht versioniert, siehe `.gitignore`) anpassbar:
- `RSH_ENV` (`production` oder `development` – zeigt PHP-Fehler im Browser an)
- `RSH_BASE_URL` (nur nötig, falls die Anwendung nicht im Domain-Root liegt)
- `RSH_DB_PATH` (nur nötig, falls die SQLite-Datei woanders liegen soll)

### Backup

Da alle Daten in einer einzigen Datei liegen, ist ein Backup denkbar einfach:
`data/rsh-ls.sqlite` regelmäßig kopieren (z.B. per Cronjob/FTP), fertig.

## Ordnerstruktur

```
/public      Login, Dashboard, Suche
/includes    Bootstrap, DB (SQLite), Schema, Auth, Rechte, Helper, Layout
/modules     Lager, Aufträge, Veranstaltungen, Ausgabe, Rückgabe, Mitarbeiter, Historie
/terminal    Große Touch-Oberfläche für das Lager-Tablet
/api         JSON-Endpunkte (Suche)
/admin       Zentrale Verwaltungsseite (nur Technikleitung)
/assets      CSS/JS
/data        SQLite-Datenbankdatei (wird automatisch angelegt)
/uploads     Zukünftige Datei-Uploads
```

## Sicherheitskonzept

- Login ausschließlich über die Mitarbeiter-ID – alle Berechtigungen werden serverseitig
  anhand der in der Session gespeicherten Rolle geprüft (`includes/permissions.php`),
  niemals anhand von Client-Eingaben.
- PDO mit Prepared Statements für sämtliche Datenbankzugriffe.
- CSRF-Token auf allen datenverändernden Formularen.
- Session-Regeneration beim Login, automatischer Logout nach Inaktivität
  (Lager-Terminal-Rolle bleibt bewusst länger angemeldet).
- `includes/`, `data/` und PHP-Ausführung in `uploads/` sind serverseitig gesperrt
  (`.htaccess`) bzw. tragen einen Direktzugriffs-Schutz in jeder Datei – die SQLite-Datei
  darf niemals über den Browser direkt herunterladbar sein.
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
