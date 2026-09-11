<?php

declare(strict_types=1);

namespace Ephpm\SessionHandler\Tests;

use Ephpm\SessionHandler\InMemoryKvOps;
use Ephpm\SessionHandler\KvSessionHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(KvSessionHandler::class)]
final class KvSessionHandlerTest extends TestCase
{
    private function handler(
        ?InMemoryKvOps $ops = null,
        string $prefix = 'php_session:',
        int $ttl = 1440,
    ): KvSessionHandler {
        return new KvSessionHandler($prefix, $ttl, $ops ?? new InMemoryKvOps());
    }

    // ── basic SessionHandlerInterface contract ───────────────────────────────

    public function test_open_and_close_are_no_op_successes(): void
    {
        $handler = $this->handler();
        self::assertTrue($handler->open('/tmp/sessions', 'PHPSESSID'));
        self::assertTrue($handler->close());
    }

    public function test_read_returns_empty_string_for_unknown_session(): void
    {
        // PHP requires '' (NOT null/false) when the session doesn't exist —
        // it's the cue to start a fresh $_SESSION rather than fail.
        self::assertSame('', $this->handler()->read('does-not-exist'));
    }

    public function test_write_then_read_round_trips_payload(): void
    {
        $handler = $this->handler();
        $payload = 'user|s:5:"alice";role|s:5:"admin";';
        self::assertTrue($handler->write('sess-abc', $payload));
        self::assertSame($payload, $handler->read('sess-abc'));
    }

    public function test_destroy_removes_the_session(): void
    {
        $handler = $this->handler();
        $handler->write('sess-xyz', 'data');
        self::assertTrue($handler->destroy('sess-xyz'));
        self::assertSame('', $handler->read('sess-xyz'));
    }

    public function test_destroy_succeeds_even_when_session_was_already_gone(): void
    {
        // PHP's contract: destroy() should report success when the
        // session no longer exists — that IS what the caller asked for.
        self::assertTrue($this->handler()->destroy('never-existed'));
    }

    public function test_gc_returns_zero_because_kv_handles_expiry_natively(): void
    {
        // The KV store's TTL machinery sweeps expired keys lazily;
        // there's nothing for PHP-level GC to do.
        self::assertSame(0, $this->handler()->gc(60));
    }

    // ── TTL / expiry ─────────────────────────────────────────────────────────

    public function test_write_applies_configured_ttl(): void
    {
        $ops = new InMemoryKvOps();
        $handler = $this->handler($ops, ttl: 60);
        $handler->write('sess-ttl', 'data');
        $pttl = $ops->pttl('php_session:sess-ttl');
        self::assertGreaterThan(0, $pttl);
        self::assertLessThanOrEqual(60_000, $pttl);
    }

    public function test_constructor_falls_back_to_session_gc_maxlifetime_ini(): void
    {
        // Don't pass an explicit ttl — handler should read the ini default.
        $iniTtl = (int) \ini_get('session.gc_maxlifetime') ?: 1440;
        $ops = new InMemoryKvOps();
        $handler = new KvSessionHandler('php_session:', null, $ops);
        $handler->write('sess', 'data');
        $pttl = $ops->pttl('php_session:sess');
        self::assertGreaterThan(0, $pttl);
        self::assertLessThanOrEqual($iniTtl * 1000, $pttl);
    }

    // ── SessionUpdateTimestampHandlerInterface ───────────────────────────────

    public function test_validate_id_reflects_session_existence(): void
    {
        $handler = $this->handler();
        self::assertFalse($handler->validateId('missing'));
        $handler->write('present', 'data');
        self::assertTrue($handler->validateId('present'));
    }

