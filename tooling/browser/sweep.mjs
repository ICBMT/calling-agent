// Headless-browser inspection sweep for the sandbox app.
//
//   node tooling/browser/sweep.mjs [baseUrl] [routes...]
//
// Visits each route in HeadlessChrome (npm-bundled @sparticuz/chromium),
// records: HTTP status, page title, JS console errors/warnings, failed
// requests, and writes a full-page screenshot per route to
// storage/browser-shots/. Exits non-zero if any page has console errors or
// 5xx responses — so it doubles as a UI smoke check.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const TOOL_ROOT = path.dirname(fileURLToPath(import.meta.url));
const APP_ROOT = path.resolve(TOOL_ROOT, '..', '..');
const SHOTS = path.join(APP_ROOT, 'storage', 'browser-shots');

const BASE = process.argv[2] || process.env.SWEEP_BASE_URL || 'http://127.0.0.1:8000';
const ROUTES = process.argv.slice(3).length ? process.argv.slice(3) : ['/', '/login', '/register'];

fs.mkdirSync(SHOTS, { recursive: true });

const [{ default: chromium }, { default: puppeteer }] = await Promise.all([
    import('@sparticuz/chromium'),
    import('puppeteer-core'),
]);

// Ensure the npm-bundled browser is available locally (self-healing).
const chromiumHome = path.join(TOOL_ROOT, '.chromium');
if (
    !fs.existsSync(path.join(chromiumHome, 'chromium')) ||
    !fs.existsSync(path.join(chromiumHome, 'libs', 'lib', 'libnss3.so'))
) {
    await import('node:child_process').then(({ execFileSync }) =>
        execFileSync(process.execPath, [path.join(TOOL_ROOT, 'extract-libs.mjs')], {
            stdio: 'inherit',
        }),
    );
}
const executablePath = process.env.CHROMIUM_PATH || path.join(chromiumHome, 'chromium');

const browser = await puppeteer.launch({
    executablePath,
    args: [
        // Based on @sparticuz/chromium defaults, minus --single-process
        // (breaks the CDP TargetManager attach) and --headless='shell'
        // duplication.
        '--ash-no-nudges',
        '--no-default-browser-check',
        '--no-pings',
        '--font-render-hinting=none',
        '--disable-features=AudioServiceOutOfProcess',
        '--ignore-gpu-blocklist',
        '--disable-setuid-sandbox',
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--use-gl=angle',
        '--use-angle=swiftshader-webgl',
        '--enable-unsafe-swiftshader',
    ],
    headless: true,
    env: {
        ...process.env,
        // System libs extracted from @sparticuz/chromium's bundled al2023 set
        // (extract-libs.mjs / `npm run setup` in this directory handles this).
        LD_LIBRARY_PATH: [
            process.env.CHROMIUM_LIBS,
            path.join(chromiumHome, 'libs', 'lib'),
            chromiumHome,
        ]
            .filter(Boolean)
            .join(':'),
    },
});

const results = [];
for (const route of ROUTES) {
    const page = await browser.newPage();
    const consoleErrors = [];
    const consoleWarnings = [];
    const failedRequests = [];
    const pageErrors = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
        if (msg.type() === 'warning') consoleWarnings.push(msg.text());
    });
    page.on('pageerror', (err) => pageErrors.push(String(err)));
    page.on('requestfailed', (req) =>
        failedRequests.push(`${req.method()} ${req.url()} :: ${req.failure()?.errorText}`),
    );

    let status = null;
    let title = '';
    let navError = null;
    try {
        const resp = await page.goto(BASE + route, {
            waitUntil: 'domcontentloaded',
            timeout: 45000,
        });
        // Let JS boot + first frames settle (the 3D globe animates forever,
        // which keeps networkidle from ever firing). Fixed budget, not busy-wait.
        await new Promise((r) => setTimeout(r, 2500));
        status = resp?.status() ?? null;
        title = await page.title();
        await page.screenshot({
            path: path.join(SHOTS, (route === '/' ? 'home' : route.replaceAll('/', '_')) + '.png'),
            fullPage: true,
        });
    } catch (e) {
        navError = String(e?.message || e);
    }
    await page.close();

    results.push({
        route,
        status,
        title,
        consoleErrors,
        consoleWarnings,
        failedRequests,
        pageErrors,
        navError,
    });
}

await browser.close();

let bad = 0;
for (const r of results) {
    const problems = [];
    if (r.navError) problems.push('nav: ' + r.navError);
    if (r.status === null || r.status >= 500) problems.push('status=' + r.status);
    if (r.pageErrors.length) problems.push(...r.pageErrors.map((e) => 'js-ex: ' + e));
    if (r.consoleErrors.length) problems.push(...r.consoleErrors.map((e) => 'console-error: ' + e));
    const assetFails = r.failedRequests.filter((f) => !/favicon/.test(f));
    if (assetFails.length) problems.push(...assetFails.map((f) => 'req-fail: ' + f));

    const verdict = problems.length ? 'FAIL' : 'PASS';
    if (problems.length) bad++;
    console.log(`\n[${verdict}] ${r.route} — HTTP ${r.status} — "${r.title}"`);
    for (const p of problems.slice(0, 8)) console.log('   -', p);
    if (r.consoleWarnings.length) {
        for (const w of r.consoleWarnings.slice(0, 3)) console.log('   ~ warn:', w);
    }
}
console.log(
    `\nSweep complete: ${results.length - bad}/${results.length} routes clean. Screenshots in ${path.relative(APP_ROOT, SHOTS)}/`,
);
process.exit(bad ? 1 : 0);
