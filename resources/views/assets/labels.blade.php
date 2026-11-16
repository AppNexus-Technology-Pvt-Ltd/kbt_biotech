<x-layouts.app title="QR / Barcode Labels">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> QR / Barcode Labels</nav>
    <h1 class="page-title">QR / Barcode Label Print</h1>
    <p class="page-sub">Generate printable labels for tagging equipment.</p>

    <div data-labels data-api="{{ route('api.assets.labels') }}" data-ids="{{ e(request('ids')) }}" data-register="{{ route('assets.register') }}">
        <div class="alert alert--info" data-labels-empty hidden role="status">
            No assets selected. Tick assets in the <a href="{{ route('assets.register') }}">Asset Register</a> and choose “Print QR”, or open an asset and use its Print QR button.
        </div>
        <div class="labels-grid" data-labels-main hidden>
            <form class="card card__body" data-labels-form novalidate>
                <div class="field">
                    <span class="field__label">Label Type</span>
                    <div class="radios">
                        <label class="check"><input type="radio" name="kind" value="qr"> QR Code</label>
                        <label class="check"><input type="radio" name="kind" value="barcode"> Barcode</label>
                        <label class="check"><input type="radio" name="kind" value="both" checked> Both</label>
                    </div>
                </div>
                <div class="field">
                    <label class="field__label" for="f-size">Label Size</label>
                    <div data-field><select class="select" id="f-size" name="size">
                        <option value="60x40" selected>60 x 40 mm</option>
                        <option value="50x30">50 x 30 mm</option>
                        <option value="100x50">100 x 50 mm</option>
                    </select></div>
                </div>
                <div class="field">
                    <span class="field__label">Include Information</span>
                    <div class="checks">
                        <label class="check"><input type="checkbox" name="inc" value="tag" checked> Asset ID</label>
                        <label class="check"><input type="checkbox" name="inc" value="name" checked> Equipment Name</label>
                        <label class="check"><input type="checkbox" name="inc" value="serial" checked> Serial Number</label>
                        <label class="check"><input type="checkbox" name="inc" value="department"> Department</label>
                        <label class="check"><input type="checkbox" name="inc" value="location"> Location</label>
                        <label class="check"><input type="checkbox" name="inc" value="logo" checked> Logo</label>
                    </div>
                </div>
                <div class="field">
                    <label class="field__label" for="f-copies">Quantity (copies per asset)</label>
                    <div class="input-wrap" data-field style="max-width:140px"><input id="f-copies" name="copies" type="number" min="1" max="50" value="1"></div>
                    <div class="field__error" data-error-for="copies" role="alert"></div>
                    <p class="field__hint" data-labels-count></p>
                </div>
                <x-button class="btn--sm" style="width:auto" loading="Generating…">Generate Labels</x-button>
            </form>
            <div class="card card__body">
                <span class="field__label">Label Preview</span>
                <div class="label-preview" data-labels-preview><div class="empty">Loading…</div></div>
            </div>
        </div>
    </div>
</x-layouts.app>
