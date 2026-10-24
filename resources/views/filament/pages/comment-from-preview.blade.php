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
        <div class="gd-wrap" x-data="gdPreviewPage()" data-revision="{{ $revisionId }}"
             x-on:gd-preview-reloaded.window="reloaded($event.detail)">
            <div>
                @include('filament.pages.partials.gd-toolbar', [
                    'documentTitle' => $documentTitle, 'tabCount' => $tabCount, 'canEdit' => $canEdit, 'canComment' => $canComment, 'record' => $record,
                ])
                @include('filament.pages.partials.gd-canvas', ['html' => $html])
            </div>

            <x-filament::section class="gd-side">
                <x-slot name="heading">Komentar baru</x-slot>

                <template x-if="mode === 'edit'">
                    <p class="text-sm text-gray-500" id="gd-edit-mode-note">Pindah ke mode Komentar untuk menambah komentar.</p>
                </template>

                <template x-if="mode !== 'edit'">
                    <div>
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
                    </div>
                </template>

                @include('filament.pages.partials.gd-comment-list', [
                    'threads' => $threads, 'commentsError' => $commentsError, 'documentId' => $record->google_docs_id,
                ])
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
