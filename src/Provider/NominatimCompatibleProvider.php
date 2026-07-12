<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Provider;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Skyyware\SkyyAddressValidation\Config\AddressValidationConfig;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class NominatimCompatibleProvider implements AddressProviderInterface
{
    private const RESULT_LIMIT = 5;

    public function __construct(
        private HttpClientInterface $httpClient,
        private AddressValidationConfig $config,
        private LoggerInterface $logger,
        private float $timeoutSeconds = 2.0,
    ) {
    }

    public function verify(Address $address): ProviderResult
    {
        try {
            $configuration = $this->config->providerConfiguration();
        } catch (InvalidArgumentException) {
            $this->logger->warning('skyy_address_validation.provider_configuration_invalid');

            return ProviderResult::unavailable();
        }

        if ($configuration === null) {
            return ProviderResult::unavailable();
        }

        try {
            $response = $this->httpClient->request('GET', $configuration['endpoint'], [
                'query' => [
                    'street' => $address->street,
                    'city' => $address->city,
                    'postalcode' => $address->postalCode,
                    'countrycodes' => strtolower($address->countryCode),
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => self::RESULT_LIMIT,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => $configuration['userAgent'],
                    'From' => $configuration['contactEmail'],
                ],
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
                'max_redirects' => 0,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning(
                    'skyy_address_validation.provider_http_failure',
                    ['status_code' => $statusCode],
                );

                return ProviderResult::unavailable();
            }

            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface) {
            $this->logger->warning('skyy_address_validation.provider_unavailable');

            return ProviderResult::unavailable();
        }

        if (!array_is_list($payload)) {
            $this->logger->warning('skyy_address_validation.provider_malformed_response');

            return ProviderResult::unavailable();
        }

        if ($payload === []) {
            return ProviderResult::rejected(null);
        }

        $candidates = [];
        foreach (\array_slice($payload, 0, self::RESULT_LIMIT) as $item) {
            $candidate = $this->candidateFromPayload($item);
            if ($candidate === null) {
                continue;
            }

            $candidates[] = $candidate;
            if ($this->addressesMatch($address, $candidate)) {
                return ProviderResult::verified($candidate);
            }
        }

        if ($candidates === []) {
            $this->logger->warning('skyy_address_validation.provider_malformed_response');

            return ProviderResult::unavailable();
        }

        return ProviderResult::rejected($candidates[0]);
    }

    private function candidateFromPayload(mixed $item): ?Address
    {
        if (!\is_array($item) || !isset($item['address']) || !\is_array($item['address'])) {
            return null;
        }

        $details = $item['address'];
        $road = $this->nonEmptyString($details['road'] ?? null);
        $city = null;
        foreach (['city', 'town', 'village', 'municipality'] as $cityKey) {
            $city = $this->nonEmptyString($details[$cityKey] ?? null);
            if ($city !== null) {
                break;
            }
        }

        $postalCode = $this->nonEmptyString($details['postcode'] ?? null);
        $countryCode = $this->nonEmptyString($details['country_code'] ?? null);
        if ($road === null || $city === null || $postalCode === null || $countryCode === null) {
            return null;
        }

        $houseNumber = $this->nonEmptyString($details['house_number'] ?? null);
        $street = $houseNumber === null ? $road : $road . ' ' . $houseNumber;

        return new Address($street, $city, $postalCode, strtoupper($countryCode));
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function addressesMatch(Address $left, Address $right): bool
    {
        return $this->canonical($left->street) === $this->canonical($right->street)
            && $this->canonical($left->city) === $this->canonical($right->city)
            && $this->canonical($left->postalCode) === $this->canonical($right->postalCode)
            && $this->canonical($left->countryCode) === $this->canonical($right->countryCode);
    }

    private function canonical(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value, 'UTF-8');
    }
}
