<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Skyyware\SkyyAddressValidation\Config\AddressValidationConfig;
use Skyyware\SkyyAddressValidation\Provider\Address;
use Skyyware\SkyyAddressValidation\Provider\AddressProviderInterface;
use Skyyware\SkyyAddressValidation\Provider\ProviderResult;
use Skyyware\SkyyAddressValidation\Service\RemoteAddressValidationService;
use Stringable;

final class RemoteAddressValidationServiceTest extends TestCase
{
    public function testDisabledProviderPassesThroughWithoutCountryLookupOrProviderCall(): void
    {
        $provider = new ServiceTestProvider(ProviderResult::unavailable());
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('search');
        $service = $this->service($provider, $repository, enabled: false);
        $output = $this->addressOutput();

        self::assertSame($output, $service->validateAndNormalize(
            new DataBag($output),
            $output,
            Context::createDefaultContext(),
            'street',
        ));
        self::assertSame(0, $provider->calls);
    }

    /** @param array<string, mixed> $output */
    #[DataProvider('incompleteAddressProvider')]
    public function testIncompleteAddressPassesThroughWithoutLookup(array $output): void
    {
        $provider = new ServiceTestProvider(ProviderResult::unavailable());
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('search');
        $service = $this->service($provider, $repository);

        self::assertSame($output, $service->validateAndNormalize(
            new DataBag($output),
            $output,
            Context::createDefaultContext(),
            'street',
        ));
        self::assertSame(0, $provider->calls);
    }

    public function testCountryIsoIsResolvedAndUnavailableProviderFailsOpen(): void
    {
        $provider = new ServiceTestProvider(ProviderResult::unavailable());
        $output = $this->addressOutput();
        $service = $this->service($provider, $this->countryRepository('DE'));

        self::assertSame($output, $service->validateAndNormalize(
            new DataBag($output),
            $output,
            Context::createDefaultContext(),
            'street',
        ));
        self::assertEquals(
            new Address('Königstraße 10', 'Stuttgart', '70173', 'DE'),
            $provider->lastAddress,
        );
    }

    public function testVerifiedAddressRemainsUnchangedWhenNormalizationIsDisabled(): void
    {
        $normalized = new Address('Koenigstrasse 10', 'Stuttgart', '70173', 'DE');
        $provider = new ServiceTestProvider(ProviderResult::verified($normalized));
        $output = $this->addressOutput();
        $service = $this->service($provider, $this->countryRepository('DE'), normalize: false);

        self::assertSame($output, $service->validateAndNormalize(
            new DataBag($output),
            $output,
            Context::createDefaultContext(),
            'street',
        ));
    }

    public function testVerifiedAddressIsNormalizedOnlyWhenExplicitlyEnabled(): void
    {
        $normalized = new Address('Koenigstrasse 10', 'Stuttgart-Mitte', '70174', 'DE');
        $provider = new ServiceTestProvider(ProviderResult::verified($normalized));
        $output = $this->addressOutput();
        $service = $this->service($provider, $this->countryRepository('DE'), normalize: true);

        $actual = $service->validateAndNormalize(
            new DataBag($output),
            $output,
            Context::createDefaultContext(),
            'street',
        );

        self::assertSame('Koenigstrasse 10', $actual['street']);
        self::assertSame('Stuttgart-Mitte', $actual['city']);
        self::assertSame('70174', $actual['zipcode']);
        self::assertSame($output['countryId'], $actual['countryId']);
    }

