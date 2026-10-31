{{--
    "Install the app" hint, only on phones and only while not installed: one line. Android/Chrome: a button that
    opens the install prompt. Otherwise "How?" opens the steps (iPhone: Safari). Closing it is remembered on this phone.
--}}
<div x-data="{
        show: false,
        help: false,
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
    data-install-hint
    style="margin:0 0 12px;padding:6px 8px 6px 10px;border-radius:10px;background:rgba(4,66,153,.08);font-size:13px;line-height:1.3">
    <div style="display:flex;gap:8px;align-items:center">
        <img src="{{ asset('images/brand/icon-192.png') }}" alt="" style="width:22px;height:22px;border-radius:6px;flex:none">
        <strong style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ __('app.install.title') }}</strong>
        <x-filament::button size="xs" x-show="prompt" x-on:click="install()">{{ __('app.install.button') }}</x-filament::button>
        <button type="button" x-show="! prompt" x-on:click="help = ! help" style="background:none;border:0;padding:2px 4px;font-size:13px;font-weight:600;text-decoration:underline;cursor:pointer;color:inherit;flex:none">{{ __('app.install.how') }}</button>
        <button type="button" x-on:click="close()" aria-label="{{ __('app.install.close') }}" style="background:none;border:0;padding:0 4px;font-size:20px;line-height:1;cursor:pointer;opacity:.6;flex:none">×</button>
    </div>
    <div x-show="help" x-cloak style="margin-top:6px">
        <span x-show="ios">{{ __('app.install.ios') }}</span>
        <span x-show="! ios">{{ __('app.install.android') }}</span>
    </div>
</div>
