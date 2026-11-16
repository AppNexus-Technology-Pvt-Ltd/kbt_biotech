<x-layouts.app title="Retire / Dispose Asset">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> <a href="{{ route('assets.show', $asset) }}">{{ $asset->asset_tag }}</a> <span>&rsaquo;</span> Retire / Dispose</nav>
    <form class="card card__body wf-form" data-wf="dispose" novalidate data-asset-url="{{ route('api.assets.show', $asset->id) }}"
          data-submit="{{ url('/api/assets/'.$asset->id.'/dispose') }}" data-done="{{ route('assets.show', $asset) }}">
        <h1 class="page-title">Retire / Dispose Asset – {{ $asset->asset_tag }}</h1>
        <p class="page-sub" data-asset-line>{{ $asset->name }}</p>
        <div class="alert alert--info" role="note">This cannot be undone. The asset will leave all counts and become read-only.</div>
        <div class="form-grid">
            <div class="field">
                <span class="field__label">Disposal Type *</span>
                <div class="radios" data-field>
                    <label class="check"><input type="radio" name="type" value="retirement" @checked($asset->status !== 'retired') @disabled($asset->status === 'retired')> Retirement</label>
                    <label class="check"><input type="radio" name="type" value="disposal" @checked($asset->status === 'retired')> Disposal</label>
                </div>
                <div class="field__error" data-error-for="type" role="alert"></div>
                @if($asset->status === 'retired')<p class="field__hint">This asset is already retired; it can now be disposed.</p>@endif
            </div>
            <x-input label="Date *" name="disposal_date" type="date" :value="now()->format('Y-m-d')" max="{{ now()->format('Y-m-d') }}" />
            <div class="field">
                <label class="field__label" for="f-reason">Reason *</label>
                <div data-field><select class="select" id="f-reason" name="reason"><option value="">Select reason</option>
                    @foreach(\App\Models\AssetDisposal::REASONS as $v => $t)<option value="{{ $v }}">{{ $t }}</option>@endforeach</select></div>
                <div class="field__error" data-error-for="reason" role="alert"></div>
            </div>
            <div class="field">
                <label class="field__label" for="f-condition">Condition *</label>
                <div data-field><select class="select" id="f-condition" name="condition"><option value="">Select condition</option>
                    @foreach(\App\Models\AssetDisposal::CONDITIONS as $v => $t)<option value="{{ $v }}">{{ $t }}</option>@endforeach</select></div>
                <div class="field__error" data-error-for="condition" role="alert"></div>
            </div>
        </div>
        <div class="field">
            <label class="field__label" for="f-remarks">Final Remarks</label>
            <textarea class="textarea" id="f-remarks" name="remarks" rows="3" maxlength="2000"></textarea>
            <div class="field__error" data-error-for="remarks" role="alert"></div>
        </div>
        <div class="form-actions">
            <a class="btn btn--outline btn--sm btn--auto" href="{{ route('assets.show', $asset) }}">Cancel</a>
            <x-button class="btn--sm" style="width:auto" loading="Submitting…">Submit</x-button>
        </div>
    </form>
</x-layouts.app>
