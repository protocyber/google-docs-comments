import { test, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = fileURLToPath(new globalThis.URL('.', import.meta.url));
const OUT = join(__dirname, 'out');
const URL = 'file://' + join(OUT, 'rich.html');
const RUNS: [number, number, string][] = JSON.parse(readFileSync(join(OUT, 'runs.json'), 'utf8'));

// Docs text at [start, end) from the server-side runs (JS strings are UTF-16, like Docs).
function oracle(start: number, end: number): string {
    let out = '';
    for (const [s, e, text] of RUNS) {
        if (e <= start || s >= end) continue;
        const from = Math.max(start, s) - s;
        out += text.slice(from, Math.min(end, e) - s);
    }
    return out;
}
const norm = (t: string) => t.replace(/[^\p{L}\p{N}\uFFFC]+/gu, '');

async function open(page: Page, viewport = { width: 1280, height: 900 }) {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('dialog', (d) => { errors.push('dialog: ' + d.message()); d.dismiss(); });
    await page.setViewportSize(viewport);
    await page.goto(URL);
    await page.waitForFunction(() => (window as any).Alpine && document.querySelector('.gd-wrap')?.hasAttribute('x-data')
        && (window as any).Alpine.$data(document.querySelector('.gd-wrap')).active !== undefined);
    return errors;
}

const state = (page: Page) => page.evaluate(() => {
    const d = (window as any).Alpine.$data(document.querySelector('.gd-wrap'));
    return { active: d.active as string | null, flash: d.flash as string | null, range: d.range ? { ...d.range } : null };
});

const OPEN_BG = 'rgb(254, 247, 205)';
const ACTIVE_BG = 'rgb(253, 230, 138)';

test('random selections map onto Docs indexes on a scaled page', async ({ page }) => {
    const errors = await open(page, { width: 700, height: 900 });
    await expect(page.locator('.gd-page')).toHaveCSS('transform', /matrix\(0\./);

    const mismatches = await page.evaluate(({ runs, n }) => {
        const doc = document.getElementById('gd-doc')!;
        const nodes: Text[] = [];
        const walker = document.createTreeWalker(doc, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const t = walker.currentNode as Text;
            if (!t.parentElement!.closest('style')) nodes.push(t);
        }
        let seed = 7;
        const rnd = (m: number) => { seed = (seed * 48271) % 2147483647; return seed % m; };
        const oracle = (start: number, end: number) => {
            let out = '';
            for (const [s, e, text] of runs) {
                if (e <= start || s >= end) continue;
                const from = Math.max(start, s) - s;
                out += (text as string).slice(from, Math.min(end, e) - s);
            }
            return out;
        };
        const norm = (t: string) => t.replace(/[^\p{L}\p{N}\uFFFC]+/gu, '');
        const bad: string[] = [];
        let checked = 0;
        for (let i = 0; i < n * 3 && checked < n; i++) {
            let a = rnd(nodes.length), b = rnd(nodes.length);
            if (a > b) [a, b] = [b, a];
            const ao = rnd(nodes[a].length + 1), bo = rnd(nodes[b].length + 1);
            if (a === b && ao >= bo) continue;
            const r = document.createRange();
            r.setStart(nodes[a], ao);
            r.setEnd(nodes[b], bo);
            const sel = getSelection()!;
            sel.removeAllRanges();
            sel.addRange(r);
            const got = (window as any).GdPreview.selectionToRange(doc);
            if (!got) continue; // selection only over decoration
            checked++;
            if (norm(got.text) !== norm(oracle(got.start, got.end))) {
                bad.push(`${got.start}-${got.end}: ${JSON.stringify(got.text)}`);
            }
        }
        return { bad, checked };
    }, { runs: RUNS, n: 200 });

    expect(mismatches.checked).toBeGreaterThan(150);
    expect(mismatches.bad).toEqual([]);
    expect(errors).toEqual([]);
});

test('mouse drag over highlighted text selects and does not navigate', async ({ page }) => {
    await open(page);
    const first = page.locator('#gd-doc [data-c~="c2"]').first();
    const last = page.locator('#gd-doc [data-c~="c3"]').last();
    const a = (await first.boundingBox())!;
    const b = (await last.boundingBox())!;
    await page.mouse.move(a.x + 2, a.y + a.height / 2);
    await page.mouse.down();
    await page.mouse.move(b.x + b.width - 2, b.y + b.height / 2, { steps: 8 });
    await page.mouse.up();

    const s = await state(page);
    expect(s.active).toBeNull();
    expect(s.range).not.toBeNull();
    expect(norm(s.range!.text)).toBe(norm(oracle(s.range!.start, s.range!.end)));
});

test('card click scrolls to its text and highlights it', async ({ page }) => {
    await open(page);
    const span = page.locator('#gd-doc [data-c~="c1"]').first();
    await expect(span).not.toBeInViewport();
    await expect(span).toHaveCSS('background-color', OPEN_BG);

    await page.locator('.gd-card[data-comment-id="c1"] .gd-card-main').click();

    await expect(span).toBeInViewport();
    await expect(span).toHaveCSS('background-color', ACTIVE_BG);
    await expect(page.locator('.gd-card[data-comment-id="c1"] .gd-card-main')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.gd-card[data-comment-id="c1"]')).toHaveClass(/is-active/);
});

test('clicking highlighted text activates its comment and cycles overlaps', async ({ page }) => {
    await open(page);
    const overlap = page.locator('#gd-doc [data-c="c2 c3"]').first();

    await overlap.click();
    await expect.poll(async () => (await state(page)).active).toBe('c2'); // shorter range first
    await expect(page.locator('.gd-card[data-comment-id="c2"]')).toBeInViewport();

    await page.waitForTimeout(600); // avoid a double-click
    await overlap.click();
    await expect.poll(async () => (await state(page)).active).toBe('c3');

    await page.waitForTimeout(600);
    await overlap.click();
    await expect.poll(async () => (await state(page)).active).toBe('c2');
});

test('Esc and a click on plain text clear the active comment', async ({ page }) => {
    await open(page);
    await page.locator('.gd-card[data-comment-id="c2"] .gd-card-main').click();
    await expect.poll(async () => (await state(page)).active).toBe('c2');
    await page.keyboard.press('Escape');
    await expect.poll(async () => (await state(page)).active).toBeNull();
    await expect(page.locator('#gd-doc [data-c~="c2"]').first()).toHaveCSS('background-color', OPEN_BG);

    await page.locator('.gd-card[data-comment-id="c2"] .gd-card-main').click();
    await page.locator('#gd-doc h1 span[data-s]').first().click();
    await expect.poll(async () => (await state(page)).active).toBeNull();
});

test('resolved comments are hidden until toggled and flash their text', async ({ page }) => {
    await open(page);
    const card = page.locator('.gd-card[data-comment-id="c4"]');
    await expect(card).toBeHidden();
    await page.locator('#gd-show-resolved').check();
    await expect(card).toBeVisible();

    await card.locator('.gd-card-main').click();
    const s = await state(page);
    expect(s.flash).toBe('c4');
    expect(s.active).toBeNull();
    await expect(page.locator('#gd-doc [data-r~="c4"]').first()).toHaveCSS('background-color', ACTIVE_BG);
    await expect.poll(async () => (await state(page)).flash, { timeout: 4000 }).toBeNull();
});

test('non-located cards are labelled and not navigable', async ({ page }) => {
    const errors = await open(page);
    for (const [id, label] of [['c5', 'di tab lain'], ['c6', 'teks asli sudah dihapus'], ['c7', 'lokasi tidak diketahui']]) {
        const card = page.locator(`.gd-card[data-comment-id="${id}"]`);
        await expect(card).toContainText(label);
        await card.locator('.gd-card-main').click({ force: true });
        expect((await state(page)).active).toBeNull();
    }
    expect(errors).toEqual([]);
});

test('reload with unknown activate keeps the current comment', async ({ page }) => {
    const errors = await open(page);
    await page.locator('.gd-card[data-comment-id="c2"] .gd-card-main').click();
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('gd-preview-reloaded', { detail: { activate: 'not-yet-listed' } })));
    await page.waitForTimeout(100);
    expect((await state(page)).active).toBe('c2');

    await page.evaluate(() => window.dispatchEvent(new CustomEvent('gd-preview-reloaded', { detail: { activate: 'c3' } })));
    await expect.poll(async () => (await state(page)).active).toBe('c3');
    expect(errors).toEqual([]);
});

test('comment content is text, replies collapse, no script runs', async ({ page }) => {
    const errors = await open(page);
    await expect(page.locator('.gd-card[data-comment-id="c2"] .gd-card-body').first()).toHaveText('Komentar <script>alert(1)</script>');

    const c1 = page.locator('.gd-card[data-comment-id="c1"]');
    await expect(c1.locator('.gd-reply:visible')).toHaveCount(2);
    await c1.getByText('Lihat 2 balasan lain').click();
    await expect(c1.locator('.gd-reply:visible')).toHaveCount(4);
    expect(errors).toEqual([]);
});
