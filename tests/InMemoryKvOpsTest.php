<?php

declare(strict_types=1);

namespace Ephpm\SessionHandler\Tests;

use Ephpm\SessionHandler\InMemoryKvOps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryKvOps::class)]
final class InMemoryKvOpsTest extends TestCase
{
    public function test_set_and_get_round_trip(): void
    {
        $ops = new InMemoryKvOps();
        self::assertTrue($ops->set('greeting', 'hello'));
        self::assertSame('hello', $ops->get('greeting'));
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        self::assertNull((new InMemoryKvOps())->get('nope'));
    }

    public function test_del_returns_one_when_present_zero_when_missing(): void
    {
        $ops = new InMemoryKvOps();
        $ops->set('k', 'v');
        self::assertSame(1, $ops->del('k'));
        self::assertSame(0, $ops->del('k'));
        self::assertNull($ops->get('k'));
    }

    public function test_exists_reflects_set_and_del(): void
    {
        $ops = new InMemoryKvOps();
        self::assertFalse($ops->exists('k'));
        $ops->set('k', 'v');
        self::assertTrue($ops->exists('k'));
        $ops->del('k');
        self::assertFalse($ops->exists('k'));
    }

    public function test_incr_creates_key_then_accumulates(): void
    {
        $ops = new InMemoryKvOps();
        self::assertSame(1, $ops->incrBy('hits', 1));
        self::assertSame(6, $ops->incrBy('hits', 5));
        self::assertSame(4, $ops->incrBy('hits', -2));
        self::assertSame('4', $ops->get('hits'));
    }

    public function test_incr_throws_on_non_integer_value(): void
    {
        $ops = new InMemoryKvOps();
        $ops->set('label', 'not-a-number');
        $this->expectException(\RuntimeException::class);
        $ops->incrBy('label', 1);
    }

    public function test_set_with_ttl_then_pttl_within_window(): void
    {
        $ops = new InMemoryKvOps();
        $ops->set('k', 'v', 60);
        $pttl = $ops->pttl('k');
        self::assertGreaterThan(0, $pttl);
        self::assertLessThanOrEqual(60_000, $pttl);
    }

    public function test_pttl_minus_one_for_no_expiry(): void
    {
        $ops = new InMemoryKvOps();
        $ops->set('k', 'v');
        self::assertSame(-1, $ops->pttl('k'));
    }

    public function test_pttl_minus_two_for_missing_key(): void
    {
        self::assertSame(-2, (new InMemoryKvOps())->pttl('nope'));
    }

    public function test_ttl_rounds_up_to_seconds(): void
    {
        $ops = new InMemoryKvOps();
        $ops->set('k', 'v', 1);
        // pttl should be (0, 1000] → ttl rounded up = 1.
        self::assertSame(1, $ops->ttl('k'));
    }

    public function test_expire_only_succeeds_on_existing_key(): void
    {
        $ops = new InMemoryKvOps();
        self::assertFalse($ops->expire('missing', 30));
        $ops->set('k', 'v');
        self::assertTrue($ops->expire('k', 30));
        $pttl = $ops->pttl('k');
        self::assertGreaterThan(0, $pttl);
        self::assertLessThanOrEqual(30_000, $pttl);
    }

    public function test_expire_with_zero_or_negative_is_noop(): void
    {
        $ops = new InMemoryKvOps();
        $ops->set('k', 'v', 60);
        self::assertFalse($ops->expire('k', 0));
        self::assertFalse($ops->expire('k', -5));
        // Original TTL unchanged.
        self::assertGreaterThan(0, $ops->pttl('k'));
    }

    public function test_flush_clears_values_and_deadlines(): void
    {
        $ops = new InMemoryKvOps();
        $ops->set('a', 'one');
        $ops->set('b', 'two', 60);
        self::assertTrue($ops->flush());
        self::assertNull($ops->get('a'));
        self::assertNull($ops->get('b'));
        self::assertSame(-2, $ops->pttl('b'));
    }
}
