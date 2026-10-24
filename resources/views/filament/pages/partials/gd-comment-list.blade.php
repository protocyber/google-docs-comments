{{-- Comment list. Needs: $threads, $commentsError, $documentId, $canComment. --}}
@php
    $open = collect($threads)->where('status', 'open')->count();
    $resolved = collect($threads)->where('status', 'resolved')->count();
    $labels = ['other_tab' => 'di tab lain', 'deleted' => 'teks asli sudah dihapus', 'unknown' => 'lokasi tidak diketahui'];
    $when = function (?string $t) {
        if (! $t) return [null, null];
        $c = \Illuminate\Support\Carbon::parse($t)->setTimezone(config('app.timezone'))->locale('id');
        return [$c->diffForHumans(), $c->translatedFormat('j F Y H:i')];
    };
@endphp

<div class="gd-comments-head">
    <span class="font-semibold">Komentar ({{ $open }})</span>
    @if ($resolved)
        <label class="flex items-center gap-1 text-xs">
            <input type="checkbox" x-model="showResolved" id="gd-show-resolved">
            Tampilkan yang selesai ({{ $resolved }})
        </label>
    @endif
</div>

@if ($commentsError)
    <p class="text-sm text-warning-600">{{ $commentsError }}</p>
@elseif (empty($threads))
    <p class="text-sm text-gray-500">Belum ada komentar.</p>
@endif

<div class="gd-comments" id="gd-comments">
    @foreach ($threads as $t)
        @php
            $located = $t['location'] === 'here';
            [$rel, $abs] = $when($t['created']);
            $replies = $t['replies'];
            $hidden = count($replies) > 3 ? count($replies) - 2 : 0;
        @endphp
        <div class="gd-card {{ $t['status'] === 'resolved' ? 'is-resolved' : '' }}"
             wire:key="gd-card-{{ $t['id'] }}"
             data-comment-id="{{ $t['id'] }}"
             data-status="{{ $t['status'] }}"
             data-located="{{ $located ? '1' : '0' }}"
             data-start="{{ $t['start'] ?? '' }}"
             data-len="{{ $located ? $t['end'] - $t['start'] : '' }}"
             @if ($t['status'] === 'resolved') x-show="showResolved" x-cloak @endif
             x-bind:class="{ 'is-active': active === @js($t['id']) }">
            <a class="gd-open" href="https://docs.google.com/document/d/{{ $documentId }}/edit?disco={{ urlencode($t['id']) }}"
               target="_blank" rel="noopener" title="Buka di Google Docs" aria-label="Buka di Google Docs">↗</a>
            <button type="button" class="gd-card-main"
                    x-on:click="clickCard(@js($t['id']))"
                    x-bind:aria-pressed="active === @js($t['id']) ? 'true' : 'false'"
                    @unless ($located) aria-disabled="true" @endunless>
                <span class="gd-card-meta">
                    <span class="gd-card-author">{{ $t['author'] }}</span>
                    @if ($rel)<span title="{{ $abs }}">{{ $rel }}</span>@endif
                    @if ($t['status'] === 'resolved')<span class="gd-badge">Selesai</span>@endif
                    @unless ($located)<span class="gd-badge">{{ $labels[$t['location']] }}</span>@endunless
                </span>
                @if ($t['quote'] !== '')<span class="gd-card-quote">{{ $t['quote'] }}</span>@endif
                <span class="gd-card-body">{{ $t['content'] }}</span>
            </button>
            @if ($canComment && $t['status'] !== 'resolved')
                <div class="gd-resolve" x-data="{ resolving: false, reply: '' }" x-on:click.stop>
                    <button type="button" class="gd-resolve-btn" x-show="!resolving" x-on:click="resolving = true">Resolve</button>
                    <div x-show="resolving" x-cloak class="gd-resolve-form">
                        <textarea x-model="reply" rows="2" placeholder="Sudah diperbaiki" aria-label="Balasan"
                                  class="block w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-600 text-xs"></textarea>
                        <div class="gd-resolve-actions">
                            <button type="button" class="gd-resolve-confirm"
                                    x-on:click="resolve(@js($t['id']), reply); resolving = false; reply = ''">Tandai selesai</button>
                            <button type="button" class="gd-resolve-cancel" x-on:click="resolving = false; reply = ''">Batal</button>
                        </div>
                    </div>
                </div>
            @endif
            @if ($replies)
                <div class="gd-replies" x-data="{ all: false }">
                    @if ($hidden)
                        <button type="button" class="gd-more" x-show="!all" x-on:click="all = true">Lihat {{ $hidden }} balasan lain</button>
                    @endif
                    @foreach ($replies as $i => $r)
                        @php [$rrel, $rabs] = $when($r['created']); @endphp
                        <div class="gd-reply" @if ($i < $hidden) x-show="all" x-cloak @endif>
                            <span class="gd-card-meta"><span class="gd-card-author">{{ $r['author'] }}</span>
                                @if ($rrel)<span title="{{ $rabs }}">{{ $rrel }}</span>@endif</span>
                            <div class="gd-card-body">{{ $r['content'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
</div>
