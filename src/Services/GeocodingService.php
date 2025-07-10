<?php

namespace AddressCorrector\Services;

use AddressCorrector\Exceptions\GeocodingException;

class GeocodingService
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function validate($addressParts)
    {
        // Mock-Response zurückgeben
        return [
            'status' => 'OK',
            'results' => [
                [
                    'formatted_address' => $this->formatAddress($addressParts),
                    'geometry' => [
                        'location' => [
                            'lat' => 0,
                            'lng' => 0
                        ]
                    ]
                ]
            ]
        ];
    }

    private function formatAddress($parts)
    {
        $address = '';
        if (isset($parts['street'])) {
            $address .= $parts['street'];
            if (isset($parts['street_number'])) {
                $address .= ' ' . $parts['street_number'];
            }
        }
        if (isset($parts['postal_code']) || isset($parts['city'])) {
            $address .= ', ';
            if (isset($parts['postal_code'])) {
                $address .= $parts['postal_code'] . ' ';
            }
            if (isset($parts['city'])) {
                $address .= $parts['city'];
            }
        }
        return $address;
    }


    private function formatAddressForGeocoding(array $parts)
    {
        return implode(' ', $parts);
    }

    private function processGeocodingResult(array $data)
    {
        if (empty($data['results'])) {
            return null;
        }

        $result = $data['results'][0];
        return [
            'formatted_address' => $result['formatted_address'],
            'location' => $result['geometry']['location'],
            'status' => $data['status']
        ];
    }
}
