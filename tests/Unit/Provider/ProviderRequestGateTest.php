<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Skyyware\SkyyAddressValidation\Provider\Address;
use Skyyware\SkyyAddressValidation\Provider\AddressProviderInterface;
use Skyyware\SkyyAddressValidation\Provider\GatedAddressProvider;
use Skyyware\SkyyAddressValidation\Provider\ProviderRequestGate;
use Skyyware\SkyyAddressValidation\Provider\ProviderResult;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ProviderRequestGateTest extends TestCase
{
    public function testAllowsOnlyOneGrantPerSecondWithoutSleeping(): void
    {
        $clock = new MockClock();
        $cache = new ArrayAdapter(clock: $clock);
        $factory = new LockFactory(new InMemoryStore());
        $gate = new ProviderRequestGate($factory, $cache);

        self::assertTrue($gate->acquire());
        $gate->release();
        self::assertFalse($gate->acquire());

        $clock->sleep(1.01);
        self::assertTrue($gate->acquire());
        $gate->release();
    }

    public function testConcurrentAcquisitionIsDeniedImmediately(): void
    {
        $cache = new ArrayAdapter();
        $factory = new LockFactory(new InMemoryStore());
        $first = new ProviderRequestGate($factory, $cache);
        $second = new ProviderRequestGate($factory, $cache);

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());
        $first->release();
    }

    public function testDeniedGateReturnsUnavailableWithoutCallingProvider(): void
    {
        $cache = new ArrayAdapter();
        $factory = new LockFactory(new InMemoryStore());
        $seedGate = new ProviderRequestGate($factory, $cache);
        self::assertTrue($seedGate->acquire());
        $seedGate->release();

        $inner = new GateTestProvider();
        $result = (new GatedAddressProvider(
            $inner,
            new ProviderRequestGate($factory, $cache),
        ))->verify($this->address());

        self::assertTrue($result->isUnavailable());
        self::assertSame(0, $inner->calls);
    }

    public function testInFlightLockIsReleasedWhenProviderThrows(): void
    {
        $clock = new MockClock();
        $cache = new ArrayAdapter(clock: $clock);
        $factory = new LockFactory(new InMemoryStore());
        $throwing = new GateTestProvider(throw: true);
        $provider = new GatedAddressProvider(
            $throwing,
            new ProviderRequestGate($factory, $cache),
        );

        try {
            $provider->verify($this->address());
            self::fail('Expected inner provider exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('provider bug', $exception->getMessage());
        }

        $clock->sleep(1.01);
        $gate = new ProviderRequestGate($factory, $cache);
        self::assertTrue($gate->acquire());
        $gate->release();
    }

    private function address(): Address
    {
        return new Address('Main Street 1', 'Berlin', '10115', 'DE');
    }
}

final class GateTestProvider implements AddressProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly bool $throw = false)
    {
    }

    public function verify(Address $address): ProviderResult
    {
        ++$this->calls;

        if ($this->throw) {
            throw new RuntimeException('provider bug');
        }

        return ProviderResult::verified($address);
    }
}