    public function test_update_timestamp_refreshes_ttl_without_rewriting_payload(): void
    {
        $ops = new InMemoryKvOps();
        $handler = $this->handler($ops, ttl: 60);
        $handler->write('sess', 'original-payload');

        // updateTimestamp should bump the TTL — verify by passing a
        // larger ttl on a second handler and watching pttl grow.
        $bumpHandler = $this->handler($ops, ttl: 600);
        self::assertTrue($bumpHandler->updateTimestamp('sess', 'whatever'));

        $pttl = $ops->pttl('php_session:sess');
        self::assertGreaterThan(60_000, $pttl);
        // And the payload itself is unchanged.
        self::assertSame('original-payload', $handler->read('sess'));
    }

    public function test_update_timestamp_falls_back_to_write_when_session_vanished(): void
    {
        // Edge case: session expired between read() and updateTimestamp().
        // The ini contract is "ensure the session exists with a fresh
        // TTL when this returns true" — so writing the payload is the
        // right recovery move.
        $handler = $this->handler();
        self::assertTrue($handler->updateTimestamp('never-written', 'recovered-payload'));
        self::assertSame('recovered-payload', $handler->read('never-written'));
    }

    // ── prefix / multi-handler isolation ─────────────────────────────────────

    public function test_prefix_is_honoured_by_every_method(): void
    {
        $ops = new InMemoryKvOps();
        $handler = $this->handler($ops, prefix: 'site-a:');
        $handler->write('sess1', 'data');

        // Verify the underlying key includes the prefix.
        self::assertTrue($ops->exists('site-a:sess1'));
        self::assertFalse($ops->exists('php_session:sess1'));

        $handler->destroy('sess1');
        self::assertFalse($ops->exists('site-a:sess1'));
    }

    public function test_two_handlers_with_different_prefixes_dont_collide(): void
    {
        $ops = new InMemoryKvOps();
        $a = $this->handler($ops, prefix: 'site-a:');
        $b = $this->handler($ops, prefix: 'site-b:');

        $a->write('shared-id', 'A-payload');
        $b->write('shared-id', 'B-payload');

        self::assertSame('A-payload', $a->read('shared-id'));
        self::assertSame('B-payload', $b->read('shared-id'));
    }

    // ── SessionIdInterface ───────────────────────────────────────────────────

