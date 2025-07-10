<?php
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
        'host' => '',
        'dbname' => '',
        'user' => '',
        'password' => '',
        'charset' => ''
    ]
];