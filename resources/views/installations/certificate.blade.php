@if($pdf)
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Commissioning Certificate {{ $job->job_number }}</title><style>@page { margin: 18mm; } body { margin: 0; }</style></head>
<body>@include('installations._certificate')</body></html>
@else
<x-layouts.app title="Commissioning Certificate">
    <nav class="crumbs" aria-label="Breadcrumb">Installations <span>&rsaquo;</span> <a href="{{ route('installations.show', $job) }}">{{ $job->job_number }}</a> <span>&rsaquo;</span> Certificate</nav>
    <div class="page-head">
        <div><h1 class="page-title">Commissioning Certificate</h1><p class="page-sub">Generate, print and store the commissioning certificate.</p></div>
        <div class="page-head__actions">
            <a class="btn btn--primary btn--sm btn--auto" href="{{ route('installations.certificate.pdf', $job) }}">Download PDF</a>
            <button type="button" class="btn btn--outline btn--sm btn--auto" onclick="window.print()">Print Certificate</button>
            <a class="btn btn--outline btn--sm btn--auto" href="{{ route('installations.show', $job) }}">Back to job</a>
        </div>
    </div>
    <div class="cert-wrap">@include('installations._certificate')</div>
</x-layouts.app>
@endif
