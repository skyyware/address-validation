<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Skyyware\SkyyAddressValidation\Config\AddressValidationConfig;
use Skyyware\SkyyAddressValidation\Provider\Address;
use Skyyware\SkyyAddressValidation\Provider\AddressProviderInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

class RemoteAddressValidationService
{
    /**
     * @param EntityRepository<CountryCollection> $countryRepository
     */
    public function __construct(
        private readonly AddressProviderInterface $provider,
        private readonly AddressValidationConfig $config,
        private readonly EntityRepository $countryRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $output
     *
     * @return array<string, mixed>
     */
    public function validateAndNormalize(
        DataBag $input,
        array $output,
        Context $context,
        string $propertyPath,
    ): array {
        if (!$this->config->isProviderEnabled() || !$this->isComplete($output)) {
            return $output;
        }

        $country = $this->countryRepository
            ->search(new Criteria([(string) $output['countryId']]), $context)
            ->first();
        if (!$country instanceof CountryEntity || $country->getIso() === null || trim($country->getIso()) === '') {
            $this->logger->notice('skyy_address_validation.country_unavailable');

            return $output;
        }

        $address = new Address(
            trim((string) $output['street']),
            trim((string) $output['city']),
            trim((string) $output['zipcode']),
            strtoupper(trim($country->getIso())),
        );
        $result = $this->provider->verify($address);
        if ($result->isUnavailable()) {
            $this->logger->notice('skyy_address_validation.verification_unavailable');

            return $output;
        }

        if ($result->isRejected()) {
            $this->throwRejectedAddress($input, $address, $result->suggestion(), $propertyPath);
        }

        $normalized = $result->normalizedAddress();
        if (!$this->config->shouldNormalizeAddresses() || $normalized === null) {
            return $output;
        }

        $output['street'] = $normalized->street;
        $output['city'] = $normalized->city;
        $output['zipcode'] = $normalized->postalCode;

        return $output;
    }

    /**
     * @param array<string, mixed> $output
     */
    private function isComplete(array $output): bool
    {
        foreach (['street', 'city', 'zipcode', 'countryId'] as $field) {
            if (!\is_string($output[$field] ?? null) || trim($output[$field]) === '') {
                return false;
            }
        }

        return true;
    }

    private function throwRejectedAddress(
        DataBag $input,
        Address $address,
        ?Address $suggestion,
        string $propertyPath,
    ): never {
        $parameters = [];
        if ($suggestion !== null) {
            $parameters['{{ suggestion }}'] = \sprintf(
                '%s, %s %s, %s',
                $suggestion->street,
                $suggestion->postalCode,
                $suggestion->city,
                $suggestion->countryCode,
            );
        }

        $violation = new ConstraintViolation(
            'VIOLATION::SKYY_ADDRESS_NOT_VERIFIED',
            'VIOLATION::SKYY_ADDRESS_NOT_VERIFIED',
            $parameters,
            $input->all(),
            $propertyPath,
            $address->street,
            null,
            'SKYY_ADDRESS_NOT_VERIFIED',
        );

        throw new ConstraintViolationException(new ConstraintViolationList([$violation]), $input->all());
    }
}
