# Dresden Bean Shop

Ein kleiner, deutschsprachiger Demo-Onlineshop mit **PHP, PostgreSQL und
Docker Compose**. Der Shop verwendet fiktive Kundenprofile, verarbeitet keine
Zahlungen und versendet keine Ware. Bitte keine echten Namen oder Kontaktdaten
eingeben: Das lokale Bestellarchiv ist ohne Login sichtbar.

## Lokal starten

Voraussetzung: Docker mit Compose. Auf dem Mac muss Docker Desktop laufen.
Öffne den Projektordner in VS Code und führe im Terminal aus:

```bash
docker compose up --build -d --wait
```

Öffne **http://localhost:8080**. Falls Port 8080 belegt ist, ändere in
`compose.yaml` den ersten Port von `8080` auf beispielsweise `8081`.

1. Lege zwei Packungen Elbe Espresso und eine Packung Neustadt Filter in den Warenkorb.
2. Prüfe den Gesamtpreis von **40,70 €** (bei den unveränderten Startpreisen).
3. Wähle ein Demo-Kundenprofil und schließe die Bestellung ab. Der Warenkorb leert
   sich, der Lagerbestand sinkt und im Browser erscheint die Bestellnummer.
4. Öffne **Bestellarchiv**. Filtere nach Kundenprofil oder Bestellnummer und klappe
   die Bestellung auf, um Artikel, Mengen und Einzelpreise zu sehen.
5. Prüfe den Umsatzbericht im Terminal:

```bash
docker compose exec web php bin/sales.php
docker compose exec web php tests/integration.php
```

Die Tests arbeiten mit PostgreSQL-Temporärtabellen und lassen Shop-Daten unverändert.
`docker compose down` stoppt die Container; Bestellungen und Bestand bleiben im
Datenbank-Volume gespeichert. Basistabellen und Produktdaten werden nur beim
ersten Start geladen. Die wiederholbare Archiv-Migration läuft vor dem Webstart.
Bestehende Bestellungen erscheinen als **Ohne Kundenprofil**. Für das Upgrade
den bestehenden Ordner bzw. das bisherige Compose-Projekt und dessen
Datenbank-Volume weiterverwenden.

## Wie es funktioniert

| Datei | Verantwortlichkeit |
| --- | --- |
| `public/index.php` | HTML-Ansicht, Formularverarbeitung, Session-Warenkorb und Aufruf des Checkouts |
| `public/archive.php` | Archivansicht, Filter nach Kundenprofil und Bestellnummer |
| `src/shop.php` | Bestelltransaktion, Bestandsprüfung und Preisformatierung |
| `src/customers.php` | Demo-Kundenprofile und SQL-Abfrage für das Bestellarchiv |
| `src/database.php` | PostgreSQL-Verbindung über PDO |
| `database/01-schema.sql` | Produkte, Bestellungen und Bestellpositionen mit Fremdschlüsseln und Constraints |
| `database/03-customers.sql` | Wiederholbare Migration für Profile und historische Namen |
| `database/sales.sql` | Verkäufe pro Produkt per JOIN, SUM und GROUP BY |
| `tests/integration.php` | Tests für erfolgreiche und fehlgeschlagene Bestellungen sowie den Bericht |

**Warum wird der Bestand beim Checkout noch einmal geprüft?** Ein Warenkorb ist
keine Reservierung. Das bedingte SQL-`UPDATE` zieht nur dann Bestand ab, wenn
genug vorhanden ist. PostgreSQL sperrt die betroffene Zeile beim Update. Scheitert
ein Artikel, werden alle Abbuchungen und die Bestellung zurückgerollt.
Bestellpositionen speichern den Verkaufspreis in Cent, damit spätere Preisänderungen
vergangene Umsätze nicht verändern und kein Fließkomma für Geld nötig ist.
Kunden- und Produktnamen werden beim Checkout ebenfalls gespeichert. Im Archiv
steht dadurch weiterhin die damalige Bezeichnung, auch wenn sich das Profil
oder der Katalog später ändert. Für ältere Bestellpositionen ohne gespeicherten
Produktnamen übernimmt die Migration den Namen aus dem aktuellen Katalog.

**Grenzen der Demo:** Der PHP-Entwicklungsserver und die mitgelieferten
Datenbank-Zugangsdaten sind nur für den lokalen Betrieb gedacht. Keine produktive
Bereitstellung mit dieser Konfiguration. Kein Login, keine Zahlungen und keine
Backend-Administration.

## Nächste Erweiterungen

Zeige im Katalog nur verfügbare Röstungen, wenn ein Kontrollkästchen aktiviert ist:
Lies den GET-Parameter in `public/index.php` aus und ergänze abhängig davon
`WHERE stock > 0` im Katalog-SQL. Prüfe beide Ansichten und dokumentiere die
Änderung in einem eigenen Commit. Erkläre danach, warum ein Filter per GET
funktioniert, eine Bestellung aber per POST ausgeführt wird.

Danach: Anmeldung mit getrennten Berechtigungen (Kunden sehen nur ihre eigenen
Bestellungen, Mitarbeitende das gesamte Archiv), anschließend Bestellstatus und
ein einfaches Verkaufsdashboard pro Kunde.
