<?php

namespace AddressCorrector\Validators;

class AddressValidator
{
    public function isValidInput($address)
    {
        return !empty($address) && is_string($address) && strlen($address) >= 5;
    }

    public function validatePostalCode($postalCode)
    {
        return preg_match('/^[0-9]{5}$/', $postalCode) === 1;
    }
}
