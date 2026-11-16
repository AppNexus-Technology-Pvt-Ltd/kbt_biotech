<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowException;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Department;
use App\Models\InstallationChecklistItem as Item;
use App\Models\InstallationJob;
use App\Models\Manufacturer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FacilityContext;
use App\Services\InstallationReportService;
use App\Services\InstallationWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Installation & commissioning API. Route middleware enforces installations.*; facility access is enforced here. */
class InstallationController extends Controller
{
    private const REL = ['asset:id,asset_tag,name,manufacturer,model,serial_number,facility_id', 'department:id,name', 'engineer:id,name,designation', 'facility:id,name'];

    public function __construct(private InstallationWorkflow $workflow) {}

    private function scoped(Request $r): Builder
    {
        return InstallationJob::query()->inFacilities(FacilityContext::ids($r->user()));
    }

    private function find(Request $r, int $id): InstallationJob
    {
        $job = InstallationJob::with(self::REL)->findOrFail($id);
        abort_unless($r->user()->canAccessFacility($job->facility_id), 404);

        return $job;
    }

    /* ---------------------------------------------------------------- dashboard */

    public function dashboard(Request $r): JsonResponse
    {
        $counted = fn () => $this->scoped($r)->whereIn('status', ['in_progress', 'pending_approval', 'completed']);
        $by = $counted()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        // last six months: planned (by planned date) vs completed (by completion date)
        $months = collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $from = $months->first();
        $planned = $this->scoped($r)->where('status', '!=', 'draft')->where('status', '!=', 'cancelled')->where('planned_date', '>=', $from->toDateString())->pluck('planned_date');
        $completed = $this->scoped($r)->where('status', 'completed')->where('completed_at', '>=', $from)->pluck('completed_at');
        $trend = $months->map(fn ($m) => ['label' => $m->format('M'), 'planned' => $planned->filter(fn ($d) => $d->format('Y-m') === $m->format('Y-m'))->count(),
            'completed' => $completed->filter(fn ($d) => $d->format('Y-m') === $m->format('Y-m'))->count()])->values();

        $depts = $counted()->leftJoin('departments as d', 'd.id', '=', 'installation_jobs.department_id')
            ->selectRaw("coalesce(d.name, 'No department') as label, count(*) as c")->groupBy('label')->orderByDesc('c')->get();
        $top = $depts->take(4)->map(fn ($x) => ['label' => $x->label, 'count' => (int) $x->c])->values();
        if ($depts->count() > 4) {
            $top->push(['label' => 'Others', 'count' => (int) $depts->slice(4)->sum('c')]);
        }

        $recent = $this->scoped($r)->with(['asset:id,asset_tag,name', 'department:id,name'])->latest('id')->limit(5)->get()->map(fn ($j) => $this->row($j));

        return response()->json(['success' => true, 'data' => [
            'total' => (int) $by->sum(), 'in_progress' => (int) ($by['in_progress'] ?? 0), 'pending_approval' => (int) ($by['pending_approval'] ?? 0), 'completed' => (int) ($by['completed'] ?? 0),
            'trend' => $trend, 'departments' => $top, 'recent' => $recent,
        ]]);
    }

    /* ------------------------------------------------------------------ options */

