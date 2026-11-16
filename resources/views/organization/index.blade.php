@php($canManage = auth()->user()->can('organization.manage'))
<x-layouts.app :title="$cfg['title']">
    <nav class="crumbs" aria-label="Breadcrumb">Organization <span>&rsaquo;</span> {{ $cfg['title'] }}</nav>

    <div class="page-head">
        <div>
            <h1 class="page-title">{{ $cfg['title'] }}</h1>
            <p class="page-sub">{{ $cfg['sub'] }}</p>
        </div>
        <div class="page-head__actions">
            @if($cfg['facilityPicker'])
                <label class="inline-pick">
                    <span>Select Facility</span>
                    <select class="select" data-org-facility aria-label="Select facility"></select>
                </label>
            @endif
            @if(! empty($cfg['buildingPicker']))
                <label class="inline-pick">
                    <span>Select Building</span>
                    <select class="select" data-org-building aria-label="Select building"></select>
                </label>
            @endif
            @if($canManage && ! empty($cfg['add']) && ($page !== 'facilities' || auth()->user()->canAccessAllFacilities()))
                @if(! empty($cfg['addUrl']))
                    <a class="btn btn--primary btn--sm btn--auto" href="{{ $cfg['addUrl'] }}">+ {{ $cfg['add'] }}</a>
                @else
                    <button type="button" class="btn btn--primary btn--sm btn--auto" data-org-add>+ {{ $cfg['add'] }}</button>
                @endif
            @endif
        </div>
    </div>

    <div class="card org" data-org-page data-config='@json($cfg)' data-can-manage="{{ $canManage ? '1' : '0' }}"
         data-selected-facility="{{ \App\Services\FacilityContext::selectedId(auth()->user()) }}">
        <div class="org__toolbar">
            @if(! empty($cfg['search']))
            <div class="input-wrap org__search">
                <x-icon name="search" />
                <input type="search" placeholder="{{ $cfg['search'] }}" aria-label="{{ $cfg['search'] }}" data-org-search autocomplete="off">
            </div>
            @else
            <div class="org__search"></div>
            @endif
            @foreach($cfg['filters'] as $filter)
                <select class="select org__filter" data-org-filter="{{ $filter['name'] }}" aria-label="{{ $filter['label'] }}">
                    <option value="">{{ $filter['label'] }}</option>
                    @foreach($filter['options'] as $value => $text)
                        <option value="{{ $value }}">{{ $text }}</option>
                    @endforeach
                </select>
            @endforeach
        </div>

        <div class="table-wrap">
            <table class="table" data-org-table>
                <thead>
                    <tr>
                        <th style="width:48px">#</th>
                        @foreach($cfg['columns'] as $col)<th>{{ $col[1] }}</th>@endforeach
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody data-org-rows>
                    <tr><td colspan="{{ count($cfg['columns']) + 2 }}"><div class="empty">Loading…</div></td></tr>
                </tbody>
            </table>
        </div>

        <div class="org__foot">
            <span data-org-summary class="muted">&nbsp;</span>
            <div class="pager" data-org-pager></div>
        </div>
    </div>

    <dialog class="dialog dialog--form" data-org-dialog aria-labelledby="org-dialog-title">
        <form method="dialog" data-org-form novalidate>
            <h3 id="org-dialog-title" data-org-dialog-title></h3>
            <div class="form-grid" data-org-fields></div>
            <div class="dialog__actions">
                <button type="button" class="btn btn--outline btn--sm" data-org-cancel>Cancel</button>
                <x-button class="btn--sm" style="width:auto" loading="Saving…" data-org-save>Save</x-button>
            </div>
        </form>
    </dialog>
</x-layouts.app>
