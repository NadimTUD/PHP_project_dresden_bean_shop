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
3. Wähle **Registrieren** in der oberen Navigation, erstelle ein Konto und schließe die
   Bestellung ab. Der Warenkorb leert sich und im Browser erscheint die Bestellnummer.
4. Öffne **Meine Bestellungen**. Klappe die Bestellung auf, um Datum, Uhrzeit, Artikel,
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

**Schon Mitarbeiter?** Öffne **Team** in der Navigation und trage die E-Mail
des registrierten Kontos ein, dem du Mitarbeiterrechte geben möchtest. Das
Konto kann danach **Lager**, **Kundenverwaltung**, **Team** und das
Mitarbeiterarchiv öffnen. Vergib diese Rolle nur an vertrauenswürdige Personen.

**Erstes Mitarbeiterkonto in einer neuen lokalen Installation:** Setze einmal
einen privaten, zufälligen `STAFF_SETUP_TOKEN` (mindestens 32 Zeichen) in
deiner lokalen `.env` und starte den Shop mit Docker Compose neu. Melde dich
mit dem gewünschten Konto an und öffne **Meine Kundendaten → Mitarbeiterzugang
einrichten**. Gib den Code dort ein. Sobald ein Mitarbeiterkonto existiert,
ist die Einrichtung gesperrt. Entferne danach den Code aus `.env`. Es gibt
keinen Standardcode und keine Mitarbeiterrolle per Selbstregistrierung.

Alternativ kann der lokale Betreiber das erste Konto weiterhin per CLI einrichten:

```bash
docker compose exec web php bin/grant-staff.php team@example.test
```

Mit diesem Konto zeigt **Bestellarchiv** alle Bestellungen und einen Kundenfilter.
Über **Kundenverwaltung** können Mitarbeitende Name und Shop-E-Mail von
Kundenkonten bearbeiten und deren Bestellungen öffnen. Kunden bearbeiten ihre
eigenen Daten über **Meine Kundendaten**. Bereits gespeicherte Bestellungen
behalten die Daten vom Bestellzeitpunkt.
Passwortkonten können dort ihr Shop-Passwort mit dem aktuellen Passwort ändern.
Reine Google-Konten verwalten ihr Passwort direkt bei Google.
Die Registrierung im Browser kann keine Mitarbeiterrechte vergeben.

### Lagerverwaltung

Öffne als Mitarbeiter **Lager**. Trage bei einer oder mehreren Röstungen die
zusätzliche Stückzahl ein, dann klicke **Bestand auffüllen**. Leere Felder
bleiben unverändert. Die letzten Lagerbewegungen zeigen Nachfüllungen und
Verkäufe (mit Verweis auf die zugehörige Bestellung). Ältere Bestellungen und
Bestände bleiben erhalten; das Protokoll beginnt mit diesem Update.

**Musterentscheidung:** Checkout nutzt den schmalen Port
`InventoryForOrders` und kennt keine SQL-Anweisungen zur Lagerverwaltung.
`PostgresInventory` ist der Adapter; die Lagerseite benutzt ihn unabhängig
vom Bestellarchiv. Das ist eine Schnittstelle mit Dependency Injection und
kein GoF-Bridge-Muster: Es gibt nicht zwei unabhängig variierende
Klassenhierarchien. Die Bestellung gibt dem Adapter dieselbe aktive
PostgreSQL-Transaktion weiter, damit Bestellung, Bestandsabzug und
Lagerprotokoll gemeinsam gespeichert oder zurückgerollt werden.

### Portfolio-Demo: Bestellung bis zur Stornierung

1. Lege als Kunde eine frische Bestellung an. Im Lager sinkt der Bestand,
   im Kundenarchiv erscheint **Neu**.
2. Öffne als Mitarbeiter **Bestellarchiv**, klappe die Bestellung auf und starte
   die Bearbeitung. Der Kunde sieht **In Bearbeitung** und den Zeitstempel.
3. Schließe die Bestellung ab oder wähle **Stornieren und Bestand zurückbuchen**.
   Beim Storno erscheinen die Packungen wieder im Lager, ohne die Bestellung
   oder ihren Verlauf zu löschen. Stornierte Bestellungen zählen nicht zum Umsatz.
4. Öffne den Bestellverlauf und das Lagerprotokoll. Nur erlaubte Statusübergänge
   sind verfügbar; ein veralteter oder doppelt gesendeter Storno bucht nicht erneut.