    #[DataProvider('propertyPathProvider')]
    public function testRejectedAddressProducesFieldBoundViolation(string $propertyPath): void
    {
        $suggestion = new Address('Königstraße 12', 'Stuttgart', '70173', 'DE');
        $provider = new ServiceTestProvider(ProviderResult::rejected($suggestion));
        $output = $this->addressOutput();
        $service = $this->service($provider, $this->countryRepository('DE'));

        try {
            $service->validateAndNormalize(
                new DataBag($output),
                $output,
                Context::createDefaultContext(),
                $propertyPath,
            );
            self::fail('Expected an address constraint violation.');
        } catch (ConstraintViolationException $exception) {
            $violation = $exception->getViolations()->get(0);
            self::assertSame($propertyPath, $violation->getPropertyPath());
            self::assertSame('SKYY_ADDRESS_NOT_VERIFIED', $violation->getCode());
            self::assertSame(
                'Königstraße 12, 70173 Stuttgart, DE',
                $violation->getParameters()['{{ suggestion }}'] ?? null,
            );
        }
    }

    public function testMissingCountryFailsOpenAndLogsNoAddressData(): void
    {
        $provider = new ServiceTestProvider(ProviderResult::verified(null));
        $repository = $this->createMock(EntityRepository::class);
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn(null);
        $repository->method('search')->willReturn($result);
        $logger = new RemoteServiceLogger();
        $output = $this->addressOutput();
        $service = $this->service($provider, $repository, logger: $logger);

        self::assertSame($output, $service->validateAndNormalize(
            new DataBag($output),
            $output,
            Context::createDefaultContext(),
            'street',
        ));

        $logs = json_encode($logger->records, JSON_THROW_ON_ERROR);
        foreach (['Königstraße', 'Stuttgart', '70173', $output['countryId']] as $pii) {
            self::assertStringNotContainsString($pii, $logs);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incompleteAddressProvider(): iterable
    {
        $complete = [
            'street' => 'Main Street 1',
            'city' => 'Berlin',
            'zipcode' => '10115',
            'countryId' => '018f2f18d7f171f6a86fc5d0fca9f908',
        ];

        foreach (array_keys($complete) as $field) {
            $missing = $complete;
            $missing[$field] = '';
            yield 'missing ' . $field => [$missing];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function propertyPathProvider(): iterable
    {
        yield 'standalone address' => ['street'];
        yield 'billing address' => ['billingAddress.street'];
        yield 'shipping address' => ['shippingAddress.street'];
    }

    /** @param EntityRepository<CountryCollection> $repository */
    private function service(
        ServiceTestProvider $provider,
        EntityRepository $repository,
        bool $enabled = true,
        bool $normalize = false,
        ?RemoteServiceLogger $logger = null,
    ): RemoteAddressValidationService {
        return new RemoteAddressValidationService(
            $provider,
            $this->config($enabled, $normalize),
            $repository,
            $logger ?? new RemoteServiceLogger(),
        );
    }

    private function config(bool $enabled, bool $normalize): AddressValidationConfig
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getBool')->willReturnCallback(
            static fn (string $key): bool => match ($key) {
                'SkyyAddressValidation.config.providerEnabled' => $enabled,
                'SkyyAddressValidation.config.normalizeAddresses' => $normalize,
                default => false,
            },
        );

        return new AddressValidationConfig($systemConfig);
    }

    /** @return EntityRepository<CountryCollection> */
    private function countryRepository(string $iso): EntityRepository
    {
        $country = new CountryEntity();
        $country->setId('018f2f18d7f171f6a86fc5d0fca9f908');
        $country->setIso($iso);
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($country);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    /**
     * @return array{street: string, city: string, zipcode: string, countryId: string}
     */
    private function addressOutput(): array
    {
        return [
            'street' => 'Königstraße 10',
            'city' => 'Stuttgart',
            'zipcode' => '70173',
            'countryId' => '018f2f18d7f171f6a86fc5d0fca9f908',
        ];
    }
}

final class ServiceTestProvider implements AddressProviderInterface
{
    public int $calls = 0;

    public ?Address $lastAddress = null;

    public function __construct(private readonly ProviderResult $result)
    {
    }

    public function verify(Address $address): ProviderResult
    {
        ++$this->calls;
        $this->lastAddress = $address;

        return $this->result;
    }
}

final class RemoteServiceLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }
}
