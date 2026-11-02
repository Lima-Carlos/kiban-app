{{--
    "A new part appears right away": when the person comes back to this tab after being away a while (e.g. in the
    Customer area of the Core, contracting or testing another part), ask for a fresh license; if the systems changed,
    reload. Quiet on any failure (the license is renewed every few minutes anyway).
--}}
@if (app(\App\Tenancy\TenantContext::class)->current())
    <script>
        (() => {
            let hiddenAt = null;
            document.addEventListener('visibilitychange', async () => {
                if (document.visibilityState === 'hidden') { hiddenAt = Date.now(); return; }
                if (hiddenAt === null || Date.now() - hiddenAt < 8000) { return; }
                hiddenAt = null;
                try {
                    const response = await fetch(@js(route('license.sync')), {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'application/json' },
                    });
                    if (response.ok && (await response.json()).changed) { window.location.reload(); }
                } catch (error) { /* offline: nothing to do */ }
            });
        })();
    </script>
@endif
