// Interactive browser flow: registers a real account through the actual web
// forms (CSRF, sessions, validation), then screenshots the authenticated
// workspace journey (overview with the guided tour, agents index, agent
// create form). Writes shots to storage/browser-shots/flow-*.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const TOOL_ROOT = path.dirname(fileURLToPath(import.meta.url));
const APP_ROOT = path.resolve(TOOL_ROOT, '..', '..');
const SHOTS = path.join(APP_ROOT, 'storage', 'browser-shots');
const BASE = process.argv[2] || 'http://127.0.0.1:8000';
const chromiumHome = path.join(TOOL_ROOT, '.chromium');

fs.mkdirSync(SHOTS, { recursive: true });

const puppeteer = (await import('puppeteer-core')).default;
const browser = await puppeteer.launch({
    executablePath: path.join(chromiumHome, 'chromium'),
    args: [
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--disable-setuid-sandbox',
        '--font-render-hinting=none',
        '--enable-unsafe-swiftshader',
        '--use-gl=angle',
        '--use-angle=swiftshader-webgl',
    ],
    headless: true,
    env: {
        ...process.env,
        LD_LIBRARY_PATH: [
            process.env.CHROMIUM_LIBS,
            path.join(chromiumHome, 'libs', 'lib'),
            chromiumHome,
        ]
            .filter(Boolean)
            .join(':'),
    },
});

const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 900 });
const errors = [];
page.on('pageerror', (e) => errors.push('js: ' + e));
page.on('console', (m) => {
    if (m.type() === 'error') errors.push('console: ' + m.text());
});

const snap = (name) =>
    page.screenshot({ path: path.join(SHOTS, `flow-${name}.png`), fullPage: false });
const log = (...a) => console.log('[flow]', ...a);

// 1. Register through the real form.
await page.goto(BASE + '/register', { waitUntil: 'domcontentloaded' });
const email = `flow-${Date.now()}@example.com`;
await page.type('input[name=name]', 'Browser Bot');
await page.type('input[name=organization_name]', 'Flow Industries');
await page.type('input[name=email]', email);
await page.type('input[name=password]', 'a-good-password-2026');
await page.type('input[name=password_confirmation]', 'a-good-password-2026');
await snap('01-register-filled');
// WASM PHP is slower than native — register includes a bcrypt hash; give it room.
await page.click('button[type=submit]');
await page.waitForFunction(() => !location.pathname.endsWith('/register'), { timeout: 90000 });
log('after register:', page.url());

// 2. Workspace overview (guided journey lives here).
await new Promise((r) => setTimeout(r, 2000));
await snap('02-workspace-overview');

// 3. Agents index + create form.
await page.goto(BASE + '/app/agents', { waitUntil: 'domcontentloaded' });
await new Promise((r) => setTimeout(r, 1200));
await snap('03-agents');
await page.goto(BASE + '/app/agents/create', { waitUntil: 'domcontentloaded' });
await new Promise((r) => setTimeout(r, 1200));
await snap('04-agent-create');

// 4. On the create form: pick the "friendly" personality + save purpose text,
//    then screenshot the live realism preview computed by agentBuilder.
const textareas = await page.$$('textarea');
if (textareas.length) {
    await textareas[0].click({ clickCount: 3 });
    await textareas[0].type('Walk every caller to a booked appointment with warm patient energy.');
}
const personality = await page.$('select[name=personality]');
if (personality) await personality.select('friendly');
await new Promise((r) => setTimeout(r, 1800));
await snap('05-agent-create-realism-preview');

log('final url:', page.url());
log('js errors:', errors.length ? errors : 'none');
await browser.close();
process.exit(errors.some((e) => !/WebGL|THREE/.test(e)) ? 1 : 0);
