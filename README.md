# Dresden Bean Shop

Ein kleiner, deutschsprachiger Demo-Onlineshop mit **PHP, PostgreSQL und
Docker Compose**. Enthält Kundenkonten, ein Bestellarchiv und eine
Mitarbeiteransicht. Der Shop verarbeitet keine Zahlungen und versendet keine Ware.
Verwende beim lokalen Test mit Passwort ein fiktives Konto, zum Beispiel
`kaffee@example.test`, und kein bereits genutztes Passwort.

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
3. Öffne **Anmelden / Registrieren**, erstelle ein Konto und schließe die
   Bestellung ab. Der Warenkorb leert sich und im Browser erscheint die Bestellnummer.
4. Öffne **Bestellarchiv**. Klappe die Bestellung auf, um Datum, Uhrzeit, Artikel,
   Mengen und Preise zu sehen. Normale Konten sehen nur ihre eigenen Bestellungen.
5. Prüfe den Umsatzbericht im Terminal:

```bash
docker compose exec web php bin/sales.php
docker compose exec web php tests/integration.php
```

Die Tests arbeiten mit PostgreSQL-Temporärtabellen und lassen Shop-Daten unverändert.
`docker compose down` stoppt die Container; Bestellungen und Bestand bleiben im
Datenbank-Volume gespeichert. Basistabellen und Produktdaten werden nur beim
ersten Start geladen. Die wiederholbaren Migrationen laufen vor dem Webstart.
Alte Bestellungen bleiben in der Mitarbeiteransicht sichtbar und werden keinem
neu registrierten Konto automatisch zugeordnet. Für das Upgrade
den bestehenden Ordner bzw. das bisherige Compose-Projekt und dessen
Datenbank-Volume weiterverwenden.

### Mitarbeiteransicht

Registriere zuerst ein separates Testkonto im Shop. Weise ihm danach lokal die
Mitarbeiterrolle zu (ersetze die Beispieladresse durch die registrierte Adresse):

```bash
docker compose exec web php bin/grant-staff.php team@example.test
```

Mit diesem Konto zeigt **Bestellarchiv** alle Bestellungen und einen Kundenfilter.
Die Registrierung im Browser kann keine Mitarbeiterrechte vergeben.

### Optional: Google-Anmeldung

1. Lege in der [Google Cloud Console](https://console.cloud.google.com/) einen
   OAuth-Client vom Typ **Webanwendung** an und konfiguriere den Zustimmungsbildschirm.
   Füge dein Testkonto hinzu, falls die Anwendung im Testmodus ist.
2. Hinterlege `http://localhost:8080/google.php` als autorisierte Weiterleitungs-URI.
   Verwende im Browser ebenfalls `localhost` und nicht `127.0.0.1`.
3. Kopiere `.env.example` nach `.env`, trage Client-ID und Secret **nur lokal**
   ein und starte Compose erneut. `.env` wird nicht mit Git oder dem Image geteilt.

Der Button **Mit Google anmelden** erscheint dann unter **Anmelden** und legt
beim ersten Login ein Konto an. Änderungen an Name oder E-Mail betreffen nur
das Shop-Profil. Google-Konten werden über ihre Google-Kennung erkannt und
nicht automatisch mit Passwortkonten gleicher E-Mail verknüpft.

## Wie es funktioniert

| Datei | Verantwortlichkeit |
| --- | --- |
| `public/index.php` | HTML-Ansicht, Formularverarbeitung, Session-Warenkorb und Aufruf des Checkouts |
| `public/archive.php` | Archivansicht, Filter nach Kundenprofil und Bestellnummer |
| `public/account.php` | Anmeldung, Registrierung und Profilverwaltung |
| `src/shop.php` | Bestelltransaktion, Bestandsprüfung und Preisformatierung |
| `src/customers.php` | SQL-Abfrage für das Bestellarchiv |
| `src/accounts.php` | Kundenkonten, Passworthashes und Sitzungen |
| `src/database.php` | PostgreSQL-Verbindung über PDO |
| `database/01-schema.sql` | Produkte, Bestellungen und Bestellpositionen mit Fremdschlüsseln und Constraints |
| `database/03-customers.sql` | Wiederholbare Migration für Profile und historische Namen |
| `database/04-accounts.sql` | Kundenkonten, Mitarbeiterrolle und Verknüpfung mit Bestellungen |
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
Bereitstellung mit dieser Konfiguration. Passwortkonten haben in dieser Demo
keine E-Mail-Bestätigung, Passwort-Wiederherstellung oder Login-Drosselung.

## Nächste Erweiterungen

Zeige im Katalog nur verfügbare Röstungen, wenn ein Kontrollkästchen aktiviert ist:
Lies den GET-Parameter in `public/index.php` aus und ergänze abhängig davon
`WHERE stock > 0` im Katalog-SQL. Prüfe beide Ansichten und dokumentiere die
Änderung in einem eigenen Commit. Erkläre danach, warum ein Filter per GET
funktioniert, eine Bestellung aber per POST ausgeführt wird.

Danach: Bestellstatus mit Änderungsverlauf und ein einfaches Verkaufsdashboard
pro Kunde.
