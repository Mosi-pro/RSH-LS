# RSH Technik Lager System (RSH-LS)

Internes Lager-, Auftrags- und Veranstaltungsverwaltungssystem der RSH Technik AG.
PHP 8.x, ohne Framework, lauffähig auf normalem PHP-Webhosting. Alle Daten werden in
**einer einzigen SQLite-Datei** (`data/rsh-ls.sqlite`) gespeichert – kein separater
Datenbankserver, keine Zugangsdaten, kein manueller SQL-Import nötig.

Grundprinzip: **Alles, was das Lager verlässt, gehört zu einem Auftrag.**

## Umfang

**Kernmodule (MVP):**
1. Login (Mitarbeiter-ID, ohne Passwort)
2. Dashboard (inkl. Live-Warnungen)
3. Lager / Geräte (inkl. Excel-Import)
4. Kategorien & Lagerorte
5. Aufträge (inkl. Technik-Zusammenstellung, Reservierung, Status)
6. Veranstaltungen
7. Ausgabe (Lager-Terminal)
8. Rückgabe (Lager-Terminal, vollständig/unvollständig)
9. Mitarbeiterverwaltung
10. Historie / Audit-Log
11. Globale Suche

**Erweiterungen:**
12. QR-Codes (Geräte-Scan-Landingpage, druckbare Einzel-/Stapel-Etiketten)
13. Inventur (Soll/Ist-Abgleich per Scan-Eingabe, Abweichungsprotokoll)
14. Defekte & Wartung (Defekt melden, Bearbeitungsstatus, Wartungsfälligkeiten)
15. Auswertungen (Auslastung, meistgenutzte Geräte, häufigste Defekte, Aufträge/Monat, Inventurabweichungen)
16. PDF-Export (Auftragszettel/Packliste) & Excel-Export (Lager, Aufträge, Historie)
17. Benachrichtigungen (live berechnete Dashboard-Warnungen; zusätzlich persistente,
    pro Mitarbeiter zustellbare Benachrichtigungen z.B. bei behobenem Defekt)
18. Kamera-QR-Scan (jsQR) an jedem Scan-Feld – Handy-/Tablet-/Laptop-Kamera statt
    ausschließlich externem USB-/BT-Scanner
19. Werkstatt-Bereich (eigene Oberfläche, nur Technikleitung + Werkstatt-Account):
    Gerät per Scan in die Reparatur aufnehmen, erneutes Scannen checkt aus, Lösung
    eintragen – der Melder des Defekts wird automatisch benachrichtigt
20. Neu gemeldete Defekte laufen sofort als „Eingehende Aufträge" in der Werkstatt-
    Oberfläche auf (inkl. Benachrichtigung an die Werkstatt) – nicht erst nach dem
    Einscannen
21. Werkstattauftrag als professionelles PDF mit eingebettetem QR-Code (verweist auf
    die digitale Detailseite) – druckbar, Lösungsfeld zum Ausfüllen von Hand

Noch nicht enthalten: Barcode-*Erzeugung* für Nicht-Geräte-Objekte, E-Mail-/Telegram-
Zustellung von Benachrichtigungen (aktuell nur In-App), Kundenportal, Mehrsprachigkeit.

## Setup

1. Alle Projektdateien per FTP/SFTP auf den Webspace hochladen.
2. Document Root auf das Projekt-Root-Verzeichnis zeigen lassen (nicht auf `public/`,
   da `modules/`, `terminal/` und `api/` ebenfalls direkt aufgerufen werden).
3. `data/` und `uploads/` beschreibbar machen (Verzeichnisrechte 755/775).
4. Fertig. Beim allerersten Aufruf legt die Anwendung `data/rsh-ls.sqlite` automatisch
   an – inklusive Schema und Beispiel-Mitarbeitern (`198`, `203`, `245`, `999`, `355`).

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
/includes    Bootstrap, DB (SQLite), Schema/Migrationen, Auth, Rechte, Helper, Layout,
             XLSX-Reader/-Writer, PDF-Writer
/modules     Lager (inkl. Import/Export/QR/Etiketten), Aufträge (inkl. PDF/Excel),
             Veranstaltungen, Ausgabe, Rückgabe, Mitarbeiter, Historie (inkl. Excel),
             Inventur, Defekte & Wartung, Reports
