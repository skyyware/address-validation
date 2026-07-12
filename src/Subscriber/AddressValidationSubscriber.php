<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Framework\Event\DataMappingEvent;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Skyyware\SkyyAddressValidation\Config\AddressValidationConfig;
use Skyyware\SkyyAddressValidation\Service\RemoteAddressValidationService;
use Skyyware\SkyyAddressValidation\Validation\Constraint\NoDigitsInName;
use Skyyware\SkyyAddressValidation\Validation\Constraint\PhoneCharacters;
use Skyyware\SkyyAddressValidation\Validation\Constraint\PostalCodeCharacters;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class AddressValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AddressValidationConfig $config,
        private RemoteAddressValidationService $remoteValidation,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'framework.validation.address.create' => 'onBuildAddressValidation',
            'framework.validation.address.update' => 'onBuildAddressValidation',
            CustomerEvents::MAPPING_REGISTER_ADDRESS_BILLING => 'onBillingAddressMapped',
            CustomerEvents::MAPPING_REGISTER_ADDRESS_SHIPPING => 'onShippingAddressMapped',
            CustomerEvents::MAPPING_ADDRESS_CREATE => 'onAddressMapped',
        ];
    }

    public function onBuildAddressValidation(BuildValidationEvent $event): void
    {
        $definition = $event->getDefinition();
        if ($this->config->shouldValidateNames()) {
            $definition->add('firstName', new NoDigitsInName());
            $definition->add('lastName', new NoDigitsInName());
        }
        if ($this->config->shouldValidatePhoneNumbers()) {
            $definition->add('phoneNumber', new PhoneCharacters());
        }
        if ($this->config->shouldValidatePostalCodes()) {
            $definition->add('zipcode', new PostalCodeCharacters());
        }
    }

    public function onBillingAddressMapped(DataMappingEvent $event): void
    {
        $this->mapAddress($event, 'billingAddress.street');
    }

    public function onShippingAddressMapped(DataMappingEvent $event): void
    {
        $this->mapAddress($event, 'shippingAddress.street');
    }

    public function onAddressMapped(DataMappingEvent $event): void
    {
        $this->mapAddress($event, 'street');
    }

    private function mapAddress(DataMappingEvent $event, string $propertyPath): void
    {
        $event->setOutput($this->remoteValidation->validateAndNormalize(
            $event->getInput(),
            $event->getOutput(),
            $event->getContext(),
            $propertyPath,
        ));
    }
}
