@props(['title' => null])
<x-layouts.base :title="$title">
    <main class="auth-card-page">
        <div style="width:100%;max-width:440px">
            <section class="auth-card">
                <x-logo stacked size="md" />
                {{ $slot }}
            </section>
            <p class="auth-foot">&copy; {{ date('Y') }} KBT RUVIA BioTech. All rights reserved.</p>
        </div>
    </main>
</x-layouts.base>
