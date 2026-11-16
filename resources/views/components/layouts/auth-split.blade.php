@props(['title' => null])
<x-layouts.base :title="$title">
    <main class="auth-split">
        <section class="auth-hero" aria-label="KBT RUVIA BioTech">
            <div>
                <x-logo light size="lg" stacked />
            </div>
            <p class="auth-hero__sub">Biomedical Maintenance &amp; Installations Management System</p>
            <p class="auth-hero__tag">Reliable Equipment.<br>Safer Care.</p>
            <ul class="auth-features" style="list-style:none;padding:0;margin-top:auto">
                @foreach([['box', 'Asset Management'], ['calendar-check', 'Preventive Maintenance'], ['clipboard', 'Work Orders'], ['shield', 'Calibration & Compliance']] as [$icon, $label])
                    <li class="auth-feature"><span class="ico"><x-icon :name="$icon" /></span>{{ $label }}</li>
                @endforeach
            </ul>
        </section>
        <section class="auth-panel">
            <div class="auth-card" style="max-width:420px">
                {{ $slot }}
                <p class="auth-foot" style="margin-top:22px">&copy; {{ date('Y') }} KBT RUVIA BioTech. All rights reserved.</p>
            </div>
        </section>
    </main>
</x-layouts.base>
