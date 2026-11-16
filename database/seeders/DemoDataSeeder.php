<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Building;
use App\Models\Department;
use App\Models\EquipmentCategory;
use App\Models\Facility;
use App\Models\InstallationChecklistItem as Item;
use App\Models\InstallationJob;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\InstallationWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * OPTIONAL sample data so every screen has something to show:
 *     php artisan db:seed --class=DemoDataSeeder
 * It goes through the real services (installation workflow etc.), is safe to re-run, and must NOT be used in production.
 * Demo logins: engineer / manager / technician  .test addresses, password "Demo@12345".
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'Demo@12345';

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        DB::transaction(function () {
            [$h1, $h2] = $this->facilities();
            $people = $this->staff($h1);
            $depts = $this->departments($h1, $h2, $people);
            $locs = $this->locations($h1, $h2, $depts);
            $cats = $this->categories();
            $mfrs = $this->manufacturers();
            $assets = $this->assets($h1, $h2, $depts, $locs, $cats, $mfrs);
            $this->shifts($h1);
            $this->workOrders($h1, $assets, $people);
            $this->installations($assets, $people);
        });

        $this->command?->info('Demo data ready. Logins (password '.self::PASSWORD.'): rajesh.kumar@demo.test (engineer), meera.nair@demo.test (manager / approver), arvind.s@demo.test (technician).');
    }

    private function facilities(): array
    {
        $make = fn ($code, $name, $type, $city) => Facility::firstOrCreate(['code' => $code], [
            'name' => $name, 'type' => $type, 'city' => $city, 'address' => '123 Health Avenue', 'state' => 'Tamil Nadu', 'country' => 'India',
            'pin_code' => '600018', 'is_active' => true,
        ]);

        return [$make('H001', 'City General Hospital', 'main_hospital', 'Chennai'), $make('H002', 'KBT RUVIA Specialty Center', 'specialty_center', 'Coimbatore')];
    }

    private function staff(Facility $h1): array
    {
        $make = function (string $email, string $name, string $role, string $emp, string $designation, string $dept) use ($h1) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                $user = User::unguarded(fn () => User::create([
                    'name' => $name, 'username' => strstr($email, '@', true), 'email' => $email, 'password' => self::PASSWORD, 'employee_id' => $emp,
                    'designation' => $designation, 'department' => $dept, 'status' => 'active', 'email_verified_at' => now(),
                ]));
                $user->roles()->attach(Role::where('slug', $role)->value('id'), ['assigned_at' => now()]);
                $user->facilities()->attach($h1->id, ['is_primary' => true]);
            }

            return $user;
        };

        return [
            'engineer' => $make('rajesh.kumar@demo.test', 'Dr. Rajesh Kumar', 'biomedical_engineer', 'EMP-1001', 'Biomedical Engineer', 'Biomedical Engineering'),
            'manager' => $make('meera.nair@demo.test', 'Dr. Meera Nair', 'facility_manager', 'EMP-1002', 'Department Head', 'ICU'),
            'technician' => $make('arvind.s@demo.test', 'Arvind S', 'technician', 'EMP-1003', 'Biomedical Technician', 'Biomedical Engineering'),
            'engineer2' => $make('karthik.r@demo.test', 'Karthik R', 'biomedical_engineer', 'EMP-1004', 'Biomedical Engineer', 'Biomedical Engineering'),
        ];
    }

    private function departments(Facility $h1, Facility $h2, array $p): array
    {
        $d = [];
        foreach ([['D001', 'ICU', 'clinical'], ['D002', 'Radiology', 'clinical'], ['D003', 'CSSD', 'non_clinical'], ['D004', 'Operation Theatre', 'clinical'],
            ['D005', 'Emergency', 'clinical'], ['D006', 'Cardiology', 'clinical'], ['D007', 'NICU', 'clinical'], ['D008', 'Biomedical Engineering', 'non_clinical']] as [$code, $name, $type]) {
            $d[$name] = Department::firstOrCreate(['facility_id' => $h1->id, 'code' => $code], ['name' => $name, 'type' => $type]);
        }
        $d['ICU']->update(['hod_user_id' => $p['manager']->id]);
        $d['H2 Radiology'] = Department::firstOrCreate(['facility_id' => $h2->id, 'code' => 'D001'], ['name' => 'Radiology', 'type' => 'clinical']);

        return $d;
    }

    private function locations(Facility $h1, Facility $h2, array $d): array
    {
        $b = [];
        foreach ([['B01', 'Main Block', 8], ['B02', 'OPD Block', 5], ['B03', 'Emergency Block', 3]] as [$code, $name, $floors]) {
            $b[$name] = Building::firstOrCreate(['facility_id' => $h1->id, 'code' => $code], ['name' => $name, 'floors' => $floors, 'description' => $name.' of the hospital']);
        }
        $loc = [];
        foreach ([
            ['L001', 'ICU-1', 'icu', 2, 'Main Block', 'ICU'], ['L002', 'ICU-2', 'icu', 2, 'Main Block', 'ICU'], ['L003', 'Ultrasound Room 1', 'room', 1, 'Main Block', 'Radiology'],
            ['L004', 'RAD-X1', 'room', 1, 'Main Block', 'Radiology'], ['L005', 'OT-1', 'operating_theatre', 3, 'Main Block', 'Operation Theatre'], ['L006', 'CSSD-1', 'room', 0, 'Main Block', 'CSSD'],
            ['L007', 'ER-01', 'room', 0, 'Emergency Block', 'Emergency'], ['L008', 'CARD-01', 'room', 1, 'OPD Block', 'Cardiology'], ['L009', 'NICU-1', 'ward', 2, 'Main Block', 'NICU'],
            ['L010', 'BME Workshop', 'other', 0, 'Main Block', 'Biomedical Engineering'],
        ] as [$code, $name, $type, $floor, $building, $dept]) {
            $loc[$name] = Location::firstOrCreate(['facility_id' => $h1->id, 'code' => $code], ['name' => $name, 'type' => $type, 'floor' => $floor, 'building_id' => $b[$building]->id, 'department_id' => $d[$dept]->id]);
        }
        $loc['H2 RAD-1'] = Location::firstOrCreate(['facility_id' => $h2->id, 'code' => 'L001'], ['name' => 'CT Room 1', 'type' => 'room', 'floor' => 0, 'department_id' => $d['H2 Radiology']->id]);

        return $loc;
    }

    private function categories(): array
    {
        $c = [];
        foreach ([['CAT001', 'Patient Monitoring', 'Vital signs monitoring equipment'], ['CAT002', 'Life Support', 'Ventilators, anaesthesia machines'], ['CAT003', 'Diagnostic Imaging', 'X-ray, ultrasound, CT'],
            ['CAT004', 'Therapeutic', 'Infusion pumps, defibrillators'], ['CAT005', 'Laboratory', 'Lab analysers and instruments'], ['CAT006', 'Sterilization', 'Autoclaves and sterilizers']] as [$code, $name, $desc]) {
            $c[$name] = EquipmentCategory::firstOrCreate(['code' => $code], ['name' => $name, 'description' => $desc]);
        }

        return $c;
    }

    private function manufacturers(): array
    {
        $m = [];
        foreach ([['M001', 'GE Healthcare', 'USA'], ['M002', 'Philips', 'Netherlands'], ['M003', 'Siemens', 'Germany'], ['M004', 'Mindray', 'China'], ['M005', 'Dräger', 'Germany'], ['M006', 'Getinge', 'Sweden']] as [$code, $name, $country]) {
            $m[$name] = Manufacturer::firstOrCreate(['code' => $code], ['name' => $name, 'country' => $country]);
        }

        return $m;
    }

    private function assets(Facility $h1, Facility $h2, array $d, array $l, array $c, array $m): array
    {
        $out = [];
        $rows = [
            // tag, name, category, manufacturer, model, dept, location, status, installed?, cost
            ['BIO-0001', 'Patient Monitor', 'Patient Monitoring', 'Philips', 'IntelliVue MX450', 'ICU', 'ICU-1', 'operational', true, 850000],
            ['BIO-0002', 'Ventilator', 'Life Support', 'Dräger', 'Evita V500', 'ICU', 'ICU-2', 'operational', true, 1450000],
            ['BIO-0003', 'Infusion Pump', 'Therapeutic', 'Mindray', 'BeneFusion nSP', 'ICU', 'ICU-1', 'operational', true, 95000],
            ['BIO-0004', 'Defibrillator', 'Therapeutic', 'Philips', 'HeartStart XL+', 'Emergency', 'ER-01', 'under_maintenance', true, 520000],
            ['BIO-0005', 'X-Ray Machine', 'Diagnostic Imaging', 'Siemens', 'Ysio Max', 'Radiology', 'RAD-X1', 'operational', true, 4200000],
            ['BIO-0006', 'Ultrasound Scanner', 'Diagnostic Imaging', 'GE Healthcare', 'Logiq P9', 'Radiology', 'Ultrasound Room 1', 'out_of_service', true, 2500000],
            ['BIO-0007', 'Autoclave', 'Sterilization', 'Getinge', 'GSS67H', 'CSSD', 'CSSD-1', 'operational', true, 1850000],
            ['BIO-0008', 'Anesthesia Machine', 'Life Support', 'Dräger', 'Perseus A500', 'Operation Theatre', 'OT-1', 'operational', true, 2900000],
            ['BIO-0009', 'ECG Machine', 'Diagnostic Imaging', 'GE Healthcare', 'MAC 2000', 'Cardiology', 'CARD-01', 'operational', true, 180000],
            // awaiting installation (no installed date) - used by the installation jobs below
            ['BIO-0010', 'C-Arm X-Ray', 'Diagnostic Imaging', 'GE Healthcare', 'OEC 3D', 'Operation Theatre', 'OT-1', 'operational', false, 6800000],
            ['BIO-0011', 'Syringe Pump', 'Therapeutic', 'Mindray', 'SK-500', 'ICU', 'ICU-2', 'operational', false, 65000],
            ['BIO-0012', 'Infant Incubator', 'Life Support', 'Dräger', 'Babyleo TN500', 'NICU', 'NICU-1', 'under_maintenance', false, 1250000],
            ['BIO-0013', 'Ultrasound Scanner', 'Diagnostic Imaging', 'Philips', 'Affiniti 50', 'Radiology', 'Ultrasound Room 1', 'operational', false, 3100000],
            ['BIO-0014', 'Patient Monitor', 'Patient Monitoring', 'Mindray', 'BeneVision N12', 'Emergency', 'ER-01', 'operational', false, 410000],
            ['BIO-0015', 'Ventilator', 'Life Support', 'Philips', 'Trilogy Evo', 'ICU', 'ICU-1', 'operational', false, 900000],
            ['BIO-0016', 'Defibrillator', 'Therapeutic', 'GE Healthcare', 'Responder 2000', 'Emergency', 'ER-01', 'operational', false, 380000],
            ['BIO-0018', 'Infusion Pump', 'Therapeutic', 'Mindray', 'BeneFusion eSP', 'ICU', 'ICU-2', 'operational', false, 88000],
            ['BIO-0019', 'ECG Machine', 'Diagnostic Imaging', 'Philips', 'PageWriter TC30', 'Cardiology', 'CARD-01', 'operational', false, 210000],
        ];
        foreach ($rows as $i => [$tag, $name, $cat, $mfr, $model, $dept, $loc, $status, $installed, $cost]) {
            $out[$tag] = Asset::firstOrCreate(['asset_tag' => $tag], [
                'facility_id' => $h1->id, 'name' => $name, 'category' => $cat, 'category_id' => $c[$cat]->id, 'manufacturer' => $mfr, 'manufacturer_id' => $m[$mfr]->id,
                'model' => $model, 'serial_number' => 'SN'.(98000 + $i * 37), 'asset_type' => 'movable', 'department_id' => $d[$dept]->id, 'location_id' => $l[$loc]->id,
                'status' => $status, 'purchase_date' => now()->subMonths(6 + $i * 3)->toDateString(), 'purchase_cost' => $cost, 'funding_source' => 'Hospital Fund',
                'installed_at' => $installed ? now()->subMonths(5 + $i * 3)->toDateString() : null,
                'warranty_expiry_at' => now()->addDays(20 + $i * 45)->toDateString(), 'amc_expiry_at' => now()->addDays(120 + $i * 60)->toDateString(), 'amc_vendor' => $mfr,
                'last_pm_at' => now()->subDays(40 + $i)->toDateString(), 'next_pm_due_at' => now()->addDays(($i % 4 === 0 ? -5 : 20) + $i * 3)->toDateString(),
            ]);
        }
        $out['BIO-0017'] = Asset::firstOrCreate(['asset_tag' => 'BIO-0017'], [
            'facility_id' => $h2->id, 'name' => 'CT Scanner', 'category' => 'Diagnostic Imaging', 'category_id' => $c['Diagnostic Imaging']->id, 'manufacturer' => 'Siemens', 'manufacturer_id' => $m['Siemens']->id,
            'model' => 'Somatom go.Up', 'serial_number' => 'SN77001', 'asset_type' => 'immovable', 'department_id' => $d['H2 Radiology']->id, 'location_id' => $l['H2 RAD-1']->id,
            'status' => 'operational', 'purchase_date' => now()->subYear()->toDateString(), 'purchase_cost' => 14500000, 'installed_at' => now()->subMonths(10)->toDateString(),
        ]);

        return $out;
    }

    private function shifts(Facility $h1): void
    {
        foreach ([['Morning', '06:00', '14:00', 'regular'], ['Evening', '14:00', '22:00', 'regular'], ['Night', '22:00', '06:00', 'regular'], ['On-Call', '00:00', '23:59', 'on_call']] as [$n, $s, $e, $t]) {
            Shift::firstOrCreate(['facility_id' => $h1->id, 'name' => $n], ['start_time' => $s, 'end_time' => $e, 'type' => $t]);
        }
    }

    private function workOrders(Facility $h1, array $a, array $p): void
    {
        if (WorkOrder::count() > 0) {
            return;
        }
        $rows = [
            ['BIO-0004', 'Defibrillator not charging', 'breakdown', 'critical', 'in_progress'], ['BIO-0006', 'Probe replacement', 'breakdown', 'high', 'open'],
            ['BIO-0001', 'Quarterly preventive maintenance', 'preventive', 'medium', 'completed'], ['BIO-0002', 'Annual calibration', 'calibration', 'medium', 'open'],
            ['BIO-0003', 'Occlusion alarm check', 'preventive', 'low', 'on_hold'], ['BIO-0007', 'Door gasket replacement', 'breakdown', 'high', 'in_progress'],
        ];
        foreach ($rows as $i => [$tag, $title, $type, $prio, $status]) {
            WorkOrder::create(['facility_id' => $h1->id, 'asset_id' => $a[$tag]->id, 'number' => sprintf('WO-%04d', $i + 1), 'title' => $title, 'type' => $type, 'priority' => $prio,
                'status' => $status, 'assigned_to' => $p['technician']->id, 'created_by' => $p['engineer']->id, 'completed_at' => $status === 'completed' ? now() : null]);
        }
    }

    /** Pushes real jobs through the real workflow so statuses, checklists, certificates and audit rows are all genuine. */
    private function installations(array $a, array $p): void
    {
        if (InstallationJob::count() > 0) {
            return;
        }
        $wf = app(InstallationWorkflow::class);
        $mk = fn (string $tag, array $over = [], bool $submit = true) => $wf->create($p['engineer'], $a[$tag], array_merge([
            'type' => 'new_installation', 'priority' => 'normal', 'planned_date' => now()->addDays(3)->toDateString(), 'engineer_id' => $p['engineer']->id,
            'estimated_hours' => 6, 'vendor_name' => $a[$tag]->manufacturer,
        ], $over), $submit);
        $finish = function (InstallationJob $j) {
            foreach ($j->items as $i) {
                $i->update(['status' => $i->section === 'installation' ? 'completed' : 'pass', 'measured_value' => $i->section === 'acceptance' ? 'Within spec' : null, 'updated_by' => $j->engineer_id]);
            }
        };

        // history: three completed jobs in earlier months (feeds the trend chart)
        foreach ([['BIO-0018', 3, 'Mindray'], ['BIO-0019', 2, 'Philips'], ['BIO-0016', 1, 'GE Healthcare']] as [$tag, $monthsAgo, $vendor]) {
            $j = $mk($tag, ['planned_date' => now()->subMonths($monthsAgo)->toDateString(), 'vendor_name' => $vendor]);
            $finish($j);
            $wf->requestApproval($p['engineer'], $j);
            $wf->approve($p['manager'], $j);
            $j->update(['completed_at' => now()->subMonths($monthsAgo)->addDays(2), 'submitted_at' => now()->subMonths($monthsAgo)]);
        }

        // this month: one completed, one waiting for approval, one in progress, one draft, one cancelled
        $done = $mk('BIO-0012', ['priority' => 'high', 'planned_date' => now()->subDays(6)->toDateString()]);
        $finish($done); $wf->requestApproval($p['engineer'], $done); $wf->approve($p['manager'], $done);

        $waiting = $mk('BIO-0011', ['planned_date' => now()->subDays(2)->toDateString()]);
        $finish($waiting); $wf->requestApproval($p['engineer2'], $waiting);

        $going = $mk('BIO-0010', ['priority' => 'critical', 'estimated_hours' => 12]);
        foreach ($going->items()->where('section', 'site_readiness')->get() as $i) {
            $i->update(['status' => 'pass', 'updated_by' => $p['engineer']->id]);
        }
        foreach ($going->items()->where('section', 'installation')->orderBy('position')->limit(4)->get() as $k => $i) {
            $i->update(['status' => $k < 3 ? 'completed' : 'in_progress', 'updated_by' => $p['engineer']->id]);
        }

        $mk('BIO-0013', ['planned_date' => now()->addDays(10)->toDateString()], false);

        $cancelled = $mk('BIO-0014', ['planned_date' => now()->addDays(1)->toDateString()]);
        $wf->cancel($p['engineer'], $cancelled, 'Site not ready, civil work pending');
    }
}
