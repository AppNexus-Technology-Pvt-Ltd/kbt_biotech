<x-layouts.app title="Installation Reports">
    <nav class="crumbs" aria-label="Breadcrumb">Installations <span>&rsaquo;</span> Reports</nav>
    <h1 class="page-title">Installation Reports</h1>
    <p class="page-sub">Generate and export installation reports.</p>

    <div data-inst-reports data-base="{{ url('/api/installations/reports') }}" data-options="{{ route('api.installations.options') }}">
        <form class="card card__body" data-filters novalidate>
            <div class="form-grid">
                <div class="field"><label class="field__label" for="r-type">Report Type</label>
                    <div data-field><select class="select" id="r-type" name="type">
                        @foreach($types as $k => [$t])<option value="{{ $k }}">{{ $t }}</option>@endforeach</select></div></div>
                <div class="field"><span class="field__label">Planned Date Range</span>
                    <div class="date-range"><input class="select" type="date" name="from" aria-label="From"> <span class="muted">to</span> <input class="select" type="date" name="to" aria-label="To"></div></div>
                <div class="field"><label class="field__label" for="r-dept">Department</label>
                    <div data-field><select class="select" id="r-dept" name="department_id"><option value="">All Departments</option></select></div></div>
                <div class="field"><label class="field__label" for="r-fac">Facility</label>
                    <div data-field><select class="select" id="r-fac" name="facility_id"><option value="">All facilities</option></select></div></div>
            </div>
            <div class="form-actions" style="border:0;margin:0;padding:0"><button type="submit" class="btn btn--primary btn--sm" style="width:100%">Generate Report</button></div>
        </form>

        <div class="report-list" style="margin-top:16px">
            @foreach($types as $k => [$t, $d])
                @continue($k === 'summary')
                <div class="card report-card"><div><strong>{{ $t }}</strong><p class="muted">{{ $d }}</p></div>
                    <button type="button" class="btn btn--outline btn--sm btn--auto" data-download="{{ $k }}">Download</button></div>
            @endforeach
        </div>

        <div class="card" data-result hidden style="margin-top:18px">
            <div class="card__head"><h2 data-title></h2><button type="button" class="btn btn--outline btn--sm btn--auto" data-print>Print</button></div>
            <div class="chips" data-summary></div>
            <div class="table-wrap" data-table></div>
            <div class="org__foot"><span class="muted" data-note></span></div>
        </div>
    </div>
</x-layouts.app>
