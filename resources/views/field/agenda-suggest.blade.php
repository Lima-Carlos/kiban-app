{{-- The suggested order of a technician's day (Agenda → "Suggest order"), shown before confirming. --}}
@if ($plan === [])
    <p style="font-size:14px;opacity:.75;margin:0">{{ __('field.agenda.suggest_nothing') }}</p>
@else
    <ol style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px">
        @foreach ($plan as $index => $step)
            @php($changed = ! $step['order']->scheduled_at->eq($step['at']))
            <li style="display:flex;gap:10px;align-items:flex-start;font-size:14px">
                <span style="flex:none;width:24px;height:24px;border-radius:999px;background:#044299;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center">{{ $index + 1 }}</span>
                <div style="min-width:0">
                    <div>
                        <strong>{{ $step['at']->setTimezone($timezone)->format('H:i') }}</strong>
                        @if ($changed)
                            <span style="font-size:12px;opacity:.65;text-decoration:line-through">{{ $step['order']->scheduled_at->setTimezone($timezone)->format('H:i') }}</span>
                        @endif
                        · {{ $step['order']->customer->displayName() }}
                    </div>
                    <div style="font-size:12px;opacity:.75">
                        {{ $step['order']->label() }}
                        @if ($step['travel'])
                            · {{ __('field.agenda.suggest_travel', ['minutes' => $step['travel']]) }}
                        @endif
                        @if (! $step['order']->site?->hasPoint())
                            · <span style="color:#b45309">{{ __('field.agenda.suggest_no_point') }}</span>
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ol>
@endif
