<x-layouts.app title="Change Asset Status">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> <a href="{{ route('assets.show', $asset) }}">{{ $asset->asset_tag }}</a> <span>&rsaquo;</span> Change Status</nav>
    <form class="card card__body wf-form" data-wf="status" novalidate data-asset-url="{{ route('api.assets.show', $asset->id) }}"
          data-submit="{{ url('/api/assets/'.$asset->id.'/status') }}" data-retire-url="{{ route('assets.dispose', $asset) }}">
        <h1 class="page-title">Change Asset Status – {{ $asset->asset_tag }}</h1>
        <p class="page-sub" data-asset-line>{{ $asset->name }}</p>
        <div class="form-grid">
            <div class="field">
                <span class="field__label">New Status *</span>
                <div class="radios radios--col" data-field>
                    @foreach(\App\Models\Asset::EDITABLE_STATUSES as $v)
                        <label class="check"><input type="radio" name="status" value="{{ $v }}"> {{ \App\Models\Asset::STATUSES[$v] }}</label>
                    @endforeach
                    @can('assets.approve')
                        <label class="check"><input type="radio" name="status" value="retired" data-retire> Retired</label>
                    @endcan
                </div>
                <div class="field__error" data-error-for="status" role="alert"></div>
                <p class="field__hint" data-current-line></p>
            </div>
            <div>
                <div class="field">
                    <label class="field__label" for="f-reason">Reason for Change *</label>
                    <div class="input-wrap" data-field><input id="f-reason" name="reason" maxlength="255" list="status-reasons" placeholder="Select or enter reason"></div>
                    <datalist id="status-reasons"><option value="Preventive maintenance"><option value="Breakdown"><option value="Awaiting spare parts"><option value="Repaired and tested"><option value="Calibration due"></datalist>
                    <div class="field__error" data-error-for="reason" role="alert"></div>
                </div>
                <x-input label="Effective Date *" name="effective_date" type="date" :value="now()->format('Y-m-d')" max="{{ now()->format('Y-m-d') }}" />
            </div>
        </div>
        <div class="field">
            <label class="field__label" for="f-remarks">Remarks</label>
            <textarea class="textarea" id="f-remarks" name="remarks" rows="3" maxlength="2000"></textarea>
            <div class="field__error" data-error-for="remarks" role="alert"></div>
        </div>
        <div class="form-actions">
            <a class="btn btn--outline btn--sm btn--auto" href="{{ route('assets.show', $asset) }}">Cancel</a>
            <x-button class="btn--sm" style="width:auto" loading="Updating…">Update Status</x-button>
        </div>
    </form>
</x-layouts.app>