/terminal    Große Touch-Oberfläche für das Lager-Tablet
/api         JSON-Endpunkte (Suche)
/admin       Zentrale Verwaltungsseite (nur Technikleitung)
/assets      CSS/JS
/data        SQLite-Datenbankdatei (wird automatisch angelegt)
/uploads     Zukünftige Datei-Uploads
```

## Schema-Migrationen

Neue Datenbankfelder/-tabellen werden über `includes/migrations.php` ausgerollt
(Versionsnummer in der SQLite-Datei selbst, `PRAGMA user_version`). Ein Update der
Projektdateien reicht aus – beim nächsten Aufruf wird die bestehende
`data/rsh-ls.sqlite` automatisch und ohne Datenverlust auf den neuesten Stand
gebracht. Neue Migrationen werden in `includes/migrations.php` als neuer Eintrag mit
der nächsthöheren Versionsnummer ergänzt; bestehende Einträge nie nachträglich ändern.

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
| Lagerleitung             | Lager + Aufträge + Inventur + Defekte verwalten + Auswertungen |
| Mitarbeiter              | eigene Buchungen + Defekte melden |
| Veranstaltungsleitung   | Veranstaltungen + Aufträge + Defekte melden |
| Lager-Terminal            | Ausgabe / Rückgabe + Defekte melden |
| Werkstatt                 | Eigene Werkstatt-Oberfläche (Reparatur-Check-in/-out) |
| Gast                      | nur freigegebene Informationen  |

**Gerätebearbeitung (`lager.edit`) ist bewusst nur der Technikleitung und der
Lagerleitung vorbehalten** – alle anderen Rollen sehen das Lager nur lesend.
Defekte *melden* darf breit jede Rolle mit Lagerzugriff; Defekte *bearbeiten/
beheben* sowie Inventur und Auswertungen bleiben ebenfalls Technikleitung +
Lagerleitung vorbehalten. Der Werkstatt-Bereich selbst (`werkstatt.access`)
ist – wie gewünscht – ausschließlich Technikleitung und dem Werkstatt-Account
zugänglich, unabhängig von den übrigen Lagerrechten.

## Werkstatt

Beispiel-Account `355` (Rolle „Werkstatt”, Mitarbeiter-ID über die
Mitarbeiterverwaltung änderbar). Eigene Oberfläche unter „Werkstatt” im Menü:
0. Sobald irgendwer einen Defekt meldet, erscheint er **sofort** oben unter
   „Eingehende Aufträge” (unabhängig vom physischen Einscannen) und die
   Werkstatt wird per Glocke benachrichtigt.
1. Gerät scannen/eingeben → wird in die Reparatur aufgenommen (Status
   „In Reparatur”), ein offener Defekt zum Gerät wechselt auf „In Bearbeitung”.
2. Dasselbe Gerät erneut scannen → Auscheck-Seite: Lösung eintragen, Status
   nach der Reparatur festlegen (verfügbar / weiterhin defekt / verloren /
   aussortiert).
3. Beim Auschecken wird der ursprüngliche Melder des Defekts automatisch über
   die Glocke oben rechts benachrichtigt (Fehlerbeschreibung + Lösungstext).

Zu jeder Meldung lässt sich über „PDF” ein professioneller Werkstattauftrag
ausdrucken (Gerät, Problem, Priorität, Melder, ggf. Lösung) mit einem
eingebetteten QR-Code, der auf die digitale Detailseite verweist – der
QR-Code wird komplett serverseitig in reinem PHP erzeugt (`includes/qr_encoder.php`,
kein Composer/keine externe Bibliothek nötig) und direkt als Vektorgrafik ins PDF
gezeichnet.

## QR-Codes

Die QR-Code-Erzeugung läuft clientseitig über die kleine, weit verbreitete
Bibliothek `qrcodejs` (per `<script>`-Tag von cdnjs.cloudflare.com geladen) –
das Gerät des Betrachters (nicht der Server) braucht dafür beim Aufrufen einer
Geräteseite/eines Etiketts kurz Internetzugriff. Serverseitig ist dafür nichts
weiter nötig.

## Barcode-/QR-Scanner

Eingabefelder mit `data-scan-target` (z.B. Mitarbeiter-ID- und Auftragsnummer-Felder am
Terminal) lösen bei einem Enter-Zeichen automatisch das umgebende Formular aus – das
deckt die typische Tastatur-Emulation von USB-/Bluetooth-Scannern bereits ab.

Zusätzlich gibt es an jedem Scan-Feld einen „📷 Kamera“-Button (`data-camera-scan-for`),
der über die Gerätekamera (Handy/Tablet/Laptop) per `jsQR` direkt einen QR-Code
ausliest, ohne externen Scanner – genutzt in Ausgabe, Rückgabe, Inventur und Werkstatt.
