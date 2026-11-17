# Dresden Bean Shop

Ein kleiner, deutschsprachiger Demo-Onlineshop mit **PHP, PostgreSQL und
Docker Compose**. Der Shop speichert keine Kundendaten, verarbeitet keine Zahlungen
und versendet keine Ware. Die Produkte sind fiktiv.

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
3. Schließe die Demo-Bestellung ab. Der Warenkorb leert sich, der Lagerbestand sinkt
   und im Browser erscheint die Bestellnummer.
4. Prüfe den Umsatzbericht im Terminal:

```bash
docker compose exec web php bin/sales.php
docker compose exec web php tests/integration.php
```

Die Tests arbeiten mit PostgreSQL-Temporärtabellen und lassen Shop-Daten unverändert.
`docker compose down` stoppt die Container; Bestellungen und Bestand bleiben im
Datenbank-Volume gespeichert. Die SQL-Dateien werden beim erstmaligen Anlegen
der Datenbank eingelesen, nicht bei jedem Start.

## Wie es funktioniert

| Datei | Verantwortlichkeit |
| --- | --- |
| `public/index.php` | HTML-Ansicht, Formularverarbeitung, Session-Warenkorb und Aufruf des Checkouts |
| `src/shop.php` | Bestelltransaktion, Bestandsprüfung und Preisformatierung |
| `src/database.php` | PostgreSQL-Verbindung über PDO |
| `database/01-schema.sql` | Produkte, Bestellungen und Bestellpositionen mit Fremdschlüsseln und Constraints |
| `database/sales.sql` | Verkäufe pro Produkt per JOIN, SUM und GROUP BY |
| `tests/integration.php` | Tests für erfolgreiche und fehlgeschlagene Bestellungen sowie den Bericht |

**Warum wird der Bestand beim Checkout noch einmal geprüft?** Ein Warenkorb ist
keine Reservierung. Das bedingte SQL-`UPDATE` zieht nur dann Bestand ab, wenn
genug vorhanden ist. PostgreSQL sperrt die betroffene Zeile beim Update. Scheitert
ein Artikel, werden alle Abbuchungen und die Bestellung zurückgerollt.
Bestellpositionen speichern den Verkaufspreis in Cent, damit spätere Preisänderungen
vergangene Umsätze nicht verändern und kein Fließkomma für Geld nötig ist.

**Grenzen der Demo:** Der PHP-Entwicklungsserver und die mitgelieferten
Datenbank-Zugangsdaten sind nur für den lokalen Betrieb gedacht. Keine produktive
Bereitstellung mit dieser Konfiguration. Kein Login, keine Zahlungen und keine
Backend-Administration.

## Erste eigene Erweiterung

Zeige im Katalog nur verfügbare Röstungen, wenn ein Kontrollkästchen aktiviert ist:
Lies den GET-Parameter in `public/index.php` aus und ergänze abhängig davon
`WHERE stock > 0` im Katalog-SQL. Prüfe beide Ansichten und dokumentiere die
Änderung in einem eigenen Commit. Erkläre danach, warum ein Filter per GET
funktioniert, eine Bestellung aber per POST ausgeführt wird.
