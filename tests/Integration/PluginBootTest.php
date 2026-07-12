<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Tests\Integration;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Skyyware\SkyyAddressValidation\Config\AddressValidationConfig;
use Skyyware\SkyyAddressValidation\Provider\AddressProviderInterface;
use Skyyware\SkyyAddressValidation\Service\RemoteAddressValidationService;
use Skyyware\SkyyAddressValidation\Subscriber\AddressValidationSubscriber;
use Skyyware\SkyyAddressValidation\Validation\Constraint\NoDigitsInName;
use Skyyware\SkyyAddressValidation\Validation\Constraint\PhoneCharacters;
use Skyyware\SkyyAddressValidation\Validation\Constraint\PostalCodeCharacters;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PluginBootTest extends TestCase
{
    use KernelTestBehaviour;

    public function testInstalledShopwareCoreMatchesConfiguredExpectation(): void
    {
        $installedVersion = InstalledVersions::getPrettyVersion('shopware/core');
        self::assertNotNull($installedVersion);

        $expectedVersion = getenv('EXPECTED_SHOPWARE_CORE_VERSION');
        if (\is_string($expectedVersion) && $expectedVersion !== '') {
            self::assertSame(ltrim($expectedVersion, 'v'), ltrim($installedVersion, 'v'));
        }
    }

    public function testContainerCompilesWithRuntimeServices(): void
    {
        self::assertInstanceOf(
            AddressProviderInterface::class,
            self::getContainer()->get(AddressProviderInterface::class),
        );
        self::assertInstanceOf(
            RemoteAddressValidationService::class,
            self::getContainer()->get(RemoteAddressValidationService::class),
        );
        self::assertInstanceOf(
            AddressValidationSubscriber::class,
            self::getContainer()->get(AddressValidationSubscriber::class),
        );
    }

    public function testPackagedPluginInstallsWithReleaseVersion(): void
    {
        /** @var EntityRepository<PluginCollection> $repository */
        $repository = self::getContainer()->get('plugin.repository');
        $plugin = $repository->search(
            (new Criteria())->addFilter(new EqualsFilter('name', 'SkyyAddressValidation')),
            Context::createDefaultContext(),
        )->first();

        self::assertNotNull($plugin);
        self::assertSame('0.1.0', $plugin->getVersion());
    }

    public function testRemoteVerificationIsDisabledByDefault(): void
    {
        $config = self::getContainer()->get(AddressValidationConfig::class);
        self::assertInstanceOf(AddressValidationConfig::class, $config);
        self::assertFalse($config->isProviderEnabled());
        self::assertNull($config->providerConfiguration());
    }

    public function testRegisteredSubscriberAddsDefaultLocalConstraints(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $definition = new DataValidationDefinition('address.create');
        $event = new BuildValidationEvent($definition, new DataBag(), Context::createDefaultContext());

        $dispatcher->dispatch($event, $event->getName());

        $properties = $definition->getProperties();
        self::assertCount(1, $properties['firstName']);
        self::assertCount(1, $properties['lastName']);
        self::assertCount(1, $properties['phoneNumber']);
        self::assertCount(1, $properties['zipcode']);
        self::assertContainsOnlyInstancesOf(NoDigitsInName::class, $properties['firstName']);
        self::assertContainsOnlyInstancesOf(NoDigitsInName::class, $properties['lastName']);
        self::assertContainsOnlyInstancesOf(PhoneCharacters::class, $properties['phoneNumber']);
        self::assertContainsOnlyInstancesOf(PostalCodeCharacters::class, $properties['zipcode']);
    }

    public function testStorefrontViolationSnippetsLoadAndInterpolate(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertSame(
            'Names cannot contain digits.',
            $translator->trans('error.VIOLATION::SKYY_NAME_DIGITS', locale: 'en-GB'),
        );
        self::assertSame(
            'We could not confirm this address. Did you mean Main Street 1, 10115 Berlin, DE?',
            $translator->trans(
                'error.VIOLATION::SKYY_ADDRESS_NOT_VERIFIED_SUGGESTION',
                ['{{ suggestion }}' => 'Main Street 1, 10115 Berlin, DE'],
                locale: 'en-GB',
            ),
        );
    }
}
