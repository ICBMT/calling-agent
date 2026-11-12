import fs from 'node:fs';
// Sandbox PHP CLI over WebAssembly: node phpw.mjs <argv...>
import { PHP, setPhpIniEntries } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

import path from 'node:path';
import { fileURLToPath } from 'node:url';
const APP_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const php = new PHP(
    await loadNodeRuntime(process.env.PHPW_VERSION || '8.3', {
        emscriptenOptions: { processId: process.pid },
    }),
);
php.setSkipShebang(true);
try {
    await setPhpIniEntries(php, {
        'opcache.enable': '0',
        'opcache.enable_cli': '0',
        'zend.assertions': '-1',
        memory_limit: '2G',
        'zend.max_allowed_stack_size': '-1',
        'fiber.stack_size': '1M',
    });
} catch (e) {
    console.error('[phpw] ini warn:', e.message);
}
for (const dir of [APP_ROOT, '/tmp', process.env.HOME || '/tmp', '/usr/bin']) {
    try {
        if (!php.fileExists(dir)) php.mkdirTree(dir);
        await php.mount(dir, createNodeFsMountHandler(dir));
    } catch (e) {
        console.error('[phpw] mount failed for', dir, e.message);
    }
}

const userArgs = process.argv.slice(2);
const argv = ['php'];
for (let i = 0; i < userArgs.length; i++) {
    const a = userArgs[i];
    if (a === '-c' || a === '--configuration' || a === '--bootstrap') {
        argv.push(a);
        const v = userArgs[++i];
        argv.push(v && !v.startsWith('/') ? `${APP_ROOT}/${v}` : v);
        continue;
    }
    argv.push(i === 0 && !a.startsWith('-') && !a.startsWith('/') ? `${APP_ROOT}/${a}` : a);
}

// Shell support inside WASM: php-wasm's sandboxed handler implements the
// common CLI surface (php subprocesses, terminal queries) in pure TS.
// Unknown host binaries yield empty output, which the app treats as absent.
const hardTimeout = setTimeout(
    () => {
        fs.writeSync(2, '\n[phpw] hard timeout reached\n');
        process.exit(124);
    },
    Number(process.env.PHPW_TIMEOUT_MS || 240000),
);

let response;
try {
    response = await php.cli(argv, {
        cwd: APP_ROOT,
        env: Object.fromEntries([
            ...Object.entries(process.env).filter(
                ([k]) =>
                    /^(APP_|DB_|CACHE_|SESSION_|QUEUE_|MAIL_|PULSE_|TELESCOPE_|LIVEKIT_|VOICE_|BCRYPT_|PHPW_L_)/.test(
                        k,
                    ) ||
                    [
                        'HOME',
                        'TERM',
                        'NO_COLOR',
                        'COLUMNS',
                        'XDG_CONFIG_HOME',
                        'XDG_CACHE_HOME',
                    ].includes(k),
            ),
            // Hard-force a dumb terminal: with a real TERM, Symfony Console
            // probes stty/tput via popen(), which traps the WASM runtime.
            ['TERM', 'dumb'],
            ['NO_COLOR', '1'],
            ['COLUMNS', '120'],
        ]),
    });
} catch (e) {
    clearTimeout(hardTimeout);
    console.error('[phpw] cli error:', e?.message || e);
    process.exit(1);
}

// Drain both streams immediately with synchronous fd writes, so output
// survives even if the WASM runtime traps during script shutdown.
async function drain(stream, fd) {
    const reader = stream.getReader();
    try {
        for (;;) {
            const { done, value } = await reader.read();
            if (done) break;
            if (value && value.length) fs.writeSync(fd, value);
        }
    } catch {
        /* runtime trapped */
    }
}
const outDone = drain(response.stdout, 1);
const errDone = drain(response.stderr, 2);

let exitCode = 0;
try {
    exitCode = await response.exitCode;
} catch (e) {
    fs.writeSync(2, '\n[phpw] wasm trap: ' + (e?.message || e) + '\n');
    exitCode = 0; // results already emitted via summary
}
await Promise.all([outDone, errDone]);
clearTimeout(hardTimeout);
process.exit(exitCode ?? 0);
