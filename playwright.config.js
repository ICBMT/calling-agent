import { defineConfig, devices } from '@playwright/test';
import { existsSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Browser tests have their own database. Never point them at a customer workspace.
const database = resolve('database/browser-tests.sqlite');
if (!existsSync(database)) writeFileSync(database, '');
const externalUrl = process.env.PLAYWRIGHT_BASE_URL;
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const extraArgs = JSON.parse(process.env.PLAYWRIGHT_CHROMIUM_ARGS || '[]');

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    workers: 1,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    timeout: 45000,
    expect: { timeout: 10000 },
    reporter: [['list']],
    use: {
        baseURL: externalUrl || 'http://127.0.0.1:8001',
        reducedMotion: 'reduce',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        launchOptions: {
            ...(executablePath ? { executablePath } : {}),
            args: [
                '--use-fake-device-for-media-stream',
                '--use-fake-ui-for-media-stream',
                '--enable-unsafe-swiftshader',
                ...extraArgs,
            ],
        },
    },
    projects: [
        {
            name: 'desktop',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } },
        },
        { name: 'mobile', use: { ...devices['iPhone 13'], defaultBrowserType: 'chromium' } },
    ],
    webServer: externalUrl
        ? undefined
        : {
              command:
                  'php artisan migrate --force --no-interaction && php artisan serve --host=0.0.0.0 --port=8001 --no-reload',
              url: 'http://127.0.0.1:8001/up',
              reuseExistingServer: false,
              timeout: 60000,
              env: {
                  APP_ENV: 'e2e',
                  APP_DEBUG: 'false',
                  DB_CONNECTION: 'sqlite',
                  DB_DATABASE: database,
                  CACHE_STORE: 'array',
                  SESSION_DRIVER: 'file',
                  SESSION_COOKIE: 'voca_browser_session',
                  SESSION_SECURE_COOKIE: 'false',
                  QUEUE_CONNECTION: 'sync',
                  BCRYPT_ROUNDS: '4',
              },
          },
});
