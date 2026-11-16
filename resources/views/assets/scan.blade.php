<x-layouts.app title="Scan Asset">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> Scan</nav>
    <h1 class="page-title">Scan Asset QR / Barcode</h1>
    <p class="page-sub">Point the camera at a label to look up the asset, or type the asset ID.</p>

    <div class="scan-grid" data-scan data-lookup="{{ route('api.assets.lookup') }}" data-initial="{{ e(request('code')) }}">
        <div class="card card__body">
            <div class="scan-view" data-scan-view>
                <video data-scan-video playsinline muted></video>
                <div class="scan-frame" aria-hidden="true"></div>
                <div class="scan-idle" data-scan-idle>Camera is off</div>
            </div>
            <div class="scan-actions">
                <button type="button" class="btn btn--primary btn--sm btn--auto" data-scan-start>Start camera</button>
                <button type="button" class="btn btn--outline btn--sm btn--auto" data-scan-stop hidden>Stop camera</button>
            </div>
            <p class="muted" data-scan-note style="margin-top:10px;font-size:12.5px"></p>
            <form class="scan-manual" data-scan-form novalidate>
                <div class="input-wrap" data-field><input name="code" placeholder="Or type an asset ID, e.g. BIO-0001" maxlength="200" autocomplete="off" aria-label="Asset ID"></div>
                <button type="submit" class="btn btn--outline btn--sm btn--auto">Look up</button>
            </form>
        </div>
        <div data-scan-result aria-live="polite"><div class="card card__body"><div class="empty"><strong>Nothing scanned yet</strong><span>The asset will appear here.</span></div></div></div>
    </div>
</x-layouts.app>
