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
 * (a CSPRNG-backed id honouring `session.sid_length` /
 * `session.sid_bits_per_character`, generated directly rather than via
 * `session_create_id()` to avoid a re-entrancy trap — see
 * {@see self::create_sid()}); without it the handler would still work
 * but PHP issues a deprecation notice on PHP 8.4+.
 */
final class KvSessionHandler implements
    SessionHandlerInterface,
    SessionUpdateTimestampHandlerInterface,
    SessionIdInterface
{
    private KvOpsInterface $ops;
    private string $prefix;
    private int $ttlSeconds;

    private bool $lockSessions;
    private int $lockTtlSeconds;
    private int $lockSpinIntervalUs;
    private int $lockMaxWaitMs;

    /**
     * Whether this handler currently owns the per-session lock, and under
     * which key. Only set when acquisition succeeded, so `close()` never
     * deletes a lock owned by someone else.
     */
    private bool $lockHeld = false;
    private ?string $lockKey = null;

    /**
     * @param string             $prefix             Key prefix written before each session
     *                                               id. Defaults to `php_session:`. Bump
     *                                               this in config to invalidate every
     *                                               session at once.
     * @param int|null           $ttlSeconds         Session lifetime in seconds. `null`
     *                                               reads `session.gc_maxlifetime` from
     *                                               php.ini at construction time
     *                                               (default 1440 s / 24 minutes).
     * @param KvOpsInterface|null $ops               Backend override (mainly for tests).
     *                                               Defaults to {@see SapiKvOps}.
     * @param bool               $lockSessions       Opt in to per-session-id single-flighting
     *                                               (see below). Default `false` preserves the
     *                                               original lock-free behaviour exactly.
     * @param int                $lockTtlSeconds     Lifetime of the lock entry — the safety
     *                                               valve that frees a session whose owner
     *                                               crashed before `close()`. Default 30 s.
     * @param int                $lockSpinIntervalUs Microseconds to sleep between spin
     *                                               retries while waiting for the lock.
     *                                               Default 20 000 µs (20 ms).
     * @param int                $lockMaxWaitMs      Maximum total time to spin for the lock
     *                                               before giving up and proceeding lock-free
     *                                               (availability over strict exclusion).
     *                                               Default 5 000 ms.
     *
     * Session locking (opt-in). PHP's Files handler serialises concurrent
     * requests that share a session cookie by holding a `flock()` for the
     * request's lifetime. This handler does not do that by default. When
     * `$lockSessions` is true, `read()` acquires a per-session lock at
     * `<prefix>lock:<id>` with a bounded {@see \ephpm_kv_setnx} spin and
     * `close()` releases it. Because the SAPI has no compare-and-delete, the
     * release is *best-effort* (`del` without owner-token verification) —
     * Files-handler parity, pending a future CAS primitive. The lock TTL caps
     * the blast radius of a crashed owner.
     */
    public function __construct(
        string $prefix = 'php_session:',
        ?int $ttlSeconds = null,
        ?KvOpsInterface $ops = null,
        bool $lockSessions = false,
        int $lockTtlSeconds = 30,
        int $lockSpinIntervalUs = 20_000,
        int $lockMaxWaitMs = 5_000,
    ) {
        $this->prefix = $prefix;
        $this->ttlSeconds = $ttlSeconds ?? (int) \ini_get('session.gc_maxlifetime') ?: 1440;
        $this->ops = $ops ?? new SapiKvOps();
        $this->lockSessions = $lockSessions;
        $this->lockTtlSeconds = $lockTtlSeconds;
        $this->lockSpinIntervalUs = $lockSpinIntervalUs;
        $this->lockMaxWaitMs = $lockMaxWaitMs;
    }

    // ── SessionHandlerInterface ──────────────────────────────────────────────

    /**
     * `open` is a no-op — the SAPI is always reachable, there is no
     * connection to negotiate. It must return true so PHP doesn't abort
     * the session lifecycle. (The session id isn't known here — it's
     * handed to `read()` — so lock acquisition happens there.)
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * Releases the per-session lock if this handler holds one. Best-effort:
     * with no compare-and-delete primitive we cannot verify the owner token
     * before deleting, so the TTL is the real safety net (see the constructor).
     */
    public function close(): bool
    {
        $this->releaseLock();
        return true;
    }

    /**
     * Read a session's serialized payload. PHP expects an empty string
     * (NOT null/false) when the session doesn't exist — that's the cue
     * to start a fresh `$_SESSION`.
     *
     * When session locking is enabled this is where the per-session lock is
     * acquired (it's the first lifecycle call that knows the id), so the read
     * and everything the request does with `$_SESSION` afterwards is
     * single-flighted against other requests for the same id.
     */
    public function read(string $id): string
    {
        if ($this->lockSessions) {
            $this->acquireLock($id);
        }
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
     * Generate a new session id.
     *
     * We deliberately do **not** call `session_create_id()` here. When this
     * object is the active save handler, PHP dispatches id creation to this
     * very method — so calling `session_create_id()` from inside it is a
     * re-entrancy trap. Current PHP guards the re-entry, but that guard is an
     * implementation detail that has changed across versions; depending on it
     * is fragile. Generating the id directly here removes the hazard entirely
     * and still honours `session.sid_length` and
     * `session.sid_bits_per_character` (so ids look exactly like PHP's own).
     */
    public function create_sid(): string
    {
        $length = (int) \ini_get('session.sid_length') ?: 32;
        $bits = (int) \ini_get('session.sid_bits_per_character') ?: 4;

        // PHP's session id alphabets, keyed by sid_bits_per_character. All
        // three are subsets of [A-Za-z0-9,-], matching PHP's own output.
        $alphabet = match ($bits) {
            5 => '0123456789abcdefghijklmnopqrstuv',
            6 => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-,',
            default => '0123456789abcdef', // 4 bits (PHP default): hex
        };

        $max = \strlen($alphabet) - 1;
        $id = '';
        for ($i = 0; $i < $length; $i++) {
            // random_int() is CSPRNG-backed, matching the entropy source PHP
            // uses for session ids.
            $id .= $alphabet[\random_int(0, $max)];
        }

        return $id;
    }

    // ── session locking (opt-in) ─────────────────────────────────────────────

    /**
     * The KV key for a session id's lock. Namespaced under the handler's
     * prefix so multi-tenant deployments (per-host prefixes) never share a
     * lock across sites.
     */
    private function lockKeyFor(string $id): string
    {
        return $this->prefix . 'lock:' . $id;
    }

    /**
     * Acquire the per-session lock with a bounded spin. Returns true if the
     * lock was taken. On timeout it returns false and the request proceeds
     * lock-free — availability over strict mutual exclusion, and the same
     * outcome as the default (locking-disabled) path.
     */
    private function acquireLock(string $id): bool
    {
        $key = $this->lockKeyFor($id);
        $token = \bin2hex(\random_bytes(16));
        $deadlineMs = $this->nowMs() + $this->lockMaxWaitMs;

        do {
            if ($this->ops->setnx($key, $token, $this->lockTtlSeconds)) {
                $this->lockHeld = true;
                $this->lockKey = $key;
                return true;
            }
            if ($this->nowMs() >= $deadlineMs) {
                return false;
            }
            \usleep($this->lockSpinIntervalUs);
        } while (true);
    }

    /**
     * Best-effort release of a lock this handler acquired. No-op if we never
     * took one (e.g. locking disabled, or the acquire timed out).
     */
    private function releaseLock(): void
    {
        if ($this->lockHeld && $this->lockKey !== null) {
            // No CAS: we cannot prove we still own the token before deleting.
            // The lock TTL bounds the damage; this matches Files-handler parity.
            $this->ops->del($this->lockKey);
        }
        $this->lockHeld = false;
        $this->lockKey = null;
    }

    private function nowMs(): int
    {
        return (int) (\microtime(true) * 1000);
    }
}
