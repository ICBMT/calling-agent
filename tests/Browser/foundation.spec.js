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
        viewport: innerWidth,
        page: document.documentElement.scrollWidth,
    }));
    expect(sizes.page).toBeLessThanOrEqual(sizes.viewport + 1);
}
async function openWorkspaceNav(page, isMobile) {
    if (isMobile) await page.getByRole('button', { name: 'Toggle workspace navigation' }).click();
}

// These checks validate the device/UI foundation, not an actual AI provider or telephone call.
test('homepage, use cases, FAQ, and navigation remain accessible', async ({ page, isMobile }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/');
    await expect(page).toHaveTitle('Voca — Make every hello count.');
    await expect(page.locator('h1')).toContainText('Every call.');
    await expect(page.locator('html')).toHaveAttribute('data-motion', 'reduced');
    await assertNoOverflow(page);
    if (isMobile) {
        const menu = page.getByRole('button', { name: 'Toggle menu' });
        await menu.click();
        await expect(menu).toHaveAttribute('aria-expanded', 'true');
        await page
            .getByRole('navigation', { name: 'Mobile navigation' })
            .getByRole('link', { name: 'Made for you' })
            .click();
        await expect(menu).toHaveAttribute('aria-expanded', 'false');
    }
    const tab = page.getByRole('tab', { name: /The front desk/ });
    await tab.focus();
    await tab.press('ArrowRight');
    await expect(page.getByRole('tab', { name: /Customer care/ })).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await expect(page.getByRole('tabpanel')).toContainText('Less explaining.');
    await page.getByText('Can I make real AI calls today?', { exact: true }).click();
    await expect(page.getByText('Not in this release.', { exact: false })).toBeVisible();
    await assertAccessible(page);
    await assertNoOverflow(page);
    expect(errors).toEqual([]);
});