    public function options(Request $r): JsonResponse
    {
        $user = $r->user();
        $facilityId = $r->integer('facility_id') ?: null;
        if ($facilityId) {
            abort_unless($user->canAccessFacility($facilityId), 403, 'You do not have access to this facility.');
        }
        $scope = $facilityId ? [$facilityId] : $user->accessibleFacilities()->pluck('id')->all();

        $assets = Asset::query()->whereIn('facility_id', $scope)->live()
            ->whereDoesntHave('installationJobs', fn ($q) => $q->whereIn('status', InstallationJob::OPEN_STATUSES))
            ->when($r->filled('q'), function ($q) use ($r) {
                $like = '%'.addcslashes(trim((string) $r->input('q')), '%_\\').'%';
                $q->where(fn ($w) => $w->where('asset_tag', 'like', $like)->orWhere('name', 'like', $like));
            })
            ->with('department:id,name')->orderBy('asset_tag')->limit(30)->get()
            ->map(fn ($a) => ['id' => $a->id, 'facility_id' => $a->facility_id, 'asset_tag' => $a->asset_tag, 'name' => $a->name, 'department_id' => $a->department_id, 'department' => $a->department?->name]);

        $staff = $facilityId ? User::where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'designation'])
            ->filter(fn ($u) => $u->canAccessFacility($facilityId))->values() : collect();

        return response()->json(['success' => true, 'data' => [
            'assets' => $assets,
            'departments' => Department::whereIn('facility_id', $scope)->where('is_active', true)->orderBy('name')->get(['id', 'facility_id', 'name']),
            'engineers' => $staff->filter(fn ($u) => $u->hasPermission('installations.manage'))->values()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'designation' => $u->designation]),
            'staff' => $staff->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'designation' => $u->designation]),
            'vendors' => Manufacturer::where('is_active', true)->orderBy('name')->pluck('name'),
            'facilities' => $user->accessibleFacilities()->get(['id', 'name']),
        ]]);
    }

    /* --------------------------------------------------------------------- list */

    public function index(Request $r): JsonResponse
    {
        $q = $this->scoped($r)->with(['asset:id,asset_tag,name', 'department:id,name']);
        if ($r->filled('facility_id')) {
            abort_unless($r->user()->canAccessFacility($r->integer('facility_id')), 403, 'You do not have access to this facility.');
            $q->where('facility_id', $r->integer('facility_id'));
        }
        if ($term = trim((string) $r->input('q'))) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(fn ($w) => $w->where('job_number', 'like', $like)->orWhereHas('asset', fn ($a) => $a->where('asset_tag', 'like', $like)->orWhere('name', 'like', $like)));
        }
        if (array_key_exists($r->input('status'), InstallationJob::STATUSES)) {
            $q->where('status', $r->input('status'));
        }
        foreach (['department_id', 'engineer_id'] as $col) {
            if ($r->filled($col)) {
                $q->where($col, $r->integer($col));
            }
        }
        if (array_key_exists($r->input('type'), InstallationJob::TYPES)) {
            $q->where('type', $r->input('type'));
        }
        if (array_key_exists($r->input('priority'), InstallationJob::PRIORITIES)) {
            $q->where('priority', $r->input('priority'));
        }
        if ($r->filled('from')) {
            $q->whereDate('planned_date', '>=', $r->date('from'));
        }
        if ($r->filled('to')) {
            $q->whereDate('planned_date', '<=', $r->date('to'));
        }

        $page = $q->orderByDesc('id')->paginate(min(max($r->integer('per_page', 10), 5), 50));

        return response()->json([
            'success' => true, 'data' => $page->getCollection()->map(fn ($j) => $this->row($j))->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem() ?? 0, 'to' => $page->lastItem() ?? 0],
        ]);
    }

    private function row(InstallationJob $j): array
    {
        return ['id' => $j->id, 'job_number' => $j->job_number, 'asset_tag' => $j->asset?->asset_tag, 'equipment' => $j->asset?->name, 'department' => $j->department?->name,
            'planned_date' => $j->planned_date->format('d-m-Y'), 'status' => $j->status, 'status_label' => InstallationJob::STATUSES[$j->status], 'url' => route('installations.show', $j)];
    }

    /* --------------------------------------------------------------------- show */

    public function show(Request $r, int $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->detail($r, $this->find($r, $id))]);
    }

    private function detail(Request $r, InstallationJob $j): array
    {
        $u = $r->user();
        $j->loadMissing(['handover.recipient:id,name,designation', 'decider:id,name']);
        $manage = $u->can('installations.manage');
        $a = $j->asset;

        return $this->row($j->loadMissing(['asset', 'department'])) + [
            'facility_id' => $j->facility_id, 'facility' => $j->facility?->name, 'asset_id' => $j->asset_id, 'asset_name' => $a?->name,
            'asset_model' => collect([$a?->manufacturer, $a?->model])->filter()->implode(' '), 'department_id' => $j->department_id,
            'type' => $j->type, 'type_label' => InstallationJob::TYPES[$j->type], 'priority' => $j->priority, 'priority_label' => InstallationJob::PRIORITIES[$j->priority],
            'vendor_name' => $j->vendor_name, 'planned_date_iso' => $j->planned_date->format('Y-m-d'), 'estimated_hours' => $j->estimated_hours,
            'engineer_id' => $j->engineer_id, 'engineer' => $j->engineer?->name, 'remarks' => $j->remarks, 'decision_note' => $j->decision_note, 'decided_by' => $j->decider?->name,
            'completed_at' => $j->completed_at?->format('d-m-Y'), 'certificate_number' => $j->certificate_number,
            'sections' => $this->workflow->sectionSummary($j),
            'handover' => $j->handover ? [
                'date' => $j->handover->handover_date->format('d-m-Y'), 'to' => $j->handover->recipient?->name, 'to_designation' => $j->handover->recipient?->designation,
                'staff_trained' => $j->handover->staff_trained ?? [], 'notes' => $j->handover->notes, 'signature_url' => route('installations.signature', $j),
            ] : null,
            'certificate' => $j->status === 'completed' ? ['view' => route('installations.certificate', $j), 'pdf' => route('installations.certificate.pdf', $j)] : null,
            'can' => [
                'edit' => $manage && in_array($j->status, ['draft', 'in_progress'], true),
                'submit' => $manage && $j->status === 'draft',
                'request_approval' => $manage && $j->status === 'in_progress',
                'decide' => $u->can('installations.approve') && $j->status === 'pending_approval' && $j->approval_requested_by !== $u->id,
                'cancel' => $manage && $j->isOpen(),
                'delete' => $manage && $j->status === 'draft',
                'checklist' => $manage && $j->status === 'in_progress',
                'documents' => $manage && $j->status !== 'cancelled',
                'remove_documents' => $manage && $j->isOpen(),
                'handover' => $manage && $j->status === 'completed' && ! $j->handover,
            ],
        ];
    }

    /* -------------------------------------------------------------- create / edit */

    private function rules(?InstallationJob $job): array
    {
        return [
            'asset_id' => [$job ? 'sometimes' : 'required', 'integer', 'exists:assets,id'],
            'type' => ['required', Rule::in(array_keys(InstallationJob::TYPES))],
            'priority' => ['required', Rule::in(array_keys(InstallationJob::PRIORITIES))],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'vendor_name' => ['nullable', 'string', 'max:160'],
            'planned_date' => ['required', 'date_format:Y-m-d', ...($job && $job->planned_date ? [] : ['after_or_equal:today'])],
            'estimated_hours' => ['nullable', 'numeric', 'min:0.5', 'max:1000'],
            'engineer_id' => ['required', 'integer', 'exists:users,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate($this->rules(null) + ['submit' => ['sometimes', 'boolean']], ['planned_date.after_or_equal' => 'The planned date cannot be in the past.']);
        $asset = Asset::findOrFail($data['asset_id']);
        abort_unless($r->user()->canAccessFacility($asset->facility_id), 403, 'You do not have access to this facility.');
        $this->checkDepartment($data, $asset->facility_id);

        $job = $this->workflow->create($r->user(), $asset, $data, $r->boolean('submit'));

        return response()->json(['success' => true, 'message' => $job->status === 'draft' ? 'Draft saved.' : 'Installation job submitted.',
            'data' => ['id' => $job->id, 'url' => route('installations.show', $job)]], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $job = $this->find($r, $id);
        $data = $r->validate($this->rules($job), ['planned_date.after_or_equal' => 'The planned date cannot be in the past.']);
        $this->checkDepartment($data, $job->facility_id);
        $job = $this->workflow->update($r->user(), $job, $data);

        return response()->json(['success' => true, 'message' => 'Job updated.', 'data' => $this->detail($r, $job->fresh(self::REL))]);
    }

    private function checkDepartment(array $data, int $facilityId): void
    {
        if (! empty($data['department_id']) && Department::find($data['department_id'])->facility_id !== $facilityId) {
            throw new WorkflowException('The department belongs to a different facility.');
        }
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $this->workflow->delete($r->user(), $this->find($r, $id));

        return response()->json(['success' => true, 'message' => 'Draft deleted.']);
    }

    /* ---------------------------------------------------------------- transitions */

    private function transition(Request $r, int $id, callable $do, string $message): JsonResponse
    {
        $job = $do($this->find($r, $id));

        return response()->json(['success' => true, 'message' => $message, 'data' => $this->detail($r, $job->fresh(self::REL))]);
    }

    public function submit(Request $r, int $id): JsonResponse
    {
        return $this->transition($r, $id, fn ($j) => $this->workflow->submit($r->user(), $j), 'Installation job submitted. The checklists are ready.');
    }

    public function requestApproval(Request $r, int $id): JsonResponse
    {
        return $this->transition($r, $id, fn ($j) => $this->workflow->requestApproval($r->user(), $j), 'Sent for approval.');
    }

    public function approve(Request $r, int $id): JsonResponse
    {
        return $this->transition($r, $id, fn ($j) => $this->workflow->approve($r->user(), $j), 'Approved. The asset has been commissioned.');
    }

    public function reject(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['note' => ['required', 'string', 'max:255']], ['note.required' => 'Please give a reason for rejecting.']);

        return $this->transition($r, $id, fn ($j) => $this->workflow->reject($r->user(), $j, $d['note']), 'Returned to the engineer.');
    }

    public function cancel(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Please give a reason for cancelling.']);

        return $this->transition($r, $id, fn ($j) => $this->workflow->cancel($r->user(), $j, $d['reason']), 'Installation job cancelled.');
    }

    /* ------------------------------------------------------------------ checklists */

    public function checklist(Request $r, int $id): JsonResponse
    {
        $job = $this->find($r, $id);

        return response()->json(['success' => true, 'data' => [
            'items' => $job->items()->get()->map(fn ($i) => $this->item($i))->groupBy('section'),
            'sections' => $this->workflow->sectionSummary($job),
        ]]);
    }

    private function item(Item $i): array
    {
        return ['id' => $i->id, 'section' => $i->section, 'position' => $i->position, 'title' => $i->title, 'detail' => $i->detail, 'measured_value' => $i->measured_value,
            'status' => $i->status, 'remarks' => $i->remarks, 'is_custom' => $i->is_custom];
    }

    private function editableJob(Request $r, int $id): InstallationJob
    {
        $job = $this->find($r, $id);
        if ($job->status !== 'in_progress') {
            throw new WorkflowException('Checklists can only be changed while the job is in progress.', 409);
        }

        return $job;
    }

    public function updateItem(Request $r, int $id, int $itemId): JsonResponse
    {
        $job = $this->editableJob($r, $id);
        $item = $job->items()->findOrFail($itemId);
        $d = $r->validate([
            'status' => ['sometimes', Rule::in(Item::STATUSES[$item->section])],
            'measured_value' => ['sometimes', 'nullable', 'string', 'max:120'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title' => ['sometimes', 'string', 'max:255'], 'detail' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        if (! $item->is_custom) {
            unset($d['title'], $d['detail']);
        }
        if ($item->section !== 'acceptance') {
            unset($d['measured_value']);
        }
        $old = $item->only(array_keys($d));
        $item->update($d + ['updated_by' => $r->user()->id]);
        if (isset($d['status']) && $old['status'] !== $d['status']) {
            AuditLogger::log('installation.checklist_updated', $r->user(), $job, ['item' => $item->title, 'status' => $old['status']], ['item' => $item->title, 'status' => $d['status']]);
        }

        return response()->json(['success' => true, 'data' => ['item' => $this->item($item->fresh()), 'sections' => $this->workflow->sectionSummary($job)]]);
    }

    public function addItem(Request $r, int $id): JsonResponse
    {
        $job = $this->editableJob($r, $id);
        $d = $r->validate(['section' => ['required', Rule::in(array_keys(Item::SECTIONS))], 'title' => ['required', 'string', 'max:255'], 'detail' => ['nullable', 'string', 'max:255']]);
        $pos = (int) $job->items()->where('section', $d['section'])->max('position');
        $item = Item::create($d + ['installation_job_id' => $job->id, 'position' => $pos + 1, 'status' => 'pending', 'is_custom' => true, 'updated_by' => $r->user()->id]);
        AuditLogger::log('installation.checklist_item_added', $r->user(), $job, null, ['section' => $d['section'], 'title' => $d['title']]);

        return response()->json(['success' => true, 'message' => 'Item added.', 'data' => ['item' => $this->item($item), 'sections' => $this->workflow->sectionSummary($job)]], 201);
    }

    public function deleteItem(Request $r, int $id, int $itemId): JsonResponse
    {
        $job = $this->editableJob($r, $id);
        $item = $job->items()->findOrFail($itemId);
        if (! $item->is_custom) {
            throw new WorkflowException('Standard checklist items cannot be removed. Mark them N/A instead where that is allowed.');
        }
        $item->delete();
        AuditLogger::log('installation.checklist_item_removed', $r->user(), $job, ['title' => $item->title]);

        return response()->json(['success' => true, 'message' => 'Item removed.', 'data' => ['sections' => $this->workflow->sectionSummary($job)]]);
    }

    /* -------------------------------------------------------------------- documents */

    public function documents(Request $r, int $id): JsonResponse
    {
        $job = $this->find($r, $id);

        return response()->json(['success' => true, 'data' => $job->documents()->with('uploader:id,name')->latest()->get()->map(fn ($d) => $this->doc($job, $d))]);
    }

    public function uploadDocument(Request $r, int $id): JsonResponse
    {
        $job = $this->find($r, $id);
        if ($job->status === 'cancelled') {
            throw new WorkflowException('Documents cannot be added to a cancelled job.');
        }
        $d = $r->validate([
            'title' => ['required', 'string', 'max:160'],
            'category' => ['sometimes', Rule::in(['document', 'signed_certificate'])],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:10240'],
        ], ['file.max' => 'The file must be 10 MB or smaller.', 'file.mimes' => 'Allowed types: PDF, DOC, XLS, JPG, PNG.']);
        $category = $d['category'] ?? 'document';
        if ($category === 'signed_certificate' && $job->status !== 'completed') {
            throw new WorkflowException('A signed certificate can be uploaded after the job is completed.');
        }

        $file = $r->file('file');
        $doc = $job->documents()->create([
            'category' => $category, 'title' => $d['title'], 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => $file->getMimeType(),
            'size' => $file->getSize(), 'uploaded_by' => $r->user()->id,
            'file_path' => $file->storeAs('installation-documents/'.$job->id, Str::uuid().'.'.$file->guessExtension(), 'local'),
        ]);
        AuditLogger::log('installation.document_added', $r->user(), $job, null, ['title' => $doc->title, 'category' => $category]);

        return response()->json(['success' => true, 'message' => 'Document uploaded.', 'data' => $this->doc($job, $doc->load('uploader:id,name'))], 201);
    }

    public function deleteDocument(Request $r, int $id, int $docId): JsonResponse
    {
        $job = $this->find($r, $id);
        if (! $job->isOpen()) {
            throw new WorkflowException('Documents of a completed or cancelled job are kept as a record.');
        }
        $doc = $job->documents()->findOrFail($docId);
        Storage::disk('local')->delete($doc->file_path);
        $doc->delete();
        AuditLogger::log('installation.document_removed', $r->user(), $job, ['title' => $doc->title]);

        return response()->json(['success' => true, 'message' => 'Document removed.']);
    }

    private function doc(InstallationJob $job, $d): array
    {
        return ['id' => $d->id, 'title' => $d->title, 'name' => $d->original_name, 'category' => $d->category, 'type' => strtoupper(pathinfo($d->original_name, PATHINFO_EXTENSION)),
            'size' => $d->size, 'uploaded_by' => $d->uploader?->name, 'date' => $d->created_at?->format('d-m-Y'), 'url' => route('installations.documents.download', [$job->id, $d->id])];
    }

    /* ---------------------------------------------------------------------- handover */

    public function handover(Request $r, int $id): JsonResponse
    {
        $job = $this->find($r, $id);
        $d = $r->validate([
            'handover_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'handover_to' => ['required', 'integer', 'exists:users,id'],
            'staff_trained' => ['nullable', 'array', 'max:20'],
            'staff_trained.*.group' => ['required', 'string', 'max:60'],
            'staff_trained.*.count' => ['required', 'integer', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'signature' => ['required', 'string', 'max:450000'],
        ], ['signature.required' => 'The department sign-off is required.', 'handover_date.before_or_equal' => 'The handover date cannot be in the future.']);

        $this->workflow->handover($r->user(), $job, $d);

        return response()->json(['success' => true, 'message' => 'Handover recorded.', 'data' => $this->detail($r, $job->fresh(self::REL))], 201);
    }

    /* ------------------------------------------------------------------------ reports */

    public function report(Request $r, string $type, InstallationReportService $reports): JsonResponse|StreamedResponse
    {
        $res = $reports->build($type, $r->user(), $r);
        AuditLogger::log('installation.report_generated', $r->user(), null, null, ['report' => $type, 'format' => $r->input('format', 'json')]);

        if ($r->input('format') === 'csv') {
            return response()->streamDownload(function () use ($res) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array_values($res['columns']));
                foreach ($res['rows'] as $row) {
                    fputcsv($out, array_map(fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v,
                        array_map(fn ($k) => $row[$k] ?? null, array_keys($res['columns']))));
                }
                fclose($out);
            }, 'installation-'.$type.'-report-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json(['success' => true, 'data' => [
            'title' => $res['title'], 'columns' => collect($res['columns'])->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'rows' => $res['rows']->take(200)->values(), 'total' => $res['rows']->count(), 'shown' => min(200, $res['rows']->count()), 'summary' => $res['summary'],
        ]]);
    }
}
