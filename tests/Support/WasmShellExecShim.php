<?php

/*
 * Sandbox compatibility shim for running the PHPUnit suite under the
 * WebAssembly PHP runtime (see phpunit.wasm.xml and voca-tools/phpw.mjs).
 *
 * The WASM runtime cannot spawn OS subprocesses: popen()/proc_open() cross
 * an asyncify boundary that traps at the VM level. Laravel's `voca:doctor`
 * probes the Python venv via @shell_exec(), which would otherwise trap the
 * whole test run.
 *
 * PHP resolves unqualified function calls against the *current namespace*
 * first, so defining App\Console\Commands\shell_exec() here — loaded only by
 * the WASM-test bootstrap via phpunit.wasm.xml — shadows the native function
 * for that namespace.
 *
 * Behavior: returns null for every command. The app already treats an empty
 * @shell_exec() result as "cannot inspect this component" (a WARNING-level
 * outcome in voca:doctor with remediation guidance, never a silent PASS), so
 * no test expectation is weakened. Native runs (phpunit.xml, real PHP CLI)
 * never load this file and keep the real shell_exec() behavior.
 */

namespace App\Console\Commands {
    if (! function_exists(__NAMESPACE__.'\\shell_exec')) {
        function shell_exec(string $command): ?string
        {
            return null; // Subprocess probing is unavailable under WebAssembly.
        }
    }
}

namespace {
    // Intentionally empty. The shim above only affects the command namespace.
}