test('voice lab releases its microphone when closed', async ({ page }) => {
    await page.addInitScript(() => {
        const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
        navigator.mediaDevices.getUserMedia = async (options) => {
            const stream = await original(options);
            window.__checkedTracks = stream.getTracks();
            return stream;
        };
    });
    await page.goto('/');
    await page.getByRole('button', { name: 'Explore the voice lab' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByText('Local microphone check. Not an AI conversation.')).toBeVisible();
    await page.getByRole('button', { name: 'Check my microphone' }).click();
    await expect(page.getByText('Listening to your microphone', { exact: true })).toBeVisible();
    await expect
        .poll(() => page.evaluate(() => window.__checkedTracks?.[0]?.readyState))
        .toBe('live');
    await page.getByRole('button', { name: 'Close voice lab' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect
        .poll(() =>
            page.evaluate(() =>
                window.__checkedTracks.every((track) => track.readyState === 'ended'),
            ),
        )
        .toBe(true);
});

test('closing while permission is pending never leaves a microphone open', async ({ page }) => {
    await page.addInitScript(() => {
        navigator.mediaDevices.getUserMedia = () =>
            new Promise((resolve) => {
                window.__resolveMicrophone = resolve;
            });
    });
    await page.goto('/');
    await page.getByRole('button', { name: 'Explore the voice lab' }).click();
    await page.getByRole('button', { name: 'Check my microphone' }).click();
    await expect(page.getByRole('button', { name: 'Requesting permission…' })).toBeDisabled();
    await page.getByRole('button', { name: 'Close voice lab' }).click();
    await page.evaluate(() => {
        const audio = new AudioContext();
        const stream = audio.createMediaStreamDestination().stream;
        window.__pendingTrack = stream.getAudioTracks()[0];
        window.__resolveMicrophone(stream);
        audio.close();
    });
    await expect.poll(() => page.evaluate(() => window.__pendingTrack.readyState)).toBe('ended');
});

test('registration, persistent drafts, settings, and logout work end to end', async ({
    page,
    isMobile,
}) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const email = `browser-${Date.now()}-${Math.random().toString(36).slice(2, 7)}@example.com`;
    await page.goto('/register');
    await assertAccessible(page);
    await page.getByLabel('Your name', { exact: true }).fill('Alex Browser');
    await page.getByLabel('Business name', { exact: true }).fill('Good Conversations Studio');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-tests-only-2026');
    await page.getByLabel('Confirm password').fill('browser-tests-only-2026');
    await page.getByRole('button', { name: 'Create my workspace' }).click();
    await expect(page).toHaveURL(/\/app$/);
    await expect(page).toHaveTitle('Overview — Voca');
    await expect(page.getByText('No agents yet.', { exact: false })).toBeVisible();
    await assertAccessible(page);
    await assertNoOverflow(page);
    if (isMobile) {
        await openWorkspaceNav(page, true);
        await expect(
            page.getByRole('dialog', { name: 'Workspace navigation', exact: true }),
        ).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(
            page.getByRole('button', { name: 'Toggle workspace navigation' }),
        ).toBeFocused();
    }
    await page.getByRole('link', { name: 'Create an agent', exact: true }).click();
    await page.getByLabel('Agent name', { exact: false }).fill('Maya Browser');
    await page.getByLabel('What’s their role?', { exact: false }).selectOption('receptionist');
    await page.getByLabel('Personality', { exact: false }).selectOption('empathetic');
    await page.getByLabel('Preferred language', { exact: false }).selectOption('ur');
    await page
        .getByLabel('The opening line', { exact: false })
        .fill('Hello! I am an AI assistant. How can I help?');
    await page.getByRole('button', { name: 'Use a starter template' }).click();
    await expect(page.getByLabel('Agent instructions', { exact: false })).toHaveValue(
        /Maya Browser/,
    );
    await assertAccessible(page);
    await page.getByRole('button', { name: 'Save my draft agent' }).click();
    await expect(page).toHaveURL(/\/app\/agents\/\d+\/edit$/);
    await expect(page.getByRole('status')).toContainText('Draft saved.');
    await page.reload();
    await expect(page.getByLabel('Agent name', { exact: false })).toHaveValue('Maya Browser');
    await expect(page.getByLabel('Preferred language', { exact: false })).toHaveValue('ur');
    await page.getByLabel('Agent name', { exact: false }).fill('Maya, thoughtfully updated');
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Changes saved.');
    await openWorkspaceNav(page, isMobile);
    await page
        .getByRole('navigation', { name: 'Workspace settings navigation' })
        .getByRole('link', { name: 'Settings', exact: true })
        .click();
    await page.getByLabel('Business name').fill('Good Company, updated');
    await page.getByLabel('Business timezone').selectOption('Asia/Karachi');
    await page.getByLabel('Default agent language').selectOption('hi');
    await assertAccessible(page);
    await page.getByRole('button', { name: 'Save workspace settings' }).click();
    await expect(page.getByRole('status')).toContainText('Workspace settings saved.');
    await page.reload();
    await expect(page.getByLabel('Business name')).toHaveValue('Good Company, updated');
    await expect(page.getByLabel('Business timezone')).toHaveValue('Asia/Karachi');
    await page.goto('/app/agents');
    await page.getByLabel('Search agents by name').fill('thoughtfully updated');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('.agent-card')).toHaveCount(1);
    await assertAccessible(page);
    await page.getByRole('link', { name: 'Edit agent', exact: true }).click();
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Remove this agent', exact: true }).click();
    await expect(page).toHaveURL(/\/app\/agents$/);
    await expect(page.getByRole('status')).toContainText('Agent removed.');
    await page.goto('/app/setup');
    await expect(page.getByText('Service verification', { exact: true })).toBeVisible();
    await assertAccessible(page);
    await assertNoOverflow(page);
    await page.goto('/app/playground');
    await expect(page.getByText('Local microphone check. Not an AI conversation.')).toBeVisible();
    await assertAccessible(page);
    await assertNoOverflow(page);
    await openWorkspaceNav(page, isMobile);
    await page
        .getByRole('navigation', { name: 'Workspace navigation', exact: true })
        .getByRole('link', { name: 'Call history' })
        .click();
    await expect(
        page.getByText('This workspace has no call activity yet.', { exact: false }),
    ).toBeVisible();
    await assertNoOverflow(page);
    await assertAccessible(page);
    await openWorkspaceNav(page, isMobile);
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await expect(page).toHaveURL('/');
    await page.goto('/app');
    await expect(page).toHaveURL('/login');
    expect(errors).toEqual([]);
});

test('real HTTP form submissions reject missing CSRF tokens', async ({ page, request }) => {
    await page.goto('/register');
    const response = await request.post('/register', { form: { email: 'csrf-check@example.com' } });
    expect(response.status()).toBe(419);
});

test('the homepage works without WebGL', async ({ page }) => {
    await page.addInitScript(() => {
        const original = HTMLCanvasElement.prototype.getContext;
        HTMLCanvasElement.prototype.getContext = function (type, ...args) {
            return type.startsWith('webgl') ? null : original.call(this, type, ...args);
        };
    });
    await page.goto('/');
    await expect(page.locator('.sculpture-fallback')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Build your first agent' })).toBeVisible();
    await page.getByRole('link', { name: 'Build your first agent' }).click();
    await expect(page).toHaveURL('/register');
});

test('motion preference can be paused and is remembered', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-motion', 'full');
    await page.getByRole('button', { name: 'Motion on', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-motion', 'reduced');
    await expect(page.getByRole('button', { name: 'Motion off', exact: true })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-motion', 'reduced');
});
