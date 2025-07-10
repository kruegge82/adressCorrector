<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AddressCorrector\AddressCorrector;
use AddressCorrector\Exceptions\AddressCorrectionException;

header('Content-Type: application/json; charset=utf-8');

try {
    $config = require __DIR__ . '/../config.php';
    $corrector = new AddressCorrector($config);

    // Adresse aus POST oder GET lesen
    $address = [
        'company' => $_POST['company'] ?? '',
        'street' => $_POST['street'] ?? '',
        'street_number' => $_POST['street_number'] ?? '',
        'address_addition' => $_POST['additional_info'] ?? '',
        'postal_code' => $_POST['zip_code'] ?? '',
        'city' => $_POST['city'] ?? ''
    ];

    if (!$address) {
        http_response_code(400);
        echo json_encode(['error' => 'Parameter "address" fehlt']);
        exit;
    }

    $result = $corrector->correctAddressComponents($address);

    echo json_encode(['success' => true, 'data' => $result]);

} catch (AddressCorrectionException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Interner Serverfehler']);
}
