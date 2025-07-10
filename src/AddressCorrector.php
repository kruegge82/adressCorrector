<?php

namespace AddressCorrector;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use AddressCorrector\Database\AddressDatabase;
use AddressCorrector\Services\GeocodingService;
use AddressCorrector\Validators\AddressValidator;
use AddressCorrector\Exceptions\AddressCorrectionException;

class AddressCorrector
{
    private $logger;
    private $database;
    private $geocodingService;
    private $validator;
    private $config;

    public function __construct(array $config = null)
    {
        $this->config = $config ?: require __DIR__ . '/../config.php';
        $this->initializeLogger();
        $this->database = new AddressDatabase($this->config['database'], $this->config['log']['path']);
        $this->geocodingService = new GeocodingService($this->config['geocoding_api']);
        $this->validator = new AddressValidator();
    }


    private function initializeLogger()
    {
        $this->logger = new Logger('address_corrector');
        $this->logger->pushHandler(new StreamHandler(
            $this->config['log']['path'],
            $this->config['log']['level']
        ));
    }

    /**
     * Korrigiert eine gegebene Adresse
     * @param string $address Die zu korrigierende Adresse
     * @return array Die korrigierte Adresse mit zusätzlichen Informationen
     * @throws AddressCorrectionException
     */
    public function correctAddress(string $address): array
    {
        if (!$this->validator->isValidInput($address)) {
            throw new AddressCorrectionException('Ungültige Adresseingabe');
        }

        $this->logger->info('Starte Adresskorrektur', ['input' => $address]);

        // Adresse parsen (z.B. Straße, Hausnummer, PLZ, Ort extrahieren)
        $components = $this->parseAddress($address);
        $components['original_address'] = $address;

        // 1. Ort normalisieren (z.B. Bindestrich-Orte kürzen)
        if (isset($components['city'])) {
            $components['city'] = $this->normalizeCityName($components['city']);
        }

        // 2. Ort korrigieren anhand PLZ und Datenbank
        if (isset($components['city']) && isset($components['postal_code'])) {
            $correctedCity = $this->database->findClosestCity($components['city'], $components['postal_code']);
            if ($correctedCity !== $components['city']) {
                $this->logger->info('Stadt korrigiert', [
                    'original' => $components['city'],
                    'corrected' => $correctedCity
                ]);
                $components['city'] = $correctedCity;
            }
        }

        // 3. Straße normalisieren (Abkürzungen etc.)
        if (isset($components['street'])) {
            $components['street'] = $this->normalizeStreetName($components['street']);
        }

        // 4. Hausnummer vorne erkennen und trennen
        if (isset($components['street'])) {
            $split = $this->splitStreetAndNumber($components['street']);
            $components['street'] = $split['street'];
            if (!empty($split['street_number'])) {
                $components['street_number'] = $split['street_number'];
            }
        }

        // 5. Straße korrigieren anhand PLZ und Datenbank
        if (isset($components['street']) && isset($components['postal_code'])) {
            $availableStreets = $this->database->findStreetsByPostalCode($components['postal_code']);
            if (!empty($availableStreets)) {
                $correctedStreet = $this->database->findSimilarStreet($components['street'], $availableStreets);
                if ($correctedStreet && $correctedStreet !== $components['street']) {
                    $originalStreet = $components['street'];
                    $components['street'] = $correctedStreet;
                    $this->logger->info('Straße korrigiert', [
                        'original' => $originalStreet,
                        'corrected' => $correctedStreet
                    ]);
                }
            }
        }

        // 6. PLZ und Ort validieren
        if (isset($components['postal_code']) && isset($components['city'])) {
            if (!$this->database->validatePostalCode($components['postal_code'], $components['city'])) {
                $this->logger->warning('PLZ passt nicht zur korrigierten Stadt', [
                    'city' => $components['city'],
                    'postal_code' => $components['postal_code']
                ]);
            }
        }

        // 7. Ortsteil (District) ermitteln, falls möglich
        if (isset($components['postal_code']) && isset($components['city'])) {
            $district = $this->database->findCityDistrict(
                $components['city'],
                $components['postal_code'],
                $components['street'] ?? null
            );
            if ($district) {
                $components['district'] = $district;
            }
        }

        // 8. Ergebnis zusammenbauen
        $result = $this->buildResult($components);

        $this->logger->info('Adresskorrektur erfolgreich', ['result' => $result]);

        return $result;
    }



