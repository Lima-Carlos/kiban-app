@php
    $matrix = $this->matrix();
    $types = $matrix['types'];
    $rows = $matrix['rows'];
    $upcoming = $this->upcoming();
    $teams = $this->teamOptions();
    $dateFormat = __('app.date_format');
    $tones = ['ok' => 'success', 'soon' => 'warning', 'overdue' => 'danger', 'missing' => 'gray'];
    $cellText = function (array $cell) use ($dateFormat): string {
        $document = $cell['document'];
        if ($cell['status'] === 'none') {
            return '';
        }
        if ($document === null) {
            return __('documents.trainings.status.missing');
        }
        if ($document->expires_on === null) {
            return __('documents.trainings.cell.no_expiry');
        }
        $date = $document->expires_on->translatedFormat($dateFormat);

        return $cell['status'] === 'overdue' ? __('documents.trainings.cell.expired_on', ['date' => $date]) : __('documents.trainings.cell.until', ['date' => $date]);
    };
    $cellTitle = function (array $cell, $type) use ($dateFormat): string {
        $parts = [$type->label(), __('documents.trainings.status.'.$cell['status'])];
        if ($cell['document']?->issued_on) {
            $parts[] = __('documents.trainings.proof.taken_on').' '.$cell['document']->issued_on->translatedFormat($dateFormat);
        }
        if ($cell['document']?->trainingDetails()) {
            $parts[] = $cell['document']->trainingDetails();
        }
        if ($cell['required']) {
            $parts[] = __('documents.trainings.matrix.required');
        }

        return implode(' · ', $parts);
    };
@endphp

