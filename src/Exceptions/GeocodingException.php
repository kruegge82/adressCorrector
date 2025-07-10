<?php

namespace AddressCorrector\Exceptions;

class GeocodingException extends \Exception
{
    // Spezifische Fehlermeldungen für Geocoding-Fehler
    public const ERROR_INVALID_REQUEST = 'Ungültige Anfrage an den Geocoding-Service';
    public const ERROR_API_KEY = 'Ungültiger API-Schlüssel';
    public const ERROR_QUOTA_EXCEEDED = 'API-Quota überschritten';
    public const ERROR_NO_RESULTS = 'Keine Ergebnisse gefunden';
    
    public function __construct($message = "", $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
