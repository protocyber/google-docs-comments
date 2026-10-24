{{--
    Toolbar above the canvas. Needs: $documentTitle, $tabCount, $canEdit,
    $canComment, $record. Parent x-data = gdPreviewPage() (mode/save/block
    state added by Task 4b in resources/js/gd-preview.js).
--}}
<div class="gd-toolbar">
    <p class="text-sm text-gray-500 mb-2">
        {{ $documentTitle }} · blok teks di dokumen untuk memilih lokasi komentar
        · <a href="https://docs.google.com/document/d/{{ $record->google_docs_id }}/edit" target="_blank" rel="noopener" class="underline">buka di Google Docs</a>
        · <button type="button" wire:click="loadDocument" class="underline">muat ulang</button>
        · <a href="https://docs.google.com/document/d/{{ $record->google_docs_id }}/edit" target="_blank" rel="noopener" class="underline" id="gd-version-history">
            Riwayat versi
        </a>
        <span class="text-gray-400" id="gd-version-history-hint">
            lalu tekan <span x-text="navigator.platform.indexOf('Mac') > -1 ? '⌘+Option+Shift+H' : 'Ctrl+Alt+Shift+H'">Ctrl+Alt+Shift+H</span> · perlu akses edit
        </span>
    </p>

    @if ($tabCount > 1)
        <p class="text-sm text-warning-600 mb-2">Dokumen ini punya {{ $tabCount }} tab; hanya tab pertama yang ditampilkan.</p>
    @endif

    <div class="gd-toolbar-row mb-2">
        @if ($canEdit)
            <div class="gd-mode-toggle" role="group" aria-label="Mode">
                <button type="button" id="gd-mode-comment"
                        x-on:click="setMode('comment')"
                        x-bind:class="{ 'is-active': mode === 'comment' }">Komentar</button>
                <button type="button" id="gd-mode-edit"
                        x-on:click="setMode('edit')"
                        x-bind:class="{ 'is-active': mode === 'edit' }">Edit</button>
            </div>

            <template x-if="mode === 'edit'">
                <div class="gd-edit-tools" x-cloak>
                    <span class="gd-save" id="gd-save-indicator" x-bind:data-state="save">
                        <span x-show="save === 'dirty'">Belum tersimpan</span>
                        <span x-show="save === 'saving'">Menyimpan…</span>
                        <span x-show="save === 'saved'">Tersimpan</span>
                        <span x-show="save === 'error'">
                            Gagal menyimpan
                            <button type="button" id="gd-retry-save" x-on:click="retrySave()">Coba lagi</button>
                        </span>
                        <span x-show="save === 'conflict'">
                            Dokumen berubah di Google Docs
                            <button type="button" id="gd-copy-block" x-on:click="copyBlock()">Salin teks</button>
                            <button type="button" id="gd-reload-conflict" x-on:click="reloadAfterConflict()">Muat ulang</button>
                        </span>
                    </span>

                    <button type="button" id="gd-delete-paragraph"
                            x-on:click="deleteParagraph()" x-bind:disabled="!block">Hapus paragraf</button>
                </div>
            </template>
        @else
            <p class="text-sm text-gray-500" id="gd-no-edit-note">Anda tidak punya akses edit ke dokumen ini.</p>
        @endif
    </div>
</div>
