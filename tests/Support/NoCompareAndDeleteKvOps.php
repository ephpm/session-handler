<?php

declare(strict_types=1);

namespace Ephpm\SessionHandler\Tests\Support;

use Ephpm\SessionHandler\KvOpsInterface;

/**
 * Decorator exposing only {@see KvOpsInterface} — no compare-and-delete — so
 * tests can exercise the handler's fallback path (a custom backend, or an
 * ePHPm runtime without `ephpm_kv_del_if_eq()`).
 */
final class NoCompareAndDeleteKvOps implements KvOpsInterface
{
    public function __construct(private readonly KvOpsInterface $inner)
    {
    }

    public function get(string $key): ?string
    {
        return $this->inner->get($key);
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        return $this->inner->set($key, $value, $ttlSeconds);
    }

    public function setnx(string $key, string $value, int $ttlSeconds = 0): bool
    {
        return $this->inner->setnx($key, $value, $ttlSeconds);
    }

    public function del(string $key): int
    {
        return $this->inner->del($key);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function incrBy(string $key, int $delta): int
    {
        return $this->inner->incrBy($key, $delta);
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        return $this->inner->expire($key, $ttlSeconds);
    }

    public function ttl(string $key): int
    {
        return $this->inner->ttl($key);
    }

    public function pttl(string $key): int
    {
        return $this->inner->pttl($key);
    }

    public function flush(): bool
    {
        return $this->inner->flush();
    }
}
