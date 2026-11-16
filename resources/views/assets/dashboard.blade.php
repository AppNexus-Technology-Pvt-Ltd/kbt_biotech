<x-layouts.app title="Asset Dashboard">
    <h1 class="page-title">Asset Dashboard</h1>
    <p class="page-sub">Centralised overview of biomedical equipment across your facilities.</p>

    <div data-asset-dashboard data-url="{{ route('api.assets.dashboard') }}">
        <div class="empty" data-dash-loading>Loading…</div>
        <div class="alert alert--error" data-dash-error hidden role="alert">
            <span data-dash-error-msg></span> <button type="button" class="link" data-dash-retry>Try again</button>
        </div>
        <div data-dash-content hidden>
            <div class="stats">
                <a class="card stat stat--link" href="{{ route('assets.register') }}">
                    <span class="stat__icon stat__icon--green"><x-icon name="box" /></span>
                    <div><div class="stat__label">Total Assets</div><div class="stat__value" data-stat="total">–</div><div class="stat__note is-good" data-note="total"></div></div>
                </a>
                <a class="card stat stat--link" href="{{ route('assets.register', ['status' => 'operational']) }}">
                    <span class="stat__icon stat__icon--green"><x-icon name="check-circle" /></span>
                    <div><div class="stat__label">Operational</div><div class="stat__value" data-stat="operational">–</div><div class="stat__note" data-note="operational"></div></div>
                </a>
                <a class="card stat stat--link" href="{{ route('assets.register', ['status' => 'under_maintenance']) }}">
                    <span class="stat__icon stat__icon--red"><x-icon name="settings" /></span>
                    <div><div class="stat__label">Under Maintenance</div><div class="stat__value" data-stat="under_maintenance">–</div><div class="stat__note" data-note="under_maintenance"></div></div>
                </a>
                <a class="card stat stat--link" href="{{ route('assets.register', ['status' => 'out_of_service']) }}">
                    <span class="stat__icon stat__icon--orange"><x-icon name="alert" /></span>
                    <div><div class="stat__label">Out of Service</div><div class="stat__value" data-stat="out_of_service">–</div><div class="stat__note" data-note="out_of_service"></div></div>
                </a>
            </div>
            <div class="grid-2">
                <div class="card"><div class="card__head"><h2>Assets by Category</h2></div><div class="card__body" data-categories></div></div>
                <div class="card"><div class="card__head"><h2>Asset Status</h2></div><div class="card__body" data-status-chart></div></div>
            </div>
        </div>
    </div>
</x-layouts.app>
