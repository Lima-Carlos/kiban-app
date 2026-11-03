<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-flag">
        <x-slot name="heading">{{ __('timeclock.holidays.national_title', ['year' => $year]) }}</x-slot>
        <x-slot name="afterHeader">
            <div style="display:flex;gap:6px">
                <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" wire:click="previousYear" :label="__('timeclock.holidays.previous_year')" />
                <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" wire:click="nextYear" :label="__('timeclock.holidays.next_year')" />
            </div>
        </x-slot>

        @if ($this->isBrazil())
            <x-slot name="description">{{ __('timeclock.holidays.national_help') }}</x-slot>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px 18px">
                @foreach ($this->holidays() as $date => $name)
                    <div style="display:flex;gap:10px;padding:4px 0;border-bottom:1px solid rgba(127,127,127,.15)">
                        <span style="font-variant-numeric:tabular-nums;opacity:.75;min-width:78px">{{ \Carbon\CarbonImmutable::parse($date)->translatedFormat(__('timeclock.holidays.day_month_weekday_format')) }}</span>
                        <span>{{ $name }}</span>
                    </div>
                @endforeach
            </div>
            {{-- A real button (readable on the dark theme too), not a small dark-blue link at the bottom. --}}
            @if ($this->hasOptional())
            <div data-optional-holidays style="margin-top:14px;padding:12px 14px;border-radius:10px;border:1px solid rgba(127,127,127,.3);display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center">
                <span style="flex:1 1 260px;font-size:14px;line-height:1.45">{{ __('timeclock.holidays.optional_help') }}</span>
                <x-filament::button tag="a" :href="$this->settingsUrl()" icon="heroicon-m-adjustments-horizontal" size="md">{{ __('timeclock.holidays.optional_button') }}</x-filament::button>
            </div>
            @endif
        @else
            <p style="opacity:.8">{{ __('timeclock.holidays.no_national') }}</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