Die Statusmaschine steht in `src/order-workflow.php`. PostgreSQL sperrt die
Bestellzeile bei einem Statuswechsel; Status, Lageränderung und Protokoll
werden bei einem Storno in **derselben Transaktion** geschrieben. Alte
Bestellungen erscheinen als **Altbestand**, weil ihr bisheriger Status
nicht bekannt ist. Sie bleiben sichtbar, aber nicht stornierbar.

### Optional: Google-Anmeldung

1. Lege in der [Google Cloud Console](https://console.cloud.google.com/) einen
   OAuth-Client vom Typ **Webanwendung** an und konfiguriere den Zustimmungsbildschirm.
   Füge dein Testkonto hinzu, falls die Anwendung im Testmodus ist.
2. Hinterlege `http://localhost:8080/google.php` als autorisierte Weiterleitungs-URI.
   Verwende im Browser ebenfalls `localhost` und nicht `127.0.0.1`.
3. Kopiere `.env.example` nach `.env`, trage Client-ID und Secret **nur lokal**
   ein und starte Compose erneut. `.env` wird nicht mit Git oder dem Image geteilt.

Der Button **Mit Google anmelden** erscheint dann oberhalb der Passwort-Anmeldung und legt
beim ersten Login ein Konto an. Änderungen an Name oder E-Mail betreffen nur
das Shop-Profil. Google-Konten werden über ihre Google-Kennung erkannt und
nicht automatisch mit Passwortkonten gleicher E-Mail verknüpft.

### Öffentlich zugängliche Demo (nicht automatisch bereitgestellt)

`localhost` und `127.0.0.1` funktionieren nur auf dem eigenen Gerät. Ein
öffentlicher GitHub-Link zeigt den Code, führt aber kein PHP und PostgreSQL aus.
Für eine teilbare Demo brauchst du:

1. Einen Hostingdienst für einen PHP-Produktionsserver und eine **separate**
   PostgreSQL-Datenbank mit fiktiven Shopdaten und privaten Zugangsdaten.
   Die lokale Compose-Konfiguration und den PHP-Entwicklungsserver nicht ins
   Internet stellen. Vor Freigabe u. a. HTTPS, Secure-Session-Cookies,
   Login-Drosselung und den Umgang mit Kontodaten einrichten.
2. Einen eigenen OAuth-Webclient für die öffentliche Umgebung. Dessen
   Weiterleitungs-URI muss exakt der öffentlichen HTTPS-Adresse entsprechen
   (z. B. `https://shop.example.com/google.php`). ID, Secret und URI als
   Umgebungsvariablen beim Hostingdienst hinterlegen, niemals in Git.
3. In Google Auth Platform die Zielgruppe **External** und für einen allgemein
   zugänglichen Login den Status **In production** wählen. Googles Branding-,
   Datenschutz- und ggf. Verifizierungsanforderungen beachten. OAuth prüft
   keine Stadtzugehörigkeit: Wer den Link hat, kann die Demo aufrufen.

Siehe [Googles Hinweise zu Produktionsanwendungen](https://developers.google.com/identity/protocols/oauth2/production-readiness/policy-compliance).

## Wie es funktioniert

| Datei | Verantwortlichkeit |
| --- | --- |
| `public/index.php` | HTML-Ansicht, Formularverarbeitung, Session-Warenkorb und Aufruf des Checkouts |
| `public/archive.php` | Archivansicht, Filter nach Kundenprofil und Bestellnummer |
| `public/account.php` | Anmeldung, Registrierung und Profilverwaltung |
| `public/customers.php` | Mitarbeiteransicht zur Bearbeitung von Kundenkonten |
| `public/inventory.php` | Lagerbestand, Nachfüllung und Bewegungsprotokoll für Mitarbeitende |
| `src/navigation.php` | Gemeinsame Navigation für Gäste, Kunden und Mitarbeiter |
| `src/shop.php` | Bestelltransaktion und Preisformatierung |
| `src/inventory.php` | Port für den Bestandsabzug und PostgreSQL-Adapter |
| `src/customers.php` | SQL-Abfrage für das Bestellarchiv |
| `src/accounts.php` | Kundenkonten, Passworthashes und Sitzungen |
| `src/database.php` | PostgreSQL-Verbindung über PDO |
| `database/01-schema.sql` | Produkte, Bestellungen und Bestellpositionen mit Fremdschlüsseln und Constraints |
| `database/03-customers.sql` | Wiederholbare Migration für Profile und historische Namen |
| `database/04-accounts.sql` | Kundenkonten, Mitarbeiterrolle und Verknüpfung mit Bestellungen |
| `database/05-inventory.sql` | Lagerbewegungen für Verkäufe und Nachfüllungen |
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
