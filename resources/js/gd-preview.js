/*
 * Google Docs preview: selection -> Docs indexes, comment navigation,
 * page fit-to-width, edit mode, and the Alpine component for the page.
 * Inlined by the Filament page and by the browser-test harness.
 */
(function (global) {
    function runs(root) {
        return Array.from(root.querySelectorAll('[data-s]'));
    }

    function inDeco(node) {
        const el = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
        return !!(el && el.closest('[data-deco]'));
    }

    function pointIndex(root, node, offset, isEnd) {
        // Fast path: boundary inside the text of a run.
        if (node.nodeType === Node.TEXT_NODE && !inDeco(node)) {
            const run = node.parentElement && node.parentElement.closest('[data-s]');
            if (run && root.contains(run)) {
                const s = +run.dataset.s;
                const e = +run.dataset.e;
                if (run.dataset.atom) return isEnd ? e : s;
                return Math.min(s + offset, e);
            }
        }
        // Boundary on an element, in decoration, inside an atom label or
        // between blocks: snap to the nearest run in document order.
        const all = runs(root);
        if (isEnd) {
            for (let i = all.length - 1; i >= 0; i--) {
                const r = document.createRange();
                r.selectNodeContents(all[i]);
                if (r.comparePoint(node, offset) >= 0) return +all[i].dataset.e;
            }
            return null;
        }
        for (let i = 0; i < all.length; i++) {
            const r = document.createRange();
            r.selectNodeContents(all[i]);
            if (r.comparePoint(node, offset) <= 0) return +all[i].dataset.s;
        }
        return null;
    }

    function selectionToRange(root) {
        const sel = global.getSelection();
        if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return null;
        const range = sel.getRangeAt(0);
        if (!root.contains(range.commonAncestorContainer)) return null;
        const start = pointIndex(root, range.startContainer, range.startOffset, false);
        const end = pointIndex(root, range.endContainer, range.endOffset, true);
        if (start === null || end === null || end <= start) return null;
        return { start: start, end: end, text: visibleText(range) };
    }

    // Selection text with each atom as one U+FFFC (as the Docs API counts it)
    // and without decoration or the page's <style>.
    function visibleText(range) {
        const frag = range.cloneContents();
        frag.querySelectorAll('[data-deco], style').forEach(function (el) { el.remove(); });
        frag.querySelectorAll('[data-atom]').forEach(function (el) {
            el.replaceWith(document.createTextNode('\uFFFC'));
        });
        const holder = document.createElement('div');
        holder.style.cssText = 'position:absolute;left:-9999px;white-space:pre-wrap';
        holder.appendChild(frag);
        document.body.appendChild(holder);
        const text = holder.innerText;
        holder.remove();
        return text;
    }

    const nav = {
        spans: function (root, id) {
            return Array.from(root.querySelectorAll('[data-c~="' + CSS.escape(id) + '"], [data-r~="' + CSS.escape(id) + '"]'));
        },
        idsAt: function (el) {
            const run = el && el.closest && el.closest('[data-c]');
            return run ? run.dataset.c.split(' ') : [];
        },
        scrollTo: function (el) {
            if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },
    };

    function highlightCss(active, flash) {
        let css = '';
        if (active) css += '.gd-page [data-c~="' + CSS.escape(active) + '"]{background-color:#fde68a !important}';
        if (flash) css += '.gd-page [data-r~="' + CSS.escape(flash) + '"],.gd-page [data-c~="' + CSS.escape(flash) + '"]{background-color:#fde68a !important}';
        return css;
    }

    // Scale the page down to the canvas width; the holder takes the scaled
    // size so the canvas scrolls over the visible page only.
    function autoFit(canvas) {
        function fit() {
            const holder = canvas.querySelector('.gd-holder');
            const page = canvas.querySelector('.gd-page');
            if (!holder || !page) return;
            const style = getComputedStyle(canvas);
            const room = canvas.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            const natural = page.offsetWidth;
            const scale = natural > 0 ? Math.min(1, room / natural) : 1;
            const transform = scale < 1 ? 'scale(' + scale.toFixed(4) + ')' : '';
            if (page.style.transform !== transform) page.style.transform = transform;
            const w = Math.ceil(natural * scale) + 'px';
            const h = Math.ceil(page.offsetHeight * scale) + 'px';
            if (holder.style.width !== w) holder.style.width = w;
            if (holder.style.height !== h) holder.style.height = h;
        }
        new ResizeObserver(fit).observe(canvas);
        new MutationObserver(fit).observe(canvas, { childList: true, subtree: true, attributes: true, attributeFilter: ['style'] });
        canvas.addEventListener('load', fit, true);
        fit();
        return fit;
    }

    // ---- Edit mode: window.GdEdit, pure DOM<->text helpers -------------

    // Walks blockEl like the Docs text model: [data-atom] -> one U+FFFC,
    // br[data-vt] -> U+000B (soft break), br[data-pb] -> '\n' (hard break
    // inserted by Enter), [data-deco] ignored entirely, a trailing browser
    // <br> (neither data-vt nor data-pb) ignored. When `target` is given
    // ({node, offset} a DOM boundary inside blockEl) also records the
    // character offset of that boundary in `state.found`.
    function walkBlock(node, target, state) {
        if (node.nodeType === Node.TEXT_NODE) {
            if (state.found === null && target && target.node === node) {
                state.found = state.text.length + target.offset;
            }
            state.text += node.data;
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) return;
        if (node.hasAttribute('data-deco')) return;
        if (node.hasAttribute('data-atom')) {
            if (state.found === null && target && target.node === node) state.found = state.text.length;
            state.text += '\uFFFC';
            return;
        }
        if (node.tagName === 'BR') {
            if (state.found === null && target && target.node === node) state.found = state.text.length;
            if (node.hasAttribute('data-vt')) state.text += '\u000B';
            else if (node.hasAttribute('data-pb')) state.text += '\n';
            // else: trailing browser-inserted <br>, contributes nothing.
            return;
        }
        const children = node.childNodes;
        for (let i = 0; i < children.length; i++) {
            if (state.found === null && target && target.node === node && target.offset === i) {
                state.found = state.text.length;
            }
            walkBlock(children[i], target, state);
        }
        if (state.found === null && target && target.node === node && target.offset === children.length) {
            state.found = state.text.length;
        }
    }

    function read(blockEl) {
        const state = { text: '', found: null };
        walkBlock(blockEl, null, state);
        return state.text;
    }

    // Character offset (GdEdit.read units) of a DOM boundary inside blockEl.
    function offsetOf(blockEl, node, offset) {
        const state = { text: '', found: null };
        walkBlock(blockEl, { node: node, offset: offset }, state);
        return state.found !== null ? state.found : state.text.length;
    }

    function isHighSurrogate(code) { return code >= 0xd800 && code <= 0xdbff; }
    function isLowSurrogate(code) { return code >= 0xdc00 && code <= 0xdfff; }

    // Common-prefix/suffix diff in UTF-16 units; never splits a surrogate
    // pair. Returns null when saved === current.
    function diff(saved, current) {
        if (saved === current) return null;
        const maxP = Math.min(saved.length, current.length);
        let pLen = 0;
        while (pLen < maxP && saved[pLen] === current[pLen]) pLen++;
        if (pLen > 0 && pLen < saved.length
            && isHighSurrogate(saved.charCodeAt(pLen - 1)) && isLowSurrogate(saved.charCodeAt(pLen))) {
            pLen--;
        }
        const maxS = Math.min(saved.length - pLen, current.length - pLen);
        let sLen = 0;
        while (sLen < maxS && saved[saved.length - 1 - sLen] === current[current.length - 1 - sLen]) sLen++;
        if (sLen > 0) {
            const savedSuffixStart = saved.length - sLen;
            if (savedSuffixStart > pLen
                && isHighSurrogate(saved.charCodeAt(savedSuffixStart - 1)) && isLowSurrogate(saved.charCodeAt(savedSuffixStart))) {
                sLen--;
            }
        }
        const removed = saved.slice(pLen, saved.length - sLen);
        const inserted = current.slice(pLen, current.length - sLen);
        if (removed === '' && inserted === '') return null;
        return { from: pLen, removed: removed, inserted: inserted };
    }

    // Shifts every data-s/e/ps/pe/ds/de >= from by delta, skipping anything
    // inside (or being) an actively-edited block ([data-edited]).
    function shift(root, from, delta) {
        if (!delta) return;
        const attrs = ['s', 'e', 'ps', 'pe', 'ds', 'de'];
        root.querySelectorAll('[data-s],[data-e],[data-ps],[data-pe],[data-ds],[data-de]').forEach(function (el) {
            if (el.closest('[data-edited]')) return;
            attrs.forEach(function (a) {
                const key = 'data-' + a;
                if (el.hasAttribute(key)) {
                    const v = +el.getAttribute(key);
                    if (v >= from) el.setAttribute(key, String(v + delta));
                }
            });
        });
    }

    function convertSoftBreaks(root) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (n) {
                return (n.parentElement && n.parentElement.closest('[data-deco]'))
                    ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            },
        });
        const nodes = [];
        let n;
        while ((n = walker.nextNode())) nodes.push(n);
        nodes.forEach(function (node) {
            if (node.data.indexOf('\n') === -1) return;
            const parts = node.data.split('\n');
            const frag = document.createDocumentFragment();
            parts.forEach(function (part, i) {
                if (part !== '') frag.appendChild(document.createTextNode(part));
                if (i < parts.length - 1) {
                    const br = document.createElement('br');
                    br.setAttribute('data-vt', '');
                    frag.appendChild(br);
                }
            });
            node.replaceWith(frag);
        });
    }

    function removeFiller(el) {
        el.querySelectorAll('[data-deco]').forEach(function (n) {
            if (n.textContent === '\u200B') n.remove();
        });
    }

    function insertBreakAtCaret(block, attr) {
        const sel = global.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        if (!block.contains(range.commonAncestorContainer)) return;
        range.deleteContents();
        const br = document.createElement('br');
        br.setAttribute(attr, '');
        range.insertNode(br);
        range.setStartAfter(br);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function insertPlainText(block, text) {
        const sel = global.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        if (!block.contains(range.commonAncestorContainer)) return;
        range.deleteContents();
        const lines = String(text).split('\n');
        lines.forEach(function (line, i) {
            if (i > 0) {
                const br = document.createElement('br');
                br.setAttribute('data-pb', '');
                range.insertNode(br);
                range.setStartAfter(br);
                range.collapse(true);
            }
            if (line !== '') {
                const node = document.createTextNode(line);
                range.insertNode(node);
                range.setStartAfter(node);
                range.collapse(true);
            }
        });
        sel.removeAllRanges();
        sel.addRange(range);
    }

    // Current selection as a one-element range array, if non-collapsed and
    // inside block (used as a getTargetRanges() fallback for browsers/paths
    // that don't populate it).
    function currentSelectionRanges(block) {
        const sel = global.getSelection();
        if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return [];
        const r = sel.getRangeAt(0);
        if (!block.contains(r.commonAncestorContainer)) return [];
        return [r];
    }

    function rangesOverlap(a, b) {
        return a.compareBoundaryPoints(Range.END_TO_START, b) < 0 && a.compareBoundaryPoints(Range.START_TO_END, b) > 0;
    }

    function rangesIntersectAtom(block, staticRanges) {
        const atoms = block.querySelectorAll('[data-atom]');
        if (!atoms.length || !staticRanges || !staticRanges.length) return false;
        for (const r of staticRanges) {
            let range;
            try {
                range = document.createRange();
                range.setStart(r.startContainer, r.startOffset);
                range.setEnd(r.endContainer, r.endOffset);
            } catch (e) {
                continue;
            }
            for (const atom of atoms) {
                const atomRange = document.createRange();
                atomRange.selectNode(atom);
                if (rangesOverlap(range, atomRange)) return true;
            }
        }
        return false;
    }

    function placeCaretAtEnd(el) {
        const sel = global.getSelection();
        const range = document.createRange();
        range.selectNodeContents(el);
        range.collapse(false);
        sel.removeAllRanges();
        sel.addRange(range);
    }

    const TOC_TOOLTIP = 'Tidak bisa diedit di sini: daftar isi';
    const SUGGESTION_TOOLTIP = 'Tidak bisa diedit di sini: ada saran (suggestion) yang belum diterima';

    // Alpine component of the page (x-data="gdPreviewPage()").
    function gdPreviewPage() {
        return {
            range: null, comment: '', busy: false, active: null, flash: null, showResolved: false,
            _flashTimer: null, _fit: null,

            // Edit mode.
            mode: 'comment', save: 'saved', block: null, revision: null,
            _errorMessage: '', _anyEdited: false, _saveDebounce: null,

            wire() { return this.$wire || global.gdFakeWire; },

            get activeCss() { return highlightCss(this.active, this.flash); },
            get saveError() { return this._errorMessage; },

            init() {
                this._fit = autoFit(this.$refs.canvas);
                this.revision = (this.$wire && this.$wire.revisionId) || this.$root.dataset.revision || null;
                this.$refs.doc.querySelectorAll('[data-ro]').forEach((el) => {
                    el.title = el.dataset.ro === 'toc' ? TOC_TOOLTIP : SUGGESTION_TOOLTIP;
                });
                global.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        this.active = null;
                        if (this.mode === 'edit' && this.block) this.leaveBlock();
                    }
                });
                global.addEventListener('beforeunload', (e) => {
                    if (['dirty', 'saving', 'error', 'conflict'].includes(this.save)) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });
            },

            card(id) {
                return this.$root.querySelector('.gd-card[data-comment-id' + '=\"' + CSS.escape(id) + '\"]');
            },

            onDocUp(e) {
                if (this.mode === 'edit') { this.onEditClick(e); return; }
                const r = selectionToRange(this.$refs.doc);
                if (r) { this.range = r; return; }
                // Collapsed: a click. Highlighted text activates its comment.
                const ids = nav.idsAt(e.target);
                if (ids.length === 0) { this.active = null; return; }
                ids.sort((a, b) => this.len(a) - this.len(b));
                const at = ids.indexOf(this.active);
                const next = at >= 0 ? ids[(at + 1) % ids.length] : ids[0];
                this.active = next;
                const card = this.card(next);
                if (card) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            },

            len(id) {
                const c = this.card(id);
                return c ? +c.dataset.len : Infinity;
            },

            clickCard(id) {
                const card = this.card(id);
                if (!card || card.dataset.located !== '1') return;
                if (card.dataset.status === 'resolved') {
                    this.flash = id;
                    clearTimeout(this._flashTimer);
                    this._flashTimer = setTimeout(() => { this.flash = null; }, 2000);
                } else {
                    this.active = id;
                }
                nav.scrollTo(nav.spans(this.$refs.doc, id)[0]);
            },

            reloaded(detail) {
                clearTimeout(this._saveDebounce);
                this._saveDebounce = null;
                this.range = null;
                this.comment = '';
                global.getSelection().removeAllRanges();
                this.block = null;
                this.save = 'saved';
                this.$nextTick(() => {
                    if (this._fit) this._fit();
                    const want = detail && detail.activate;
                    if (want && this.card(want)) {
                        this.active = want;
                        this.card(want).scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        nav.scrollTo(nav.spans(this.$refs.doc, want)[0]);
                    } else if (this.active && !this.card(this.active)) {
                        this.active = null;
                    }
                    if (detail && typeof detail.mergeTarget === 'number' && this.mode === 'edit') {
                        const target = detail.mergeTarget;
                        const el = Array.from(this.$refs.doc.querySelectorAll('[data-ps]'))
                            .find((p) => +p.dataset.ps <= target && target < +p.dataset.pe + 1);
                        if (el) {
                            this.activateBlock(el);
                            placeCaretAtEnd(el);
                        }
                    }
                });
            },

            async submit() {
                if (!this.range || !this.comment.trim()) return;
                this.busy = true;
                try {
                    await this.$wire.addComment(this.range.start, this.range.end, this.range.text, this.comment);
                } finally {
                    this.busy = false;
                }
            },

            // ---- Edit mode -------------------------------------------------

            setMode(next) {
                if (next === this.mode) return;
                if (this.mode === 'edit' && next === 'comment') {
                    this._leaveEditMode(next);
                    return;
                }
                this.mode = next;
            },

            async _leaveEditMode(next) {
                clearTimeout(this._saveDebounce);
                this._saveDebounce = null;
                const block = this.block;
                if (block) {
                    await this.triggerSave(block);
                    if (this.save === 'conflict' || this.save === 'error') return; // stay in Edit
                    this.leaveBlock(block);
                }
                if (this._anyEdited) {
                    this._anyEdited = false;
                    await this.wire().loadDocument();
                    this.reloaded();
                }
                this.mode = next;
            },

            activateBlock(el) {
                this.block = el;
                this.save = 'saved';
                el.setAttribute('contenteditable', 'true');
                el.setAttribute('data-edited', '');
                el.querySelectorAll('[data-atom], [data-deco]').forEach((n) => n.setAttribute('contenteditable', 'false'));
                convertSoftBreaks(el);
                removeFiller(el);
                el._savedText = read(el);
                el.focus();
            },

            async leaveBlock(block) {
                clearTimeout(this._saveDebounce);
                this._saveDebounce = null;
                block = block || this.block;
                if (!block) return;
                await this.triggerSave(block);
                if (this.block === block) this.block = null;
                block.removeAttribute('contenteditable');
                block.removeAttribute('data-edited');
                block.querySelectorAll('[data-atom], [data-deco]').forEach((n) => n.removeAttribute('contenteditable'));
            },

            onEditClick(e) {
                const target = e.target.closest('[data-ps]');
                if (target && target === this.block) return;
                const old = this.block;
                if (old) this.leaveBlock(old);
                if (!target || target.dataset.ro) return;
                this.activateBlock(target);
            },

            onEditInput() {
                if (this.mode !== 'edit' || !this.block) return;
                if (this.save === 'conflict') return; // frozen until Salin teks / Muat ulang
                this.save = 'dirty';
                clearTimeout(this._saveDebounce);
                this._saveDebounce = setTimeout(() => this.triggerSave(this.block), 1500);
            },

            onBeforeInput(e) {
                if (this.mode !== 'edit' || !this.block || !this.block.contains(e.target)) return;
                const t = e.inputType;
                if (t === 'insertText' || t === 'insertReplacementText' || t === 'insertFromPaste' || t === 'insertFromDrop') {
                    const targetRanges = e.getTargetRanges ? e.getTargetRanges() : [];
                    const ranges = targetRanges.length ? targetRanges : currentSelectionRanges(this.block);
                    if (rangesIntersectAtom(this.block, ranges)) {
                        e.preventDefault();
                        return;
                    }
                }
                if (t === 'insertParagraph') {
                    e.preventDefault();
                    insertBreakAtCaret(this.block, 'data-pb');
                    this.onEditInput();
                    return;
                }
                if (t === 'insertLineBreak') {
                    e.preventDefault();
                    insertBreakAtCaret(this.block, 'data-vt');
                    this.onEditInput();
                    return;
                }
                if (t === 'insertFromPaste' || t === 'insertFromDrop') {
                    e.preventDefault();
                    const dt = e.dataTransfer || global.clipboardData;
                    const text = dt ? dt.getData('text/plain') : '';
                    insertPlainText(this.block, text);
                    this.onEditInput();
                    return;
                }
                if (t && t.indexOf('format') === 0) {
                    e.preventDefault();
                    return;
                }
                if (t && t.indexOf('delete') === 0) {
                    if (t === 'deleteContentBackward' && this.caretAtBlockStart() && this.block.hasAttribute('data-merge')) {
                        e.preventDefault();
                        this.mergeUp();
                        return;
                    }
                    const ranges = e.getTargetRanges ? e.getTargetRanges() : [];
                    if (rangesIntersectAtom(this.block, ranges)) {
                        e.preventDefault();
                    }
                }
            },

            caretAtBlockStart() {
                const sel = global.getSelection();
                if (!sel || !sel.isCollapsed || sel.rangeCount === 0) return false;
                const r = sel.getRangeAt(0);
                if (!this.block.contains(r.startContainer)) return false;
                return offsetOf(this.block, r.startContainer, r.startOffset) === 0;
            },

            triggerSave(block) {
                if (!block || !block.isConnected) return Promise.resolve();
                if (block._saving) return block._savePromise;
                block._saving = true;
                block._savePromise = this._saveLoop(block).finally(() => { block._saving = false; });
                return block._savePromise;
            },

            async _saveLoop(block) {
                for (;;) {
                    const current = read(block);
                    const d = diff(block._savedText, current);
                    if (!d) {
                        if (this.block === block) this.save = 'saved';
                        return;
                    }
                    if (d.removed.indexOf('\uFFFC') !== -1) {
                        if (this.block === block) {
                            this.save = 'error';
                            this._errorMessage = 'Objek (gambar/chip) tidak bisa dihapus dari sini.';
                        }
                        return;
                    }
                    if (this.block === block) this.save = 'saving';
                    const ps = +block.dataset.ps;
                    const start = ps + d.from;
                    const end = start + d.removed.length;
                    const revision = this.revision;
                    const res = await this.wire().saveEdit(revision, start, end, d.inserted);
                    if (!res.ok) {
                        if (this.block === block) {
                            if (res.reason === 'conflict') {
                                this.save = 'conflict';
                            } else {
                                this.save = 'error';
                                this._errorMessage = res.message || 'Gagal menyimpan';
                            }
                        }
                        return;
                    }
                    this.revision = res.revisionId;
                    this._anyEdited = true;
                    const delta = d.inserted.length - d.removed.length;
                    const oldPe = +block.dataset.pe;
                    block.dataset.pe = String(oldPe + delta);
                    block.dataset.de = String(+block.dataset.de + delta);
                    shift(this.$refs.doc, oldPe, delta);
                    block._savedText = current;
                }
            },

            retrySave() {
                if (this.block) this.triggerSave(this.block);
            },

            copyBlock() {
                if (!this.block) return;
                const text = read(this.block);
                if (global.navigator && global.navigator.clipboard && global.navigator.clipboard.writeText) {
                    global.navigator.clipboard.writeText(text).catch(() => {});
                }
            },

            async reloadAfterConflict() {
                if (!global.confirm('Perubahan yang belum tersimpan di paragraf ini akan hilang. Lanjut?')) return;
                clearTimeout(this._saveDebounce);
                this._saveDebounce = null;
                if (this.block) {
                    this.block.removeAttribute('contenteditable');
                    this.block.removeAttribute('data-edited');
                }
                this.save = 'saved';
                await this.wire().loadDocument();
                this.reloaded();
            },

            async mergeUp() {
                clearTimeout(this._saveDebounce);
                this._saveDebounce = null;
                const block = this.block;
                if (!block) return;
                await this.triggerSave(block);
                if (this.save === 'conflict' || this.save === 'error') return;
                const ps = +block.dataset.ps;
                const res = await this.wire().saveEdit(this.revision, ps - 1, ps, '');
                if (!res.ok) {
                    if (res.reason === 'conflict') this.save = 'conflict';
                    else { this.save = 'error'; this._errorMessage = res.message || 'Gagal menyimpan'; }
                    return;
                }
                this.revision = res.revisionId;
                this._anyEdited = true;
                await this.wire().loadDocument();
                this.reloaded({ mergeTarget: ps - 1 });
            },

            async deleteParagraph() {
                clearTimeout(this._saveDebounce);
                this._saveDebounce = null;
                const block = this.block;
                if (!block) return;
                await this.triggerSave(block);
                if (this.save === 'conflict' || this.save === 'error') return;
                const activeIds = new Set();
                block.querySelectorAll('[data-c]').forEach((el) => el.dataset.c.split(' ').forEach((id) => activeIds.add(id)));
                const doneIds = new Set();
                block.querySelectorAll('[data-r]').forEach((el) => el.dataset.r.split(' ').forEach((id) => doneIds.add(id)));
                if (activeIds.size || doneIds.size) {
                    const msg = 'Paragraf ini punya ' + activeIds.size + ' komentar aktif dan '
                        + doneIds.size + ' komentar selesai. Teks yang dikomentari akan ikut terhapus. Lanjut?';
                    if (!global.confirm(msg)) return;
                }
                const ds = +block.dataset.ds;
                const de = +block.dataset.de;
                const res = await this.wire().saveEdit(this.revision, ds, de, '');
                if (!res.ok) {
                    if (res.reason === 'conflict') this.save = 'conflict';
                    else { this.save = 'error'; this._errorMessage = res.message || 'Gagal menyimpan'; }
                    return;
                }
                this.revision = res.revisionId;
                this._anyEdited = true;
                await this.wire().loadDocument();
                this.reloaded();
            },

            // ---- Resolve from the sidebar -----------------------------------

            nextOpenCard(id) {
                const cards = Array.from(document.querySelectorAll('.gd-card[data-located="1"]'))
                    .filter((c) => c.dataset.status !== 'resolved');
                const at = cards.findIndex((c) => c.dataset.commentId === id || c.getAttribute('data-comment-id') === id);
                if (at === -1) return cards.length ? cards[0].getAttribute('data-comment-id') : null;
                for (let i = at + 1; i < cards.length; i++) return cards[i].getAttribute('data-comment-id');
                const other = cards.filter((c) => c.getAttribute('data-comment-id') !== id);
                return other.length ? other[0].getAttribute('data-comment-id') : null;
            },

            async resolve(id, reply) {
                const next = this.nextOpenCard(id);
                if (this.mode === 'edit' && this.block) {
                    clearTimeout(this._saveDebounce);
                    this._saveDebounce = null;
                    await this.triggerSave(this.block);
                    if (this.save === 'conflict' || this.save === 'error') return;
                    this.leaveBlock(this.block);
                }
                await this.wire().resolveComment(id, reply || '', next);
            },
        };
    }

    global.GdPreview = { selectionToRange: selectionToRange, nav: nav, highlightCss: highlightCss, autoFit: autoFit };
    global.GdEdit = { read: read, diff: diff, shift: shift, offsetOf: offsetOf };
    global.gdPreviewPage = gdPreviewPage;
})(window);
