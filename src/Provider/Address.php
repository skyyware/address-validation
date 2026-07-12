<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Provider;

final readonly class Address
{
    public function __construct(
        public string $street,
        public string $city,
        public string $postalCode,
        public string $countryCode,
    ) {
    }
}
