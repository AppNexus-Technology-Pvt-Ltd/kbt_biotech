<?php

namespace App\Http\Controllers;

use App\Models\InstallationJob;
use App\Services\InstallationReportService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Blade pages for Installation & Commissioning. Data is loaded through Api\InstallationController. */
class InstallationPageController extends Controller
{
    private function accessible(Request $r, InstallationJob $job): InstallationJob
    {
        abort_unless($r->user()->canAccessFacility($job->facility_id), 404);

        return $job;
    }

    public function dashboard(): View
    {
        return view('installations.dashboard');
    }

    public function jobs(): View
    {
        return view('installations.jobs');
    }

    public function create(): View
    {
        return view('installations.job', ['job' => null]);
    }

    public function show(Request $r, InstallationJob $job): View
    {
        return view('installations.job', ['job' => $this->accessible($r, $job)]);
    }

    public function reports(): View
    {
        return view('installations.reports', ['types' => InstallationReportService::TYPES]);
    }

    /* ------------------------------------------------------------- certificate */

    private function certificateData(InstallationJob $job): array
    {
        abort_unless($job->status === 'completed', 404);
        $job->load(['asset.manufacturerRecord:id,name', 'asset.department:id,name', 'department.hod:id,name,designation', 'facility', 'engineer:id,name,designation', 'handover.recipient:id,name,designation']);
        $a = $job->asset;
        $head = $job->handover?->recipient ?? $job->department?->hod;

        return ['job' => $job, 'asset' => $a, 'manufacturer' => $a->manufacturerRecord?->name ?? $a->manufacturer,
            'installedOn' => ($a->installed_at ?? $job->completed_at)->format('d-m-Y'), 'commissionedOn' => $job->completed_at->format('d-m-Y'),
            'engineer' => $job->engineer, 'head' => $head, 'facility' => $job->facility, 'handover' => $job->handover];
    }

    public function certificate(Request $r, InstallationJob $job): View
    {
        return view('installations.certificate', $this->certificateData($this->accessible($r, $job)) + ['pdf' => false]);
    }

    public function certificatePdf(Request $r, InstallationJob $job): Response
    {
        $html = view('installations.certificate', $this->certificateData($this->accessible($r, $job)) + ['pdf' => true])->render();
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="commissioning-certificate-'.$job->job_number.'.pdf"',
            'X-Content-Type-Options' => 'nosniff']);
    }

    /* --------------------------------------------------------------- downloads */

    public function signature(Request $r, InstallationJob $job): StreamedResponse
    {
        $this->accessible($r, $job);
        $h = $job->handover;
        abort_unless($h && Storage::disk('local')->exists($h->signature_path), 404);

        return Storage::disk('local')->response($h->signature_path, null, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function downloadDocument(Request $r, InstallationJob $job, int $document): StreamedResponse
    {
        $this->accessible($r, $job);
        $doc = $job->documents()->findOrFail($document);
        abort_unless(Storage::disk('local')->exists($doc->file_path), 404);

        return Storage::disk('local')->download($doc->file_path, $doc->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
