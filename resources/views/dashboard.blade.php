<x-layouts.app title="Dashboard">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-sub">Overview of biomedical equipment maintenance and operations</p>

    <div data-dashboard data-url="{{ route('api.dashboard') }}">
        <div data-dash-loading>
            <div class="stats">
                @for($i = 0; $i < 4; $i++)<div class="card skeleton" style="height:96px"></div>@endfor
            </div>
            <div class="grid-2">
                <div class="card skeleton" style="height:300px"></div>
                <div class="card skeleton" style="height:300px"></div>
            </div>
        </div>

        <div data-dash-error hidden class="card">
            <div class="error-box">
                <x-icon name="alert" style="width:30px;height:30px" />
                <strong>We couldn't load the dashboard.</strong>
                <span data-dash-error-msg></span>
                <button type="button" class="btn btn--outline btn--sm btn--auto" data-dash-retry>Try again</button>
            </div>
        </div>

        <div data-dash-content hidden>
            <div class="stats" data-stats></div>

            <div class="grid-2" data-row-1>
                <section class="card" data-card="trend">
                    <div class="card__head"><h2>Work Order Trend</h2><span class="page-sub" style="margin:0;font-size:12px">Last 6 months</span></div>
                    <div class="card__body" data-trend></div>
                </section>
                <section class="card" data-card="recent">
                    <div class="card__head"><h2>Recent Work Orders</h2><a class="link" href="{{ url('/work-orders') }}" style="font-size:13px">View All</a></div>
                    <div class="card__body table-wrap" data-recent></div>
                </section>
            </div>

            <div class="grid-3" data-row-2>
                <section class="card" data-card="status"><div class="card__head"><h2>Work Order Status</h2></div><div class="card__body" data-status></div></section>
                <section class="card" data-card="priority"><div class="card__head"><h2>Priority Indicators</h2><span class="page-sub" style="margin:0;font-size:12px">Open work orders</span></div><div class="card__body" data-priority></div></section>
                <section class="card" data-card="pm"><div class="card__head"><h2>Preventive Maintenance Overview</h2></div><div class="card__body" data-pm></div></section>
            </div>
        </div>
    </div>
</x-layouts.app>
