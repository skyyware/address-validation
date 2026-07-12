<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Provider;

final readonly class ProviderResult
{
    private const VERIFIED = 'verified';

    private const REJECTED = 'rejected';

    private const UNAVAILABLE = 'unavailable';

    private function __construct(
        private string $status,
        private ?Address $address,
    ) {
    }

    public static function verified(?Address $normalized): self
    {
        return new self(self::VERIFIED, $normalized);
    }

    public static function rejected(?Address $suggestion): self
    {
        return new self(self::REJECTED, $suggestion);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, null);
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    public function isUnavailable(): bool
    {
        return $this->status === self::UNAVAILABLE;
    }

    public function normalizedAddress(): ?Address
    {
        return $this->isVerified() ? $this->address : null;
    }

    public function suggestion(): ?Address
    {
        return $this->isRejected() ? $this->address : null;
    }
}
