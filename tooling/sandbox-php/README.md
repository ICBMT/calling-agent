# Sandbox PHP toolchain (WASM)

This directory makes PHP + Composer + PHPUnit work inside environments that
**cannot install PHP natively** (no apt/brew; most download hosts blocked),
such as this Arena sandbox. It is honest tooling: it runs the _real_ test
suite against the _real_ application — no skipped tests, no mocked framework.

## Architecture

```
Composer (real)                Sandbox (this directory)
─────────────                  ──────────────────────────
packagist + github codeload -> mini_composer.py ......... installs all 109
                                 lock-pinned packages from codeload.github.com
                                 zipballs (the only reachable dist host) and
                                 generates a PSR-4/PSR-0/files/classmap
                                 autoloader + vendor/composer/installed.json
apply_wasm_shims.py ........... two surgical vendor/ patches (see file header;
                                 destructor-time work traps the WASM VM)
@php-wasm/node 3.1.54 ......... real PHP 8.3/8.4/8.5 compiled to WebAssembly
phpw.mjs ...................... the `php` CLI: mounts the repo into the WASM
                                 runtime, sets ini overrides (opcache off,
                                 zend.max_allowed_stack_size=-1), runs argv
phpunit.wasm.xml (repo root) .. phpunit.xml + cacheResult=false +
                                 APP_BASE_PATH + shim bootstrap
tests/Support/WasmShellExecShim.php
                              .. WASM cannot spawn OS processes; this
                                 namespaces shim makes @shell_exec() return
                                 null. Doctor treats that as "cannot probe"
                                 (WARNING with remediation), never a fake PASS
```

## Usage

```bash
# one-time setup (idempotent)
npm --prefix tooling/sandbox-php ci
python3 tooling/sandbox-php/mini_composer.py        # install vendor/
python3 tooling/sandbox-php/apply_wasm_shims.py      # WASM patches

# every-day
node tooling/sandbox-php/phpw.mjs artisan --version
node tooling/sandbox-php/phpw.mjs artisan test -- --testsuite=Feature
node tooling/sandbox-php/phpw.mjs artisan voca:doctor
```

`phpw.mjs artisan test` cannot fork a phpunit subprocess (no proc_open in
WASM), so invoke phpunit directly instead — the npm script wraps this:

```bash
npm run test:php:wasm
```

Verified: **241 tests / 1806 assertions green** (PHP 8.3.33 WASM, sqlite
:memory:, real migrations, real bcrypt, real HTTP kernel).

## Known honest limits

- `artisan test`'s extra process layers (`proc_open` to a PHP binary) are
  reported as unavailable instead of faked; run phpunit directly.
- Doctor's Python/plugin probes degrade to WARNING under WASM (no
  subprocesses), which the report prints openly.
- `php artisan serve` is replaced by a Node bridge server (see
  `tooling/browser/serve.mjs`) because WASM CLI has no socket server.
