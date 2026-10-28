/**
 * Browser smoke test against a running, seeded app with queue workers:
 *
 *   MODERATION_CLASSIFIER=fake php artisan migrate:fresh --seed
 *   composer run dev            (or: artisan serve + one queue:work per pipeline queue)
 *   E2E_URL=http://127.0.0.1:8000 npm run test:e2e
 *
 * As a member: submits every sample on the Submit page and waits for the queued
 * pipeline to decide each one, then appeals a rejection. As a moderator: takes
 * tasks from the queue, rejects one with a reason and escalates another. As an
 * admin: decides the escalation and backtests a policy change. Finally visits the
 * staff pages in light mode, dark mode and at phone width.
 *
 * Fails on page errors, console errors, horizontal overflow, broken images or a
 * pipeline outcome that differs from the sample's promise. Uses an installed Edge
 * or Chrome (E2E_BROWSER=msedge|chrome) via playwright-core; screenshots go to
 * storage/e2e.
 */
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright-core';

const BASE = process.env.E2E_URL ?? 'http://127.0.0.1:8000';
const OUT = 'storage/e2e';
mkdirSync(OUT, { recursive: true });

const results = [];
let failed = false;

async function step(name, fn) {
    const started = Date.now();
    try {
        await fn();
        results.push(`PASS ${name} (${Date.now() - started}ms)`);
    } catch (e) {
        failed = true;
        results.push(`FAIL ${name}: ${e.message.split('\n')[0]}`);
    }
}

const browser = await chromium.launch({
    channel: process.env.E2E_BROWSER ?? 'msedge',
    headless: true,
});

/** A signed-in page that records page and console errors. */
async function session(email, options = {}) {
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        ...options,
    });
    const page = await context.newPage();
    const problems = [];

    page.on('pageerror', (e) => problems.push(`page error: ${e.message}`));
    page.on(
        'console',
        (m) => m.type() === 'error' && problems.push(`console: ${m.text()}`),
    );

    await page.goto(`${BASE}/login`);
    await page.fill('#email', email);
    await page.fill('#password', 'password');
    await page.click('button[type=submit]');
    await page.waitForURL(/\/dashboard|\/submissions/);

    return { context, page, problems };
}

/** Fail on recorded errors, overflow and images that did not load; then screenshot. */
async function check({ page, problems }, label) {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    if (overflow > 1) throw new Error(`horizontal overflow of ${overflow}px`);

    // The layout clips overflow, so also look for content cut off at the right edge,
    // ignoring anything inside a container that is meant to scroll sideways.
    const clipped = await page.evaluate(() => {
        const edge = document.documentElement.clientWidth + 1;
        const scrollsInside = (el) => {
            for (let p = el.parentElement; p; p = p.parentElement) {
                if (
                    ['auto', 'scroll'].includes(
                        getComputedStyle(p).overflowX,
                    ) &&
                    p.getBoundingClientRect().right <= edge
                )
                    return true;
            }

            return false;
        };

        return [...document.body.querySelectorAll('*')]
            .filter((el) => {
                const rect = el.getBoundingClientRect();

                return (
                    rect.width > 1 &&
                    rect.height > 0 &&
                    rect.right > edge &&
                    !scrollsInside(el)
                );
            })
            .slice(0, 3)
            .map(
                (el) =>
                    `${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 3).join('.')}`,
            );
    });
    if (clipped.length)
        throw new Error(
            `content cut off at the right edge: ${clipped.join(', ')}`,
        );

    const broken = await page.evaluate(() =>
        [...document.images]
            .filter((img) => img.complete && img.naturalWidth === 0)
            .map((img) => img.src),
    );
    if (broken.length) throw new Error(`broken images: ${broken.join(', ')}`);

    if (problems.length) throw new Error(problems.splice(0).join(' | '));
    await page.screenshot({ path: `${OUT}/${label}.png`, fullPage: true });
}

const STATUSES = ['Processing', 'In review', 'Approved', 'Rejected'];

/** The submission page polls while processing; wait until the pipeline has decided. */
async function settledStatus(page) {
    const handle = await page.waitForFunction(
        (statuses) => {
            const badge = [...document.querySelectorAll('span, div')].find(
                (el) => statuses.includes(el.textContent.trim()),
            );
            const text = badge?.textContent.trim();

            return text && text !== 'Processing' ? text : null;
        },
        STATUSES,
        { timeout: 90_000, polling: 500 },
    );

    return handle.jsonValue();
}

async function toast(page, text) {
    await page
        .getByText(text, { exact: true })
        .first()
        .waitFor({ timeout: 10_000 });
}

// --- Member: every sample through the real queue -------------------------

const SAMPLES = [
    ['Harbour sunset', 'Approved'],
    ['Crypto giveaway', 'Rejected'],
    ['Parts for sale', 'In review'],
    ['Known meme', 'Rejected'],
    ['Personal attack', 'Rejected'],
    ['Distress', 'In review'],
];

const member = await session('member@example.com');
const submitted = {};

