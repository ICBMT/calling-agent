import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

async function assertAccessible(page) {
    const result = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
        .analyze();
    expect(result.violations).toEqual([]);
}

async function assertNoOverflow(page) {
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
    expect(storage.indexedDatabases).toEqual([]);
    expect(storage.cacheNames).toEqual([]);
    expect(storage.serviceWorkers).toBe(0);
}

// Browser lifecycle and honest negative boundary checks for Milestone 3 Real-Time Voice Playground.
// This test does NOT stub or simulate an AI voice conversation; when external LiveKit or OpenAI credentials
// are absent, the UI honestly displays configuration prerequisites and disables unverified starts.
test('voice playground displays honest prerequisites, enforces consent, and guards lifecycle', async ({
    page,
}) => {
    test.setTimeout(90000);
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    // Register a fresh browser test account
    await page.goto('/register');
    await page.getByLabel('Your name', { exact: true }).fill('Voice Tester');
    await page.getByLabel('Business name', { exact: true }).fill('Voice Test Workspace');
    await page
        .getByLabel('Email address')
        .fill(`voice-${Date.now()}-${Math.random().toString(36).slice(2, 6)}@example.com`);
    await page.getByLabel('Password', { exact: true }).fill('browser-tests-only-2026');
    await page.getByLabel('Confirm password').fill('browser-tests-only-2026');
    await page.getByRole('button', { name: 'Create my workspace' }).click();
    await expect(page).toHaveURL(/\/app$/);

    // Navigate to playground
    await page.goto('/app/playground');
    await expect(page).toHaveTitle(/Real-Time Browser Voice/);
    await expect(
        page.getByRole('heading', { name: /Native Realtime Browser Voice/ }),
    ).toBeVisible();

    // In a fresh test workspace without configured providers or agents, honest blockers must be displayed
    await expect(page.getByText('Configuration required')).toBeVisible();
    await expect(page.getByRole('alert')).toContainText('Setup prerequisites for real-time voice');
    await expect(page.getByText('Save a draft agent with instructions')).toBeVisible();

    // Start button must be disabled when prerequisites or consent are missing
    const startButton = page.getByRole('button', { name: /Start voice session/ });
    await expect(startButton).toBeDisabled();

    // Ephemeral transcript empty state
    await expect(
        page.getByText('Start a session and speak into your microphone', { exact: false }),
    ).toBeVisible();

    // Diagnostics empty/honest labels
    await expect(page.getByText('Derived strictly from browser WebRTC stats')).toBeVisible();
    await expect(page.getByText('Actual telemetry only')).toBeVisible();

    // Accessibility and responsive layout check
    await assertAccessible(page);
    await assertNoOverflow(page);

    // Create a draft agent to test configuration updates
    await page.goto('/app/agents/create');
    await page.getByLabel('Agent name', { exact: false }).fill('Playground Receptionist');
    await page.getByLabel('What’s their role?', { exact: false }).selectOption('receptionist');
    await page.getByLabel('Preferred language', { exact: false }).selectOption('ur');
    await page.getByLabel('Personality', { exact: false }).selectOption('friendly');
    await page
        .getByLabel('The opening line', { exact: false })
        .fill('Assalam-o-Alaikum, Voca mein khushamdeed.');
    await page
        .getByLabel('Agent instructions', { exact: false })
        .fill('You are a helpful Urdu-speaking receptionist for tests.');
    await page.getByRole('button', { name: 'Save my draft agent' }).click();
    await expect(page).toHaveURL(/\/app\/agents\/\d+\/edit$/);

    // Return to playground: the new agent should be listed in the dropdown
    await page.goto('/app/playground');
    const agentSelect = page.getByLabel('Draft agent instructions');
    await expect(agentSelect).toContainText('Playground Receptionist');

    // Consent checkbox starts unchecked
    const consentCheckbox = page.locator('#voice-consent');
    await expect(consentCheckbox).not.toBeChecked();
    await expect(startButton).toBeDisabled();

    // Checking consent without LiveKit infrastructure still keeps start disabled
    await consentCheckbox.check();
    await expect(consentCheckbox).toBeChecked();
    await expect(startButton).toBeDisabled();

    // Local microphone device check remains accessible
    const localCheck = page.getByText('Local microphone device check');
    await expect(localCheck).toBeVisible();
    await expect(page.getByText('Your audio stays on this device', { exact: false })).toBeVisible();

    // Verify privacy: no persistent secrets in client storage (room tokens, API keys, transcripts)
    await noPersistentSecrets(page, ['livekit_token', 'voca.events', 'room_token']);

    expect(errors).toEqual([]);
});
