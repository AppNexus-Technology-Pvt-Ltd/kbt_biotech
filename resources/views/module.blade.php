<x-layouts.app :title="$label">
    <h1 class="page-title">{{ $label }}</h1>
    <p class="page-sub">{{ $description }}</p>
    <div class="card">
        <div class="empty">
            <x-icon name="inbox" />
            @if($count)
                <strong>{{ number_format($count) }} record(s) in your facilities</strong>
                <span>The detailed {{ strtolower($label) }} screens are not part of this release yet.</span>
            @else
                <strong>No {{ strtolower($label) }} records yet</strong>
                <span>This module has not been set up. Records will appear here once they are created.</span>
            @endif
        </div>
    </div>
</x-layouts.app>
