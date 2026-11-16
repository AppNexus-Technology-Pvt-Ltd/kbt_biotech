<x-layouts.app title="Installation Dashboard">
    <h1 class="page-title">Installation Dashboard</h1>
    <p class="page-sub">Overview of ongoing and completed equipment installations.</p>

    <div data-inst-dashboard data-url="{{ route('api.installations.dashboard') }}">
        <div class="empty" data-loading>Loading…</div>
        <div class="alert alert--error" data-error hidden role="alert"><span data-error-msg></span> <button type="button" class="link" data-retry>Try again</button></div>
        <div data-content hidden>
            <div class="stats">
                <a class="card stat stat--link" href="{{ route('installations.jobs') }}"><span class="stat__icon stat__icon--blue"><x-icon name="install" /></span>
                    <div><div class="stat__label">Total Installations</div><div class="stat__value" data-stat="total">–</div></div></a>
                <a class="card stat stat--link" href="{{ route('installations.jobs', ['status' => 'in_progress']) }}"><span class="stat__icon stat__icon--orange"><x-icon name="clock" /></span>
                    <div><div class="stat__label">In Progress</div><div class="stat__value" data-stat="in_progress">–</div></div></a>
                <a class="card stat stat--link" href="{{ route('installations.jobs', ['status' => 'pending_approval']) }}"><span class="stat__icon stat__icon--red"><x-icon name="alert" /></span>
                    <div><div class="stat__label">Pending Approval</div><div class="stat__value" data-stat="pending_approval">–</div></div></a>
                <a class="card stat stat--link" href="{{ route('installations.jobs', ['status' => 'completed']) }}"><span class="stat__icon stat__icon--green"><x-icon name="check-circle" /></span>
                    <div><div class="stat__label">Completed</div><div class="stat__value" data-stat="completed">–</div></div></a>
            </div>
            <div class="grid-2">
                <div class="card"><div class="card__head"><h2>Installation Trend (Last 6 Months)</h2></div><div class="card__body" data-trend></div></div>
                <div class="card"><div class="card__head"><h2>Installations by Department</h2></div><div class="card__body" data-departments></div></div>
            </div>
            <div class="card">
                <div class="card__head"><h2>Recent Installation Jobs</h2><a class="link" href="{{ route('installations.jobs') }}">View All</a></div>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Job No</th><th>Asset ID</th><th>Equipment Name</th><th>Department</th><th>Planned Date</th><th>Status</th></tr></thead>
                    <tbody data-recent></tbody>
                </table></div>
            </div>
        </div>
    </div>
</x-layouts.app>
