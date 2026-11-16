<x-layouts.app title="Asset Register">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> Asset Register</nav>
    <div class="page-head">
        <div>
            <h1 class="page-title">Asset Register</h1>
            <p class="page-sub">Centralised registry of all biomedical equipment.</p>
        </div>
        <div class="page-head__actions">
            @can('assets.create')
                <a class="btn btn--outline btn--sm btn--auto" href="{{ route('assets.import') }}">Import</a>
            @endcan
            <button type="button" class="btn btn--outline btn--sm btn--auto" data-asset-export>Export</button>
            <button type="button" class="btn btn--outline btn--sm btn--auto" data-print-qr data-labels-url="{{ route('assets.labels') }}">Print QR</button>
            @can('assets.create')
                <a class="btn btn--primary btn--sm btn--auto" href="{{ route('assets.create') }}">+ Add Asset</a>
            @endcan
        </div>
    </div>

    <div class="card org" data-asset-register data-url="{{ route('api.assets.index') }}" data-export="{{ route('api.assets.export') }}"
         data-options="{{ route('api.assets.options') }}" data-can-edit="{{ auth()->user()->can('assets.edit') ? '1' : '0' }}" data-init-status="{{ request('status') }}" data-init-q="{{ request('q') }}">
        <div class="org__toolbar">
            <div class="input-wrap org__search">
                <x-icon name="search" />
                <input type="search" placeholder="Search by asset ID, name, serial no..." aria-label="Search assets" data-f="q" autocomplete="off">
            </div>
            <select class="select org__filter" data-f="category_id" aria-label="Category"><option value="">All Categories</option></select>
            <select class="select org__filter" data-f="department_id" aria-label="Department"><option value="">All Departments</option></select>
            <select class="select org__filter" data-f="status" aria-label="Status">
                <option value="">All Status</option>
                @foreach(\App\Models\Asset::STATUSES as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th style="width:36px"><input type="checkbox" data-select-page aria-label="Select all on this page"></th><th>Asset ID</th><th>Equipment Name</th><th>Category</th><th>Department</th><th>Location</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
                <tbody data-rows><tr><td colspan="8"><div class="empty">Loading…</div></td></tr></tbody>
            </table>
        </div>
        <div class="org__foot"><span data-summary class="muted">&nbsp;</span><div class="pager" data-pager></div></div>
    </div>
</x-layouts.app>
