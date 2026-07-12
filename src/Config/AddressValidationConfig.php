<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Config;

use InvalidArgumentException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final readonly class AddressValidationConfig
{
    private const PREFIX = 'SkyyAddressValidation.config.';

    public function __construct(private SystemConfigService $systemConfig)
    {
    }

    /**
     * @return array{endpoint: string, userAgent: string, contactEmail: string}|null
     */
    public function providerConfiguration(): ?array
    {
        if (!$this->isProviderEnabled()) {
            return null;
        }

        $endpoint = trim($this->systemConfig->getString(self::PREFIX . 'providerEndpoint'));
        $userAgent = trim($this->systemConfig->getString(self::PREFIX . 'providerUserAgent'));
        $contactEmail = trim($this->systemConfig->getString(self::PREFIX . 'providerContactEmail'));

        if (!$this->isValidHttpsEndpoint($endpoint)
            || $userAgent === ''
            || preg_match('/[\x00-\x1F\x7F]/', $userAgent) === 1
            || filter_var($contactEmail, \FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException('Remote address provider configuration is invalid.');
        }

        return [
            'endpoint' => $endpoint,
            'userAgent' => $userAgent,
            'contactEmail' => $contactEmail,
        ];
    }

    public function isProviderEnabled(): bool
    {
        return $this->systemConfig->getBool(self::PREFIX . 'providerEnabled');
    }

    public function shouldValidateNames(): bool
    {
        return $this->systemConfig->getBool(self::PREFIX . 'validateNames');
    }

    public function shouldValidatePhoneNumbers(): bool
    {
        return $this->systemConfig->getBool(self::PREFIX . 'validatePhoneNumbers');
    }

    public function shouldValidatePostalCodes(): bool
    {
        return $this->systemConfig->getBool(self::PREFIX . 'validatePostalCodes');
    }

    public function shouldNormalizeAddresses(): bool
    {
        return $this->systemConfig->getBool(self::PREFIX . 'normalizeAddresses');
    }

    private function isValidHttpsEndpoint(string $endpoint): bool
    {
        if (filter_var($endpoint, \FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($endpoint);

        return \is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && (string) ($parts['host'] ?? '') !== '';
    }
}
