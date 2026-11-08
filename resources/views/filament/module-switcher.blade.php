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
                <span class="kb-system-name" style="max-width:100%;font-size:11px;line-height:1.2;text-align:center;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ $system['name'] }}</span>
                @if ($system['attention'] > 0)
                    <span style="position:absolute;top:2px;right:4px;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#dc2626;color:#fff;font-size:11px;line-height:18px;text-align:center">{{ $system['attention'] }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    {{-- Filament scrolls the menu to center the current item, which hides these icons even when the item fits
         without scrolling: then the menu goes back to the top. --}}
    <script>
        // A long word ("Mantenimiento", "Appointments") gets a smaller letter instead of being cut in the middle;
        // only when even the smallest one does not fit, the word breaks.
        const kbFitSystemNames = () => document.querySelectorAll('.kb-system-name').forEach((name) => {
            let size = 11;
            name.style.overflowWrap = 'normal';
            name.style.fontSize = size + 'px';
            while (name.scrollWidth > name.clientWidth && size > 8) {
                size -= 0.5;
                name.style.fontSize = size + 'px';
            }
            if (name.scrollWidth > name.clientWidth) name.style.overflowWrap = 'anywhere';
        });
        document.addEventListener('DOMContentLoaded', kbFitSystemNames);
        document.addEventListener('livewire:navigated', kbFitSystemNames);

        document.addEventListener('DOMContentLoaded', () => setTimeout(() => {
            const nav = document.querySelector('.fi-main-sidebar .fi-sidebar-nav');
            const item = nav?.querySelector('.fi-sidebar-item.fi-active');
            if (nav && item && item.offsetTop + item.offsetHeight <= nav.clientHeight) nav.scrollTo(0, 0);
        }, 30));
    </script>
@endif
