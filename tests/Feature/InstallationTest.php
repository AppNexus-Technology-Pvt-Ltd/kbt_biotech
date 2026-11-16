<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Department;
use App\Models\Facility;
use App\Models\InstallationChecklistItem as Item;
use App\Models\InstallationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class InstallationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private Facility $fa;

    private Facility $fb;

    private Department $dept;

    private Asset $asset;

    private User $engineer;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedRbac();
        $this->fa = Facility::create(['name' => 'City General', 'code' => 'H001', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'is_active' => true]);
        $this->fb = Facility::create(['name' => 'Other Hospital', 'code' => 'H002', 'is_active' => true]);
        $this->dept = Department::create(['facility_id' => $this->fa->id, 'code' => 'D1', 'name' => 'ICU', 'type' => 'clinical']);
        $this->asset = Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0001', 'name' => 'Ventilator', 'manufacturer' => 'GE', 'model' => 'CareVent', 'serial_number' => 'SN1',
            'department_id' => $this->dept->id, 'status' => 'under_maintenance']);
        $this->engineer = $this->makeUser('biomedical_engineer', [$this->fa->id], ['name' => 'Dr. Rajesh', 'designation' => 'Biomedical Engineer']);
        $this->approver = $this->makeUser('facility_manager', [$this->fa->id], ['name' => 'Manager']);
    }

    private function payload(array $over = []): array
    {
        return array_merge(['asset_id' => $this->asset->id, 'type' => 'new_installation', 'priority' => 'normal', 'planned_date' => now()->addDay()->format('Y-m-d'),
            'engineer_id' => $this->engineer->id, 'estimated_hours' => 4], $over);
    }

    private function newJob(array $over = [], bool $submit = true): int
    {
        $this->actingAs($this->engineer);

        return $this->postJson('/api/installations', $this->payload($over) + ['submit' => $submit])->assertCreated()->json('data.id');
    }

    /** Mark every checklist item of a job complete. */
    private function completeChecklists(int $id): void
    {
        $this->actingAs($this->engineer);
        foreach (Item::where('installation_job_id', $id)->get() as $item) {
            $status = $item->section === 'installation' ? 'completed' : 'pass';
            $this->putJson("/api/installations/$id/checklist/{$item->id}", ['status' => $status])->assertOk();
        }
    }

    private function completedJob(): int
    {
        $id = $this->newJob();
        $this->completeChecklists($id);
        $this->postJson("/api/installations/$id/request-approval")->assertOk();
        $this->actingAs($this->approver)->postJson("/api/installations/$id/approve")->assertOk();

        return $id;
    }

    private function signature(): string
    {
        return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".str_repeat('x', 700));
    }

    /* ------------------------------------------------------------------ pages */

    public function test_pages_render_and_are_permission_gated(): void
    {
        $id = $this->newJob();
        $this->actingAs($this->approver);
        foreach (['/installations', '/installations/jobs', '/installations/create', '/installations/reports', "/installations/$id"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/installations/jobs')->assertSee('New Installation');

        $this->actingAs($this->makeUser('technician', [$this->fa->id]));   // no installations.view
        $this->get('/installations')->assertForbidden();
        $this->getJson('/api/installations')->assertForbidden();

        $this->actingAs($this->makeUser('auditor'));                       // view only
        $this->get('/installations/jobs')->assertOk();
        $this->get('/installations/create')->assertForbidden();
        $this->postJson('/api/installations', $this->payload())->assertForbidden();
        $this->postJson("/api/installations/$id/approve")->assertForbidden();
    }

    /* ----------------------------------------------------------------- create */

    public function test_create_draft_then_submit_creates_the_standard_checklists(): void
    {
        $id = $this->newJob(submit: false);
        $job = InstallationJob::find($id);
        $this->assertSame('draft', $job->status);
        $this->assertSame('INST-0001', $job->job_number);
        $this->assertSame($this->dept->id, $job->department_id);          // defaults to the asset's department
        $this->assertSame(0, $job->items()->count());

        // checklists are not available on a draft
        $this->putJson("/api/installations/$id/checklist/1", ['status' => 'pass'])->assertStatus(409);

        $this->postJson("/api/installations/$id/submit")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertSame([7, 7, 6], [$job->items()->where('section', 'site_readiness')->count(), $job->items()->where('section', 'installation')->count(), $job->items()->where('section', 'acceptance')->count()]);
        $this->assertSame('Space Availability', $job->items()->where('section', 'site_readiness')->first()->title);
        $this->postJson("/api/installations/$id/submit")->assertStatus(409);

        $second = $this->newJob(['asset_id' => Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0002', 'name' => 'Monitor'])->id]);
        $this->assertSame('INST-0002', InstallationJob::find($second)->job_number);
    }

    public function test_create_validation_and_business_rules(): void
    {
        $this->actingAs($this->engineer);
        $this->postJson('/api/installations', [])->assertStatus(422)->assertJsonValidationErrors(['asset_id', 'type', 'priority', 'planned_date', 'engineer_id']);
        $this->postJson('/api/installations', $this->payload(['planned_date' => '2020-01-01']))->assertStatus(422)->assertJsonValidationErrors('planned_date');
        $this->postJson('/api/installations', $this->payload(['type' => 'x', 'priority' => 'x', 'estimated_hours' => -1]))->assertStatus(422)->assertJsonValidationErrors(['type', 'priority', 'estimated_hours']);

        // engineer must be active staff of the facility with installations.manage
        $outsider = $this->makeUser('biomedical_engineer', [$this->fb->id]);
        $viewer = $this->makeUser('auditor', [$this->fa->id]);
        $this->postJson('/api/installations', $this->payload(['engineer_id' => $outsider->id]))->assertStatus(422);
        $this->postJson('/api/installations', $this->payload(['engineer_id' => $viewer->id]))->assertStatus(422);
        $this->postJson('/api/installations', $this->payload(['department_id' => Department::create(['facility_id' => $this->fb->id, 'code' => 'D9', 'name' => 'X', 'type' => 'clinical'])->id]))->assertStatus(422);

        // one open job per asset; closed assets cannot be installed
        $this->postJson('/api/installations', $this->payload())->assertCreated();
        $this->postJson('/api/installations', $this->payload())->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'already has an open installation job'));
        $retired = Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0009', 'name' => 'Old', 'status' => 'retired']);
        $this->postJson('/api/installations', $this->payload(['asset_id' => $retired->id]))->assertStatus(422);
    }

    public function test_facility_scoping(): void
    {
        $id = $this->newJob();
        $foreign = $this->makeUser('facility_manager', [$this->fb->id]);
        $this->actingAs($foreign);
        $this->getJson("/api/installations/$id")->assertNotFound();
        $this->get("/installations/$id")->assertNotFound();
        $this->postJson("/api/installations/$id/cancel", ['reason' => 'x'])->assertNotFound();
        $this->postJson('/api/installations', $this->payload())->assertForbidden();
        $this->getJson('/api/installations')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/installations/options?facility_id='.$this->fa->id)->assertForbidden();
    }

    public function test_update_draft_and_in_progress_rules(): void
    {
        $id = $this->newJob(submit: false);
        $this->actingAs($this->engineer);
        $other = Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0003', 'name' => 'Pump']);
        $this->putJson("/api/installations/$id", $this->payload(['asset_id' => $other->id, 'priority' => 'high']))->assertOk()->assertJsonPath('data.asset_tag', 'BIO-0003')->assertJsonPath('data.priority', 'high');

        $this->postJson("/api/installations/$id/submit")->assertOk();
        $this->putJson("/api/installations/$id", $this->payload(['asset_id' => $this->asset->id]))->assertStatus(422);          // asset locked after submit
        $this->putJson("/api/installations/$id", $this->payload(['asset_id' => $other->id, 'remarks' => 'Updated']))->assertOk()->assertJsonPath('data.remarks', 'Updated');
        $this->deleteJson("/api/installations/$id")->assertStatus(422);                                                       // only drafts can be deleted
    }

    public function test_draft_can_be_deleted_and_audited(): void
    {
        $id = $this->newJob(submit: false);
        $this->deleteJson("/api/installations/$id")->assertOk();
        $this->assertDatabaseMissing('installation_jobs', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'installation.deleted']);
    }

    /* --------------------------------------------------------------- checklists */

    public function test_checklist_updates_are_validated_per_section_and_summarised(): void
    {
        $id = $this->newJob();
        $site = Item::where('installation_job_id', $id)->where('section', 'site_readiness')->first();
        $task = Item::where('installation_job_id', $id)->where('section', 'installation')->first();
        $test = Item::where('installation_job_id', $id)->where('section', 'acceptance')->first();

        $this->putJson("/api/installations/$id/checklist/{$site->id}", ['status' => 'completed'])->assertStatus(422)->assertJsonValidationErrors('status');   // wrong vocabulary
        $this->putJson("/api/installations/$id/checklist/{$task->id}", ['status' => 'pass'])->assertStatus(422);
        $this->putJson("/api/installations/$id/checklist/{$site->id}", ['status' => 'na', 'remarks' => 'Not needed'])->assertOk();
        $this->putJson("/api/installations/$id/checklist/{$task->id}", ['status' => 'completed'])->assertOk()->assertJsonPath('data.sections.installation.percent', 14);
        $res = $this->putJson("/api/installations/$id/checklist/{$test->id}", ['status' => 'fail', 'measured_value' => '90 sec'])->assertOk();
        $this->assertSame('failed', $res->json('data.sections.acceptance.state'));
        $this->assertSame('90 sec', $test->fresh()->measured_value);

        // measured value is ignored outside acceptance tests; standard items keep their title
        $this->putJson("/api/installations/$id/checklist/{$site->id}", ['measured_value' => 'x', 'title' => 'Hacked'])->assertOk();
        $this->assertNull($site->fresh()->measured_value);
        $this->assertSame('Space Availability', $site->fresh()->title);
    }

    public function test_custom_items_can_be_added_and_removed_but_standard_ones_cannot(): void
    {
        $id = $this->newJob();
        $this->postJson("/api/installations/$id/checklist", ['section' => 'site_readiness', 'title' => ''])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->postJson("/api/installations/$id/checklist", ['section' => 'bogus', 'title' => 'x'])->assertStatus(422);
        $custom = $this->postJson("/api/installations/$id/checklist", ['section' => 'site_readiness', 'title' => 'Fire exit clear', 'detail' => 'Check'])->assertCreated()->json('data.item');
        $this->assertSame(8, $custom['position']);
        $this->assertTrue($custom['is_custom']);
        $this->getJson("/api/installations/$id/checklist")->assertJsonCount(8, 'data.items.site_readiness');

        $standard = Item::where('installation_job_id', $id)->where('is_custom', false)->first();
        $this->deleteJson("/api/installations/$id/checklist/{$standard->id}")->assertStatus(422);
        $this->deleteJson("/api/installations/$id/checklist/{$custom['id']}")->assertOk();
        $this->assertDatabaseMissing('installation_checklist_items', ['id' => $custom['id']]);
    }

    /* --------------------------------------------------------- approval workflow */

    public function test_cannot_request_approval_until_every_checklist_is_complete(): void
    {
        $id = $this->newJob();
        $this->postJson("/api/installations/$id/request-approval")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Site Readiness: 7 of 7'));

        $this->completeChecklists($id);
        $test = Item::where('installation_job_id', $id)->where('section', 'acceptance')->first();
        $this->putJson("/api/installations/$id/checklist/{$test->id}", ['status' => 'fail'])->assertOk();
        $this->postJson("/api/installations/$id/request-approval")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '1 failed'));

        $this->putJson("/api/installations/$id/checklist/{$test->id}", ['status' => 'pass'])->assertOk();
        $this->postJson("/api/installations/$id/request-approval")->assertOk()->assertJsonPath('data.status', 'pending_approval');

        // locked while waiting
        $item = Item::where('installation_job_id', $id)->first();
        $this->putJson("/api/installations/$id/checklist/{$item->id}", ['status' => 'pending'])->assertStatus(409);
        $this->putJson("/api/installations/$id", $this->payload(['asset_id' => $this->asset->id]))->assertStatus(422);
    }

    public function test_approval_commissions_the_asset_and_issues_a_certificate(): void
    {
        $id = $this->newJob();
        $this->completeChecklists($id);
        $this->postJson("/api/installations/$id/request-approval")->assertOk();

        // the requester cannot approve, even with the permission; users without it cannot either
        $this->actingAs($this->engineer)->postJson("/api/installations/$id/approve")->assertForbidden();
        $this->actingAs($this->makeUser('facility_manager', [$this->fa->id]))->assertAuthenticated();
        $self = $this->makeUser('admin');
        $job = InstallationJob::find($id);
        $job->update(['approval_requested_by' => $self->id]);
        $this->actingAs($self)->postJson("/api/installations/$id/approve")->assertStatus(403)->assertJsonPath('message', 'You cannot approve or reject a job you sent for approval.');
        $job->update(['approval_requested_by' => $this->engineer->id]);

        $this->actingAs($this->approver)->postJson("/api/installations/$id/approve")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.certificate_number', 'CC-INST-0001');

        $asset = $this->asset->fresh();
        $this->assertSame(now()->toDateString(), $asset->installed_at->toDateString());
        $this->assertSame('operational', $asset->status);                                   // was under maintenance
        $this->assertDatabaseHas('asset_status_changes', ['asset_id' => $asset->id, 'from_status' => 'under_maintenance', 'to_status' => 'operational']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'installation.approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'asset.commissioned', 'subject_id' => $asset->id]);
        $this->postJson("/api/installations/$id/approve")->assertStatus(409);
        $this->assertSame(0, InstallationJob::where('asset_id', $asset->id)->whereIn('status', InstallationJob::OPEN_STATUSES)->count());
    }

    public function test_relocation_keeps_the_original_installed_date(): void
    {
        $this->asset->update(['installed_at' => '2020-05-01', 'status' => 'operational']);
        $id = $this->newJob(['type' => 'relocation']);
        $this->completeChecklists($id);
        $this->postJson("/api/installations/$id/request-approval")->assertOk();
        $this->actingAs($this->approver)->postJson("/api/installations/$id/approve")->assertOk();
        $this->assertSame('2020-05-01', $this->asset->fresh()->installed_at->toDateString());
    }

    public function test_reject_needs_a_reason_and_returns_the_job_to_the_engineer(): void
    {
        $id = $this->newJob();
        $this->completeChecklists($id);
        $this->postJson("/api/installations/$id/request-approval")->assertOk();

        $this->actingAs($this->approver);
        $this->postJson("/api/installations/$id/reject", [])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/installations/$id/reject", ['note' => 'Earthing reading missing'])->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.decision_note', 'Earthing reading missing');
        $this->assertSame('under_maintenance', $this->asset->fresh()->status);               // untouched

        $this->actingAs($this->engineer);
        $item = Item::where('installation_job_id', $id)->first();
        $this->putJson("/api/installations/$id/checklist/{$item->id}", ['remarks' => 'Re-measured'])->assertOk();   // editable again
        $this->postJson("/api/installations/$id/request-approval")->assertOk();
    }

    public function test_cancel_needs_a_reason_and_frees_the_asset(): void
    {
        $id = $this->newJob();
        $this->actingAs($this->engineer);
        $this->postJson("/api/installations/$id/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/installations/$id/cancel", ['reason' => 'Site not ready'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/installations/$id/cancel", ['reason' => 'again'])->assertStatus(409);
        $this->postJson('/api/installations', $this->payload())->assertCreated();            // asset available again
    }

    /* -------------------------------------------------------------- documents */

    public function test_documents_upload_download_and_rules(): void
    {
        $id = $this->newJob();
        $doc = $this->post("/api/installations/$id/documents", ['title' => 'Installation report', 'file' => UploadedFile::fake()->create('r.pdf', 80, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $docId = $doc->json('data.id');
        $this->assertSame('PDF', $doc->json('data.type'));
        $this->get("/installations/$id/documents/$docId")->assertOk();
        $this->post("/api/installations/$id/documents", ['title' => 'Bad', 'file' => UploadedFile::fake()->create('x.exe', 5)], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post("/api/installations/$id/documents", ['title' => 'Early', 'category' => 'signed_certificate', 'file' => UploadedFile::fake()->create('s.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);

        $this->actingAs($this->makeUser('facility_manager', [$this->fb->id]))->get("/installations/$id/documents/$docId")->assertNotFound();
        $this->actingAs($this->engineer)->deleteJson("/api/installations/$id/documents/$docId")->assertOk();
    }

    /* ----------------------------------------------------- certificate + handover */

    public function test_certificate_exists_only_for_completed_jobs_and_downloads_as_pdf(): void
    {
        $open = $this->newJob();
        $this->get("/installations/$open/certificate")->assertNotFound();
        $this->get("/installations/$open/certificate.pdf")->assertNotFound();
        $this->assertNull($this->getJson("/api/installations/$open")->json('data.certificate'));

        $this->asset->update(['status' => 'operational']);
        InstallationJob::find($open)->update(['status' => 'cancelled']);
        $asset2 = Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0050', 'name' => 'Autoclave', 'manufacturer' => 'Getinge', 'serial_number' => 'A1']);
        $id = $this->newJob(['asset_id' => $asset2->id]);
        $this->completeChecklists($id);
        $this->postJson("/api/installations/$id/request-approval")->assertOk();
        $this->actingAs($this->approver)->postJson("/api/installations/$id/approve")->assertOk();

        $this->get("/installations/$id/certificate")->assertOk()->assertSee('COMMISSIONING CERTIFICATE', false)->assertSee('BIO-0050')->assertSee('Autoclave')->assertSee('Chennai');
        $pdf = $this->get("/installations/$id/certificate.pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('content-type'));
        $this->actingAs($this->makeUser('facility_manager', [$this->fb->id]))->get("/installations/$id/certificate.pdf")->assertNotFound();

        // signed copy can now be uploaded
        $this->actingAs($this->engineer)->post("/api/installations/$id/documents", ['title' => 'Signed', 'category' => 'signed_certificate', 'file' => UploadedFile::fake()->create('s.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        // records of a completed job are kept
        $docId = \App\Models\InstallationDocument::first()->id;
        $this->deleteJson("/api/installations/$id/documents/$docId")->assertStatus(422);
    }

    public function test_handover_rules_signature_and_one_time_recording(): void
    {
        $open = $this->newJob();
        $headUser = $this->makeUser('facility_manager', [$this->fa->id], ['name' => 'Dr. Meera Nair', 'designation' => 'Department Head']);
        $body = ['handover_date' => now()->format('Y-m-d'), 'handover_to' => $headUser->id, 'staff_trained' => [['group' => 'Nurse Team', 'count' => 5], ['group' => 'Technician', 'count' => 2]],
            'notes' => 'Training completed', 'signature' => $this->signature()];

        $this->postJson("/api/installations/$open/handover", $body)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'after the installation has been approved'));
        InstallationJob::find($open)->update(['status' => 'cancelled']);

        $this->asset->update(['status' => 'operational']);
        $id = $this->completedJob2();
        $this->actingAs($this->engineer);
        $this->postJson("/api/installations/$id/handover", [])->assertStatus(422)->assertJsonValidationErrors(['handover_date', 'handover_to', 'signature']);
        $this->postJson("/api/installations/$id/handover", ['signature' => 'not-an-image'] + $body)->assertStatus(422);
        $this->postJson("/api/installations/$id/handover", ['signature' => 'data:image/png;base64,'.base64_encode('<?php evil')] + $body)->assertStatus(422);
        $this->postJson("/api/installations/$id/handover", ['signature' => 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".'tiny')] + $body)->assertStatus(422)->assertJsonPath('message', 'Please sign in the box before saving.');
        $this->postJson("/api/installations/$id/handover", ['handover_date' => now()->addDay()->format('Y-m-d')] + $body)->assertStatus(422)->assertJsonValidationErrors('handover_date');
        $this->postJson("/api/installations/$id/handover", ['handover_date' => now()->subDays(3)->format('Y-m-d')] + $body)->assertStatus(422);   // before commissioning
        $this->postJson("/api/installations/$id/handover", ['handover_to' => $this->makeUser('facility_manager', [$this->fb->id])->id] + $body)->assertStatus(422);
        $this->postJson("/api/installations/$id/handover", ['staff_trained' => [['group' => 'X', 'count' => 0]]] + $body)->assertStatus(422)->assertJsonValidationErrors('staff_trained.0.count');

        $res = $this->postJson("/api/installations/$id/handover", $body)->assertCreated();
        $this->assertSame('Dr. Meera Nair', $res->json('data.handover.to'));
        $this->assertSame(2, count($res->json('data.handover.staff_trained')));
        $this->assertFalse($res->json('data.can.handover'));
        $this->postJson("/api/installations/$id/handover", $body)->assertStatus(409);

        $sig = $this->get("/installations/$id/signature")->assertOk();
        $this->assertStringContainsString('image/png', $sig->headers->get('content-type'));
        $this->actingAs($this->makeUser('facility_manager', [$this->fb->id]))->get("/installations/$id/signature")->assertNotFound();
        // the certificate now names the department head
        $this->actingAs($this->engineer)->get("/installations/$id/certificate")->assertSee('Dr. Meera Nair')->assertSee('Department Head');
    }

    private function completedJob2(): int
    {
        $asset = Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0077', 'name' => 'Defibrillator', 'manufacturer' => 'Philips', 'serial_number' => 'D1']);

        return tap($this->newJob(['asset_id' => $asset->id]), function ($id) {
            $this->completeChecklists($id);
            $this->postJson("/api/installations/$id/request-approval")->assertOk();
            $this->actingAs($this->approver)->postJson("/api/installations/$id/approve")->assertOk();
        });
    }

    /* ----------------------------------------------- dashboard, list, reports */

    public function test_dashboard_list_filters_and_options(): void
    {
        $a = $this->completedJob();                                           // completed
        $b = $this->newJob(['asset_id' => Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0004', 'name' => 'Pump', 'department_id' => $this->dept->id])->id]);   // in progress
        $this->newJob(['asset_id' => Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0005', 'name' => 'Draftee'])->id], false);   // draft: not counted

        $this->actingAs($this->approver);
        $d = $this->getJson('/api/installations/dashboard')->assertOk()->json('data');
        $this->assertSame(2, $d['total']);
        $this->assertSame(1, $d['completed']);
        $this->assertSame(1, $d['in_progress']);
        $this->assertSame(0, $d['pending_approval']);
        $this->assertCount(6, $d['trend']);
        $this->assertSame(1, $d['trend'][5]['completed']);
        $this->assertSame('ICU', $d['departments'][0]['label']);
        $this->assertCount(3, $d['recent']);

        $this->getJson('/api/installations')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/installations?status=draft')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/installations?q=Pump')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/installations?q=INST-0001')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/installations?type=relocation')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/installations?engineer_id='.$this->engineer->id)->assertJsonPath('meta.total', 3);
        $this->getJson('/api/installations?from='.now()->addDays(30)->format('Y-m-d'))->assertJsonPath('meta.total', 0);

        $o = $this->getJson('/api/installations/options?facility_id='.$this->fa->id)->assertOk()->json('data');
        $assetTags = array_column($o['assets'], 'asset_tag');
        $this->assertContains('BIO-0001', $assetTags);                         // completed job: asset can be relocated / recommissioned
        $this->assertNotContains('BIO-0004', $assetTags);                      // has an open job
        $this->assertContains($this->engineer->id, array_column($o['engineers'], 'id'));
        $this->assertNotContains($this->makeUser('auditor', [$this->fa->id])->id, array_column($o['engineers'], 'id'));
        $this->assertSame($b, $b);
        $this->assertNotNull($a);
    }

    public function test_reports(): void
    {
        $this->completedJob();
        $b = $this->newJob(['asset_id' => Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0004', 'name' => 'Pump', 'purchase_cost' => 1000, 'department_id' => $this->dept->id])->id]);
        $this->completeChecklists($b);
        $this->postJson("/api/installations/$b/request-approval")->assertOk();
        $this->asset->update(['purchase_cost' => 250000]);
        $this->actingAs($this->approver);

        $this->assertSame(2, $this->getJson('/api/installations/reports/register')->json('data.total'));
        $pending = $this->getJson('/api/installations/reports/pending')->json('data');
        $this->assertSame(['INST-0002'], array_column($pending['rows'], 'job'));
        $dept = $this->getJson('/api/installations/reports/department')->json('data');
        $this->assertSame(2, $dept['rows'][0]['total']);
        $this->assertSame(1, $dept['rows'][0]['completed']);
        $cost = $this->getJson('/api/installations/reports/cost')->json('data');
        $this->assertSame('250000.00', $cost['summary']['total']);
        $sum = $this->getJson('/api/installations/reports/summary')->json('data');
        $this->assertSame(1, collect($sum['rows'])->firstWhere('status', 'Completed')['jobs']);

        $csv = $this->get('/api/installations/reports/register?format=csv')->assertOk()->streamedContent();
        $this->assertSame(3, count(array_filter(explode("\n", trim($csv)))));
        $this->getJson('/api/installations/reports/register?from=2030-02-01&to=2030-01-01')->assertStatus(422);
        $this->getJson('/api/installations/reports/nonsense')->assertNotFound();
        $this->getJson('/api/installations/reports/register?facility_id='.$this->fb->id)->assertForbidden();
    }
}
