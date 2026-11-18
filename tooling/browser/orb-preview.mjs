// Visual preview of the Siri-style voice orb: registers a real account, opens
// the playground, drives the Alpine controller through each live state, and
// screenshots the orb. Dev aid for the voice UI — writes storage/browser-shots/orb-*.
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
            path.join(chromiumHome, 'swiftshader'),
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
    page.screenshot({ path: path.join(SHOTS, `orb-${name}.png`), fullPage: false });

// Real account through the real form.
await page.goto(BASE + '/register', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('input[name=name]', { timeout: 30000 });
const email = `orb-${Date.now()}@example.com`;
await page.type('input[name=name]', 'Orb Preview');
await page.type('input[name=organization_name]', 'Orb Co');
await page.type('input[name=email]', email);
await page.type('input[name=password]', 'a-good-password-2026');
await page.type('input[name=password_confirmation]', 'a-good-password-2026');
await page.click('button[type=submit]');
await page.waitForFunction(() => !location.pathname.endsWith('/register'), { timeout: 90000 });

await page.goto(BASE + '/app/playground', { waitUntil: 'domcontentloaded' });
await new Promise((r) => setTimeout(r, 1500));
await snap('01-page');

// Drive the real Alpine controller into each live state.
const setState = (patch) =>
    page.evaluate((p) => {
        const el = document.querySelector('.voice-playground-root');
        const data = el?._x_dataStack?.[0];
        if (!data) throw new Error('Alpine data stack not found');
        Object.assign(data, p);
        document
            .querySelector('.voice-session-panel')
            ?.scrollIntoView({ block: 'center', behavior: 'instant' });
    }, patch);

const settle = () => new Promise((r) => setTimeout(r, 900));

await setState({ status: 'waiting_worker', stage: 'room', consent: true });
await settle();
await snap('02-connecting-steps');

await setState({ status: 'active', stage: 'ready', userState: 'speaking', micLevel: 0.65 });
await settle();
await snap('03-listening');

await setState({ userState: 'idle', agentState: 'thinking', micLevel: 0 });
await settle();
await snap('04-thinking');

await setState({ agentState: 'speaking', agentLevel: 0.7 });
await settle();
await snap('05-speaking');

await setState({ agentState: 'idle', agentLevel: 0.1 });
await settle();
await snap('06-idle-live');

console.log('[orb] js errors:', errors.length ? errors : 'none');
await browser.close();
