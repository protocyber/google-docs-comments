/*
 * Google Docs preview: selection -> Docs indexes, comment navigation,
 * page fit-to-width, and the Alpine component for the page.
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

    // Alpine component of the page (x-data="gdPreviewPage()").
    function gdPreviewPage() {
        return {
            range: null, comment: '', busy: false, active: null, flash: null, showResolved: false,
            _flashTimer: null, _fit: null,

            get activeCss() { return highlightCss(this.active, this.flash); },

            init() {
                this._fit = autoFit(this.$refs.canvas);
                global.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.active = null; });
            },

            card(id) {
                return this.$root.querySelector('.gd-card[data-comment-id' + '="' + CSS.escape(id) + '"]');
            },

            onDocUp(e) {
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
                this.range = null;
                this.comment = '';
                global.getSelection().removeAllRanges();
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
        };
    }

    global.GdPreview = { selectionToRange: selectionToRange, nav: nav, highlightCss: highlightCss, autoFit: autoFit };
    global.gdPreviewPage = gdPreviewPage;
})(window);
