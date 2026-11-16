<x-layouts.app title="Installation Jobs">
    <nav class="crumbs" aria-label="Breadcrumb">Installations <span>&rsaquo;</span> Jobs</nav>
    <div class="page-head">
        <div><h1 class="page-title">Installation Jobs</h1><p class="page-sub">Manage installation, acceptance testing and commissioning.</p></div>
        <div class="page-head__actions">
            @can('installations.manage')<a class="btn btn--primary btn--sm btn--auto" href="{{ route('installations.create') }}">+ New Installation</a>@endcan
        </div>
    </div>

    <div class="card org" data-inst-jobs data-url="{{ route('api.installations.index') }}" data-options="{{ route('api.installations.options') }}"
         data-init-status="{{ e(request('status')) }}">
        <div class="org__toolbar">
            <div class="input-wrap org__search"><x-icon name="search" /><input type="search" placeholder="Search by job no, asset, name..." aria-label="Search jobs" data-f="q" autocomplete="off"></div>
            <select class="select org__filter" data-f="status" aria-label="Status"><option value="">All Status</option>
                @foreach(\App\Models\InstallationJob::STATUSES as $v => $t)<option value="{{ $v }}">{{ $t }}</option>@endforeach</select>
            <select class="select org__filter" data-f="department_id" aria-label="Department"><option value="">All Departments</option></select>
            <button type="button" class="btn btn--outline btn--sm btn--auto" data-more aria-expanded="false">More Filters</button>
        </div>
        <div class="org__toolbar org__toolbar--more" data-more-panel hidden>
            <select class="select org__filter" data-f="type" aria-label="Type"><option value="">Any type</option>
                @foreach(\App\Models\InstallationJob::TYPES as $v => $t)<option value="{{ $v }}">{{ $t }}</option>@endforeach</select>
            <select class="select org__filter" data-f="priority" aria-label="Priority"><option value="">Any priority</option>
                @foreach(\App\Models\InstallationJob::PRIORITIES as $v => $t)<option value="{{ $v }}">{{ $t }}</option>@endforeach</select>
            <select class="select org__filter" data-f="engineer_id" aria-label="Engineer"><option value="">Any engineer</option></select>
            <label class="inline-pick"><span>Planned from</span><input class="select" type="date" data-f="from"></label>
            <label class="inline-pick"><span>Planned to</span><input class="select" type="date" data-f="to"></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Job No</th><th>Asset ID</th><th>Equipment Name</th><th>Department</th><th>Planned Date</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
            <tbody data-rows><tr><td colspan="7"><div class="empty">Loading…</div></td></tr></tbody>
        </table></div>
        <div class="org__foot"><span data-summary class="muted">&nbsp;</span><div class="pager" data-pager></div></div>
    </div>
</x-layouts.app>
