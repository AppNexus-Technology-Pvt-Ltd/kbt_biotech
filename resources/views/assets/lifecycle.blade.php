<x-layouts.app title="Asset Lifecycle">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> Lifecycle</nav>
    <h1 class="page-title">Asset Lifecycle</h1>
    <p class="page-sub">Every status change, retirement and disposal, newest first.</p>
    <div class="card org" data-wf="lifecycle" data-url="{{ route('api.assets.lifecycle') }}">
        <div class="org__toolbar">
            <div class="input-wrap org__search"><x-icon name="search" /><input type="search" placeholder="Search by asset ID or name..." aria-label="Search lifecycle" data-f="q" autocomplete="off"></div>
            <select class="select org__filter" data-f="status" aria-label="Changed to">
                <option value="">Any new status</option>
                @foreach(\App\Models\Asset::STATUSES as $v => $t)<option value="{{ $v }}">{{ $t }}</option>@endforeach
            </select>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Effective</th><th>Asset</th><th>Change</th><th>Reason</th><th>By</th></tr></thead>
            <tbody data-rows><tr><td colspan="5"><div class="empty">Loading…</div></td></tr></tbody>
        </table></div>
        <div class="org__foot"><span data-summary class="muted">&nbsp;</span><div class="pager" data-pager></div></div>
    </div>
</x-layouts.app>
