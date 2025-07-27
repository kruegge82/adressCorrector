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

    public function __construct(?array $config = null)
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


    public function correctAddressComponents(array $fields): array {

        $confidence = 1.0;

        // Speichere ursprüngliche Straße
        if (isset($fields['street'])) {
            $tmp_street = $fields['street'];
        }

        // 1. Zusätze aus Straße extrahieren (z.B. Landgasthof etc.)
        $fields = $this->extractAddressAdditionFromStreet($fields);
        $fields = $this->extractAddressAddition($fields);
        // 2. Straße und Ort normalisieren (Abkürzungen, Bindestriche etc.)
        $fields = $this->normalizeAddressFields($fields);

        // 3. Prüfen, ob Straße in der Datenbank existiert
        if(!$this->database->streetExists($fields['street'], $fields['postal_code'])) {
            $allStreets = $this->database->findStreetsByPostalCode($fields['postal_code']); // bereits vorhandene Methode
            $bestStreet = $this->findBestMatchingStreet($fields['street'], $allStreets);
            if ($bestStreet !== null) {
                $fields['street_number']=trim(str_replace($bestStreet,"",$fields['street']));
                $fields['street']=$bestStreet;
            } else {
                // Versuche Straße aus company oder address_addition zu extrahieren
                $fields = $this->tryExtractStreetFromOtherFields($fields);
                $confidence -= 0.1; // Korrektur aus anderen Feldern reduziert Vertrauen
            }
        }

        // 4. Hausnummer vorne erkennen und trennen
        $split = $this->splitStreetAndNumber($fields['street']);
        $fields['street'] = $split['street'];
        if (!empty($split['street_number'])) {
            $fields['street_number'] = $split['street_number'];
        }
        // Haunsummer aus address_addition extrahieren wenn Sie noch leer ist
        if (empty($fields['street_number']) && preg_match('/\d{1,5}\s*[a-zA-Z]?([\/\-]\s*\d{1,5}\s*[a-zA-Z]?)?/', $fields['address_addition'], $matches)) {
            $fields['street_number']=trim($matches[0]); //enthält die gefundene Hausnummer
            $fields['address_addition']=str_replace($matches[0],"",$fields['address_addition']);
        }


        // 5. Straße korrigieren anhand PLZ und Datenbank
        if (isset($fields['street']) && isset($fields['postal_code'])) {
            $availableStreets = $this->database->findStreetsByPostalCode($fields['postal_code']);
            if (!empty($availableStreets)) {
                // Prüfe, ob der ursprüngliche Name in der Datenbank als veraltet markiert ist
                $originalStreet = $fields['street'];
                $correctedStreet = $originalStreet;

                // Hole alle Straßen mit PLZ
                $streetsWithDetails = $this->database->getStreetsWithDetails($fields['postal_code']);

                foreach ($streetsWithDetails as $street) {
                    $currentName = $street['NAME46'];
                    $oldName = $street['OLD_NAME'] ?? null;

                    // Wenn der ursprüngliche Name veraltet ist, ersetze durch neuen Namen
                    if ($oldName && mb_strtolower($oldName) === mb_strtolower($originalStreet) && mb_strtolower($oldName)!=mb_strtolower($street['NAME46'])) {
                        $correctedStreet = $currentName;
                        $fields['original_street']=$tmp_street;
                        $confidence += 0.1; // Strasse wurde ersetzt deshalb setzen wir den abzug wieder plus
                        break;
                    }
                }

                // Setze korrigierten Namen
                if ($correctedStreet !== $originalStreet) {
                    $fields['street'] = $correctedStreet;
                    $this->logger->info('Straße ersetzt durch neuen Namen', [
                        'original' => $originalStreet,
                        'corrected' => $correctedStreet
                    ]);
                } else {
                    // Fallback: Suche nach ähnlichen Straßen
                    $correctedStreet = $this->database->findSimilarStreet($originalStreet, $availableStreets);
                    if ($correctedStreet && $correctedStreet !== $originalStreet) {
                        $fields['street'] = $correctedStreet;
                        $this->logger->info('Straße korrigiert', [
                            'original' => $originalStreet,
                            'corrected' => $correctedStreet
                        ]);

                        // Ähnlichkeitsscore für Straße berechnen
                        $streetSimilarity = $this->database->calculateStreetSimilarity($originalStreet, $correctedStreet);
                        $confidence = max(0.0, $confidence - (1.0 - $streetSimilarity) * 0.3);

                    }
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
                $confidence -= 0.2; // PLZ-Stadt-Abweichung reduziert Vertrauen
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
            } else {
                $confidence -= 0.1; // Kein Ortsteil gefunden
            }
        }
        $fields['confidence_score'] = $confidence;

        return $fields;
    }

    private function removeExactDuplicate($text) {
        $text = trim(preg_replace('/\s+/', ' ', $text)); // Mehrfache Leerzeichen entfernen
        $words = explode(' ', $text);
        $count = count($words);

        if ($count % 2 === 0 && $count > 0) {
            $half = $count / 2;
            $first = implode(' ', array_slice($words, 0, $half));
            $second = implode(' ', array_slice($words, $half));
            if ($first === $second) {
                return $first;
            }
        }
        return $text;
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

    private function findBestMatchingStreet(string $inputStreet, array $databaseStreets): ?string
    {
        $inputVariants = $this->generateStreetVariants($inputStreet);
        $bestMatch = null;
        $bestScore = 0;
        foreach ($databaseStreets as $dbStreet) {
            foreach ($inputVariants as $inputVariant) {
                if($inputVariant===$dbStreet) {
                    return 1;
                }
                $score = $this->database->calculateComplexStreetSimilarity($inputVariant, $dbStreet);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $dbStreet;
                }
            }
        }

        return ($bestScore >= 0.7) ? $bestMatch : null; // Threshold anpassen
    }
    private function generateStreetVariants(string $input): array
    {
        $abbreviationMap = [
            'Sr.' => 'Schwester',
            'Bgm.' => 'Bürgermeister',
            'Dr.' => 'Doktor',
            'Fr.' => 'Franziskaner',
            'Prof.' => 'Professor'
        ];

        //$input = strtolower($input);
        //$input = str_replace(['-', '.', ','], ' ', $input);
        $words = explode(' ', $input);

        $variants = [$input]; // Original als erste Variante

        // Erzeuge Variante mit ersetzten Abkürzungen
        $expanded = $words;
        foreach ($expanded as $i => $word) {
            $expanded2=array();
            foreach ($abbreviationMap as $search => $replace) {
                $expanded2[] = str_replace($search,$replace,$words);
            }
        }
        foreach($expanded2 as $i => $details)
        $variants[] = implode(' ', $details);

        // Erzeuge Variante mit gekürzten Formen (Langform → Abkürzung)
        $reversedMap = array_flip($abbreviationMap);
        $contracted = $words;
        foreach ($contracted as $i => $word) {
            $expanded2=array();
            foreach ($abbreviationMap as $search => $replace) {
                $expanded2[] = str_replace($search,$replace,$words);
            }
        }
        foreach($expanded2 as $i => $details)
            $variants[] = implode(' ', $details);

        return array_unique($variants);
    }

    /**
     * Normalisiert Straßennamen (Abkürzungen etc.)
     */
    private function normalizeStreetName(string $street): string
    {
        $street= $this->removeExactDuplicate($street); // Gibt "Steinbrink 6" aus

        $patterns = [
            '/straße\b/i',
            '/strasse\b/i',
            '/str(?!\.)\b/iu',
            '/ str(?!\.)\b/iu',
            '/pl\.\b/i',
        ];

        $replacements = [
            'str.',
            'str.',
            'str.',
            ' str.',
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

                    if ($candidateStreetNormalized === $knownStreetNormalized) {
                        // Straße gefunden
                        error_log($knownStreetNormalized);
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
