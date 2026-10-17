/*
 * Spike: map a DOM selection inside the preview onto Google Docs indexes.
 * Loaded inline by the preview page and by the Playwright check.
 *
 * selectionToRange(root) -> {start, end, text} | null
 */
(function (global) {
    function runs(root) {
        return Array.from(root.querySelectorAll('[data-s]'));
    }

    function pointIndex(root, node, offset, isEnd) {
        // Fast path: boundary inside the text of a run.
        if (node.nodeType === Node.TEXT_NODE) {
            const run = node.parentElement && node.parentElement.closest('[data-s]');
            if (run && root.contains(run)) {
                const s = +run.dataset.s;
                const e = +run.dataset.e;
                if (run.dataset.atom) return isEnd ? e : s;
                return Math.min(s + offset, e);
            }
        }

        // Boundary on an element, inside an atom label, or between blocks:
        // snap to the nearest run in document order.
        const all = runs(root);
        if (isEnd) {
            for (let i = all.length - 1; i >= 0; i--) {
                const r = document.createRange();
                r.selectNodeContents(all[i]);
                const cmp = r.comparePoint(node, offset); // -1 before, 0 inside, 1 after
                if (cmp >= 0) return +all[i].dataset.e;
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

    // Selection text with each atom (image, chip) counted as one U+FFFC, the
    // way the Docs API counts it, so the server can compare like for like.
    function visibleText(range) {
        const frag = range.cloneContents();
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

    global.GdPreview = { selectionToRange: selectionToRange };
})(window);
