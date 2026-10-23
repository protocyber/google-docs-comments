<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>gd-preview harness</title>
    <style>{!! $css !!}</style>
    <script>{!! $js !!}</script>
    <script defer src="alpine.js"></script>
</head>
<body style="margin:0;padding:1rem;font-family:sans-serif">
<div class="gd-wrap" x-data="gdPreviewPage()" x-on:gd-preview-reloaded.window="reloaded($event.detail)">
    <div>
        @include('filament.pages.partials.gd-canvas', ['html' => $html])
    </div>
    <div class="gd-side">
        @include('filament.pages.partials.gd-comment-list', ['threads' => $threads, 'commentsError' => null, 'documentId' => 'DOC'])
    </div>
</div>
</body>
</html>
