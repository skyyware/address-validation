<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\DataMappingEvent;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Skyyware\SkyyAddressValidation\Config\AddressValidationConfig;
use Skyyware\SkyyAddressValidation\Service\RemoteAddressValidationService;
use Skyyware\SkyyAddressValidation\Subscriber\AddressValidationSubscriber;
use Skyyware\SkyyAddressValidation\Validation\Constraint\NoDigitsInName;
use Skyyware\SkyyAddressValidation\Validation\Constraint\PhoneCharacters;
use Skyyware\SkyyAddressValidation\Validation\Constraint\PostalCodeCharacters;

final class AddressValidationSubscriberTest extends TestCase
{
    public function testSubscribesToCompatibleValidationAndMappingEvents(): void
    {
        self::assertSame([
            'framework.validation.address.create' => 'onBuildAddressValidation',
            'framework.validation.address.update' => 'onBuildAddressValidation',
            CustomerEvents::MAPPING_REGISTER_ADDRESS_BILLING => 'onBillingAddressMapped',
            CustomerEvents::MAPPING_REGISTER_ADDRESS_SHIPPING => 'onShippingAddressMapped',
            CustomerEvents::MAPPING_ADDRESS_CREATE => 'onAddressMapped',
        ], AddressValidationSubscriber::getSubscribedEvents());
    }

    public function testAddsConfiguredLocalConstraintsToAddressDefinitions(): void
    {
        $definition = new DataValidationDefinition('address.create');
        $event = new BuildValidationEvent($definition, new DataBag(), Context::createDefaultContext());
        $subscriber = new AddressValidationSubscriber(
            $this->config(names: true, phones: true, postalCodes: true),
            new SpyRemoteAddressValidationService(),
        );

        $subscriber->onBuildAddressValidation($event);

        $properties = $definition->getProperties();
        self::assertContainsOnlyInstancesOf(NoDigitsInName::class, $properties['firstName']);
        self::assertContainsOnlyInstancesOf(NoDigitsInName::class, $properties['lastName']);
        self::assertContainsOnlyInstancesOf(PhoneCharacters::class, $properties['phoneNumber']);
        self::assertContainsOnlyInstancesOf(PostalCodeCharacters::class, $properties['zipcode']);
    }

    public function testDisabledLocalRulesAddNothing(): void
    {
        $definition = new DataValidationDefinition('address.update');
        $subscriber = new AddressValidationSubscriber(
            $this->config(names: false, phones: false, postalCodes: false),
            new SpyRemoteAddressValidationService(),
        );

        $subscriber->onBuildAddressValidation(new BuildValidationEvent(
            $definition,
            new DataBag(),
            Context::createDefaultContext(),
        ));

        self::assertSame([], $definition->getProperties());
    }

    public function testMappingHandlersUseCorrectFieldPathsAndApplyReturnedOutput(): void
    {
        $remote = new SpyRemoteAddressValidationService();
        $subscriber = new AddressValidationSubscriber($this->config(), $remote);
        $context = Context::createDefaultContext();

        $billing = new DataMappingEvent(new DataBag(['street' => 'one']), ['street' => 'one'], $context);
        $subscriber->onBillingAddressMapped($billing);
        self::assertSame('/billingAddress/street', $remote->paths[0]);
        self::assertSame('normalized', $billing->getOutput()['street']);

        $shipping = new DataMappingEvent(new DataBag(['street' => 'two']), ['street' => 'two'], $context);
        $subscriber->onShippingAddressMapped($shipping);
        self::assertSame('/shippingAddress/street', $remote->paths[1]);

        $address = new DataMappingEvent(new DataBag(['street' => 'three']), ['street' => 'three'], $context);
        $subscriber->onAddressMapped($address);
        self::assertSame('/street', $remote->paths[2]);
    }

    private function config(
        bool $names = false,
        bool $phones = false,
        bool $postalCodes = false,
    ): AddressValidationConfig {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getBool')->willReturnCallback(
            static fn (string $key): bool => match ($key) {
                'SkyyAddressValidation.config.validateNames' => $names,
                'SkyyAddressValidation.config.validatePhoneNumbers' => $phones,
                'SkyyAddressValidation.config.validatePostalCodes' => $postalCodes,
                default => false,
            },
        );

        return new AddressValidationConfig($systemConfig);
    }
}

final class SpyRemoteAddressValidationService extends RemoteAddressValidationService
{
    /** @var list<string> */
    public array $paths = [];

    public function __construct()
    {
    }

    public function validateAndNormalize(
        DataBag $input,
        array $output,
        Context $context,
        string $propertyPath,
    ): array {
        $this->paths[] = $propertyPath;
        $output['street'] = 'normalized';

        return $output;
    }
}
