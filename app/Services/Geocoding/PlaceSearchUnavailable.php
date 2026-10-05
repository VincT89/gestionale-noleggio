<?php

namespace App\Services\Geocoding;

class PlaceSearchUnavailable extends \RuntimeException
{
    public static function busy(): self
    {
        return new self('La ricerca dei luoghi è momentaneamente occupata. Attendi qualche secondo e riprova.');
    }
}
