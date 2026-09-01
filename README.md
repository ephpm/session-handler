# ephpm/session-handler

PHP session save handler that stores `$_SESSION` data in
[ePHPm](https://ephpm.dev)'s in-process KV store via the `ephpm_kv_*`
SAPI functions. Drop-in replacement for the built-in Files / Memcached
/ Redis session handlers — no daemon, no socket, no `/var/lib/php/sessions/`
to manage. Works with any PHP code that calls `session_start()`,
including frameworks that wrap it (WordPress, plain CodeIgniter, custom
PHP) without any framework integration.

```php
require __DIR__ . '/vendor/autoload.php';

session_set_save_handler(new \Ephpm\SessionHandler\KvSessionHandler(), true);
session_start();

$_SESSION['user_id'] = 42;       // stored in the KV via ephpm_kv_set
echo $_SESSION['user_id'];        // read via ephpm_kv_get on the next request
```

Each `$_SESSION` access resolves to a direct C call into the Rust
DashMap backing ePHPm's KV store. There's no Redis daemon, no PHP-FPM
shared filesystem assumption, and no inter-process IPC.

---

## Table of contents

- [Why this exists](#why-this-exists)
- [Requirements](#requirements)
- [Install](#install)
- [Setup: a fresh PHP project](#setup-a-fresh-php-project)
- [Setup: WordPress](#setup-wordpress)
- [Setup: legacy / no-framework apps via `auto_prepend_file`](#setup-legacy--no-framework-apps-via-auto_prepend_file)
- [Configuration](#configuration)
- [Verifying the handler is active](#verifying-the-handler-is-active)
- [Behavior reference](#behavior-reference)
- [Limitations](#limitations)
- [Testing without ePHPm](#testing-without-ephpm)
- [Troubleshooting](#troubleshooting)
- [How it works](#how-it-works)
- [License](#license)

---

## Why this exists

The companion packages `ephpm/cache-laravel` and `ephpm/cache-symfony`
cover frameworks that own the cache abstraction. But sessions are
broader than that — `session_start()` is a baked-in PHP feature,
older codebases pre-date framework session abstractions, and even
modern code (WordPress, CodeIgniter, large parts of Drupal core) reaches
for native `$_SESSION` directly.

This package gives all of them the SAPI fast path without touching
their session code. It's the smallest possible swap: replace the
default Files handler with one line in your bootstrap, and every
`$_SESSION` write becomes an in-process function call into the Rust
KV store instead of a `flock()` + `fwrite()` against
`/var/lib/php/sessions/sess_…`.

---

## Requirements

- **PHP 8.2+** with `ext-session` (which is bundled with PHP — you
  almost certainly already have it).
- **The ePHPm runtime** — any tagged release works (the `ephpm_kv_*`
  SAPI functions this handler calls have shipped since ePHPm v0.1.0;
  current release: v0.8.6). The functions are
  registered by ePHPm's embedded PHP. Outside ePHPm,
  `SapiKvOps::__construct()` throws fast so you know immediately that
  you're not running where the handler can work. For development
  without ePHPm see [Testing without ePHPm](#testing-without-ephpm).

Confirm the SAPI is present from any PHP file:

```php
var_dump(function_exists('ephpm_kv_get'));   // expect bool(true)
```

---

## Install

ePHPm packages are distributed via their GitHub repositories, not
Packagist. Add this repo as a Composer `vcs` repository, then require
the package (`ephpm/session-handler` is tagged `v0.1.0`, so `^0.1`
resolves):

```bash
composer config repositories.ephpm/session-handler vcs https://github.com/ephpm/session-handler
composer require ephpm/session-handler:^0.1
```

That's it. No framework deps, nothing else to pull in.

---

## Setup: a fresh PHP project

Smallest possible end-to-end — drop into an ePHPm document root.

### 1. Project layout

```
my-app/
├── composer.json
├── vendor/
└── public/
    └── index.php
```

### 2. `composer.json`

```json
{
    "name": "acme/my-app",
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ephpm/session-handler" }
    ],
    "require": {
        "php": "^8.2",
        "ephpm/session-handler": "^0.1"
    }
}
```

```bash
composer install
```

### 3. `public/index.php`

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Ephpm\SessionHandler\KvSessionHandler;

// Register before session_start(). The `true` second arg makes PHP
// auto-flush via register_shutdown_function() so unwritten changes
// don't get lost if the script doesn't reach a clean exit.
session_set_save_handler(new KvSessionHandler(), true);
session_start();

$_SESSION['hits'] = ($_SESSION['hits'] ?? 0) + 1;

header('Content-Type: text/plain');
echo "You've visited this page {$_SESSION['hits']} times this session.\n";
```

### 4. `ephpm.toml`

```toml
[server]
listen = "127.0.0.1:8080"
document_root = "./public"
```

### 5. Run it

```bash
ephpm serve --config ephpm.toml
```

Open `http://127.0.0.1:8080/` in a browser, refresh a few times, watch
the counter increment. Every refresh round-trips the session through a
direct function call into the KV store — no files, no `flock`.

---

## Setup: WordPress

WordPress doesn't use `$_SESSION` itself, but a lot of plugins do
(WooCommerce, BuddyPress, contact forms, custom auth integrations).
Wire the handler in via a must-use plugin so it's active before any
plugin code runs.

### `wp-content/mu-plugins/ephpm-sessions.php`

```php
<?php
/*
 * Plugin Name: ephpm Session Storage
 * Description: Routes WordPress's PHP sessions through ePHPm's KV store.
 */

require_once ABSPATH . 'vendor/autoload.php';

session_set_save_handler(new \Ephpm\SessionHandler\KvSessionHandler(), true);
```

(`mu-plugins/` runs before regular plugins; the handler is in place
before anything calls `session_start()`.)

If your WordPress install doesn't already have a Composer-managed
`vendor/` directory, install it at the WP root:

```bash
cd /path/to/wordpress
composer init --no-interaction --name=site/wp
composer config repositories.ephpm/session-handler vcs https://github.com/ephpm/session-handler
composer require ephpm/session-handler:^0.1
```

Then point the autoload include in the mu-plugin at that vendor path.

---

## Setup: legacy / no-framework apps via `auto_prepend_file`

For apps that have no Composer setup or no clear bootstrap entry
point, register the handler globally via `auto_prepend_file`.

### `/var/www/ephpm-session-bootstrap.php`

```php
<?php
require_once '/path/to/your/vendor/autoload.php';
session_set_save_handler(new \Ephpm\SessionHandler\KvSessionHandler(), true);
```

### `ephpm.toml`

```toml
[php]
ini_overrides = [
    ["auto_prepend_file", "/var/www/ephpm-session-bootstrap.php"],
]
```

Now the handler is registered on every request, before any application
code runs, regardless of which entry point gets hit. Good for legacy
codebases with hundreds of `.php` files at the docroot.

---

## Configuration

The constructor takes three optional arguments:

```php
new KvSessionHandler(
    string $prefix = 'php_session:',     // key prefix in the KV store
    ?int $ttlSeconds = null,             // null = read session.gc_maxlifetime
    ?KvOpsInterface $ops = null,         // backend override (tests)
);
```

### Multi-tenant: per-host session prefixes

If you're running multiple sites under one ePHPm with `[server.sites_dir]`
enabled, give each site its own prefix to keep their session keys
isolated:

```php
$host = $_SERVER['HTTP_HOST'] ?? 'default';
session_set_save_handler(new KvSessionHandler(prefix: "session:{$host}:"), true);
```

ePHPm's KV store also has built-in multi-tenant isolation
(`[server.sites_dir]` gives each vhost its own DashMap), so the prefix
is really just human-readable namespacing on top of that — the KV
store already prevents cross-site reads at the store level.

### Custom session lifetime

By default the handler reads `session.gc_maxlifetime` from `php.ini`
(typically 1440 seconds / 24 minutes). Override per-app:

```php
session_set_save_handler(new KvSessionHandler(ttlSeconds: 7200), true);  // 2h
```

The TTL is reset on every write, so an actively-used session never
times out mid-conversation.

---

## Verifying the handler is active

A two-line check from any session-using script:

```php
session_start();
echo "session save handler: " . ini_get('session.save_handler') . "\n";  // 'user'
echo "handler class: " . get_class(session_get_save_handler()) . "\n";
//   Ephpm\SessionHandler\KvSessionHandler
```

If `session.save_handler` is anything other than `user` after you
called `session_set_save_handler()`, something else has overridden it.

---

## Behavior reference

| Operation                                         | What happens                                                                                            |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `session_start()` (id from cookie)                | `validateId()` checks the KV via `ephpm_kv_exists`. If missing, PHP regenerates the id.                 |
| Reading `$_SESSION` for the first time            | `read()` fetches the serialized payload via `ephpm_kv_get`.                                             |
| `$_SESSION['x'] = ...` then script end            | `write()` serializes and stores via `ephpm_kv_set` with the configured TTL.                             |
| `session.lazy_write = 1` + no payload changes     | `updateTimestamp()` refreshes TTL via `ephpm_kv_expire` without rewriting the payload.                  |
| `session_destroy()`                               | `destroy()` calls `ephpm_kv_del`.                                                                       |
| `session_gc()`                                    | No-op (returns 0) — the KV store handles expiry natively via TTL, no external sweep needed.             |
| Session cookie expires / gets cleared client-side | Server-side key persists until its TTL elapses, then the KV store reclaims it lazily on next access.    |

PHP's built-in session serialization (`session.serialize_handler`,
default `php`) is used unchanged — this handler stores whatever bytes
PHP hands it. You can switch to `php_serialize` or `php_binary` and
nothing here cares.

---

## Limitations

- **No cross-process locking on a single session id.** PHP's Files
  handler uses `flock()` so two concurrent requests with the same
  session cookie serialize at the storage layer. This handler doesn't —
  with ePHPm's threading model all PHP execution is in one process,
  and you can opt in to single-flighting per session id at the
  application level if you need it (modern apps usually call
  `session_write_close()` early to *avoid* serialization, since it
  kills concurrent AJAX). Most apps don't actually want session locking
  — but if yours does, this is a behavior change worth knowing about.
- **Restart loses session state.** ePHPm's KV is in-process; an
  `ephpm restart` clears it. If you need session persistence across
  restarts, either run a clustered ePHPm setup (gossip-replicated KV
  survives single-node restarts) or stick with the Files handler. This
  is a deliberate ePHPm design choice — the value prop is sub-microsecond
  access, which means in-memory.
- **Cookie management is unchanged.** This handler only changes how
  session payloads are *stored*; cookie name, path, secure flag,
  SameSite, lifetime — all still controlled by `session.cookie_*` ini
  settings or `session_set_cookie_params()`.

---

## Testing without ePHPm

The constructor accepts an `Ephpm\SessionHandler\KvOpsInterface` so
you can run tests on plain `php-cli` without the ePHPm runtime:

```php
use Ephpm\SessionHandler\InMemoryKvOps;
use Ephpm\SessionHandler\KvSessionHandler;

$handler = new KvSessionHandler('php_session:', 1440, new InMemoryKvOps());
$handler->write('sess-test', 'user|s:5:"alice";');
assert($handler->read('sess-test') === 'user|s:5:"alice";');
```

`InMemoryKvOps` stores everything in PHP arrays — for tests only, no
eviction, no memory limit.

---

## Troubleshooting

### `RuntimeException: ephpm KV SAPI functions are not available`

You're running under stock PHP-FPM, Apache mod_php, or `php-cli` —
not under ePHPm. The handler can only call into the KV store when
ePHPm is the SAPI. Either run your app via `ephpm serve`, or pass
`InMemoryKvOps` as the third constructor arg for local tests.

### Session works on first request, fails to persist on second

Cookie issues. Check that `session_set_save_handler()` is called
*before* `session_start()`, and confirm the session cookie is being
sent back by the browser (DevTools → Application → Cookies). Also
make sure `session.cookie_lifetime` isn't 0 if you need persistence
beyond browser close.

### Sessions disappear after `ephpm restart`

Expected — see [Limitations](#limitations). The KV store is in-process.
For persistence across restarts use a clustered ePHPm deploy where
gossip-replicated KV survives single-node loss.

### Two browser tabs interleave session writes

There's no per-session-id locking. PHP's Files handler used to provide
this; this handler does not. Either call `session_write_close()` as
soon as you've extracted what you need (the modern preferred pattern,
allows concurrent AJAX), or keep your conflicting writes idempotent.

### Counter `$_SESSION['hits']` doesn't increment past 1

You're probably not running `session_start()` on every request, or
the cookie isn't surviving between requests. Add
`var_dump(session_id(), $_SESSION)` near the top of your script and
verify the same id comes back across refreshes.

---

## How it works

ePHPm runs PHP inside the same OS process as the KV store via the
embed SAPI. The store is a Rust [`DashMap`](https://docs.rs/dashmap/)
plus TTL management. ePHPm registers a small set of host functions
(`ephpm_kv_get`, `ephpm_kv_set`, `ephpm_kv_del`, `ephpm_kv_exists`,
`ephpm_kv_expire`, `ephpm_kv_ttl`, `ephpm_kv_pttl`, `ephpm_kv_incr_by`)
into PHP's global function table. Calling one is a direct C call into
Rust — no socket, no protocol parser.

This package wraps those functions in a
`SessionHandlerInterface` + `SessionUpdateTimestampHandlerInterface`
+ `SessionIdInterface` implementation so PHP's existing session
machinery — `session_start()`, `$_SESSION`, `session_destroy()`,
`session.lazy_write`, the gc cycle — all route through the SAPI
without any application-level changes.

See [ephpm.dev/architecture/kv-store/](https://ephpm.dev/architecture/kv-store/)
for the architecture and [ephpm.dev/guides/kv-from-php/](https://ephpm.dev/guides/kv-from-php/)
for the underlying SAPI surface.

---

## License

MIT — see [LICENSE](LICENSE).
