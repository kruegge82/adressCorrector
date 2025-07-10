# AddressCorrector

Eine KI-gestützte Adresskorrektur für deutsche Adressen.  
Das Projekt korrigiert und validiert Adressen basierend auf einer Datenbank mit Postleitzahlen, Städten und Straßen.

---

## Features

- Korrektur von Städtenamen basierend auf PLZ
- Korrektur von Straßennamen mit Ähnlichkeitssuche
- Validierung von Postleitzahlen und Städten
- Erkennung von Ortsteilen (Distrikten)
- Logging der Korrekturprozesse
- Erweiterbar mit Geocoding-Service (Mock-Implementierung vorhanden)

---

## Voraussetzungen

- PHP 8.2 oder höher
- MySQL-Datenbank mit den Tabellen `DHL-ORT-DA`, `DHL-PLZ-DA`, `DHL-STRA-DB`, `DHL-OTL-DB`
- Daten befinden sich in sample.sql
- Composer
- Erweiterung `ext-pdo`
- Schreibrechte für das Log-Verzeichnis

---

## Installation

1. Repository klonen:

```bash
git clone https://github.com/kruegge82/address-corrector.git
cd address-corrector
```

2. Abhängigkeiten installieren:

```bash
composer install
```

3. Konfiguration anpassen:

- Öffne `config.php` und passe die Datenbankzugangsdaten, API-Key und Log-Pfad an.

4. Logs-Verzeichnis erstellen (falls nicht vorhanden):

```bash
mkdir logs
chmod 775 logs
```

---

## Nutzung

Ein Beispielskript `AddressCorrectionExample.php` zeigt, wie die Adresskorrektur verwendet wird:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

use AddressCorrector\AddressCorrector;

$corrector = new AddressCorrector($config);
$fields = [
    'company' => $_REQUEST['company'],
    'street' => $_REQUEST['street'],
    'street_number' => $_REQUEST['housenumber'],
    'address_addition' => $_REQUEST['additional_info'],
    'postal_code' => $_REQUEST['zip_code'],
    'city' => $_REQUEST['city']
];
$corrected = $corrector->correctAddressComponents($fields);

```

---

## Konfiguration

Die `config.php` enthält folgende Einstellungen:

```php
return [
    'geocoding_api' => [
        'url' => 'https://maps.googleapis.com/maps/api/geocode/json',
        'key' => 'YOUR_API_KEY'
    ],
    'log' => [
        'path' => __DIR__ . '/logs/address_corrector.log',
        'level' => \Monolog\Logger::INFO
    ],
    'database' => [
        'host' => 'dein-db-host',
        'dbname' => 'dein-db-name',
        'user' => 'dein-db-benutzer',
        'password' => 'dein-db-passwort',
        'charset' => 'utf8mb4'
    ]
];
```

---

## Logging

- Logs werden im in der Konfiguration angegebenen Pfad gespeichert.
- Log-Level kann angepasst werden (z.B. DEBUG, INFO, WARNING, ERROR).

---

## Tests

Derzeit sind keine automatisierten Tests enthalten.  
Es wird empfohlen, Unit-Tests für die Kernklassen zu implementieren.

---

## Lizenz

Dieses Projekt ist unter der MIT-Lizenz lizenziert.  
Siehe [LICENSE](LICENSE) für Details.

---

## Kontakt

Bei Fragen oder Problemen öffne bitte ein Issue oder kontaktiere den Entwickler.

---

*Viel Erfolg bei der Adresskorrektur!*
# adressCorrector
