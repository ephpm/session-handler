<?php

declare(strict_types=1);

namespace Ephpm\SessionHandler;

use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * PHP session save handler that stores session payloads in the ePHPm
 * KV store via the `ephpm_kv_*` SAPI functions.
 *
 * Register before calling `session_start()`:
 *
 *     session_set_save_handler(new \Ephpm\SessionHandler\KvSessionHandler(), true);
 *     session_start();
 *
 * After that, any code touching `$_SESSION` round-trips through the
 * KV store — no `/var/lib/php/sessions/` files, no Redis daemon, no
 * Memcached. The `true` second argument tells PHP to register the
 * handler's destructor as a shutdown function so unwritten changes get
 * flushed cleanly even if the script doesn't reach the end.
 *
 * Implements both {@see SessionHandlerInterface} (the basic contract)
 * and {@see SessionUpdateTimestampHandlerInterface} (lets PHP refresh
 * a session's TTL via `session.lazy_write` without rewriting the
 * payload — relevant for read-heavy apps).
 *
 * Implements {@see SessionIdInterface} so PHP uses our id generator
 * (it just delegates to PHP's own `session_create_id()`); without it
 * the handler would still work but PHP issues a deprecation notice
 * on PHP 8.4+.
 */
final class KvSessionHandler implements
    SessionHandlerInterface,
    SessionUpdateTimestampHandlerInterface,
    SessionIdInterface
{
    private KvOpsInterface $ops;
    private string $prefix;
    private int $ttlSeconds;

    /**
     * @param string             $prefix     Key prefix written before each session
     *                                       id. Defaults to `php_session:`. Bump
     *                                       this in config to invalidate every
     *                                       session at once.
     * @param int|null           $ttlSeconds Session lifetime in seconds. `null`
     *                                       reads `session.gc_maxlifetime` from
     *                                       php.ini at construction time
     *                                       (default 1440 s / 24 minutes).
     * @param KvOpsInterface|null $ops       Backend override (mainly for tests).
     *                                       Defaults to {@see SapiKvOps}.
     */
    public function __construct(
        string $prefix = 'php_session:',
        ?int $ttlSeconds = null,
        ?KvOpsInterface $ops = null,
    ) {
        $this->prefix = $prefix;
        $this->ttlSeconds = $ttlSeconds ?? (int) \ini_get('session.gc_maxlifetime') ?: 1440;
        $this->ops = $ops ?? new SapiKvOps();
    }

    // ── SessionHandlerInterface ──────────────────────────────────────────────

    /**
     * `open` and `close` are no-ops — the SAPI is always reachable, there
     * is no connection to negotiate. Both must return true so PHP doesn't
     * abort the session lifecycle.
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    /**
     * Read a session's serialized payload. PHP expects an empty string
     * (NOT null/false) when the session doesn't exist — that's the cue
     * to start a fresh `$_SESSION`.
     */
    public function read(string $id): string
    {
        return $this->ops->get($this->prefix . $id) ?? '';
    }

    /**
     * Write a session's payload. The TTL is reset on every write so an
     * actively-used session never times out mid-conversation.
     */
    public function write(string $id, string $data): bool
    {
        return $this->ops->set($this->prefix . $id, $data, $this->ttlSeconds);
    }

    public function destroy(string $id): bool
    {
        // del() returns 0 when the key was already gone; that's still a
        // success from the user's perspective ("the session no longer
        // exists, which is what you asked for").
        $this->ops->del($this->prefix . $id);
        return true;
    }

    /**
     * GC is a no-op because the KV store handles expiry natively via the
     * TTL set in `write()`. Returning 0 satisfies PHP's "how many
     * sessions did you sweep?" expectation.
     */
    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    // ── SessionUpdateTimestampHandlerInterface ───────────────────────────────

    /**
     * PHP calls this at session_start() to confirm the cookie's id maps
     * to a real session. Returning false makes PHP regenerate the id —
     * which is the right behaviour for an expired or never-existed key.
     */
    public function validateId(string $id): bool
    {
        return $this->ops->exists($this->prefix . $id);
    }

    /**
     * For read-heavy sessions PHP can skip rewriting the payload and
     * just bump the TTL. Falls back to `write()` semantics if the key
     * was somehow lost between read and timestamp-update (rare; it can
     * happen if the TTL expired in the few microseconds between calls).
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        if (!$this->ops->expire($this->prefix . $id, $this->ttlSeconds)) {
            return $this->write($id, $data);
        }
        return true;
    }

    // ── SessionIdInterface ───────────────────────────────────────────────────

    /**
     * Delegate to PHP's built-in id generator — it already honors
     * `session.sid_length`, `session.sid_bits_per_character`, and the
     * CSPRNG. Implementing this interface explicitly is mostly about
     * silencing a PHP 8.4+ deprecation about save-handlers that don't
     * declare an id strategy.
     */
    public function create_sid(): string
    {
        return \session_create_id();
    }
}