<x-filament-panels::page>
    <style>
        .kt-pill { display:inline-block; border-radius:8px; padding:4px 8px; font-size:12px; font-weight:600; line-height:1.3; white-space:nowrap }
        .kt-pill-success { background:color-mix(in oklab, var(--success-500) 16%, transparent); color:var(--success-700) }
        .kt-pill-warning { background:color-mix(in oklab, var(--warning-500) 22%, transparent); color:var(--warning-700) }
        .kt-pill-danger { background:color-mix(in oklab, var(--danger-500) 16%, transparent); color:var(--danger-700) }
        .kt-pill-gray { background:color-mix(in oklab, var(--gray-500) 16%, transparent); color:var(--gray-700); border:1px dashed color-mix(in oklab, var(--gray-500) 45%, transparent) }
        .dark .kt-pill-success { color:var(--success-300) } .dark .kt-pill-warning { color:var(--warning-300) }
        .dark .kt-pill-danger { color:var(--danger-300) } .dark .kt-pill-gray { color:var(--gray-300) }
        .kt-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch }
        .kt-matrix { border-collapse:separate; border-spacing:0; width:100%; font-size:14px }
        .kt-matrix th, .kt-matrix td { padding:8px 10px; border-bottom:1px solid rgba(127,127,127,.18); text-align:left; vertical-align:middle }
        .kt-matrix thead th { font-size:12px; font-weight:600; opacity:.8; white-space:nowrap }
        .kt-matrix .kt-sticky { position:sticky; left:0; background:var(--kt-bg, #fff); z-index:1; min-width:170px }
        .dark .kt-matrix .kt-sticky { --kt-bg: var(--gray-900) }
        .kt-cards { display:none }
        .kt-card { border:1px solid rgba(127,127,127,.22); border-radius:12px; padding:12px 14px }
        .kt-card + .kt-card { margin-top:10px }
        .kt-chip-row { display:flex; justify-content:space-between; gap:10px; align-items:center; padding:5px 0; border-top:1px solid rgba(127,127,127,.12); font-size:14px }
        .kt-link { font-size:13px; font-weight:600; color:var(--primary-600); text-decoration:underline; white-space:nowrap }
        .dark .kt-link { color:var(--primary-400) }
        @media (max-width: 639px) { .kt-table-wrap { display:none } .kt-cards { display:block } }
    </style>

    {{-- Upcoming renewals: one class for everyone who expires, is expired or is missing a course. --}}
    <x-filament::section icon="heroicon-o-calendar-days" data-upcoming>
        <x-slot name="heading">{{ __('documents.trainings.upcoming.heading') }}</x-slot>
        <x-slot name="description">{{ __('documents.trainings.upcoming.help') }}</x-slot>

        @if ($upcoming->isEmpty())
            <p style="opacity:.75;font-size:14px;margin:0">{{ __('documents.trainings.upcoming.empty') }}</p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:12px">
                @foreach ($upcoming as $item)
                    <div class="kt-card" style="margin:0" data-upcoming-type="{{ $item['type']->getKey() }}">
                        <div style="font-size:15px;font-weight:700">{{ $item['type']->label() }}</div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
                            @if ($item['missing']->isNotEmpty())
                                <span class="kt-pill kt-pill-gray">{{ trans_choice('documents.trainings.upcoming.missing', $item['missing']->count(), ['count' => $item['missing']->count()]) }}</span>
                            @endif
                            @if ($item['overdue']->isNotEmpty())
                                <span class="kt-pill kt-pill-danger">{{ trans_choice('documents.trainings.upcoming.overdue', $item['overdue']->count(), ['count' => $item['overdue']->count()]) }}</span>
                            @endif
                            @if ($item['due']->isNotEmpty())
                                <span class="kt-pill kt-pill-warning">{{ trans_choice('documents.trainings.upcoming.due', $item['due']->count(), ['count' => $item['due']->count()]) }}</span>
                            @endif
                        </div>
                        <ul style="margin:8px 0 0;padding:0;list-style:none;font-size:14px;line-height:1.6">
                            @foreach ($item['missing'] as $person)
                                <li>{{ $person->name }} <span style="opacity:.7">· {{ __('documents.trainings.status.missing') }}</span></li>
                            @endforeach
                            @foreach ($item['overdue']->merge($item['due']) as $document)
                                <li>{{ $document->person->name }} <span style="opacity:.7">· {{ $document->expires_on->lt(\App\People\Documents\PersonDocument::today()) ? __('documents.trainings.cell.expired_on', ['date' => $document->expires_on->translatedFormat($dateFormat)]) : __('documents.trainings.cell.until', ['date' => $document->expires_on->translatedFormat($dateFormat)]) }}</span></li>
                            @endforeach
                        </ul>
                        <div style="margin-top:10px">
                            <x-filament::button size="sm" icon="heroicon-o-academic-cap"
                                wire:click="mountAction('launchClass', {{ \Illuminate\Support\Js::from(['type' => $item['type']->getKey(), 'people' => $item['people']]) }})">
                                {{ trans_choice('documents.trainings.upcoming.launch', count($item['people']), ['count' => count($item['people'])]) }}
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- The matrix people x courses. --}}
    <x-filament::section icon="heroicon-o-table-cells" data-matrix>
        <x-slot name="heading">{{ __('documents.trainings.matrix.heading') }}</x-slot>

        @if ($teams !== [])
            <div style="max-width:320px;margin-bottom:12px">
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px" for="kt-team">{{ __('documents.trainings.matrix.team') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="kt-team" wire:model.live="team">
                        <option value="">{{ __('documents.trainings.matrix.all_teams') }}</option>
                        @foreach ($teams as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        @endif

        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
            @foreach ($tones as $status => $tone)
                <span class="kt-pill kt-pill-{{ $tone }}">{{ __('documents.trainings.status.'.$status) }}</span>
            @endforeach
        </div>

        @if ($types->isEmpty() || $rows->isEmpty())
            <div style="text-align:center;padding:24px 8px">
                <div style="font-size:15px;font-weight:700">{{ __('documents.trainings.matrix.empty') }}</div>
                <p style="opacity:.75;font-size:14px;margin:6px 0 0">{{ __('documents.trainings.matrix.empty_help') }}</p>
            </div>
        @else
            {{-- Computer and tablet: the table (scrolls sideways, the name stays). --}}
            <div class="kt-table-wrap kt-scroll">
                <table class="kt-matrix">
                    <thead>
                        <tr>
                            <th class="kt-sticky">{{ __('documents.trainings.matrix.person') }}</th>
                            @foreach ($types as $type)
                                <th>{{ $type->label() }}</th>
                            @endforeach
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-person="{{ $row['person']->getKey() }}">
                                <td class="kt-sticky">
                                    <div style="font-weight:700">{{ $row['person']->name }}</div>
                                    @if ($row['person']->team)
                                        <div style="font-size:12px;opacity:.7">{{ $row['person']->team->name }}</div>
                                    @endif
                                </td>
                                @foreach ($types as $type)
                                    @php($cell = $row['cells'][$type->getKey()])
                                    <td data-cell="{{ $cell['status'] }}">
                                        @if ($cell['status'] !== 'none')
                                            <span class="kt-pill kt-pill-{{ $tones[$cell['status']] }}" title="{{ $cellTitle($cell, $type) }}">{{ $cellText($cell) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td style="white-space:nowrap">
                                    <a class="kt-link" href="{{ $this->proofUrl($row['person']) }}">{{ __('documents.trainings.actions.proof') }}</a>
                                    <span style="opacity:.4">·</span>
                                    <a class="kt-link" href="{{ $this->documentsUrl($row['person']) }}">{{ __('documents.trainings.actions.documents') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Phone: one card per person. --}}
            <div class="kt-cards">
                @foreach ($rows as $row)
                    <div class="kt-card">
                        <div style="font-weight:700;font-size:15px">{{ $row['person']->name }}</div>
                        @if ($row['person']->team)
                            <div style="font-size:12px;opacity:.7;margin-bottom:4px">{{ $row['person']->team->name }}</div>
                        @endif
                        @foreach ($types as $type)
                            @php($cell = $row['cells'][$type->getKey()])
                            @if ($cell['status'] !== 'none')
                                <div class="kt-chip-row">
                                    <span>{{ $type->label() }}</span>
                                    <span class="kt-pill kt-pill-{{ $tones[$cell['status']] }}">{{ $cellText($cell) }}</span>
                                </div>
                            @endif
                        @endforeach
                        <div style="display:flex;gap:14px;margin-top:8px">
                            <a class="kt-link" href="{{ $this->proofUrl($row['person']) }}">{{ __('documents.trainings.actions.proof') }}</a>
                            <a class="kt-link" href="{{ $this->documentsUrl($row['person']) }}">{{ __('documents.trainings.actions.documents') }}</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

</x-filament-panels::page>
