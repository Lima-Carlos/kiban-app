{{--
    "News: your systems now work together" (ConnectionCatalog): the connections between the company's systems that
    matter to this person and that they did not dismiss. "Got it" hides it until tomorrow; with "Do not show again"
    the connections shown never come back for this person in this company (a new connection still shows up).
    The phone also remembers it (a page kept for use without signal would bring the window back otherwise).
--}}
@php
    $tenant = app(\App\Tenancy\TenantContext::class)->current();
    $user = auth()->user();
    $catalog = app(\App\Connections\ConnectionCatalog::class);
    $pending = $tenant && $user && $tenant->license()->isActive() && ! request()->routeIs(\App\Filament\Pages\ConnectionsPage::getRouteName())
        ? $catalog->forWindow($user)
        : [];
@endphp

@if ($pending !== [])
    @php
        $enabled = $catalog->enabled();
        $names = collect(app(\App\Modules\ModuleRegistry::class)->enabledFor($tenant))->filter(fn ($module) => in_array($module->key(), $enabled, true))->map->name()->values()->all();
        $systems = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' '.__('connections.and').' '.end($names) : implode('', $names);
    @endphp
    <div
        x-data="{
            open: false,
            forever: false,
            keys: @js(array_keys($pending)),
            store: @js('kiban.connections.'.$tenant->getKey().'.'.$user->getKey()),
            url: @js(route('connections.dismiss')),
            token: @js(csrf_token()),
            today() { const d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); },
            read() { try { return JSON.parse(localStorage.getItem(this.store) || '{}'); } catch (e) { return {}; } },
            init() {
                const saved = this.read();
                const seen = saved.forever || [];
                this.open = saved.day !== this.today() && ! this.keys.every((key) => seen.includes(key));
            },
            close(go = null) {
                const saved = this.read();
                saved.day = this.today();
                if (this.forever) { saved.forever = [...new Set([...(saved.forever || []), ...this.keys])]; }
                try { localStorage.setItem(this.store, JSON.stringify(saved)); } catch (e) {}
                this.open = false;
                const sent = fetch(this.url, {
                    method: 'POST', credentials: 'same-origin', keepalive: true,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.token, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ keys: this.keys, forever: this.forever }),
                }).catch(() => null);
                if (go) { sent.finally(() => { window.location.href = go; }); }
            },
        }"
        x-show="open"
        x-cloak
        x-on:keydown.escape.window="if (open) close()"
        data-connections-modal
        role="dialog"
        aria-modal="true"
        aria-labelledby="connections-title"
        style="position:fixed;inset:0;z-index:60;background:rgba(17,24,39,.55)"
    >
        {{-- Centered card: the list scrolls, the title and the buttons always stay in sight. --}}
        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:16px">
        <div data-connections-card x-on:click.outside="close()"
             style="width:100%;max-width:580px;max-height:100%;overflow:hidden;border-radius:16px;background:#fff;color:#1f2937;box-shadow:0 20px 50px rgba(0,0,0,.25);display:flex;flex-direction:column">
            <div data-connections-head style="padding:20px 20px 10px;display:flex;gap:12px;align-items:flex-start;flex:none">
                <div style="flex:none;width:40px;height:40px;border-radius:12px;background:rgba(4,66,153,.1);color:#044299;display:flex;align-items:center;justify-content:center">
                    <x-filament::icon icon="heroicon-o-sparkles" style="width:22px;height:22px" />
                </div>
                <div style="min-width:0">
                    <h2 id="connections-title" style="font-size:18px;font-weight:700;line-height:1.3;margin:0">{{ __('connections.window.title') }}</h2>
                    <p style="font-size:14px;opacity:.75;margin:4px 0 0">{{ __('connections.window.intro', ['systems' => $systems]) }}</p>
                </div>
            </div>

            <div data-connections-list style="padding:4px 20px 12px;display:flex;flex-direction:column;gap:10px;overflow-y:auto;flex:1 1 auto;min-height:0">
                @foreach ($pending as $connection)
                    <div data-connection="{{ $connection->key }}" style="border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:12px 14px;display:flex;gap:12px;flex:none">
                        <x-filament::icon :icon="$connection->icon" data-connections-icon style="width:22px;height:22px;flex:none;color:#044299;margin-top:2px" />
                        <div style="min-width:0;flex:1">
                            <div style="font-size:11px;font-weight:600;letter-spacing:.03em;text-transform:uppercase;color:#044299">{{ $catalog->systemsLabel($connection, $enabled) }}</div>
                            <div style="font-size:15px;font-weight:700;margin-top:2px">{{ $connection->title() }}</div>
                            <p style="font-size:14px;margin:4px 0 0;line-height:1.45">{{ $connection->body() }}</p>
                            @if ($where = $connection->urlFor($user))
                                <a href="{{ $where }}" x-on:click.prevent="close(@js($where))" style="display:inline-block;margin-top:6px;font-size:14px;font-weight:600;color:#044299;text-decoration:underline">{{ __('connections.window.see_where') }} →</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div style="padding:12px 20px 16px;display:flex;flex-wrap:wrap;gap:10px 12px;align-items:center;justify-content:space-between;border-top:1px solid rgba(127,127,127,.2);flex:none">
                <label style="display:flex;gap:8px;align-items:center;font-size:14px;cursor:pointer">
                    <input type="checkbox" x-model="forever" data-connections-forever style="width:18px;height:18px">
                    {{ __('connections.window.forever') }}
                </label>
                <div style="display:flex;gap:10px;align-items:center;margin-left:auto">
                    @if (\App\Filament\Pages\ConnectionsPage::canAccess())
                        <a href="{{ \App\Filament\Pages\ConnectionsPage::getUrl() }}" x-on:click.prevent="close(@js(\App\Filament\Pages\ConnectionsPage::getUrl()))" style="font-size:14px;color:#044299;text-decoration:underline">{{ __('connections.window.see_all') }}</a>
                    @endif
                    <x-filament::button x-on:click="close()" data-connections-ok>{{ __('connections.window.ok') }}</x-filament::button>
                </div>
            </div>
        </div>
        </div>
    </div>
    <style>
        @media (max-width: 640px) {
            [data-connections-head] { padding: 16px 16px 8px !important; }
            [data-connections-head] h2 { font-size: 16px !important; }
            [data-connections-list] { padding: 4px 16px 10px !important; }
            [data-connections-list] [data-connection] { padding: 10px 12px !important; }
            [data-connections-list] [data-connection] > svg, [data-connections-list] [data-connection] > [data-connections-icon] { display: none; }
        }
        .dark [data-connections-card] { background: #18181b !important; color: #f4f4f5 !important; }
        .dark [data-connections-card] a, .dark [data-connections-card] [style*="color:#044299"] { color: #93b4ea !important; }
    </style>
@endif
