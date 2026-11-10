import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

async function accessible(page) {
    const result = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
        .analyze();
    expect(result.violations).toEqual([]);
    const sizes = await page.evaluate(() => ({
        viewport: window.innerWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(sizes.content).toBeLessThanOrEqual(sizes.viewport + 1);
}

async function noPersistentSecrets(page, secrets) {
    const storage = await page.evaluate(async () => ({
        local: Object.entries(localStorage),
        session: Object.entries(sessionStorage),
        indexedDatabases: await indexedDB.databases(),
        cacheNames: await caches.keys(),
        serviceWorkers: (await navigator.serviceWorker.getRegistrations()).length,
        url: location.href,
    }));
    for (const secret of secrets) {
        expect(JSON.stringify(storage)).not.toContain(secret);
        expect(JSON.stringify(await page.context().cookies())).not.toContain(secret);
    }
    // Voca does not use these persistence mechanisms at all.
    expect(storage.indexedDatabases).toEqual([]);
    expect(storage.cacheNames).toEqual([]);
    expect(storage.serviceWorkers).toBe(0);
}

// No response interception and no simulated connected status. The negative check submits an
// intentionally invalid sentinel to the real backend transport; HTTP rejection OR blocked
// outbound networking is an honest failure. This is NOT authenticated live acceptance.
test('provider setup, redaction, genuine negative check, rotation and deletion work', async ({
    page,
}) => {
    test.setTimeout(90000);
    const errors = [];
    const providerResponseBodies = [];
    page.on('response', (response) => {
        if (
            new URL(response.url()).pathname.startsWith('/app/providers') &&
            response.status() === 200
        ) {
            providerResponseBodies.push(response.text());
        }
    });
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/register');
    await page.getByLabel('Your name', { exact: true }).fill('Provider Browser');
    await page.getByLabel('Business name', { exact: true }).fill('Provider Test Workspace');
    await page
        .getByLabel('Email address')
        .fill(`provider-${Date.now()}-${Math.random().toString(36).slice(2, 6)}@example.com`);
    await page.getByLabel('Password', { exact: true }).fill('browser-tests-only-2026');
    await page.getByLabel('Confirm password').fill('browser-tests-only-2026');
    await page.getByRole('button', { name: 'Create my workspace' }).click();
    await expect(page).toHaveURL(/\/app$/);
    await page.goto('/app/providers');
    await expect(page.getByRole('heading', { name: /Workspace connections/ })).toContainText('0');
    await expect(page.getByText('No provider credentials are saved in this scope.')).toBeVisible();
    await accessible(page);
    await page.getByRole('link', { name: 'Add OpenAI', exact: true }).click();
    await accessible(page);
    await expect(page.getByLabel('Provider category')).toHaveValue('realtime');
    const sentinel = 'e2e-deliberately-invalid-provider-key';
    await page.getByLabel('Connection name').fill('');
    await page.getByLabel('Connection name').evaluate((input) => input.removeAttribute('required'));
    await page.getByLabel('API key', { exact: true }).fill(sentinel);
    await page.getByRole('button', { name: 'Save encrypted credential' }).click();
    await expect(page.getByRole('alert')).toContainText('connection name', { ignoreCase: true });
    await expect(page.getByLabel('API key', { exact: true })).toHaveValue('');
    expect(await page.content()).not.toContain(sentinel);
    await page.getByLabel('Connection name').fill('Carefully connected');
    await page.getByLabel('API key', { exact: true }).fill(sentinel);
    await page.getByRole('button', { name: 'Save encrypted credential' }).click();
    await expect(page).toHaveURL(/\/app\/providers\/\d+\/edit$/);
    await expect(page.locator('.provider-result > strong')).toHaveText('Not tested');
    await expect(page.getByLabel('Replace API key')).toHaveValue('');
    expect(await page.content()).not.toContain(sentinel);
    await expect(page.getByText('No checks have been run.', { exact: false })).toBeVisible();
    await noPersistentSecrets(page, [sentinel]);
    await accessible(page);
    const id = page.url().match(/\/providers\/(\d+)\/edit/)[1];
    const blocked = await page.request.post(`/app/providers/${id}/test`, {
        form: { revision: '1' },
    });
    expect(blocked.status()).toBe(419);
    await page.getByRole('button', { name: 'Test Connection', exact: true }).click();
    await expect(page.locator('.provider-result > strong')).toHaveText('Check failed', {
        timeout: 35000,
    });
    await expect(page.locator('.provider-result-meta code')).toBeVisible();
    await expect(page.locator('.provider-result')).toContainText('Backend resolution: blocked');
    await expect(page.locator('.provider-history li')).toHaveCount(1);
    await expect(page.locator('.provider-history')).toContainText('Check failed');
    await expect(page.getByLabel('Native realtime voice model')).toHaveCount(0);
    expect(await page.content()).not.toContain(sentinel);
    await accessible(page);
    await page.getByLabel('Replace API key').fill('e2e-invalid-replacement-key');
    await page.getByRole('button', { name: 'Save connection changes' }).click();
    await expect(page.locator('.provider-result > strong')).toHaveText('Not tested');
    await expect(page.getByLabel('Replace API key')).toHaveValue('');
    expect(await page.content()).not.toContain('e2e-invalid-replacement-key');
    await expect(page.locator('.provider-history')).toContainText('Historical credential version.');
    await noPersistentSecrets(page, [sentinel, 'e2e-invalid-replacement-key']);
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Remove stored key', exact: true }).click();
    await expect(page.locator('.provider-result > strong')).toHaveText('No credential');
    await expect(page.getByRole('button', { name: 'Test Connection', exact: true })).toBeDisabled();
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Delete connection', exact: true }).click();
    await expect(page).toHaveURL(/\/app\/providers$/);
    await expect(page.locator('.provider-config-row')).toHaveCount(0);
    await page.getByRole('link', { name: 'Compare providers', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Find your frequency.' })).toBeVisible();
    await accessible(page);
    for (const [provider, categories] of [
        ['gemini', ['realtime', 'llm', 'tts']],
        ['deepgram', ['stt', 'tts']],
        ['elevenlabs', ['tts']],
    ]) {
        await page.goto(`/app/providers/create?provider=${provider}`);
        expect(
            await page
                .locator('[name="capability"] option')
                .evaluateAll((options) => options.map((option) => option.value)),
        ).toEqual(categories);
        if (provider === 'deepgram')
            await expect(page.getByLabel('Deepgram project UUID')).toBeVisible();
        await accessible(page);
    }
    await noPersistentSecrets(page, [sentinel, 'e2e-invalid-replacement-key']);
    for (const body of await Promise.all(providerResponseBodies)) {
        expect(body).not.toContain(sentinel);
        expect(body).not.toContain('e2e-invalid-replacement-key');
    }
    expect(errors).toEqual([]);
});
