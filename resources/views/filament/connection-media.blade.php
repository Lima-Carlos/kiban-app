{{--
    The picture of a connection card. With a clip recorded (public/videos/conexoes/<key>-<lang>.mp4, optional cover
    <key>-<lang>.jpg): the cover with the seal of the pair of systems and a play button; the video opens in a big
    window in the middle of the screen (with sound, not looping), with a link to the training video on YouTube when
    there is one (config kiban.connection_youtube). Otherwise, the icon.
--}}
@if ($video = $connection->video())
    @php($seal = isset($catalog, $enabled) ? $catalog->systemsLabel($connection, $enabled) : null)
    @php($youtube = $connection->youtubeUrl())
    <div x-data="{ open: false }" style="flex:1 1 100%;width:100%">
        <button type="button" data-connection-play x-on:click="open = true" aria-label="{{ __('connections.video.play', ['title' => $connection->title()]) }}"
                style="position:relative;display:block;width:100%;height:150px;padding:0;border:0;border-radius:8px;overflow:hidden;cursor:pointer;background:linear-gradient(135deg,#044299,#39383A) @if ($video['poster']) url('{{ $video['poster'] }}') center/cover @endif">
            @if ($seal)
                <span style="position:absolute;left:8px;bottom:8px;max-width:calc(100% - 16px);padding:3px 10px;border-radius:999px;background:rgba(15,23,42,.78);color:#fff;font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $seal }}</span>
            @endif
            <span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
                <span style="width:52px;height:52px;border-radius:999px;background:rgba(255,255,255,.92);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(0,0,0,.3)">
                    <x-filament::icon icon="heroicon-s-play" style="width:26px;height:26px;color:#044299;margin-left:3px" />
                </span>
            </span>
        </button>

        <template x-teleport="body">
            {{-- x-show only toggles the outer layer; the centring lives in the inner one (x-show would drop "display:flex"). --}}
            <div x-show="open" x-cloak x-on:keydown.escape.window="open = false" x-effect="if (! open && $refs.clip) { $refs.clip.pause() }"
                 style="position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.8)" role="dialog" aria-modal="true">
                <div data-connection-dialog x-on:click.self="open = false"
                     style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box">
                <div style="width:100%;max-width:880px;max-height:100%;display:flex;flex-direction:column;gap:10px">
                    <div style="display:flex;align-items:center;gap:10px;color:#fff">
                        <div style="flex:1;min-width:0;font-weight:700;font-size:16px">{{ $connection->title() }}</div>
                        <button type="button" x-on:click="open = false" aria-label="{{ __('connections.video.close') }}"
                                style="border:0;background:rgba(255,255,255,.15);color:#fff;width:34px;height:34px;border-radius:999px;font-size:20px;line-height:1;cursor:pointer">×</button>
                    </div>
                    <video data-connection-video x-ref="clip" src="{{ $video['src'] }}" @if ($video['poster']) poster="{{ $video['poster'] }}" @endif
                           controls playsinline preload="none"
                           style="width:100%;max-height:70vh;border-radius:10px;background:#000"></video>
                    @if ($youtube)
                        <a href="{{ $youtube }}" target="_blank" rel="noopener" data-connection-youtube
                           style="align-self:flex-start;color:#fff;font-weight:600;text-decoration:underline">{{ __('connections.video.youtube') }} →</a>
                    @endif
                </div>
                </div>
            </div>
        </template>
    </div>
@else
    <x-filament::icon :icon="$connection->icon" data-connections-icon style="width:22px;height:22px;flex:none;color:{{ $color ?? '#044299' }};margin-top:2px" />
@endif
