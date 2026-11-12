#!/usr/bin/env python3
"""Apply WebAssembly compatibility shims to the sandbox vendor tree.

The sandboxed PHP runtime (@php-wasm/node) runs PHP inside a WASM/asyncify
virtual machine where two perfectly-valid PHP behaviors trap at the VM level:

1. Real work executed from a *destructor* (an asyncify boundary there is
   unrewindable):
   - Illuminate\\Support\\Sleep defers its actual usleep() to __destruct().
     Laravel's Timebox (wraps every failed Auth::attempt) uses Sleep,
     so failed logins trap the VM. Shim: Timebox sleeps directly — the
     timing hardening is preserved with a REAL usleep (nothing is faked).
   - Illuminate\\Testing\\PendingCommand runs the console command from
     __destruct() when the return value is discarded, exactly what
     RefreshDatabase/DatabaseMigrations/DatabaseTruncation do for
     `migrate:fresh`. Shim: call ->run() explicitly. Migrations still run
     for real; only the *when* changes (a tick earlier at statement time).

2. Additionally, the harness (phpw.mjs) must set
   zend.max_allowed_stack_size=-1 because the WASM build's C-stack depth
   estimation misfires on legitimately deep call frames
   (TestCase -> RefreshDatabase -> Artisan -> Migrator), producing
   `RuntimeError: unreachable` in zend_leave_helper.

These shims are applied AFTER mini_composer.py installs vendor/ and are
idempotent. vendor/ is gitignored; these shims exist only on machines that
run this script (i.e., WASM sandboxes). Native installs use real Composer
and never see them. Every shim is marked with a `sandbox-wasm` comment.
"""

import os
import sys

ROOT = os.environ.get("APP_ROOT", "/home/user/calling-agent")
VENDOR = os.path.join(ROOT, "vendor")

SHIMS = [
    (
        "laravel/framework/src/Illuminate/Support/Timebox.php",
        "        Sleep::usleep($microseconds);",
        "        \\usleep($microseconds); // sandbox-wasm: Sleep defers the real usleep() to __destruct, which traps under asyncify",
    ),
    (
        "laravel/framework/src/Illuminate/Foundation/Testing/RefreshDatabase.php",
        "        $this->artisan('migrate:fresh', $this->migrateFreshUsing());",
        "        $this->artisan('migrate:fresh', $this->migrateFreshUsing())->run(); // sandbox-wasm: PendingCommand runs from __destruct; that traps under asyncify, so run eagerly",
    ),
    (
        "laravel/framework/src/Illuminate/Foundation/Testing/DatabaseMigrations.php",
        "        $this->artisan('migrate:fresh', $this->migrateFreshUsing());",
        "        $this->artisan('migrate:fresh', $this->migrateFreshUsing())->run(); // sandbox-wasm: PendingCommand runs from __destruct; that traps under asyncify, so run eagerly",
    ),
    (
        "laravel/framework/src/Illuminate/Foundation/Testing/DatabaseTruncation.php",
        "        $this->artisan('migrate:fresh', $this->migrateFreshUsing());",
        "        $this->artisan('migrate:fresh', $this->migrateFreshUsing())->run(); // sandbox-wasm: PendingCommand runs from __destruct; that traps under asyncify, so run eagerly",
    ),
]


def main() -> int:
    applied = skipped = 0
    for relpath, needle, replacement in SHIMS:
        path = os.path.join(VENDOR, relpath)
        if not os.path.isfile(path):
            print(f"[skip] missing {relpath}")
            skipped += 1
            continue
        src = open(path).read()
        if "sandbox-wasm" in src:
            print(f"[ok]   already shimmed {relpath}")
            skipped += 1
            continue
        if needle not in src:
            print(f"[warn] needle not found in {relpath} (framework version changed?)")
            skipped += 1
            continue
        open(path, "w").write(src.replace(needle, replacement, 1))
        print(f"[shim] {relpath}")
        applied += 1
    print(f"shims applied={applied} skipped={skipped}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
