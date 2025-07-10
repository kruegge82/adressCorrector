<?php
require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

use AddressCorrector\AddressCorrector;

$corrector = new AddressCorrector($config);
$fields = [
    'company' => $_REQUEST['company'],
    'street' => $_REQUEST['street'],
    'street_number' => $_REQUEST['street_number'],
    'address_addition' => $_REQUEST['additional_info'],
    'postal_code' => $_REQUEST['zip_code'],
    'city' => $_REQUEST['city']
];
$corrected = $corrector->correctAddressComponents($fields);
print_r($corrected);