    private function buildResult(array $components): array {
        $result = [
            'original' => $components['original_address'],
            'corrected' => '',
            'components' => [],
            'confidence_score' => isset($components['street']) ? 1.0 : 0.7
        ];

        // Komponenten für die Ausgabe aufbereiten
        foreach (['postal_code', 'street_number', 'street', 'city', 'district'] as $key) {
            if (isset($components[$key])) {
                $result['components'][$key] = $components[$key];
            }
        }

        // Korrigierte Adresse zusammenbauen
        $addressParts = [];
        if (isset($components['street'])) {
            $streetPart = $components['street'];
            if (isset($components['street_number'])) {
                $streetPart .= ' ' . $components['street_number'];
            }
            $addressParts[] = $streetPart;
        }

        if (isset($components['postal_code'])) {
            $locationPart = $components['postal_code'];
            if (isset($components['city'])) {
                $locationPart .= ' ' . $components['city'];
            }
            $addressParts[] = $locationPart;
        }

        $result['corrected'] = implode(', ', $addressParts);

        return $result;
    }

    private function parseAddress(string $address): array
    {
        $parts = [];

        // Versuche zuerst mit Komma zu splitten
        $addressParts = explode(',', $address);

        if (count($addressParts) >= 2) {
            // Straße und Hausnummer
            $streetPart = trim($addressParts[0]);
            if (preg_match('/\s*(\d+[a-z]?)\s*$/i', $streetPart, $matches)) {
                $parts['street_number'] = $matches[1];
                $parts['street'] = trim(str_replace($matches[0], '', $streetPart));
            } else {
                $parts['street'] = $streetPart;
            }

            // PLZ und Stadt
            $locationPart = trim($addressParts[1]);
            if (preg_match('/\b(\d{5})\b/', $locationPart, $matches)) {
                $parts['postal_code'] = $matches[1];
                $cityPart = trim(str_replace($matches[0], '', $locationPart));
                if ($cityPart) {
                    $parts['city'] = $cityPart;
                }
            }
        } else {
            // Kein Komma, versuche PLZ und Stadt zu extrahieren
            if (preg_match('/(\d{5})\s+(.+)$/', $address, $matches)) {
                $parts['postal_code'] = $matches[1];
                $parts['city'] = trim($matches[2]);

                // Straße und Hausnummer sind der Rest vor PLZ
                $streetPart = trim(str_replace($matches[0], '', $address));
                if (preg_match('/\s*(\d+[a-z]?)\s*$/i', $streetPart, $matchesStreet)) {
                    $parts['street_number'] = $matchesStreet[1];
                    $parts['street'] = trim(str_replace($matchesStreet[0], '', $streetPart));
                } else {
                    $parts['street'] = $streetPart;
                }
            } else {
                // Fallback: nur Straße (keine PLZ/Stadt)
                $parts['street'] = $address;
            }
        }

        return $parts;
    }
    public function correctAddressComponents(array $fields): array {
        // 1. Zusätze aus Straße extrahieren (z.B. Landgasthof etc.)
        $fields = $this->extractAddressAdditionFromStreet($fields);
        $fields = $this->extractAddressAddition($fields);
        // 2. Straße und Ort normalisieren (Abkürzungen, Bindestriche etc.)
        $fields = $this->normalizeAddressFields($fields);

        // 3. Prüfen, ob Straße in der Datenbank existiert
        if (empty($fields['street']) || !$this->database->streetExists($fields['street'], $fields['postal_code'])) {
            // Versuche Straße aus company oder address_addition zu extrahieren
            $fields = $this->tryExtractStreetFromOtherFields($fields);
        }

        // 4. Hausnummer vorne erkennen und trennen
        $split = $this->splitStreetAndNumber($fields['street']);
        $fields['street'] = $split['street'];
        if (!empty($split['street_number'])) {
            $fields['street_number'] = $split['street_number'];
        }

        // 5. Straße mit Datenbank abgleichen und ggf. korrigieren
        if (!empty($fields['street']) && !empty($fields['postal_code'])) {
            $availableStreets = $this->database->findStreetsByPostalCode($fields['postal_code']);
            if (!empty($availableStreets)) {
                $correctedStreet = $this->database->findSimilarStreet($fields['street'], $availableStreets);
                if ($correctedStreet && $correctedStreet !== $fields['street']) {
                    $fields['street'] = $correctedStreet;
                }
            }
        }

        // 6. PLZ und Ort validieren
        if (!empty($fields['postal_code']) && !empty($fields['city'])) {
            if (!$this->database->validatePostalCode($fields['postal_code'], $fields['city'])) {
                $this->logger->warning('PLZ passt nicht zur Stadt', [
                    'postal_code' => $fields['postal_code'],
                    'city' => $fields['city']
                ]);
            }
        }

        // 7. Ortsteil (District) ermitteln, falls möglich
        if (!empty($fields['postal_code']) && !empty($fields['city'])) {
            $district = $this->database->findCityDistrict(
                $fields['city'],
                $fields['postal_code'],
                $fields['street'] ?? null
            );
            if ($district) {
                $fields['district'] = $district;
            }
        }

        return $fields;
    }

