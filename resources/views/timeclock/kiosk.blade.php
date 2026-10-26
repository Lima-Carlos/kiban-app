<x-layout :title="__('timeclock.kiosk.title')">
    @if ($step === 'inactive')
        <h1>{{ __('timeclock.kiosk.title') }}</h1>
        <p>{{ __('timeclock.kiosk.inactive') }}</p>
    @elseif ($step === 'pin')
        {{-- Back to the start every 30 minutes (keeps the page fresh on a tablet left on). --}}
        <meta http-equiv="refresh" content="1800">
        <h1>{{ __('timeclock.kiosk.title') }}</h1>
        <p>{{ $kiosk->workplace?->name }}</p>
        <p style="font-size:30px;font-weight:bold;color:#39383A;margin:8px 0 4px" id="clock">{{ now($kiosk->workplace?->timezone)->format('H:i') }}</p>

        @isset($error)
            <div style="background:#fde2e2;color:#b42318;border-radius:10px;padding:12px 14px;margin:12px 0">{{ $error }}</div>
        @endisset

        <form method="post" action="{{ url('/relogio/pin') }}" id="pin-form">
            <label for="pin">{{ __('timeclock.kiosk.type_pin') }}</label>
            <div class="address">
                <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autofocus autocomplete="off"
                       style="font-size:32px;letter-spacing:.5em;text-align:center;font-family:monospace">
            </div>
            <div id="keypad" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:16px">
                @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                    <button type="button" data-digit="{{ $digit }}" style="margin:0;font-size:26px;padding:16px;background:#eef2f9;color:#044299">{{ $digit }}</button>
                @endforeach
                <button type="button" data-clear style="margin:0;font-size:16px;padding:16px;background:#f4f5f7;color:#39383A">{{ __('timeclock.kiosk.clear') }}</button>
                <button type="button" data-digit="0" style="margin:0;font-size:26px;padding:16px;background:#eef2f9;color:#044299">0</button>
                <button type="submit" style="margin:0;font-size:16px;padding:16px">{{ __('timeclock.kiosk.ok') }}</button>
            </div>
        </form>
        <script>
            (function () {
                var input = document.getElementById('pin');
                document.querySelectorAll('[data-digit]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        if (input.value.length < 6) { input.value += button.dataset.digit; }
                        if (input.value.length === 6) { document.getElementById('pin-form').submit(); }
                    });
                });
                document.querySelector('[data-clear]').addEventListener('click', function () { input.value = ''; input.focus(); });
                setInterval(function () {
                    var now = new Date();
                    document.getElementById('clock').textContent = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
                }, 15000);
            })();
        </script>
    @elseif ($step === 'notice')
        <h1>{{ __('timeclock.kiosk.hello', ['name' => $employee->person->name]) }}</h1>
        <h2 style="font-size:18px;margin:8px 0">{{ __('timeclock.privacy.title') }}</h2>
        @foreach ($paragraphs as $paragraph)
            <p style="text-align:left">{{ $paragraph }}</p>
        @endforeach

        <form method="post" action="{{ url('/relogio/ciente') }}">
            <input type="hidden" name="ticket" value="{{ $ticket }}">
            <button type="submit" style="font-size:18px;padding:16px">{{ __('timeclock.privacy.acknowledge') }}</button>
        </form>
        @if ($withoutPhoto)
            <form method="post" action="{{ url('/relogio/bater') }}">
                <input type="hidden" name="ticket" value="{{ $ticket }}">
                <input type="hidden" name="without_photo" value="1">
                <button type="submit" class="secondary" style="background:#f4f5f7;color:#39383A">{{ __('timeclock.privacy.without_photo') }}</button>
            </form>
            <p class="muted">{{ __('timeclock.privacy.without_photo_help') }}</p>
        @endif
        <a href="{{ url('/relogio') }}" class="button secondary">{{ __('timeclock.kiosk.cancel') }}</a>
        <script>
            setTimeout(function () { window.location.href = @js(url('/relogio')); }, {{ \App\Http\Controllers\TimeClock\KioskController::TICKET_MINUTES * 60 * 1000 }});
        </script>
    @elseif ($step === 'photo')
        <h1>{{ __('timeclock.kiosk.hello', ['name' => $employee->person->name]) }}</h1>
        <p>{{ __('timeclock.kiosk.photo_help') }}</p>

        <form method="post" action="{{ url('/relogio/bater') }}" id="punch-form">
            <input type="hidden" name="ticket" value="{{ $ticket }}">
            <input type="hidden" name="photo" id="photo">
            <video id="camera" autoplay playsinline muted style="width:100%;border-radius:10px;background:#000;display:none"></video>
            <canvas id="canvas" style="display:none"></canvas>
            <p id="no-camera" style="display:none">{{ __('timeclock.kiosk.no_camera') }}</p>
            <button type="submit" id="punch-button" style="font-size:20px;padding:18px">{{ __('timeclock.kiosk.punch') }}</button>
            <a href="{{ url('/relogio') }}" class="button secondary">{{ __('timeclock.kiosk.cancel') }}</a>
        </form>
        @if ($withoutPhoto)
            <form method="post" action="{{ url('/relogio/bater') }}">
                <input type="hidden" name="ticket" value="{{ $ticket }}">
                <input type="hidden" name="without_photo" value="1">
                <button type="submit" style="background:#f4f5f7;color:#39383A;font-size:14px">{{ __('timeclock.privacy.without_photo') }}</button>
            </form>
        @endif
        <script>
            (function () {
                var video = document.getElementById('camera');
                var form = document.getElementById('punch-form');
                var ready = false;

                if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 } }, audio: false })
                        .then(function (stream) { video.srcObject = stream; video.style.display = 'block'; ready = true; })
                        .catch(function () { document.getElementById('no-camera').style.display = 'block'; });
                } else {
                    document.getElementById('no-camera').style.display = 'block';
                }

                form.addEventListener('submit', function () {
                    document.getElementById('punch-button').disabled = true;
                    if (!ready || !video.videoWidth) { return; }
                    var canvas = document.getElementById('canvas');
                    var width = Math.min(640, video.videoWidth);
                    canvas.width = width;
                    canvas.height = Math.round(video.videoHeight * width / video.videoWidth);
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                    document.getElementById('photo').value = canvas.toDataURL('image/jpeg', 0.8);
                });

                // Nobody punched: back to the start.
                setTimeout(function () { window.location.href = @js(url('/relogio')); }, {{ \App\Http\Controllers\TimeClock\KioskController::TICKET_MINUTES * 60 * 1000 }});
            })();
        </script>
    @else
        <meta http-equiv="refresh" content="20;url={{ url('/relogio') }}">
        <div style="background:#dcfce7;color:#166534;border-radius:10px;padding:14px 16px;margin:0 0 14px">
            <div style="font-size:20px;font-weight:bold">✓ {{ __('timeclock.kiosk.done', ['time' => $punch->localTime()->format('H:i')]) }}</div>
            <div>{{ $employee->person->name }}</div>
        </div>
        <p>{{ __('timeclock.receipt.nsr') }}: <strong>{{ $punch->nsr }}</strong> · {{ $punch->localTime()->translatedFormat(__('app.date_format')) }}</p>
        <p>{{ __('timeclock.receipt.code') }}: <strong style="font-family:monospace">{{ $code }}</strong></p>
        <div style="display:flex;justify-content:center;margin:10px 0">{!! $qr !!}</div>
        <p class="muted">{{ __('timeclock.kiosk.qr_help') }}</p>
        <a href="{{ url('/relogio') }}" class="button">{{ __('timeclock.kiosk.next') }}</a>
    @endif
</x-layout>
