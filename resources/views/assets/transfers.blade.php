<x-layouts.app title="Asset Transfers">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> Asset Transfers</nav>
    <h1 class="page-title">Asset Transfers</h1>
    <p class="page-sub">Transfer requests between facilities, departments and locations.</p>
    <div class="card org" data-wf="transfers" data-url="{{ route('api.assets.transfers') }}" data-base="{{ url('/api/assets/transfers') }}">
        <div class="org__toolbar">
            <div class="input-wrap org__search"><x-icon name="search" /><input type="search" placeholder="Search by asset ID or name..." aria-label="Search transfers" data-f="q" autocomplete="off"></div>
            <select class="select org__filter" data-f="status" aria-label="Status">
                <option value="">All Status</option>
                @foreach(\App\Models\AssetTransfer::STATUSES as $v => $t)<option value="{{ $v }}">{{ $t }}</option>@endforeach
            </select>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Asset</th><th>From</th><th>To</th><th>Requested</th><th>Reason</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
            <tbody data-rows><tr><td colspan="7"><div class="empty">Loading…</div></td></tr></tbody>
        </table></div>
        <div class="org__foot"><span data-summary class="muted">&nbsp;</span><div class="pager" data-pager></div></div>
    </div>
    <dialog class="dialog" data-reject-dialog>
        <form method="dialog" data-reject-form novalidate>
            <h3>Reject transfer</h3><p>The requester will see this reason.</p>
            <div class="input-wrap" data-field><input name="note" maxlength="255" placeholder="Reason for rejection"></div>
            <div class="field__error" data-error-for="note" role="alert"></div>
            <div class="dialog__actions"><button type="button" class="btn btn--outline btn--sm" data-reject-cancel>Cancel</button><button type="submit" class="btn btn--danger btn--sm">Reject</button></div>
        </form>
    </dialog>
</x-layouts.app>