    /**
     * Normalisiert Straße und Ort (z.B. Abkürzungen, Bindestriche)
     */
    public function normalizeAddressFields(array $fields): array
    {
        // Straße normalisieren
        if (!empty($fields['street'])) {
            $fields['street'] = $this->normalizeStreetName($fields['street']);
        }

        // Ort normalisieren
        if (!empty($fields['city'])) {
            $fields['city'] = $this->normalizeCityName($fields['city']);
        }

        return $fields;
    }

    /**
     * Extrahiert Zusätze aus der Straße in das Feld address_addition
     */
    public function extractAddressAddition(array $fields): array
    {
        if (empty($fields['street'])) {
            return $fields;
        }

        $additionKeywords = [
            'Landgasthof',
            'Hotel',
            'Praxis',
            'Haus',
            'Wohnanlage',
            'Appartement',
            'Ferienwohnung',
            'Bauernhof',
            'Gästehaus',
            'Pension',
            'Restaurant',
            'Gasthof',
            'Schloss',
            'Villa',
        ];

        $street = $fields['street'];

        // HTML-Entities und Anführungszeichen entfernen
        $street = html_entity_decode($street);
        $street = str_replace(['"', '“', '”', '„', '"'], '', $street);

        foreach ($additionKeywords as $keyword) {
            $pattern = '/\b' . preg_quote($keyword, '/') . '\b(.*)$/i';

            if (preg_match($pattern, $street, $matches)) {
                $addition = trim($keyword . ' ' . trim($matches[1]));
                $street = trim(preg_replace($pattern, '', $street));

                if (!empty($fields['address_addition'])) {
                    $fields['address_addition'] .= ', ' . $addition;
                } else {
                    $fields['address_addition'] = $addition;
                }
                break;
            }
        }

        $fields['street'] = $street;

        return $fields;
    }


    /**
     * Normalisiert Straßennamen (Abkürzungen etc.)
     */
    private function normalizeStreetName(string $street): string
    {

        $patterns = [
            '/straße\b/i',
            '/strasse\b/i',
            '/str(?!\.)\b/iu',
            '/pl\.\b/i',
        ];

        $replacements = [
            'str.',
            'str.',
            'str.',
            'platz',
        ];

        return preg_replace($patterns, $replacements, $street);
    }

    /**
     * Normalisiert Ortsnamen (z.B. Bindestrich-Orte kürzen)
     */
    private function normalizeCityName(string $city): string
    {
        if (strpos($city, '-') !== false) {
            $parts = explode('-', $city);
            return trim($parts[0]);
        }
        return $city;
    }
    /**
     * Trennt Zusatz vom Straßennamen ab, wenn Zusatz vorne steht.
     * Beispiel:
     *  "Dieter Strödicke Pielstraße 8"
     * wird zu
     *  street: "Pielstraße 8"
     *  address_addition: "Dieter Strödicke"
     */
    public function extractAddressAdditionFromStreet(array $fields): array
    {
        if (empty($fields['street'])) {
            return $fields;
        }

        $streetIndicators = ['straße', 'str.', 'str', 'weg', 'platz', 'allee', 'ring', 'gasse', 'ufer', 'chaussee'];

        $words = preg_split('/\s+/u', trim($fields['street']));

        // Finde Hausnummerindex (Zahl mit optionalem Buchstaben)
        $houseNumberIndex = null;
        for ($i = count($words) - 1; $i >= 0; $i--) {
            if (preg_match('/^\d+[a-zA-Z]?$/', $words[$i])) {
                $houseNumberIndex = $i;
                break;
            }
        }

        if ($houseNumberIndex === null) {
            // Keine Hausnummer gefunden, nichts ändern
            return $fields;
        }

        // Suche von links nach rechts das erste Wort mit Straßensuffix
        $streetNameEndIndex = null;
        for ($i = 0; $i < $houseNumberIndex; $i++) {
            $wordLower = mb_strtolower($words[$i]);
            foreach ($streetIndicators as $indicator) {
                if (mb_strpos($wordLower, $indicator) !== false) {
                    $streetNameEndIndex = $i;
                    break 2;
                }
            }
        }

        if ($streetNameEndIndex === null) {
            // Kein Straßensuffix gefunden, nichts ändern
            return $fields;
        }

        // Straße: alle Wörter von Anfang bis zum Straßensuffix (inklusive)
        $streetParts = array_slice($words, 0, $streetNameEndIndex + 1);

        // Hausnummer (und evtl. weitere Wörter) danach
        $houseNumberParts = array_slice($words, $houseNumberIndex);

        // Straße zusammensetzen (Straßenname + Hausnummer)
        $street = implode(' ', array_merge($streetParts, $houseNumberParts));

        // Zusatz: alle Wörter zwischen Straßenname und Hausnummer (falls welche)
        $additionParts = array_slice($words, $streetNameEndIndex + 1, $houseNumberIndex - $streetNameEndIndex - 1);
        $addition = implode(' ', $additionParts);

        // Setze Felder
        $fields['street'] = $street;

        if (!empty($fields['address_addition'])) {
            $fields['address_addition'] .= ($addition ? ', ' . $addition : '');
        } else {
            $fields['address_addition'] = $addition;
        }

        return $fields;
    }



