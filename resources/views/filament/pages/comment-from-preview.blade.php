<x-filament-panels::page>
    <style>{!! file_get_contents(resource_path('css/gd-preview.css')) !!}</style>
    @foreach ($fontUrls as $url)
        <link rel="stylesheet" href="{{ $url }}">
    @endforeach
    {{-- Before Alpine starts, so x-data="gdPreviewPage()" resolves. --}}
    <script>{!! file_get_contents(resource_path('js/gd-preview.js')) !!}</script>

    @if ($loadError)
        <x-filament::section>
            <p class="text-danger-600">Gagal membaca dokumen: {{ $loadError }}</p>
            <x-filament::button wire:click="loadDocument" class="mt-3">Coba lagi</x-filament::button>
        </x-filament::section>
    @else
        <div class="gd-wrap" x-data="gdPreviewPage()"
             x-on:gd-preview-reloaded.window="reloaded($event.detail)">
            <div>
                <p class="text-sm text-gray-500 mb-2">
                    {{ $documentTitle }} · blok teks di dokumen untuk memilih lokasi komentar
                    · <a href="https://docs.google.com/document/d/{{ $record->google_docs_id }}/edit" target="_blank" rel="noopener" class="underline">buka di Google Docs</a>
                    · <button type="button" wire:click="loadDocument" class="underline">muat ulang</button>
                </p>
                @if ($tabCount > 1)
                    <p class="text-sm text-warning-600 mb-2">Dokumen ini punya {{ $tabCount }} tab; hanya tab pertama yang ditampilkan.</p>
                @endif
                @include('filament.pages.partials.gd-canvas', ['html' => $html])
            </div>

            <x-filament::section class="gd-side">
                <x-slot name="heading">Komentar baru</x-slot>

                <template x-if="!range">
                    <p class="text-sm text-gray-500">Belum ada teks yang dipilih.</p>
                </template>
                <template x-if="range">
                    <div class="text-sm mb-3">
                        <p class="text-gray-500">Teks terpilih <span class="font-mono" x-text="'[' + range.start + '–' + range.end + ']'"></span></p>
                        <blockquote class="border-l-4 border-primary-500 pl-2 mt-1 italic" x-text="range.text.length > 200 ? range.text.slice(0, 200) + '…' : range.text"></blockquote>
                    </div>
                </template>

                <textarea x-model="comment" rows="4" placeholder="Tulis komentar…" id="gd-comment" aria-label="Komentar baru"
                          class="block w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-600 text-sm"></textarea>

                <x-filament::button class="mt-3 w-full" id="gd-submit"
                    x-on:click="submit()" x-bind:disabled="busy || !range || !comment.trim()">
                    <span x-show="!busy">Tambah komentar</span><span x-show="busy">Menyimpan…</span>
                </x-filament::button>

                @include('filament.pages.partials.gd-comment-list', [
                    'threads' => $threads, 'commentsError' => $commentsError, 'documentId' => $record->google_docs_id,
                ])
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
