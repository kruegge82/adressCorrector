<?php

namespace AddressCorrector\Database;

use PDO;
use PDOException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class AddressDatabase
{
    private $pdo;
    private $logger;
    private $config;
    private $logPath;

    public function __construct(array $config, string $logPath = null)
    {
        $this->config = $config;
        $this->logPath = $logPath ?: __DIR__ . '/../../logs/address_corrector.log';
        $this->initializeLogger();
        $this->connectDatabase();
    }

    private function initializeLogger()
    {
        $this->logger = new Logger('address_database');
        $this->logger->pushHandler(new StreamHandler(
            $this->logPath,
            Logger::INFO
        ));
    }

    private function connectDatabase()
    {
        try {
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=%s",
                $this->config['host'],
                $this->config['dbname'],
                $this->config['charset']
            );

            $this->pdo = new PDO(
                $dsn,
                $this->config['user'],
                $this->config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            $this->logger->error('Datenbankverbindungsfehler', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }


    public function findClosestCity(string $cityName, string $plz): string
    {
        try {
            $this->logger->info('Suche ähnliche Stadt', ['input' => $cityName]);

            $plz=ltrim($plz, '0');
            // Erst nach exakter Übereinstimmung suchen
            $query = "SELECT ONAME FROM `DHL-ORT-DA` WHERE ONAME = :city";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([':city' => $cityName]);
            $exactMatch = $stmt->fetch(PDO::FETCH_COLUMN);

            if ($exactMatch) {
                $this->logger->info('Exakte Übereinstimmung gefunden', ['city' => $exactMatch]);
                return $exactMatch;
            }

            // Wenn keine exakte Übereinstimmung, suche nach ähnlichen Städten
            $query = "SELECT ONAME, 
                 CASE 
                    WHEN ONAME = ? THEN 100
                    WHEN ONAME LIKE CONCAT(?, '%') THEN 90
                    WHEN ONAME LIKE CONCAT('%', ?) THEN 80
                    WHEN ONAME LIKE CONCAT('%', ?, '%') THEN 70
                    ELSE 0
                 END as similarity
                 FROM `DHL-PLZ-DA` 
                 WHERE (ONAME LIKE CONCAT('%', ?, '%')
                 OR SOUNDEX(ONAME) = SOUNDEX(?)) AND PLZ = ?
                 ORDER BY similarity DESC, LENGTH(ONAME) ASC
                 LIMIT 1";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$cityName,$cityName,$cityName,$cityName,$cityName,$cityName,$plz]);
            $result = $stmt->fetch(PDO::FETCH_COLUMN);

            if ($result) {
                $this->logger->info('Ähnliche Stadt gefunden', [
                    'original' => $cityName,
                    'corrected' => $result
                ]);

                return $result;
            }

            // Wenn keine Ähnlichkeit gefunden wurde, versuche es mit dem Präfix
            $normalized = preg_replace('/[^a-zA-ZäöüßÄÖÜ]/', '', $cityName);
            $query = "SELECT ONAME 
                 FROM `DHL-ORT-DA`  
                 WHERE ONAME LIKE :prefix
                 ORDER BY LENGTH(ONAME) ASC
                 LIMIT 1";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute([':prefix' => $normalized . '%']);
            $prefixMatch = $stmt->fetch(PDO::FETCH_COLUMN);

            if ($prefixMatch) {
                $this->logger->info('Stadt durch Präfix gefunden', [
                    'original' => $cityName,
                    'corrected' => $prefixMatch
                ]);
                return $prefixMatch;
            }

            // Wenn immer noch nichts gefunden wurde, gib den ursprünglichen Namen zurück
            $this->logger->warning('Keine ähnliche Stadt gefunden', ['city' => $cityName]);
            return $cityName;

        } catch (PDOException $e) {
            $this->logger->error('Fehler bei der Stadtsuche', [
                'error' => $e->getMessage(),
                'city' => $cityName
            ]);
            return $cityName;
        }
    }


    public function validatePostalCode($postalCode, $city)
    {
        // Führende Nullen entfernen
        $formattedPostalCode = ltrim($postalCode, '0');

        $baseName = preg_split('/\s+(?:am|an|im|auf|bei|in)\s+/i', $city)[0];

        $sql = "SELECT COUNT(*) 
            FROM `DHL-PLZ-DA` p 
            JOIN `DHL-ORT-DA` o ON p.ALORT = o.ALORT 
            WHERE p.PLZ = :postalCode 
            AND (o.ONAME = :city 
                OR o.ONAME LIKE :startPattern 
                OR SOUNDEX(o.ONAME) = SOUNDEX(:baseName))";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':postalCode' => $formattedPostalCode,
            ':city' => $city,
            ':startPattern' => $baseName . '%',
            ':baseName' => $baseName
        ]);

        return $stmt->fetchColumn() > 0;
    }

    public function findCityDistrict(string $city, string $postalCode, ?string $street = null): ?string
    {
        try {
            // Führende Nullen entfernen
            $formattedPostalCode = ltrim($postalCode, '0');

            // Wenn eine Straße angegeben wurde, suchen wir nach dem Ortsteil basierend auf der Straße
            if ($street !== null) {
                $query = "SELECT DISTINCT o.`NAME` 
                FROM `DHL-OTL-DB` o 
                JOIN `DHL-STRA-DB` s ON o.`PLZ` = s.`PLZ`
                WHERE s.`NAME46` = :street 
                AND s.`PLZ` = :postalCode 
                AND s.`OTL-SCHL` = o.`SCHL` 
                ORDER BY o.`VERSION` DESC
                LIMIT 1";

                $stmt = $this->pdo->prepare($query);
                $stmt->execute([
                    ':street' => $street,
                    ':postalCode' => $formattedPostalCode
                ]);

                $result = $stmt->fetch(PDO::FETCH_COLUMN);
                if ($result) {
                    return $result;
                }
            }

            // Fallback: Suche nur nach PLZ
            $query = "SELECT DISTINCT o.`NAME` 
            FROM `DHL-OTL-DB` o 
            WHERE o.`PLZ` = :postalCode 
            AND o.`STATUS` = 'A'
            ORDER BY o.`VERSION` DESC
            LIMIT 1";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                ':postalCode' => $formattedPostalCode
            ]);

            return $stmt->fetch(PDO::FETCH_COLUMN) ?: null;

        } catch (PDOException $e) {
            $this->logger->error('Fehler beim Abrufen des Ortsteils', [
                'error' => $e->getMessage(),
                'city' => $city,
                'postal_code' => $postalCode,
                'street' => $street
            ]);
            return null;
        }
    }

    public function findStreetsByPostalCode($postalCode)
    {
        // Führende Nullen entfernen
        $formattedPostalCode = ltrim($postalCode, '0');

        $sql = "SELECT DISTINCT NAME46 
            FROM `DHL-STRA-DB` 
            WHERE PLZ = :postalCode";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':postalCode' => $formattedPostalCode]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }


    public function findSimilarStreet($streetName, array $availableStreets)
    {
        if (empty($availableStreets)) {
            return null;
        }

        $bestMatch = null;
        $highestScore = 0;

        foreach ($availableStreets as $street) {
            // Berechne verschiedene Ähnlichkeitsmetriken
            $score = $this->calculateComplexStreetSimilarity($streetName, $street);

            if ($score > $highestScore) {
                $highestScore = $score;
                $bestMatch = $street;
            }
        }

        // Wenn der Score hoch genug ist (über 70%), verwende den gefundenen Match
        return ($highestScore > 0.7) ? $bestMatch : $streetName;
    }

    private function calculateComplexStreetSimilarity($str1, $str2): float
    {

        // 1. Exakte Übereinstimmung
        if ($str1 === $str2) {
            return 1.0;
        }

        // 2. Levenshtein-Distanz (30% Gewichtung)
        $maxLen = max(strlen($str1), strlen($str2));
        $levenScore = 1 - (levenshtein($str1, $str2) / $maxLen);

        // 3. Gemeinsame Buchstaben am Anfang (40% Gewichtung)
        $prefixLength = 0;
        $minLen = min(strlen($str1), strlen($str2));
        for ($i = 0; $i < $minLen; $i++) {
            if ($str1[$i] === $str2[$i]) {
                $prefixLength++;
            } else {
                break;
            }
        }
        $prefixScore = $prefixLength / $minLen;

        // 4. Längenverhältnis (30% Gewichtung)
        $lengthDiff = abs(strlen($str1) - strlen($str2));
        $lengthScore = 1 - ($lengthDiff / max(strlen($str1), strlen($str2)));

        // Gewichtete Gesamtpunktzahl
        $finalScore = ($levenScore * 0.3) + ($prefixScore * 0.4) + ($lengthScore * 0.3);

        return $finalScore;
    }

    public function streetExists(string $streetName, string $postalCode): bool
    {
        $streets = $this->findStreetsByPostalCode($postalCode);
        foreach ($streets as $street) {
            if (mb_strtolower($street) === mb_strtolower($streetName)) {
                return true;
            }
        }
        return false;
    }
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }
}
