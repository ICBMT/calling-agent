<?php

/*
 * Bootstrap for WASM-based runs (phpunit.wasm.xml). Loads the sandbox
 * compatibility shims (see WasmShellExecShim.php for rationale) and then the
 * regular Composer autoloader.
 */

require __DIR__.'/WasmShellExecShim.php';
require dirname(__DIR__, 2).'/vendor/autoload.php';
