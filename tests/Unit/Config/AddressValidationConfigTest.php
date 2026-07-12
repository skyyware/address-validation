<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Tests\Unit\Config;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Skyyware\SkyyAddressValidation\Config\AddressValidationConfig;

final class AddressValidationConfigTest extends TestCase
{
    public function testDisabledProviderDoesNotReadConnectionSettings(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->expects(self::once())
            ->method('getBool')
            ->with('SkyyAddressValidation.config.providerEnabled')
            ->willReturn(false);
        $systemConfig->expects(self::never())->method('getString');

        self::assertNull((new AddressValidationConfig($systemConfig))->providerConfiguration());
    }

    public function testReturnsValidatedProviderConfiguration(): void
    {
        $config = $this->config([
            'providerEnabled' => true,
            'providerEndpoint' => ' https://geo.example.test/search ',
            'providerUserAgent' => ' StagewareAddress/1.0 ',
            'providerContactEmail' => ' maps@example.test ',
        ]);

        self::assertSame([
            'endpoint' => 'https://geo.example.test/search',
            'userAgent' => 'StagewareAddress/1.0',
            'contactEmail' => 'maps@example.test',
        ], $config->providerConfiguration());
    }

    /**
     * @param array<string, bool|string> $overrides
     */
    #[DataProvider('invalidProviderConfigurationProvider')]
    public function testRejectsIncompleteOrUnsafeEnabledConfiguration(array $overrides): void
    {
        $values = array_merge([
            'providerEnabled' => true,
            'providerEndpoint' => 'https://geo.example.test/search',
            'providerUserAgent' => 'StagewareAddress/1.0',
            'providerContactEmail' => 'maps@example.test',
        ], $overrides);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote address provider configuration is invalid.');

        $this->config($values)->providerConfiguration();
    }

    public function testExposesLocalAndNormalizationToggles(): void
    {
        $config = $this->config([
            'validateNames' => true,
            'validatePhoneNumbers' => false,
            'validatePostalCodes' => true,
            'normalizeAddresses' => false,
        ]);

        self::assertTrue($config->shouldValidateNames());
        self::assertFalse($config->shouldValidatePhoneNumbers());
        self::assertTrue($config->shouldValidatePostalCodes());
        self::assertFalse($config->shouldNormalizeAddresses());
    }

    /**
     * @return iterable<string, array{array<string, bool|string>}>
     */
    public static function invalidProviderConfigurationProvider(): iterable
    {
        yield 'empty endpoint' => [['providerEndpoint' => '']];
        yield 'non-HTTPS endpoint' => [['providerEndpoint' => 'http://geo.example.test/search']];
        yield 'relative endpoint' => [['providerEndpoint' => '/search']];
        yield 'empty user agent' => [['providerUserAgent' => '  ']];
        yield 'empty contact' => [['providerContactEmail' => '']];
        yield 'invalid contact' => [['providerContactEmail' => 'not-an-email']];
    }

    /**
     * @param array<string, bool|string> $values
     */
    private function config(array $values): AddressValidationConfig
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getBool')
            ->willReturnCallback(
                static fn (string $key): bool => (bool) ($values[self::shortKey($key)] ?? false),
            );
        $systemConfig->method('getString')
            ->willReturnCallback(
                static fn (string $key): string => (string) ($values[self::shortKey($key)] ?? ''),
            );

        return new AddressValidationConfig($systemConfig);
    }

    private static function shortKey(string $key): string
    {
        return str_replace('SkyyAddressValidation.config.', '', $key);
    }
}
