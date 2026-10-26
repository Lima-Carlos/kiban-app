{{-- The person's systems as icons at the top of the menu: one tap changes the menu and the start page. --}}
@php($systems = app(\App\Access\ModuleSwitcher::class)->items())

@if ($systems !== [])
    <nav aria-label="{{ __('app.menu.systems') }}" style="display:flex;flex-wrap:wrap;gap:6px;padding:4px 4px 12px;margin-bottom:8px;border-bottom:1px solid rgba(127,127,127,.2)">
        @foreach ($systems as $system)
            <a href="{{ $system['url'] }}"
               title="{{ $system['name'] }}"
               @if ($system['current']) aria-current="page" @endif
               style="position:relative;flex:1 1 0;min-width:64px;max-width:96px;display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 4px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;{{ $system['current'] ? 'background:'.config('kiban.brand.blue').';color:#fff' : 'background:rgba(127,127,127,.12);color:inherit' }}">
                <x-filament::icon :icon="$system['icon']" style="width:24px;height:24px" />
                <span style="max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $system['name'] }}</span>
                @if ($system['attention'] > 0)
                    <span style="position:absolute;top:2px;right:4px;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#dc2626;color:#fff;font-size:11px;line-height:18px;text-align:center">{{ $system['attention'] }}</span>
                @endif
            </a>
        @endforeach
    </nav>
@endif
