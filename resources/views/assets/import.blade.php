<x-layouts.app title="Bulk Import Assets">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> Bulk Import</nav>
    <h1 class="page-title">Bulk Import Assets</h1>
    <p class="page-sub">Import many assets from an Excel (.xlsx) or CSV file.</p>

    <div class="card card__body import" data-import data-validate="{{ route('api.assets.import.validate') }}" data-confirm="{{ route('api.assets.import.confirm') }}" data-done="{{ route('assets.register') }}">
        <ol class="steps" aria-label="Import steps">
            <li class="is-current" data-step="1"><b>1</b> Upload</li>
            <li data-step="2"><b>2</b> Validate</li>
            <li data-step="3"><b>3</b> Confirm</li>
        </ol>

        <section data-pane="1">
            <label class="dropzone" data-drop>
                <input type="file" accept=".xlsx,.csv" hidden data-file>
                <strong>Drag &amp; drop your file here</strong>
                <span class="muted">or</span>
                <span class="btn btn--outline btn--sm btn--auto">Browse File</span>
                <small class="muted">Supported formats: .xlsx, .csv · up to 1,000 rows · 5 MB</small>
            </label>
            <p class="muted" data-file-name style="margin-top:10px"></p>
            <div class="import__help">
                <a class="link" href="{{ route('api.assets.import.template') }}">Download Excel Template</a>
                <p class="muted">Use the codes from Organization setup (facility, department, location, category and manufacturer codes). Leave Asset ID blank to auto-generate it. Dates use YYYY-MM-DD. The whole file is imported or nothing is: fix any errors and upload it again.</p>
            </div>
            <div class="form-actions"><button type="button" class="btn btn--primary btn--sm btn--auto" data-upload disabled>Validate file</button></div>
        </section>

        <section data-pane="2" hidden>
            <div data-summary></div>
            <div class="table-wrap" data-errors-wrap hidden>
                <table class="table"><thead><tr><th style="width:80px">Row</th><th>Problem</th></tr></thead><tbody data-errors></tbody></table>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn--outline btn--sm btn--auto" data-reset>Upload a different file</button>
                <button type="button" class="btn btn--primary btn--sm btn--auto" data-go hidden>Import assets</button>
            </div>
        </section>
    </div>
</x-layouts.app>
