{{--
    The picture of a connection card: the short looping clip when it was recorded
    (public/videos/conexoes/<key>-<lang>.mp4, optional cover <key>-<lang>.jpg), otherwise the icon.
    Muted, autoplay, loop and playsinline (plays inside the page on the phone, no sound).
--}}
@if ($video = $connection->video())
    <video data-connection-video src="{{ $video['src'] }}" @if ($video['poster']) poster="{{ $video['poster'] }}" @endif
           muted autoplay loop playsinline preload="metadata" aria-hidden="true"
           style="flex:1 1 100%;width:100%;max-height:200px;border-radius:8px;background:#0f172a;object-fit:cover"></video>
@else
    <x-filament::icon :icon="$connection->icon" data-connections-icon style="width:22px;height:22px;flex:none;color:{{ $color ?? '#044299' }};margin-top:2px" />
@endif
