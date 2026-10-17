<x-filament-panels::page>
    <style>
        .gd-wrap { display: grid; grid-template-columns: minmax(0, 1fr) 22rem; gap: 1.5rem; align-items: start; }
        @media (max-width: 1024px) { .gd-wrap { grid-template-columns: 1fr; } }
        .gd-doc { background: #fff; color: #111; padding: 3rem 3.5rem; border-radius: .5rem;
                  box-shadow: 0 1px 3px rgba(0,0,0,.15); font: 11pt/1.5 Arial, sans-serif;
                  max-height: 75vh; overflow: auto; white-space: pre-wrap; }
        .gd-doc p, .gd-doc h1, .gd-doc h2, .gd-doc h3, .gd-doc h4, .gd-doc h5, .gd-doc h6 { margin: 0 0 .35em; }
        .gd-doc h1 { font-size: 20pt; } .gd-doc h2 { font-size: 16pt; } .gd-doc h3 { font-size: 14pt; }
        .gd-doc h4, .gd-doc h5, .gd-doc h6 { font-size: 12pt; }
        .gd-doc .gd-li { display: list-item; list-style: disc; }
        .gd-doc .b { font-weight: 700; } .gd-doc .i { font-style: italic; } .gd-doc .u { text-decoration: underline; }
        .gd-doc .a { color: #1155cc; text-decoration: underline; }
        .gd-doc .hl { background: #fff3a0; }
        .gd-doc .gd-atom { background: #eef; border-radius: 3px; padding: 0 2px; }
        /* mousedown on an <img> starts no text selection; let it fall through */
        .gd-doc .gd-atom img { max-width: 100%; max-height: 12rem; vertical-align: middle; pointer-events: none; user-select: none; }
        .gd-doc .gd-table { border-collapse: collapse; margin: .5em 0; width: 100%; white-space: normal; }
        .gd-doc .gd-table td { border: 1px solid #999; padding: .3em .5em; vertical-align: top; white-space: pre-wrap; }
        .gd-doc .gd-note { color: #888; font-style: italic; }
        .gd-doc ::selection { background: #b3d4fc; }
        .gd-side { position: sticky; top: 5rem; }
    </style>

    @if ($loadError)
        <x-filament::section>
            <p class="text-danger-600">Gagal membaca dokumen: {{ $loadError }}</p>
            <x-filament::button wire:click="loadDocument" class="mt-3">Coba lagi</x-filament::button>
        </x-filament::section>
    @else
        <div class="gd-wrap"
             x-data="{
                range: null, comment: '', busy: false,
                capture() {
                    const r = window.GdPreview.selectionToRange(this.$refs.doc);
                    if (r) this.range = r;
                },
                async submit() {
                    if (!this.range || !this.comment.trim()) return;
                    this.busy = true;
                    await $wire.addComment(this.range.start, this.range.end, this.range.text, this.comment);
                    this.busy = false;
                },
             }"
             x-on:gd-preview-reloaded.window="range = null; comment = ''; window.getSelection().removeAllRanges()">

            <div>
                <p class="text-sm text-gray-500 mb-2">
                    {{ $documentTitle }} · blok teks di dokumen untuk memilih lokasi komentar
                    · <a href="https://docs.google.com/document/d/{{ $record->google_docs_id }}/edit" target="_blank" class="underline">buka di Google Docs</a>
                    · <button type="button" wire:click="loadDocument" class="underline">muat ulang</button>
                </p>
                <div class="gd-doc" x-ref="doc" id="gd-doc"
                     x-on:mouseup="capture()" x-on:keyup="capture()" x-on:touchend="setTimeout(() => capture(), 50)">{!! $html !!}</div>
            </div>

            <x-filament::section class="gd-side">
                <x-slot name="heading">Komentar</x-slot>

                <template x-if="!range">
                    <p class="text-sm text-gray-500">Belum ada teks yang dipilih.</p>
                </template>
                <template x-if="range">
                    <div class="text-sm mb-3">
                        <p class="text-gray-500">Teks terpilih <span class="font-mono" x-text="'[' + range.start + '–' + range.end + ']'"></span></p>
                        <blockquote class="border-l-4 border-primary-500 pl-2 mt-1 italic" x-text="range.text.length > 200 ? range.text.slice(0, 200) + '…' : range.text"></blockquote>
                    </div>
                </template>

                <textarea x-model="comment" rows="4" placeholder="Tulis komentar…" id="gd-comment"
                          class="block w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-600 text-sm"></textarea>

                <x-filament::button class="mt-3 w-full" id="gd-submit"
                    x-on:click="submit()" x-bind:disabled="busy || !range || !comment.trim()">
                    <span x-show="!busy">Tambah komentar</span><span x-show="busy">Menyimpan…</span>
                </x-filament::button>
            </x-filament::section>
        </div>
    @endif

    <script>{!! file_get_contents(resource_path('js/gd-preview.js')) !!}</script>
</x-filament-panels::page>