    private function tryExtractStreetFromOtherFields(array $fields): array
    {
        $possibleFields = ['company', 'address_addition'];

        foreach ($possibleFields as $field) {
            if (!empty($fields[$field])) {
                // Hausnummer trennen
                $split = $this->splitStreetAndNumber($fields[$field]);
                $candidateStreet = $split['street'];

                // Straße normalisieren
                $candidateStreetNormalized = $this->normalizeStreetName($candidateStreet);

                // Straßen aus DB holen
                $streetsInDb = $this->database->findStreetsByPostalCode($fields['postal_code']);

                foreach ($streetsInDb as $knownStreet) {
                    $knownStreetNormalized = $this->normalizeStreetName($knownStreet);

                    if (mb_stripos($candidateStreetNormalized, $knownStreetNormalized) !== false) {
                        // Straße gefunden
                        $toreplace=$fields[$field];
                        if ($field === 'company') {
                            // Tausch: alter street-Wert in company, neue Straße in street
                            $oldStreet = $fields['street'] ?? '';
                            if(is_numeric($fields['street'])) {
                                $fields['street'] = $knownStreet . " " . $fields['street'];
                                $fields['company']='';
                            } else {
                                $fields['street'] = $knownStreet;
                                // Entferne den Straßennamen aus company
                                $newCompany = str_ireplace($toreplace, '', $fields['company']);
                                // Füge alten street-Wert an company an, falls vorhanden
                                $fields['company'] = trim(($oldStreet ? $oldStreet . ' ' : '') . $newCompany);
                            }

                        } else {
                            // Feld ist address_addition: alten street-Wert anhängen
                            if (!empty($fields['street'])) {
                                if (!empty($fields['address_addition'])) {
                                    $fields['address_addition'] .= ', ' . $fields['street'];
                                } else {
                                    $fields['address_addition'] = $fields['street'];
                                }
                            }
                            $fields['street'] = $knownStreet;

                            // Entferne den Straßennamen aus address_addition
                            $fields['address_addition'] = trim(str_ireplace($toreplace, '', $fields['address_addition']));
                        }

                        // Hausnummer übernehmen, falls noch nicht gesetzt
                        if (!empty($split['street_number']) && empty($fields['street_number'])) {
                            $fields['street_number'] = $split['street_number'];
                        }

                        return $fields;
                    }
                }
            }
        }
        return $fields;
    }


    /**
     * Trennt Hausnummer von der Straße.
     * Unterstützt Formate wie:
     * - "Musterstraße 12a"
     * - "12a Musterstraße"
     *
     * @param string $street
     * @return array ['street' => string, 'street_number' => string]
     */
    public function splitStreetAndNumber(string $street): array
    {
        $street = trim($street);

        // 1. Prüfe, ob Hausnummer vorne steht (z.B. "12a Musterstraße")
        if (preg_match('/^(\d+[a-zA-Z]?)[\s,]+(.+)$/u', $street, $matches)) {
            return [
                'street_number' => $matches[1],
                'street' => trim($matches[2])
            ];
        }

        // 2. Prüfe, ob Hausnummer hinten steht (z.B. "Musterstraße 12a")
        if (preg_match('/^(.+?)[\s,]+(\d+[a-zA-Z]?)$/u', $street, $matches)) {
            return [
                'street' => trim($matches[1]),
                'street_number' => $matches[2]
            ];
        }

        // 3. Kein Hausnummer gefunden, Straße bleibt unverändert
        return [
            'street' => $street,
            'street_number' => ''
        ];
    }


}
