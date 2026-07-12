<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Provider;

use Psr\Cache\CacheException;
use Psr\Cache\CacheItemPoolInterface;

final readonly class CachedAddressProvider implements AddressProviderInterface
{
    private const CACHE_PREFIX = 'skyy_address_validation.provider.';

    private const LONG_TTL = 2_592_000;

    private const UNAVAILABLE_TTL = 300;

    public function __construct(
        private AddressProviderInterface $inner,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function verify(Address $address): ProviderResult
    {
        try {
            $item = $this->cache->getItem($this->cacheKey($address));
            if ($item->isHit()) {
                $cached = $this->decode($item->get());
                if ($cached !== null) {
                    return $cached;
                }
            }
        } catch (CacheException) {
            return $this->inner->verify($address);
        }

        $result = $this->inner->verify($address);
        try {
            $item->set($this->encode($result));
            $item->expiresAfter($result->isUnavailable() ? self::UNAVAILABLE_TTL : self::LONG_TTL);
            $this->cache->save($item);
        } catch (CacheException) {
            // Verification results remain usable when the optional cache is unavailable.
        }

        return $result;
    }

    /**
     * @return array{status: 'rejected'|'unavailable'|'verified', address: array{street: string, city: string, postalCode: string, countryCode: string}|null}
     */
    private function encode(ProviderResult $result): array
    {
        if ($result->isVerified()) {
            return ['status' => 'verified', 'address' => $this->encodeAddress($result->normalizedAddress())];
        }

        if ($result->isRejected()) {
            return ['status' => 'rejected', 'address' => $this->encodeAddress($result->suggestion())];
        }

        return ['status' => 'unavailable', 'address' => null];
    }

    /**
     * @return array{street: string, city: string, postalCode: string, countryCode: string}|null
     */
    private function encodeAddress(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'street' => $address->street,
            'city' => $address->city,
            'postalCode' => $address->postalCode,
            'countryCode' => $address->countryCode,
        ];
    }

    private function decode(mixed $value): ?ProviderResult
    {
        if (!\is_array($value) || !isset($value['status']) || !\array_key_exists('address', $value)) {
            return null;
        }

        $decodedAddress = $this->decodeAddress($value['address']);
        if ($decodedAddress['valid'] === false) {
            return null;
        }

        return match ($value['status']) {
            'verified' => ProviderResult::verified($decodedAddress['address']),
            'rejected' => ProviderResult::rejected($decodedAddress['address']),
            'unavailable' => $decodedAddress['address'] === null ? ProviderResult::unavailable() : null,
            default => null,
        };
    }

    /**
     * @return array{valid: bool, address: Address|null}
     */
    private function decodeAddress(mixed $value): array
    {
        if ($value === null) {
            return ['valid' => true, 'address' => null];
        }

        if (!\is_array($value)
            || !\is_string($value['street'] ?? null)
            || !\is_string($value['city'] ?? null)
            || !\is_string($value['postalCode'] ?? null)
            || !\is_string($value['countryCode'] ?? null)
        ) {
            return ['valid' => false, 'address' => null];
        }

        return [
            'valid' => true,
            'address' => new Address(
                $value['street'],
                $value['city'],
                $value['postalCode'],
                $value['countryCode'],
            ),
        ];
    }

    private function cacheKey(Address $address): string
    {
        $canonical = [
            'street' => $this->canonical($address->street),
            'city' => $this->canonical($address->city),
            'postalCode' => $this->canonical($address->postalCode),
            'countryCode' => $this->canonical($address->countryCode),
        ];
        $json = json_encode(
            $canonical,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );

        return self::CACHE_PREFIX . hash('sha256', $json);
    }

    private function canonical(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value, 'UTF-8');
    }
}
