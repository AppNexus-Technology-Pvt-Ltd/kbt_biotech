@props(['title' => null])
<x-layouts.base :title="$title" :density="auth()->user()->preference('density')">
    <div class="shell" data-shell>
        <x-sidebar />
        <div class="sidebar-backdrop" data-sidebar-close></div>
        <div class="main">
            <x-topbar />
            <main class="content" id="main">
                {{ $slot }}
            </main>
        </div>
    </div>
    <x-confirm-dialog />
</x-layouts.base>
