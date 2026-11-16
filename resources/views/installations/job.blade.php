@php($editing = (bool) $job)
<x-layouts.app :title="$editing ? $job->job_number : 'New Installation'">
    <nav class="crumbs" aria-label="Breadcrumb">Installations <span>&rsaquo;</span> <a href="{{ route('installations.jobs') }}">Jobs</a> <span>&rsaquo;</span> {{ $editing ? $job->job_number : 'New' }}</nav>

    <div data-inst-job data-id="{{ $job?->id }}" data-api="{{ $editing ? url('/api/installations/'.$job->id) : '' }}" data-create-url="{{ url('/api/installations') }}"
         data-options="{{ route('api.installations.options') }}" data-list-url="{{ route('installations.jobs') }}" data-base="{{ url('/installations') }}">
        <div class="empty" data-loading @if(! $editing) hidden @endif>Loading…</div>
        <div class="alert alert--error" data-error hidden role="alert"><span data-error-msg></span> <button type="button" class="link" data-retry>Try again</button></div>

        <div data-content @if($editing) hidden @endif>
            <div class="page-head">
                <div>
                    <h1 class="page-title" data-title>{{ $editing ? $job->job_number : 'Create Installation Job' }}</h1>
                    <p class="page-sub" data-subtitle>{{ $editing ? '' : 'Enter installation details and assign to responsible engineer.' }}</p>
                </div>
                <div class="page-head__actions" data-actions></div>
            </div>
            <div class="alert alert--info" data-banner hidden role="status"></div>

            <div class="card">
                <div class="tabs" role="tablist" aria-label="Installation sections">
                    @foreach(['basic' => 'Basic Information', 'site_readiness' => 'Site Readiness', 'installation' => 'Installation Checklist', 'acceptance' => 'Acceptance Test', 'documents' => 'Documents', 'certificate' => 'Certificate', 'handover' => 'Handover'] as $key => $label)
                        <button type="button" class="tab {{ $loop->first ? 'is-active' : '' }}" role="tab" id="itab-{{ $key }}" aria-controls="ipanel-{{ $key }}"
                                aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-tab="{{ $key }}">{{ $label }}<i class="tab__dot" hidden></i></button>
                    @endforeach
                </div>
                <div class="card__body">
                    <section class="tabpanel" id="ipanel-basic" role="tabpanel" aria-labelledby="itab-basic" data-panel="basic">
                        <form data-basic novalidate>
                            <div class="form-grid">
                                <div class="field"><span class="field__label">Job Number</span><div class="readout" data-job-number>Auto-generated on save</div></div>
                                <div class="field">
                                    <span class="field__label">Installation Type *</span>
                                    <div class="radios" data-field>
                                        @foreach(\App\Models\InstallationJob::TYPES as $v => $t)<label class="check"><input type="radio" name="type" value="{{ $v }}" @checked($v === 'new_installation')> {{ $t }}</label>@endforeach
                                    </div>
                                    <div class="field__error" data-error-for="type" role="alert"></div>
                                </div>

                                <div class="field">
                                    <label class="field__label" for="f-asset_id">Asset ID *</label>
                                    <div class="combo" data-combo>
                                        <div class="input-wrap" data-field>
                                            <x-icon name="search" />
                                            <input type="text" id="f-asset_id" role="combobox" aria-expanded="false" aria-controls="asset-list" aria-autocomplete="list"
                                                   data-asset-search placeholder="Type asset ID or name…" autocomplete="off" maxlength="100">
                                            <input type="hidden" name="asset_id">
                                        </div>
                                        <ul class="combo__list" id="asset-list" role="listbox" hidden data-asset-list></ul>
                                    </div>
                                    <div class="field__error" data-error-for="asset_id" role="alert"></div>
                                    <p class="field__hint" data-asset-hint>Start typing to find a registered asset that has no open installation job.</p>
                                </div>
                                <div class="field">
                                    <label class="field__label" for="f-priority">Priority *</label>
                                    <div data-field><select class="select" id="f-priority" name="priority">
                                        @foreach(\App\Models\InstallationJob::PRIORITIES as $v => $t)<option value="{{ $v }}" @selected($v === 'normal')>{{ $t }}</option>@endforeach</select></div>
                                    <div class="field__error" data-error-for="priority" role="alert"></div>
                                </div>

                                <div class="field"><span class="field__label">Equipment Name <small class="muted">(auto-filled)</small></span><div class="readout" data-equipment>—</div></div>
                                <div class="field">
                                    <label class="field__label" for="f-vendor_name">Vendor Involved</label>
                                    <div class="input-wrap" data-field><input id="f-vendor_name" name="vendor_name" list="vendor-list" maxlength="160" placeholder="e.g. GE Healthcare"></div>
                                    <datalist id="vendor-list"></datalist>
                                    <div class="field__error" data-error-for="vendor_name" role="alert"></div>
                                </div>

                                <div class="field">
                                    <label class="field__label" for="f-department_id">Department</label>
                                    <div data-field><select class="select" id="f-department_id" name="department_id"></select></div>
                                    <div class="field__error" data-error-for="department_id" role="alert"></div>
                                </div>
                                <x-input label="Estimated Duration (Hours)" name="estimated_hours" type="number" step="0.5" min="0.5" max="1000" />

                                <x-input label="Planned Installation Date *" name="planned_date" type="date" :value="now()->format('Y-m-d')" />
                                <div class="field">
                                    <label class="field__label" for="f-engineer_id">Responsible Engineer *</label>
                                    <div data-field><select class="select" id="f-engineer_id" name="engineer_id"></select></div>
                                    <div class="field__error" data-error-for="engineer_id" role="alert"></div>
                                </div>
                            </div>
                            <div class="field">
                                <label class="field__label" for="f-remarks">Remarks</label>
                                <textarea class="textarea" id="f-remarks" name="remarks" rows="3" maxlength="2000" placeholder="Enter any additional notes..."></textarea>
                                <div class="field__error" data-error-for="remarks" role="alert"></div>
                            </div>
                        </form>
                    </section>
                    @foreach(['site_readiness', 'installation', 'acceptance', 'documents', 'certificate', 'handover'] as $key)
                        <section class="tabpanel" id="ipanel-{{ $key }}" role="tabpanel" aria-labelledby="itab-{{ $key }}" data-panel="{{ $key }}" hidden></section>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <dialog class="dialog" data-reason-dialog>
        <form method="dialog" data-reason-form novalidate>
            <h3 data-reason-title></h3><p data-reason-text></p>
            <div class="input-wrap" data-field><input name="reason" maxlength="255" placeholder="Reason"></div>
            <div class="field__error" data-error-for="reason" role="alert"></div>
            <div class="dialog__actions"><button type="button" class="btn btn--outline btn--sm" data-reason-cancel>Back</button><button type="submit" class="btn btn--danger btn--sm" data-reason-ok>Confirm</button></div>
        </form>
    </dialog>
</x-layouts.app>
