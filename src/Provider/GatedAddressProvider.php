<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Provider;

final readonly class GatedAddressProvider implements AddressProviderInterface
{
    public function __construct(
        private AddressProviderInterface $inner,
        private ProviderRequestGate $gate,
    ) {
    }

    public function verify(Address $address): ProviderResult
    {
        if (!$this->gate->acquire()) {
            return ProviderResult::unavailable();
        }

        try {
            return $this->inner->verify($address);
        } finally {
            $this->gate->release();
        }
    }
}