for (const [label, expected] of SAMPLES) {
    await step(
        `[member] sample "${label}" is ${expected.toLowerCase()}`,
        async () => {
            const { page } = member;
            await page.goto(`${BASE}/submissions/create`);
            await page
                .getByRole('button', { name: new RegExp(`^${label}`) })
                .click();
            await page.waitForFunction(
                () => document.querySelector('#caption')?.value.length > 0,
            );

            if (!['Personal attack', 'Distress'].includes(label)) {
                await page.getByAltText('Selected image preview').waitFor();
            }

            await page
                .getByRole('button', { name: 'Submit', exact: true })
                .click();
            await page.waitForURL(/\/submissions\/\d+$/);
            submitted[label] = page.url();

            const status = await settledStatus(page);
            if (status !== expected)
                throw new Error(`expected ${expected}, got ${status}`);

            await check(
                member,
                `member-${label.toLowerCase().replaceAll(' ', '-')}`,
            );
        },
    );
}

await step('[member] appeal the crypto rejection', async () => {
    const { page } = member;
    await page.goto(submitted['Crypto giveaway']);
    await page.fill(
        '#appeal',
        'This is a real giveaway run by my own small business.',
    );
    await page.getByRole('button', { name: 'Send appeal' }).click();
    await toast(page, 'Appeal sent. A different moderator will review it.');
    await page.getByText('In review', { exact: true }).first().waitFor();
    await check(member, 'member-appealed');
});

await member.context.close();

// --- Moderator: reject one task, escalate the next -----------------------

const moderator = await session('moderator@example.com');

await step('[moderator] review next, reject with a reason', async () => {
    const { page } = moderator;
    await page.goto(`${BASE}/review`);
    await page.getByRole('button', { name: 'Review next' }).click();
    await page.waitForURL(/\/review\/\d+$/);
    await page.getByRole('radio', { name: 'Reject' }).click();
    await page.locator('label', { hasText: 'Spam & scams' }).click();
    await check(moderator, 'moderator-deciding');
    await page.getByRole('button', { name: 'Confirm reject' }).click();
    await toast(page, 'Rejected.');
});

await step('[moderator] review next, escalate with a note', async () => {
    const { page } = moderator;
    await page.goto(`${BASE}/review`);
    await page.getByRole('button', { name: 'Review next' }).click();
    await page.waitForURL(/\/review\/\d+$/);
    await page.getByRole('radio', { name: 'Escalate' }).click();
    await page.fill(
        '#note',
        'Not sure about this one, an admin should decide.',
    );
    await page.getByRole('button', { name: 'Confirm escalate' }).click();
    await toast(page, 'Escalated to an admin.');
    await check(moderator, 'moderator-queue');
});

await moderator.context.close();

// --- Admin: decide the escalation, backtest a policy change --------------

const admin = await session('admin@example.com');

await step('[admin] decide an escalation', async () => {
    const { page } = admin;
    await page.goto(`${BASE}/review?kind=escalation`);
    await page.getByRole('button', { name: 'Review next' }).click();
    await page.waitForURL(/\/review\/\d+$/);
    await page.getByText('Escalation note').waitFor();
    await page.getByRole('radio', { name: 'Approve' }).click();
    await page.getByRole('button', { name: 'Confirm approve' }).click();
    await toast(page, 'Approved.');
});

await step('[admin] backtest a policy change', async () => {
    const { page } = admin;
    await page.goto(`${BASE}/policy`);
    const input = page.locator('input[type=number]').first();
    const value = Number(await input.inputValue());
    await input.fill(String(Math.max(0, value - 0.1).toFixed(2)));
    await page.getByRole('button', { name: 'Backtest' }).click();
    await page
        .getByText(/items would be decided\s+differently/)
        .waitFor({ timeout: 20_000 });
    await check(admin, 'admin-backtest');
});

await admin.context.close();

// --- Staff pages in every mode -------------------------------------------

const MODES = [
    { name: 'light', options: { colorScheme: 'light' } },
    { name: 'dark', options: { colorScheme: 'dark' } },
    {
        name: 'mobile',
        options: {
            colorScheme: 'light',
            viewport: { width: 390, height: 844 },
            isMobile: true,
        },
    },
];

/** [label, path, text that proves the page rendered data] */
const PAGES = [
    ['dashboard', '/dashboard', 'Submissions per day'],
    ['submissions', '/submissions', 'Submissions'],
    ['submit', '/submissions/create', 'Try a sample'],
    ['review-queue', '/review', 'Review queue'],
    ['policy', '/policy', 'Backtest'],
];

for (const mode of MODES) {
    const staff = await session('admin@example.com', mode.options);

    for (const [label, path, proof] of PAGES) {
        await step(`[${mode.name}] ${label}`, async () => {
            await staff.page.goto(`${BASE}${path}`);
            await staff.page.getByText(proof).first().waitFor();
            await check(staff, `${mode.name}-${label}`);
        });
    }

    await step(`[${mode.name}] submission detail`, async () => {
        await staff.page.goto(
            submitted['Harbour sunset'] ?? `${BASE}/submissions/1`,
        );
        await staff.page.getByText('Pipeline').first().waitFor();
        await check(staff, `${mode.name}-submission-detail`);
    });

    await staff.context.close();
}

await browser.close();

console.log(results.join('\n'));
console.log(
    failed ? '\nSmoke test FAILED' : `\nAll ${results.length} checks passed`,
);
process.exit(failed ? 1 : 0);