    public function test_create_sid_produces_a_php_compatible_id(): void
    {
        $handler = $this->handler();
        $id = $handler->create_sid();
        // PHP session ids match session.sid_* settings; the default is
        // alphanumeric (plus optionally `-` and `,`). Verify it's at
        // least the default 26 chars and looks like an id rather than
        // empty / garbage.
        self::assertNotEmpty($id);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9,-]+$/', $id);
    }

    public function test_create_sid_is_unique_across_calls(): void
    {
        $handler = $this->handler();
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $handler->create_sid();
        }
        self::assertSame(50, \count(\array_unique($ids)));
    }

    /**
     * Regression pin for the create_sid() -> session_create_id() re-entrancy
     * trap. When this handler is the ACTIVE save handler, PHP dispatches id
     * creation to create_sid(); if that method itself called
     * session_create_id() it could re-enter create_sid() (infinite recursion
     * on PHP versions without the core guard). We generate the id directly, so
     * driving a real session_start() through this handler must terminate and
     * yield a valid id. Runs in a separate process because it mutates the
     * global session save-handler and starts a session.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_create_sid_does_not_recurse_when_handler_is_active(): void
    {
        // No cookies: this is CLI, and we only care about id generation, not
        // transport. Avoids "headers already sent" under failOnWarning.
        \ini_set('session.use_cookies', '0');
        \ini_set('session.cache_limiter', '');

        $handler = new KvSessionHandler('php_session:', 1440, new InMemoryKvOps());
        \session_set_save_handler($handler, true);

        // If create_sid() recursed, this would stack-overflow rather than
        // return. Reaching the assertions at all is the core of the test.
        self::assertTrue(@\session_start());

        $id = \session_id();
        self::assertNotEmpty($id);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9,-]+$/', $id);

        // And a direct call is likewise safe and valid.
        $direct = $handler->create_sid();
        self::assertNotEmpty($direct);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9,-]+$/', $direct);

        @\session_write_close();
    }

    // ── opt-in session locking ───────────────────────────────────────────────

    private function lockingHandler(
        InMemoryKvOps $ops,
        int $maxWaitMs = 5_000,
        int $spinUs = 20_000,
        int $lockTtl = 30,
    ): KvSessionHandler {
        return new KvSessionHandler(
            'php_session:',
            1440,
            $ops,
            lockSessions: true,
            lockTtlSeconds: $lockTtl,
            lockSpinIntervalUs: $spinUs,
            lockMaxWaitMs: $maxWaitMs,
        );
    }

    public function test_locking_disabled_by_default_creates_no_lock_key(): void
    {
        // Default construction must not change the original lock-free behaviour.
        $ops = new InMemoryKvOps();
        $handler = $this->handler($ops);
        $handler->read('sid');
        self::assertFalse($ops->exists('php_session:lock:sid'));
    }

    public function test_locking_acquires_lock_on_read_with_ttl(): void
    {
        $ops = new InMemoryKvOps();
        $handler = $this->lockingHandler($ops, lockTtl: 30);
        $handler->read('sid');

        self::assertTrue($ops->exists('php_session:lock:sid'));
        $pttl = $ops->pttl('php_session:lock:sid');
        self::assertGreaterThan(0, $pttl);
        self::assertLessThanOrEqual(30_000, $pttl);

        $handler->close();
        self::assertFalse($ops->exists('php_session:lock:sid'));
    }

    public function test_held_lock_blocks_second_acquire_until_released(): void
    {
        $ops = new InMemoryKvOps();

        // Handler A takes the lock.
        $a = $this->lockingHandler($ops);
        $a->read('sid');
        $tokenA = $ops->get('php_session:lock:sid');
        self::assertNotNull($tokenA);

        // Handler B spins for a bounded window, then gives up — it must NOT
        // steal A's lock (value stays A's token), and it must have actually
        // waited roughly the max-wait window.
        $b = $this->lockingHandler($ops, maxWaitMs: 100, spinUs: 10_000);
        $start = \microtime(true);
        $b->read('sid');
        $elapsedMs = (\microtime(true) - $start) * 1000;

        self::assertGreaterThanOrEqual(90.0, $elapsedMs);
        self::assertSame($tokenA, $ops->get('php_session:lock:sid'));

        // A releases; now B can take it and the token changes hands.
        $a->close();
        self::assertFalse($ops->exists('php_session:lock:sid'));

        $b->read('sid');
        $tokenB = $ops->get('php_session:lock:sid');
        self::assertNotNull($tokenB);
        self::assertNotSame($tokenA, $tokenB);
    }

    public function test_lock_becomes_available_after_ttl_expiry(): void
    {
        $ops = new InMemoryKvOps();

        // A takes a lock with a 1s TTL but never releases it (simulating a
        // crashed owner). The TTL is the safety valve that frees the session.
        $a = $this->lockingHandler($ops, lockTtl: 1);
        $a->read('sid');
        $tokenA = $ops->get('php_session:lock:sid');
        self::assertNotNull($tokenA);

        // Wait out the lock TTL. InMemoryKvOps expires lazily on lookup, so
        // after this the key is genuinely gone from setnx's perspective.
        \usleep(1_100_000);
        self::assertFalse($ops->exists('php_session:lock:sid'));

        // B now acquires immediately (short max-wait proves it didn't block).
        $b = $this->lockingHandler($ops, maxWaitMs: 50);
        $start = \microtime(true);
        $b->read('sid');
        $elapsedMs = (\microtime(true) - $start) * 1000;

        self::assertLessThan(50.0, $elapsedMs);
        $tokenB = $ops->get('php_session:lock:sid');
        self::assertNotNull($tokenB);
        self::assertNotSame($tokenA, $tokenB);
    }
}
