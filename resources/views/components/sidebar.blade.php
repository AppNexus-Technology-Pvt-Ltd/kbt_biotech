@php
    $items = [];
    if (auth()->user()->can('dashboard.view')) {
        $items[] = ['Dashboard', route('dashboard'), 'dashboard', request()->routeIs('dashboard'), 'single'];
    }
    $orgPages = [];
    if (auth()->user()->can('organization.view')) {
        $orgPages = ['facilities' => 'Facilities', 'buildings' => 'Buildings', 'departments' => 'Departments', 'locations' => 'Rooms / Locations', 'equipment-categories' => 'Equipment Categories', 'manufacturers' => 'Manufacturers', 'staff' => 'Staff Directory', 'shifts' => 'Shifts', 'on-call-roster' => 'On-Call Roster', 'priority-levels' => 'Priority & SLA'];
        $items[] = ['Organization', route('org.index', 'facilities'), 'building', request()->is('organization*'), 'org'];
    }
    foreach (config('kbt.modules') as $slug => [$label, $path, $permission, $icon]) {
        if (auth()->user()->can($permission)) {
            $items[] = [$label, url($path), $icon, request()->is(ltrim($path, '/').'*'), in_array($slug, ['assets', 'installations'], true) ? $slug : 'single'];
        }
    }
@endphp
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar__brand">
        <x-logo light size="sm" />
        <svg class="brand-mini" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#11b5ab" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-label="KBT RUVIA BioTech"><path d="M2 12h4l2.5-6 4 12 3-9 1.5 3H22"/></svg>
    </div>
    <nav>
        @foreach($items as $item)
            @php
                [$label, $url, $icon, $active] = $item;
                $group = $item[4] ?? 'single';
            @endphp
            <div class="nav-row">
                <a href="{{ $url }}" class="nav-item {{ $active ? 'is-active' : '' }}" @if($active) aria-current="page" @endif title="{{ $label }}">
                    <x-icon :name="$icon" /><span>{{ $label }}</span>
                </a>
                <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="{{ $active ? 'true' : 'false' }}" aria-label="Toggle {{ $label }} submenu"><x-icon name="chevron-down" /></button>
            </div>
            @if($group === 'org')
                <div class="nav-sub {{ $active ? '' : 'is-collapsed' }}" data-nav-sub>
                    @foreach($orgPages as $slug => $text)
                        <a href="{{ route('org.index', $slug) }}" class="{{ request()->is('organization/'.$slug.'*') ? 'is-active' : '' }}">{{ $text }}</a>
                    @endforeach
                </div>
            @endif
            @if($group === 'assets')
                <div class="nav-sub {{ $active ? '' : 'is-collapsed' }}" data-nav-sub>
                    <a href="{{ route('assets.dashboard') }}" class="{{ request()->routeIs('assets.dashboard') ? 'is-active' : '' }}">Asset Dashboard</a>
                    <a href="{{ route('assets.register') }}" class="{{ request()->routeIs('assets.register', 'assets.show', 'assets.edit') ? 'is-active' : '' }}">Asset Register</a>
                    <a href="{{ route('assets.transfers') }}" class="{{ request()->routeIs('assets.transfers', 'assets.transfer') ? 'is-active' : '' }}">Asset Transfers</a>
                    <a href="{{ route('assets.lifecycle') }}" class="{{ request()->routeIs('assets.lifecycle', 'assets.status', 'assets.dispose') ? 'is-active' : '' }}">Lifecycle</a>
                    @can('assets.create')
                        <a href="{{ route('assets.create') }}" class="{{ request()->routeIs('assets.create') ? 'is-active' : '' }}">New Asset</a>
                    @endcan
                    <a href="{{ route('assets.scan') }}" class="{{ request()->routeIs('assets.scan') ? 'is-active' : '' }}">QR Scan</a>
                    @can('assets.create')
                        <a href="{{ route('assets.import') }}" class="{{ request()->routeIs('assets.import') ? 'is-active' : '' }}">Bulk Import</a>
                    @endcan
                    <a href="{{ route('assets.labels') }}" class="{{ request()->routeIs('assets.labels') ? 'is-active' : '' }}">Label Print</a>
                    @can('reports.view')
                        <a href="{{ route('assets.reports') }}" class="{{ request()->routeIs('assets.reports') ? 'is-active' : '' }}">Reports</a>
                    @endcan
                </div>
            @endif
            @if($group === 'single')
                <div class="nav-sub {{ $active ? '' : 'is-collapsed' }}" data-nav-sub>
                    <a href="{{ $url }}" class="{{ $active ? 'is-active' : '' }}">{{ $label === 'Dashboard' ? 'Overview' : $label.' Overview' }}</a>
                </div>
            @endif
            @if($group === 'installations')
                <div class="nav-sub {{ $active ? '' : 'is-collapsed' }}" data-nav-sub>
                    <a href="{{ route('installations.dashboard') }}" class="{{ request()->routeIs('installations.dashboard') ? 'is-active' : '' }}">Installation Dashboard</a>
                    <a href="{{ route('installations.jobs') }}" class="{{ request()->routeIs('installations.jobs', 'installations.show', 'installations.certificate') ? 'is-active' : '' }}">Installation Jobs</a>
                    @can('installations.manage')
                        <a href="{{ route('installations.create') }}" class="{{ request()->routeIs('installations.create') ? 'is-active' : '' }}">New Installation</a>
                    @endcan
                    <a href="{{ route('installations.reports') }}" class="{{ request()->routeIs('installations.reports') ? 'is-active' : '' }}">Reports</a>
                </div>
            @endif
        @endforeach
    </nav>
    <div class="sidebar__foot">
        <button type="button" class="nav-item" data-sidebar-collapse aria-label="Collapse sidebar">
            <x-icon name="chevrons-left" class="collapse-icon" /><span>Collapse</span>
        </button>
    </div>
</aside>
<script>
document.querySelectorAll('[data-nav-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        var sub = btn.closest('.nav-row').nextElementSibling;
        if (!sub || !sub.hasAttribute('data-nav-sub')) return;
        var open = sub.classList.toggle('is-collapsed') === false;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
});
</script>
