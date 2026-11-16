<x-layouts.app title="Asset Transfer">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> <a href="{{ route('assets.show', $asset) }}">{{ $asset->asset_tag }}</a> <span>&rsaquo;</span> Transfer</nav>
    <form class="card card__body wf-form" data-wf="transfer" novalidate data-asset-url="{{ route('api.assets.show', $asset->id) }}"
          data-options="{{ route('api.assets.options') }}" data-submit="{{ url('/api/assets/'.$asset->id.'/transfers') }}" data-done="{{ route('assets.transfers') }}">
        <h1 class="page-title">Transfer Asset – {{ $asset->asset_tag }}</h1>
        <p class="page-sub" data-asset-line>{{ $asset->name }}</p>
        <div class="form-grid">
            <div class="field"><span class="field__label">Current Location</span><div class="readout" data-current>Loading…</div></div>
            <x-input label="Requested Date *" name="requested_date" type="date" :value="now()->format('Y-m-d')" min="{{ now()->format('Y-m-d') }}" />

            <div class="field">
                <label class="field__label" for="f-to_facility_id">Transfer To Facility *</label>
                <div data-field><select class="select" id="f-to_facility_id" name="to_facility_id"></select></div>
                <div class="field__error" data-error-for="to_facility_id" role="alert"></div>
            </div>
            <div class="field"><span class="field__label">Approval Required By</span><div class="readout">Facility Manager / Administrator</div></div>

            <div class="field">
                <label class="field__label" for="f-to_department_id">Transfer To Department *</label>
                <div data-field><select class="select" id="f-to_department_id" name="to_department_id"></select></div>
                <div class="field__error" data-error-for="to_department_id" role="alert"></div>
            </div>
            <div class="field"><span class="field__label">Status</span><div class="readout"><span class="pill pill--pending">Pending Approval</span><span class="muted" style="margin-left:8px">once submitted</span></div></div>

            <div class="field">
                <label class="field__label" for="f-to_location_id">Transfer To Location *</label>
                <div data-field><select class="select" id="f-to_location_id" name="to_location_id"></select></div>
                <div class="field__error" data-error-for="to_location_id" role="alert"></div>
            </div>
            <div class="field">
                <label class="field__label" for="f-reason">Reason for Transfer *</label>
                <div class="input-wrap" data-field><input id="f-reason" name="reason" maxlength="255" placeholder="e.g. Department requirement"></div>
                <div class="field__error" data-error-for="reason" role="alert"></div>
            </div>
        </div>
        <div class="field">
            <label class="field__label" for="f-remarks">Remarks</label>
            <textarea class="textarea" id="f-remarks" name="remarks" rows="3" maxlength="2000"></textarea>
            <div class="field__error" data-error-for="remarks" role="alert"></div>
        </div>
        <div class="form-actions">
            <a class="btn btn--outline btn--sm btn--auto" href="{{ route('assets.show', $asset) }}">Cancel</a>
            <x-button class="btn--sm" style="width:auto" loading="Submitting…">Submit for Approval</x-button>
        </div>
    </form>
</x-layouts.app>
