<x-layouts.app :title="$asset->asset_tag">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> <a href="{{ route('assets.register') }}">Asset Register</a> <span>&rsaquo;</span> {{ $asset->asset_tag }}</nav>

    <div data-asset-detail data-id="{{ $asset->id }}" data-url="{{ route('api.assets.show', $asset->id) }}"
         data-api="{{ url('/api/assets/'.$asset->id) }}" data-list-url="{{ route('assets.register') }}"
         data-can-edit-docs="{{ auth()->user()->can('assets.edit') ? '1' : '0' }}">
        <div class="empty" data-loading>Loading…</div>
        <div class="alert alert--error" data-error hidden role="alert"><span data-error-msg></span> <button type="button" class="link" data-retry>Try again</button></div>

        <div data-content hidden>
            <div class="page-head">
                <div><h1 class="page-title">Asset Detail</h1></div>
                <div class="page-head__actions">
                    <a class="btn btn--outline btn--sm btn--auto" data-edit-link hidden>Edit</a>
                    <a class="btn btn--outline btn--sm btn--auto" data-labels-link>Print QR</a>
                    <a class="btn btn--outline btn--sm btn--auto" data-transfer-link hidden href="{{ route('assets.transfer', $asset) }}">Transfer</a>
                    <a class="btn btn--outline btn--sm btn--auto" data-status-link hidden href="{{ route('assets.status', $asset) }}">Change Status</a>
                    <a class="btn btn--outline btn--sm btn--auto" data-dispose-link hidden href="{{ route('assets.dispose', $asset) }}">Retire / Dispose</a>
                    <button type="button" class="btn btn--outline btn--sm btn--auto" data-delete hidden>Delete</button>
                </div>
            </div>

            <div class="alert alert--info" data-pending hidden role="status"></div>
            <div class="card card__body asset-head">
                <div class="asset-head__photo" data-photo></div>
                <div class="asset-head__main">
                    <h2 data-name></h2>
                    <p class="muted" data-subtitle></p>
                    <span class="pill" data-status></span>
                </div>
                <dl class="asset-head__facts" data-facts></dl>
            </div>

            <div class="card" style="margin-top:16px">
                <div class="tabs" role="tablist" aria-label="Asset sections">
                    @foreach(['overview' => 'Overview', 'specs' => 'Specifications', 'location' => 'Location', 'maintenance' => 'Maintenance', 'calibration' => 'Calibration', 'documents' => 'Documents', 'history' => 'History'] as $key => $label)
                        <button type="button" class="tab {{ $loop->first ? 'is-active' : '' }}" role="tab" id="dtab-{{ $key }}" aria-controls="dpanel-{{ $key }}"
                                aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-tab="{{ $key }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="card__body">
                    @foreach(['overview', 'specs', 'location', 'maintenance', 'calibration', 'documents', 'history'] as $key)
                        <section class="tabpanel" id="dpanel-{{ $key }}" role="tabpanel" aria-labelledby="dtab-{{ $key }}" data-panel="{{ $key }}" {{ $loop->first ? '' : 'hidden' }}></section>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
