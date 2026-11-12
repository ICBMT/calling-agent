// Extracts the @sparticuz/chromium bundle (browser binary + Amazon Linux
// 2023 system libs + fonts) from the npm package into tooling/browser/.chromium/
// so headless Chrome works without any external download host.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

const TOOL_ROOT = path.dirname(fileURLToPath(import.meta.url));
const DEST = path.join(TOOL_ROOT, '.chromium');
const BIN = path.join(TOOL_ROOT, 'node_modules', '@sparticuz', 'chromium', 'bin');

fs.mkdirSync(DEST, { recursive: true });
fs.mkdirSync(path.join(DEST, 'libs'), { recursive: true });

const expandTar = (name, into) => {
    const tar = path.join(DEST, name + '.tar');
    fs.writeFileSync(
        tar,
        zlib.brotliDecompressSync(fs.readFileSync(path.join(BIN, name + '.tar.br'))),
    );
    execFileSync('tar', ['-xf', tar, '-C', into]);
    fs.rmSync(tar);
};

// chromium.br is a brotli-compressed single binary, not a tar.
const chromiumBin = path.join(DEST, 'chromium');
if (!fs.existsSync(chromiumBin)) {
    fs.writeFileSync(
        chromiumBin,
        zlib.brotliDecompressSync(fs.readFileSync(path.join(BIN, 'chromium.br'))),
    );
    fs.chmodSync(chromiumBin, 0o755);
}

const libsDir = path.join(DEST, 'libs');
if (!fs.existsSync(path.join(libsDir, 'libnss3.so'))) {
    expandTar('al2023', libsDir);
    expandTar('fonts', libsDir);
}

// swiftshader = software GL, needed for screenshots on GPU-less machines.
const swiftDir = path.join(DEST, 'swiftshader');
if (!fs.existsSync(swiftDir)) {
    fs.mkdirSync(swiftDir, { recursive: true });
    expandTar('swiftshader', swiftDir);
}

const count = fs.readdirSync(libsDir, { recursive: true }).length;
console.log(`chromium ready at ${chromiumBin} (${count} lib files)`);
