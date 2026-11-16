<x-layouts.app title="Asset Reports">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> Reports</nav>
    <h1 class="page-title">Asset Reports</h1>
    <p class="page-sub">Generate, review and export asset reports for your facilities.</p>

    <div data-reports data-base="{{ url('/api/assets/reports') }}" data-options="{{ route('api.assets.options') }}">
        <div class="report-list">
            @foreach($types as $key => [$title, $desc])
                <div class="card report-card">
                    <div><strong>{{ $title }}</strong><p class="muted">{{ $desc }}</p></div>
                    <button type="button" class="btn btn--outline btn--sm btn--auto" data-report="{{ $key }}">Generate</button>
                </div>
            @endforeach
        </div>

        <div class="card" data-result hidden style="margin-top:18px">
            <div class="card__head"><h2 data-title></h2>
                <div class="page-head__actions">
                    <button type="button" class="btn btn--outline btn--sm btn--auto" data-export>Export CSV</button>
                    <button type="button" class="btn btn--outline btn--sm btn--auto" data-print>Print</button>
                </div>
            </div>
            <form class="org__toolbar" data-filters novalidate>
                <select class="select org__filter" name="facility_id" aria-label="Facility"><option value="">All facilities</option></select>
                <select class="select org__filter" name="category_id" data-only="register" aria-label="Category"><option value="">All categories</option></select>
                <select class="select org__filter" name="status" data-only="register,lifecycle" aria-label="Status"><option value="">All statuses</option>
                    @foreach(\App\Models\Asset::STATUSES as $v => $t)<option value="{{ $v }}">{{ $t }}</option>@endforeach
                </select>
                <label class="inline-pick" data-only="warranty"><span>Expiring from</span><input class="select" type="date" name="from"></label>
                <label class="inline-pick" data-only="warranty"><span>Expiring to</span><input class="select" type="date" name="to"></label>
                <button type="submit" class="btn btn--primary btn--sm btn--auto">Generate</button>
            </form>
            <div class="chips" data-summary></div>
            <div class="table-wrap" data-table></div>
            <div class="org__foot"><span class="muted" data-note></span></div>
        </div>
    </div>
</x-layouts.app>
