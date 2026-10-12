{{--
    "Install the app" hint, only on phones and only while not installed. Android/Chrome: a button that opens the
    install prompt. iPhone: how to do it in Safari. The person can close it (remembered on this phone).
--}}
<div x-data="{
        show: false,
        ios: /iphone|ipad|ipod/i.test(navigator.userAgent),
        prompt: null,
        init() {
            let closed = false;
            try { closed = localStorage.getItem('kiban.install-hint') === 'closed'; } catch (e) {}
            const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
            const phone = window.matchMedia('(max-width: 768px)').matches;
            this.show = phone && ! standalone && ! closed;
            window.addEventListener('beforeinstallprompt', (event) => { event.preventDefault(); this.prompt = event; });
        },
        install() { if (this.prompt) { this.prompt.prompt(); this.prompt = null; this.close(); } },
        close() { this.show = false; try { localStorage.setItem('kiban.install-hint', 'closed'); } catch (e) {} },
    }"
    x-show="show"
    x-cloak
    style="margin:0 0 16px;padding:12px 14px;border-radius:12px;background:rgba(4,66,153,.12);display:flex;gap:12px;align-items:center">
    <img src="{{ asset('images/brand/icon-192.png') }}" alt="" style="width:40px;height:40px;border-radius:10px;flex:none">
    <div style="flex:1;font-size:14px;line-height:1.4">
        <strong>{{ __('app.install.title') }}</strong><br>
        <span x-show="ios">{{ __('app.install.ios') }}</span>
        <span x-show="! ios && ! prompt">{{ __('app.install.android') }}</span>
    </div>
    <x-filament::button size="sm" x-show="prompt" x-on:click="install()">{{ __('app.install.button') }}</x-filament::button>
    <button type="button" x-on:click="close()" aria-label="{{ __('app.install.close') }}" style="background:none;border:0;font-size:20px;line-height:1;cursor:pointer;opacity:.6">×</button>
</div>
