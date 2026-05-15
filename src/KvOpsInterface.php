<?php

declare(strict_types=1);

namespace Ephpm\SessionHandler;

/**
 * Backend abstraction for the underlying KV store.
 *
 * The production implementation ({@see SapiKvOps}) calls the global
 * `ephpm_kv_*` functions registered by the ePHPm SAPI. Tests use
 * {@see InMemoryKvOps} so they can run anywhere without the runtime.
 *
 * Method semantics intentionally mirror the ephpm_kv_* SAPI surface
 * (TTLs in seconds, PTTL in milliseconds, etc.) — the handler adapts
 * those to PHP's SessionHandlerInterface contract, not the other way
 * around.
 */
interface KvOpsInterface
{
    /**
     * Get a value by key.
     *
     * @return string|null the value, or null when the key does not exist
     */
    public function get(string $key): ?string;

    /**
     * Set a key to a value with optional TTL.
     *
     * @param int $ttlSeconds 0 means no expiry; positive values are seconds
     *
     * @return bool true on success, false on failure (e.g. OOM under noeviction)
     */
    public function set(string $key, string $value, int $ttlSeconds = 0): bool;

    /**
     * Delete a key.
     *
     * @return int 1 if the key existed, 0 if it did not
     */
    public function del(string $key): int;

    /**
     * Whether a key currently exists.
     */
    public function exists(string $key): bool;

    /**
     * Atomically increment a counter by `delta` and return the new value.
     *
     * Creates the key (initialised to 0) if it does not yet exist. Throws
     * when the existing value is not an integer.
     */
    public function incrBy(string $key, int $delta): int;

    /**
     * Set a TTL (in seconds) on an existing key.
     *
     * @return bool true if the key existed and the TTL was applied,
     *              false if the key was missing
     */
    public function expire(string $key, int $ttlSeconds): bool;

    /**
     * Remaining TTL in seconds: -1 if the key exists with no expiry,
     * -2 if the key does not exist, otherwise the seconds remaining.
     */
    public function ttl(string $key): int;

    /**
     * Remaining TTL in milliseconds: -1 if the key exists with no expiry,
     * -2 if the key does not exist, otherwise the ms remaining.
     */
    public function pttl(string $key): int;
}
