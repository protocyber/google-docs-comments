import { test, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = fileURLToPath(new globalThis.URL('.', import.meta.url));
const OUT = join(__dirname, 'out');
const URL = 'file://' + join(OUT, 'rich.html');
const RUNS: [number, number, string][] = JSON.parse(readFileSync(join(OUT, 'runs.json'), 'utf8'));

function oracle(start: number, end: number): string {
    let out = '';
    for (const [s, e, text] of RUNS) {
        if (e <= start || s >= end) continue;
        const from = Math.max(start, s) - s;
        out += text.slice(from, Math.min(end, e) - s);
    }
    return out;
}

async function open(page: Page, viewport = { width: 1280, height: 900 }) {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.setViewportSize(viewport);
    await page.goto(URL);
    await page.waitForFunction(() => (window as any).Alpine && document.querySelector('.gd-wrap')?.hasAttribute('x-data')
        && (window as any).Alpine.$data(document.querySelector('.gd-wrap')).active !== undefined);
    return errors;
}

const state = (page: Page) => page.evaluate(() => {
    const d = (window as any).Alpine.$data(document.querySelector('.gd-wrap'));
    return { mode: d.mode as string, save: d.save as string, revision: d.revision as string | null };
});

async function toEdit(page: Page) {
    await page.locator('#gd-mode-edit').click();
}

async function fakeWire(page: Page) {
    return page.evaluate(() => ({ calls: (window as any).gdFakeWire.calls }));
}

async function resetWire(page: Page) {
    await page.evaluate(() => { (window as any).gdFakeWire.calls = []; (window as any).gdFakeWire.responses = []; });
}

// Locates the <p>/<h1..6> block whose text starts with `needle` (via oracle).
async function blockStartingWith(page: Page, needle: string) {
    const ps = RUNS.find(([, , text]) => text.includes(needle))?.[0];
    expect(ps).toBeDefined();
    return page.locator(`#gd-doc [data-ps]`).filter({ hasText: needle }).first();
}

async function setCaretAtEnd(page: Page, selector: string) {
    await page.evaluate((sel) => {
        const el = document.querySelector(sel) as HTMLElement;
        const sel2 = window.getSelection()!;
        const range = document.createRange();
        range.selectNodeContents(el);
        range.collapse(false);
        sel2.removeAllRanges();
        sel2.addRange(range);
        el.focus();
    }, selector);
}

async function setCaretAtStart(page: Page, selector: string) {
    await page.evaluate((sel) => {
        const el = document.querySelector(sel) as HTMLElement;
        const sel2 = window.getSelection()!;
        const range = document.createRange();
        range.selectNodeContents(el);
        range.collapse(true);
        sel2.removeAllRanges();
        sel2.addRange(range);
        el.focus();
    }, selector);
}

test('diff counts UTF-16 units', async ({ page }) => {
    const errors = await open(page);
    await toEdit(page);
    const [s] = RUNS.find(([, , t]) => t.includes('😀'))!;
    const emojiEnd = s + oracle(s, s + 200).indexOf('😀') + 2; // emoji is a surrogate pair
    const block = page.locator('[data-ps="27"]');
    await block.click();
    await setCaretAtEnd(page, '[data-ps="27"]'); // caret somewhere, then move precisely below
    await page.evaluate((end) => {
        const doc = document.getElementById('gd-doc')!;
        const walker = document.createTreeWalker(doc, NodeFilter.SHOW_TEXT);
        let node: Text | null;
        while ((node = walker.nextNode() as Text | null)) {
            if (node.data.includes('😀')) {
                const idx = node.data.indexOf('😀') + 2; // after the emoji
                const sel = window.getSelection()!;
                const range = document.createRange();
                range.setStart(node, idx);
                range.collapse(true);
                sel.removeAllRanges();
                sel.addRange(range);
                break;
            }
        }
    }, emojiEnd);
    await page.keyboard.type('X');
    await page.waitForTimeout(1700);
    const w = await fakeWire(page);
    expect(w.calls).toEqual([{ method: 'saveEdit', args: ['r1', emojiEnd, emojiEnd, 'X'] }]);
    expect(errors).toEqual([]);
});

test('indicator walks the states', async ({ page }) => {
    await open(page);
    await toEdit(page);
    await page.evaluate(() => {
        const wire = (window as any).gdFakeWire;
        const orig = wire.saveEdit.bind(wire);
        wire.saveEdit = async (...args: any[]) => {
            await new Promise((r) => setTimeout(r, 300));
            return orig(...args);
        };
    });
    const block = page.locator('[data-ps="27"]');
    await block.click();
    await page.keyboard.type('X');
    await expect.poll(async () => (await state(page)).save).toBe('dirty');
    await page.waitForTimeout(1700);
    await expect.poll(async () => (await state(page)).save).toBe('saving');
    await expect.poll(async () => (await state(page)).save, { timeout: 4000 }).toBe('saved');
});

test('queued save uses new revision', async ({ page }) => {
    await open(page);
    await toEdit(page);
    await page.evaluate(() => {
        const wire = (window as any).gdFakeWire;
        wire.responses = [
            new Promise((resolve) => { (window as any).__resolve1 = resolve; }),
        ];
    });
    const block = page.locator('[data-ps="27"]');
    await block.click();
    await setCaretAtEnd(page, '[data-ps="27"]');
    await page.keyboard.type('A');
    await page.waitForTimeout(1700); // debounce fires, first save in flight (held)
    await page.keyboard.type('B');
    await page.waitForTimeout(100);
    // release first save
    await page.evaluate(() => (window as any).__resolve1({ ok: true, revisionId: 'r2' }));
    await page.waitForTimeout(1700); // second debounce after first resolves + queued change saves
    await expect.poll(async () => (await fakeWire(page)).calls.length).toBeGreaterThanOrEqual(2);
    const w = await fakeWire(page);
    expect(w.calls[0].args[0]).toBe('r1');
    expect(w.calls[1].args[0]).toBe('r2');
});

test('second block uses shifted indexes', async ({ page }) => {
    await open(page);
    await toEdit(page);
    const b1 = page.locator('#gd-doc [data-ps]').filter({ hasText: 'Paragraf pengisi nomor 1 ' }).first();
    const ps1 = +(await b1.getAttribute('data-ps'))!;
    await b1.click();
    await setCaretAtEnd(page, `[data-ps="${ps1}"]`);
    await page.keyboard.type('XYZ');
    await page.waitForTimeout(1700);

    const b2 = page.locator('#gd-doc [data-ps]').filter({ hasText: 'Paragraf pengisi nomor 2 ' }).first();
    const shiftedPs2 = +(await b2.getAttribute('data-ps'))!; // already shifted by +3 in the DOM
    const idx = RUNS.findIndex(([, , t]) => t.includes('Paragraf pengisi nomor 2 '));
    const [origPs2, origPe2, text2] = RUNS[idx];
    expect(shiftedPs2).toBe(origPs2 + 3);

    // Insert at the very start of block 2 so the call's start is exactly its shifted ps.
    await b2.click();
    await setCaretAtStart(page, `[data-ps="${shiftedPs2}"]`);
    await page.keyboard.type('Q');
    await page.waitForTimeout(1700);

    const w = await fakeWire(page);
    const saves = w.calls.filter((c: any) => c.method === 'saveEdit');
    expect(saves.length).toBe(2);
    expect(saves[1].args[0]).toBe('r2');
    expect(saves[1].args[1]).toBe(shiftedPs2);
    expect(saves[1].args[2]).toBe(shiftedPs2);
    expect(saves[1].args[3]).toBe('Q');
});

test('Enter and Shift+Enter', async ({ page }) => {
    await open(page);
    await toEdit(page);
    const block = page.locator('[data-ps="27"]');
    await block.click();
    // place caret after "Temuan " (data-s=27..34 is "Temuan ")
    await page.evaluate(() => {
        const span = document.querySelector('[data-s="27"]') as HTMLElement;
        const node = span.firstChild as Text;
        const sel = window.getSelection()!;
        const range = document.createRange();
        range.setStart(node, node.data.length);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
    });
    await page.keyboard.press('Enter');
    await page.waitForTimeout(1700);
    let w = await fakeWire(page);
    expect(w.calls[0].args).toEqual(['r1', 34, 34, '\n']);

    await resetWire(page);
    await block.click({ force: true });
    await setCaretAtEnd(page, '[data-ps="27"]');
    await page.keyboard.down('Shift');
    await page.keyboard.press('Enter');
    await page.keyboard.up('Shift');
    await page.waitForTimeout(1700);
    w = await fakeWire(page);
    expect(w.calls.length).toBeGreaterThan(0);
    expect(w.calls[w.calls.length - 1].args[3]).toBe('\u000B');
});

test('soft break round-trips', async ({ page }) => {
    const errors = await open(page);
    await toEdit(page);
    // paragraph "Gambar ... lalu \u000Bbaris lunak" is data-ps="64"
    const block = page.locator('[data-ps="64"]');
    await block.click();
    await setCaretAtEnd(page, '[data-ps="64"]');
    await page.keyboard.type('!');
    await page.waitForTimeout(1700);
    const w = await fakeWire(page);
    // the inserted text is appended after the existing soft break; it must
    // not touch/duplicate the \u000B itself.
    expect(w.calls.length).toBe(1);
    expect(w.calls[0].args[3]).toBe('!');
    expect(errors).toEqual([]);
});

test('atoms cannot be deleted', async ({ page }) => {
    const errors = await open(page);
    await toEdit(page);
    const block = page.locator('[data-ps="64"]');
    await block.click();
    // caret right after the image atom, then Backspace
    await page.evaluate(() => {
        const atom = document.querySelector('[data-ps="64"] [data-atom]') as HTMLElement;
        const sel = window.getSelection()!;
        const range = document.createRange();
        range.setStartAfter(atom);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
    });
    await page.keyboard.press('Backspace');
    await page.waitForTimeout(1700);
    const w = await fakeWire(page);
    expect(w.calls.filter((c: any) => c.method === 'saveEdit')).toEqual([]);
    await expect(page.locator('[data-ps="64"] [data-atom]')).toHaveCount(1);
    expect(errors).toEqual([]);
});

test('read-only paragraphs', async ({ page }) => {
    await open(page);
    await toEdit(page);
    const usulan = page.locator('#gd-doc [data-ro]').filter({ hasText: 'Usulan' }).first();
    await usulan.click();
    await expect(usulan).not.toHaveAttribute('contenteditable', 'true');
    await expect(usulan).toHaveAttribute('title', /suggestion/);
});

test('delete paragraph asks when it carries comments', async ({ page }) => {
    const errors = await open(page);
    await toEdit(page);
    const block = page.locator('#gd-doc [data-ps]').filter({ hasText: 'Temuan' }).first();
    const ds = await block.getAttribute('data-ds');
    const de = await block.getAttribute('data-de');
    await block.click();

    let dialogMessage = '';
    page.once('dialog', async (d) => { dialogMessage = d.message(); await d.accept(); });
    await page.locator('#gd-delete-paragraph').click();
    await expect.poll(() => dialogMessage).toContain('2 komentar aktif');
    await page.waitForTimeout(100);
    const w = await fakeWire(page);
    const save = w.calls.find((c: any) => c.method === 'saveEdit');
    expect(save.args[1]).toBe(+ds!);
    expect(save.args[2]).toBe(+de!);
    expect(save.args[3]).toBe('');
    expect(w.calls.some((c: any) => c.method === 'loadDocument')).toBe(true);
    expect(errors).toEqual([]);
});

test('merge with Backspace at start', async ({ page }) => {
    await open(page);
    await toEdit(page);
    const b2 = page.locator('#gd-doc [data-ps]').filter({ hasText: 'Paragraf pengisi nomor 2 ' }).first();
    const ps = +(await b2.getAttribute('data-ps'))!;
    await b2.click();
    await setCaretAtStart(page, `[data-ps="${ps}"]`);
    await page.keyboard.press('Backspace');
    await page.waitForTimeout(100);
    const w = await fakeWire(page);
    const save = w.calls.find((c: any) => c.method === 'saveEdit');
    expect(save.args[1]).toBe(ps - 1);
    expect(save.args[2]).toBe(ps);
    expect(save.args[3]).toBe('');
    expect(w.calls.some((c: any) => c.method === 'loadDocument')).toBe(true);
});

test('conflict keeps the text', async ({ page }) => {
    const errors = await open(page);
    await toEdit(page);
    await page.evaluate(() => { (window as any).gdFakeWire.responses = [{ ok: false, reason: 'conflict' }]; });
    const block = page.locator('[data-ps="27"]');
    await block.click();
    await setCaretAtEnd(page, '[data-ps="27"]');
    await page.keyboard.type('X');
    await page.waitForTimeout(1700);
    await expect.poll(async () => (await state(page)).save).toBe('conflict');
    await expect(page.locator('#gd-save-indicator')).toContainText('Dokumen berubah di Google Docs');
    const before = await block.textContent();
    await resetWire(page);
    await page.keyboard.type('Y');
    await page.waitForTimeout(1700);
    const w = await fakeWire(page);
    expect(w.calls).toEqual([]);
    expect(await block.textContent()).toContain(before!.slice(0, 5));
    expect(errors).toEqual([]);
});

test('stale autosave debounce cancelled on leaveBlock', async ({ page }) => {
    const errors = await open(page);
    await toEdit(page);
    const block = page.locator('[data-ps="27"]');
    await block.click();
    await setCaretAtEnd(page, '[data-ps="27"]');
    await page.keyboard.type('X');
    // Leave the block well within the 1500ms debounce window: leaveBlock
    // flushes the pending change immediately and must cancel the timer.
    await page.waitForTimeout(200);
    await page.locator('[data-ps="64"]').click();
    // Now advance well past the original 1500ms window; if the stale
    // timer were not cancelled it would fire `triggerSave(null)` here.
    await page.waitForTimeout(1700);
    const w = await fakeWire(page);
    const saves = w.calls.filter((c: any) => c.method === 'saveEdit');
    expect(saves.length).toBe(1);
    expect(saves[0].args[3]).toBe('X');
    expect(errors).toEqual([]);
});

test('stale autosave debounce cancelled on switch to Komentar', async ({ page }) => {
    const errors = await open(page);
    await toEdit(page);
    const block = page.locator('[data-ps="27"]');
    await block.click();
    await setCaretAtEnd(page, '[data-ps="27"]');
    await page.keyboard.type('X');
    // Switch mode within the debounce window: _leaveEditMode flushes the
    // save immediately and must cancel the pending debounce timer too.
    await page.waitForTimeout(200);
    await page.locator('#gd-mode-comment').click();
    await expect.poll(async () => (await state(page)).mode).toBe('comment');
    await page.waitForTimeout(1700);
    const w = await fakeWire(page);
    const saves = w.calls.filter((c: any) => c.method === 'saveEdit');
    expect(saves.length).toBe(1);
    expect(saves[0].args[3]).toBe('X');
    expect(errors).toEqual([]);
});

test('select-across-atom-then-type does not bypass atom protection', async ({ page }) => {
    const errors = await open(page);
    await toEdit(page);
    const block = page.locator('[data-ps="64"]');
    await block.click();
    // Select a range spanning the image atom in "Gambar [img] lalu ...".
    await page.evaluate(() => {
        const p = document.querySelector('[data-ps="64"]') as HTMLElement;
        const atom = p.querySelector('[data-atom]') as HTMLElement;
        const before = atom.previousSibling as Text; // "Gambar "
        const after = atom.nextSibling as Text; // " lalu ..."
        const sel = window.getSelection()!;
        const range = document.createRange();
        range.setStart(before, 0);
        range.setEnd(after, 1);
        sel.removeAllRanges();
        sel.addRange(range);
    });
    await page.keyboard.type('Q');
    await page.waitForTimeout(1700);
    const w = await fakeWire(page);
    expect(w.calls.filter((c: any) => c.method === 'saveEdit')).toEqual([]);
    await expect(page.locator('[data-ps="64"] [data-atom]')).toHaveCount(1);
    expect(errors).toEqual([]);
});

test('paste strips markup and keeps newlines as paragraph breaks', async ({ page }) => {
    const errors = await open(page);
    await toEdit(page);
    const block = page.locator('[data-ps="27"]');
    await block.click();
    await setCaretAtEnd(page, '[data-ps="27"]');
    await page.evaluate(() => {
        const el = document.querySelector('[data-ps="27"]') as HTMLElement;
        const dt = new DataTransfer();
        dt.setData('text/plain', 'a<b>x</b>\nb');
        const ev = new InputEvent('beforeinput', {
            inputType: 'insertFromPaste',
            dataTransfer: dt,
            cancelable: true,
            bubbles: true,
        });
        el.dispatchEvent(ev);
    });
    await page.waitForTimeout(1700);
    // No <b> element was created: the markup was inserted as plain text.
    await expect(page.locator('[data-ps="27"] b')).toHaveCount(0);
    expect(await block.textContent()).toContain('<b>x</b>');
    const w = await fakeWire(page);
    const save = w.calls.find((c: any) => c.method === 'saveEdit');
    expect(save).toBeDefined();
    // The newline from the pasted text became a paragraph break (\n).
    expect(save.args[3]).toContain('\n');
    expect(errors).toEqual([]);
});

test('comment mode unchanged', async ({ page }) => {
    const errors = await open(page);
    await toEdit(page);
    const block = page.locator('[data-ps="27"]');
    await block.click();
    await setCaretAtEnd(page, '[data-ps="27"]');
    await page.keyboard.type('X');
    await page.waitForTimeout(1700); // auto-save fires so the switch has something to flush

    await page.locator('#gd-mode-comment').click();
    await expect.poll(async () => (await fakeWire(page)).calls.some((c: any) => c.method === 'loadDocument')).toBe(true);
    await expect.poll(async () => (await state(page)).mode).toBe('comment');
    expect(errors).toEqual([]);
});
