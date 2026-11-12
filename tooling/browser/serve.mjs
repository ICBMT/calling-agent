// Sandbox web server: serves the Laravel app via real PHP (WebAssembly SAPI).
// Static files from public/ are streamed from disk; everything else is routed
// through index.php inside PHP — same rewrite contract as `php artisan serve`.
//
//   node tooling/browser/serve.mjs [port]
//
// Default port 8000 matches the ports pinned in instructions.txt.

import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP, PHPRequestHandler, setPhpIniEntries } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

const TOOL_ROOT = path.dirname(fileURLToPath(import.meta.url));
const APP_ROOT = path.resolve(TOOL_ROOT, '..', '..');
const DOC_ROOT = path.join(APP_ROOT, 'public');
const PORT = Number(process.argv[2] || process.env.PORT || 8000);
const PHPW_VERSION = process.env.PHPW_VERSION || '8.3';

const MIME = {
    '.css': 'text/css; charset=utf-8',
    '.js': 'application/javascript; charset=utf-8',
    '.mjs': 'application/javascript; charset=utf-8',
    '.map': 'application/json',
    '.json': 'application/json',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.gif': 'image/gif',
    '.svg': 'image/svg+xml',
    '.ico': 'image/x-icon',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
    '.ttf': 'font/ttf',
    '.webp': 'image/webp',
    '.txt': 'text/plain; charset=utf-8',
    '.html': 'text/html; charset=utf-8',
};

// --- boot a reusable PHP SAPI instance -------------------------------------
const php = new PHP(
    await loadNodeRuntime(PHPW_VERSION, { emscriptenOptions: { processId: process.pid } }),
);
php.setSkipShebang(true);
await setPhpIniEntries(php, {
    'opcache.enable': '0',
    'opcache.enable_cli': '0',
    'zend.assertions': '-1',
    memory_limit: '2G',
    // WASM stack-depth detection misfires on legitimately deep Laravel frames.
    'zend.max_allowed_stack_size': '-1',
    'fiber.stack_size': '1M',
});
for (const dir of [APP_ROOT, '/tmp', process.env.HOME || '/tmp', '/usr/bin']) {
    try {
        if (!php.fileExists(dir)) php.mkdirTree(dir);
        await php.mount(dir, createNodeFsMountHandler(dir));
    } catch (e) {
        console.error('[serve] mount failed for', dir, e.message);
    }
}

const handler = new PHPRequestHandler({
    phpFactory: async () => php,
    documentRoot: DOC_ROOT,
    absoluteUrl: `http://127.0.0.1:${PORT}/`,
    isStaticFilePath: (p) => {
        const full = path.join(DOC_ROOT, p);
        return fs.existsSync(full) && fs.statSync(full).isFile();
    },
    // php artisan serve equivalent: pretty URLs -> SAPI PATH_INFO on index.php.
    rewriteRules: [
        { match: /^\/?$/, replacement: 'index.php' },
        { match: /^(?!index\.php)(.+)$/, replacement: 'index.php/$1' },
    ],
});

// --- HTTP bridge ------------------------------------------------------------
http.createServer(async (req, res) => {
    const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
    const pathname = decodeURIComponent(url.pathname);

    // Real static assets straight from disk (fast path, no WASM round-trip).
    if (req.method === 'GET' && pathname !== '/' && !pathname.includes('..')) {
        const full = path.join(DOC_ROOT, pathname);
        if (fs.existsSync(full) && fs.statSync(full).isFile()) {
            res.writeHead(200, {
                'Content-Type': MIME[path.extname(full)] || 'application/octet-stream',
            });
            fs.createReadStream(full).pipe(res);
            return;
        }
    }

    try {
        const body =
            req.method === 'GET' || req.method === 'HEAD'
                ? undefined
                : await new Promise((resolve) => {
                      const chunks = [];
                      req.on('data', (c) => chunks.push(c));
                      req.on('end', () => resolve(Buffer.concat(chunks)));
                  });
        const response = await handler.request({
            method: req.method,
            url: url.pathname + url.search,
            headers: req.headers,
            body,
        });
        const headers = {};
        for (const [k, vals] of Object.entries(response.headers || {})) {
            headers[k] = Array.isArray(vals) ? vals.join('; ') : String(vals);
        }
        res.writeHead(response.httpStatusCode || 200, headers);
        res.end(response.bytes);
    } catch (e) {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end('WASM PHP bridge error: ' + (e?.message || e));
    }
}).listen(PORT, '0.0.0.0', () => {
    console.log(
        `[serve] voca sandbox app on http://0.0.0.0:${PORT} (PHP ${PHPW_VERSION} WASM, docroot public/)`,
    );
});
