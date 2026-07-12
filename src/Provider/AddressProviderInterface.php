<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Provider;

interface AddressProviderInterface
{
    public function verify(Address $address): ProviderResult;
}
