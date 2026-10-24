<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>gd-preview harness</title>
    <style>{!! $css !!}</style>
    <script>{!! $js !!}</script>
    <script>
        // Fake Livewire wire() for Playwright: records every call, returns
        // queued responses (default ok + a bumped revision) shifted per call.
        window.gdFakeWire = {
            calls: [],
            responses: [],
            _n: 1,
            async saveEdit(revision, start, end, text) {
                this.calls.push({ method: 'saveEdit', args: [revision, start, end, text] });
                const next = this.responses.shift();
                if (next) return next;
                this._n++;
                return { ok: true, revisionId: 'r' + this._n };
            },
            async loadDocument() {
                this.calls.push({ method: 'loadDocument', args: [] });
                return null;
            },
            async resolveComment(commentId, reply, next) {
                this.calls.push({ method: 'resolveComment', args: [commentId, reply, next] });
                return null;
            },
        };
    </script>
    <script defer src="alpine.js"></script>
</head>
<body style="margin:0;padding:1rem;font-family:sans-serif">
<div class="gd-wrap" x-data="gdPreviewPage()" data-revision="r1" x-on:gd-preview-reloaded.window="reloaded($event.detail)">
    <div>
        @include('filament.pages.partials.gd-toolbar', [
            'documentTitle' => 'Fixture', 'tabCount' => 1, 'canEdit' => true, 'canComment' => true,
            'record' => (object) ['google_docs_id' => 'DOC'],
        ])
        @include('filament.pages.partials.gd-canvas', ['html' => $html])
    </div>
    <div class="gd-side">
        @include('filament.pages.partials.gd-comment-list', ['threads' => $threads, 'commentsError' => null, 'documentId' => 'DOC'])
    </div>
</div>
</body>
</html>
