@php
    $tenant = app(\App\Tenancy\TenantContext::class)->current();
    $notices = $tenant ? app(\App\Licensing\LicenseNotices::class)->for($tenant) : [];
@endphp

@foreach ($notices as $notice)
    <div style="margin:16px 0;padding:12px 16px;border-radius:10px;font-size:14px;line-height:1.5;{{ $notice['level'] === 'danger' ? 'background:#fde2e2;color:#b42318' : 'background:#fff7ed;color:#9a3412' }}">
        {{ $notice['text'] }}
    </div>
@endforeach
