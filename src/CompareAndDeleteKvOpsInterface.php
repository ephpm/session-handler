<?php

declare(strict_types=1);

namespace Ephpm\SessionHandler;

/**
 * Optional backend capability: atomic compare-and-delete.
 *
 * Kept separate from {@see KvOpsInterface} so existing third-party
 * implementations of that interface keep working unchanged. A backend that
 * implements this lets {@see KvSessionHandler} release a session lock only
 * while it still holds the lock token, so a request that overran the lock TTL
 * cannot delete the lock a later request has since acquired.
 */
interface CompareAndDeleteKvOpsInterface
{
    /**
     * Whether {@see delIfEq()} is usable right now. {@see SapiKvOps} answers
     * from `function_exists('ephpm_kv_del_if_eq')`, which is false on ePHPm
     * runtimes older than v0.11.x. When this returns false the handler falls
     * back to a plain delete.
     */
    public function supportsDelIfEq(): bool;

    /**
     * Atomically delete `$key` only while its value equals `$expected`.
     *
     * @return bool true if this call removed the key; false if the value
     *              differed, the key was missing or expired, or on failure
     */
    public function delIfEq(string $key, string $expected): bool;
}
