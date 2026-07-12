<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Provider;

use Psr\Cache\CacheException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class ProviderRequestGate
{
    private const IN_FLIGHT_RESOURCE = 'skyy_address_validation.provider.in_flight';

    private const RATE_CACHE_KEY = 'skyy_address_validation.provider.rate';

    private ?SharedLockInterface $activeLock = null;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function acquire(): bool
    {
        if ($this->activeLock !== null) {
            return false;
        }

        $lock = $this->lockFactory->createLock(self::IN_FLIGHT_RESOURCE, 10.0);
        try {
            if (!$lock->acquire(false)) {
                return false;
            }
        } catch (LockAcquiringException) {
            return false;
        }

        try {
            $rate = $this->cache->getItem(self::RATE_CACHE_KEY);
            if ($rate->isHit()) {
                $this->safeRelease($lock);

                return false;
            }

            $rate->set(true)->expiresAfter(1);
            if (!$this->cache->save($rate)) {
                $this->safeRelease($lock);

                return false;
            }
        } catch (CacheException) {
            $this->safeRelease($lock);

            return false;
        }

        $this->activeLock = $lock;

        return true;
    }

    public function release(): void
    {
        $lock = $this->activeLock;
        $this->activeLock = null;
        if ($lock !== null) {
            $this->safeRelease($lock);
        }
    }

    private function safeRelease(SharedLockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (LockReleasingException) {
            // The lock has a finite TTL, so an infrastructure release failure is fail-safe.
        }
    }
}
