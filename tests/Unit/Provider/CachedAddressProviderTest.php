<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Tests\Unit\Provider;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Skyyware\SkyyAddressValidation\Provider\Address;
use Skyyware\SkyyAddressValidation\Provider\AddressProviderInterface;
use Skyyware\SkyyAddressValidation\Provider\CachedAddressProvider;
use Skyyware\SkyyAddressValidation\Provider\ProviderResult;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Clock\MockClock;

final class CachedAddressProviderTest extends TestCase
{
    public function testCacheHitSkipsInnerProviderAndKeyContainsNoPii(): void
    {
        $clock = new MockClock();
        $cache = new RecordingArrayAdapter(clock: $clock);
        $inner = new CallbackAddressProvider(
            static fn (Address $address): ProviderResult => ProviderResult::verified($address),
        );
        $provider = new CachedAddressProvider($inner, $cache);

        $first = $provider->verify(new Address(' Main   Street 1 ', 'Berlin', '10115', 'DE'));
        $second = $provider->verify(new Address('main street 1', ' berlin ', '10115', 'de'));

        self::assertTrue($first->isVerified());
        self::assertTrue($second->isVerified());
        self::assertSame(1, $inner->calls);
        self::assertNotEmpty($cache->requestedKeys);
        self::assertMatchesRegularExpression(
            '/^skyy_address_validation\.provider\.[a-f0-9]{64}$/',
            $cache->requestedKeys[0],
        );
        foreach (['Main', 'Street', 'Berlin', '10115', 'DE'] as $pii) {
            self::assertStringNotContainsString($pii, $cache->requestedKeys[0]);
        }

        $stored = $cache->getItem($cache->requestedKeys[0])->get();
        self::assertIsArray($stored);
        self::assertSame('verified', $stored['status']);
        self::assertIsArray($stored['address']);
    }

    #[DataProvider('longLivedResultProvider')]
    public function testVerifiedAndRejectedResultsUseThirtyDayTtl(ProviderResult $result): void
    {
        $clock = new MockClock();
        $cache = new ArrayAdapter(clock: $clock);
        $inner = new CallbackAddressProvider(static fn (): ProviderResult => $result);
        $provider = new CachedAddressProvider($inner, $cache);
        $address = $this->address();

        $provider->verify($address);
        $clock->sleep(2_592_000 - 1);
        $provider->verify($address);
        self::assertSame(1, $inner->calls);

        $clock->sleep(2);
        $provider->verify($address);
        self::assertSame(2, $inner->calls);
    }

    public function testUnavailableResultUsesFiveMinuteTtl(): void
    {
        $clock = new MockClock();
        $cache = new ArrayAdapter(clock: $clock);
        $inner = new CallbackAddressProvider(static fn (): ProviderResult => ProviderResult::unavailable());
        $provider = new CachedAddressProvider($inner, $cache);
        $address = $this->address();

        $provider->verify($address);
        $clock->sleep(299);
        $provider->verify($address);
        self::assertSame(1, $inner->calls);

        $clock->sleep(2);
        $provider->verify($address);
        self::assertSame(2, $inner->calls);
    }

    public function testMalformedCachedValueIsReplacedFromInnerProvider(): void
    {
        $cache = new RecordingArrayAdapter();
        $inner = new CallbackAddressProvider(
            static fn (Address $address): ProviderResult => ProviderResult::verified($address),
        );
        $provider = new CachedAddressProvider($inner, $cache);
        $address = $this->address();

        $provider->verify($address);
        $key = $cache->requestedKeys[0];
        $item = $cache->getItem($key);
        $item->set(['status' => 'unknown'])->expiresAfter(60);
        $cache->save($item);

        self::assertTrue($provider->verify($address)->isVerified());
        self::assertSame(2, $inner->calls);
    }

    public function testCacheReadFailureFallsBackToInnerProvider(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new CacheBackendFailure());
        $inner = new CallbackAddressProvider(
            static fn (Address $address): ProviderResult => ProviderResult::verified($address),
        );

        $result = (new CachedAddressProvider($inner, $cache))->verify($this->address());

        self::assertTrue($result->isVerified());
        self::assertSame(1, $inner->calls);
    }

    public function testCacheWriteFailureDoesNotDiscardProviderResult(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willThrowException(new CacheBackendFailure());
        $inner = new CallbackAddressProvider(
            static fn (Address $address): ProviderResult => ProviderResult::verified($address),
        );

        $result = (new CachedAddressProvider($inner, $cache))->verify($this->address());

        self::assertTrue($result->isVerified());
        self::assertSame(1, $inner->calls);
    }

    /**
     * @return iterable<string, array{ProviderResult}>
     */
    public static function longLivedResultProvider(): iterable
    {
        $address = new Address('Main Street 1', 'Berlin', '10115', 'DE');

        yield 'verified' => [ProviderResult::verified($address)];
        yield 'rejected' => [ProviderResult::rejected($address)];
    }

    private function address(): Address
    {
        return new Address('Main Street 1', 'Berlin', '10115', 'DE');
    }
}

final class CacheBackendFailure extends RuntimeException implements CacheException
{
}

final class CallbackAddressProvider implements AddressProviderInterface
{
    public int $calls = 0;

    /**
     * @param Closure(Address): ProviderResult $callback
     */
    public function __construct(private readonly Closure $callback)
    {
    }

    public function verify(Address $address): ProviderResult
    {
        ++$this->calls;

        return ($this->callback)($address);
    }
}

final class RecordingArrayAdapter extends ArrayAdapter
{
    /** @var list<string> */
    public array $requestedKeys = [];

    public function getItem(mixed $key): CacheItem
    {
        if (\is_string($key)) {
            $this->requestedKeys[] = $key;
        }

        return parent::getItem($key);
    }
}
