<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('documents.trainings.proof.title') }} · {{ $person->name }}</title>
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        @page { margin: 26px 30px; }
        body { font-size: 10px; color: #39383A; margin: 0; }
        .top { background: {{ $color }}; color: #fff; padding: 12px 16px; }
        .top td { color: #fff; vertical-align: middle; }
        h1 { font-size: 16px; margin: 2px 0 0; }
        h2 { font-size: 11px; color: {{ $color }}; margin: 16px 0 6px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
        .label { color: #777; font-size: 8px; }
        table.info { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.info td { padding: 3px 8px 3px 0; vertical-align: top; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #f1f3f6; text-align: left; font-size: 8px; text-transform: uppercase; color: #555; padding: 5px 6px; border: 1px solid #d6d9de; }
        table.grid td { padding: 5px 6px; border: 1px solid #d6d9de; vertical-align: top; }
        .muted { color: #777; }
        .footer { margin-top: 16px; font-size: 8px; color: #666; border-top: 1px solid #ddd; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="top">
        <table style="width:100%"><tr>
            <td>
                <div style="font-size:9px">{{ $company }}</div>
                <h1>{{ __('documents.trainings.proof.title') }}</h1>
            </td>
            <td style="text-align:right">
                @if ($logo)<img src="{{ $logo }}" style="max-height:42px;max-width:160px;background:#fff;padding:4px">@endif
            </td>
        </tr></table>
    </div>

    <table class="info">
        <tr>
            <td style="width:40%"><div class="label">{{ __('documents.trainings.proof.company') }}</div>{{ $company }}@if ($profile->document) · {{ $profile->document }}@endif</td>
            <td style="width:35%"><div class="label">{{ __('documents.fields.person') }}</div><strong>{{ $person->name }}</strong></td>
            <td style="width:25%"><div class="label">{{ __('app.teams.team') }}</div>{{ $person->team?->name ?? '—' }}</td>
        </tr>
    </table>

    <h2>{{ __('documents.trainings.proof.courses') }}</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('documents.trainings.fields.course') }}</th>
                <th style="width:72px">{{ __('documents.trainings.proof.taken_on') }}</th>
                <th style="width:72px">{{ __('documents.trainings.proof.valid_until') }}</th>
                <th>{{ __('documents.trainings.fields.provider') }}</th>
                <th style="width:52px">{{ __('documents.trainings.fields.workload_short') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($courses as $course)
                <tr>
                    <td>
                        <strong>{{ $course->type->label() }}</strong>
                        @if ($course->training_kind || $course->number)
                            <div class="muted" style="font-size:8px">{{ collect([$course->training_kind?->getLabel(), $course->number])->filter()->implode(' · ') }}</div>
                        @endif
                    </td>
                    <td>{{ $course->issued_on?->translatedFormat(__('app.date_format')) ?? '—' }}</td>
                    <td>{{ $course->expires_on?->translatedFormat(__('app.date_format')) ?? __('documents.status.no_expiry') }}</td>
                    <td>{{ $course->provider ?: '—' }}</td>
                    <td>{{ $course->workload_hours ? __('documents.trainings.hours', ['hours' => $course->workload_hours]) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">{{ __('documents.trainings.proof.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        {{ __('documents.trainings.proof.note', ['date' => $generated->translatedFormat(__('app.datetime_format'))]) }}
        <br><strong>{{ $company }}</strong>@if ($profile->document) · {{ $profile->document }}@endif @if ($profile->phone) · {{ $profile->phone }}@endif @if ($profile->email) · {{ $profile->email }}@endif
        @if ($profile->address)<br>{{ $profile->address }}@endif
    </div>
</body>
</html>
