<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Blade pages for the Organization module; all data goes through the JSON API. */
class OrganizationController extends Controller
{
    public function index(Request $request, string $page): View
    {
        $cfg = $this->pages()[$page] ?? abort(404);

        return view('organization.index', ['page' => $page, 'cfg' => $cfg]);
    }

    public function facilityForm(Request $request, ?Facility $facility = null): View
    {
        if ($facility) {
            abort_unless($request->user()->canAccessFacility($facility->id), 403);
        } else {
            abort_unless($request->user()->canAccessAllFacilities(), 403, 'Only administrators can create facilities.');
        }

        return view('organization.facility-form', ['facility' => $facility, 'types' => Facility::TYPES]);
    }

    public function facilityImage(Request $request, Facility $facility): StreamedResponse
    {
        abort_unless($request->user()->canAccessFacility($facility->id) && $facility->image_path && Storage::disk('local')->exists($facility->image_path), 404);

        return Storage::disk('local')->response($facility->image_path, null, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Declarative page configs: the generic list view + organization.js render everything from these.
     * field types: text | number | select ; select options: a static map, or 'facilities' | 'buildings' | 'departments' | 'staff'
     */
    private function pages(): array
    {
        $facilityField = ['name' => 'facility_id', 'label' => 'Facility', 'type' => 'select', 'options' => 'facilities', 'required' => true, 'createOnly' => true];
        $statusFilter = ['name' => 'status', 'label' => 'All Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']];

        return [
            'facilities' => [
                'title' => 'Facilities', 'sub' => 'Manage hospital facilities and branches', 'noun' => 'facility', 'api' => 'facilities',
                'add' => 'Add Facility', 'addUrl' => route('org.facilities.create'), 'editUrl' => url('/organization/facilities/__ID__/edit'),
                'canDelete' => false, 'facilityPicker' => false, 'search' => 'Search facilities...',
                'columns' => [['code', 'Facility Code'], ['name', 'Facility Name'], ['type_label', 'Type'], ['city', 'City'], ['is_active', 'Status']],
                'filters' => [$statusFilter, ['name' => 'type', 'label' => 'All Types', 'options' => Facility::TYPES]],
                'fields' => [],
            ],
            'buildings' => [
                'title' => 'Buildings', 'sub' => 'Manage buildings within selected facility', 'noun' => 'building', 'api' => 'buildings',
                'add' => 'Add Building', 'canDelete' => true, 'facilityPicker' => true, 'search' => 'Search buildings...',
                'columns' => [['code', 'Building Code'], ['name', 'Building Name'], ['floors', 'Floors'], ['description', 'Description'], ['is_active', 'Status']],
                'filters' => [$statusFilter],
                'fields' => [$facilityField,
                    ['name' => 'code', 'label' => 'Building Code', 'type' => 'text', 'required' => true],
                    ['name' => 'name', 'label' => 'Building Name', 'type' => 'text', 'required' => true],
                    ['name' => 'floors', 'label' => 'Number of Floors', 'type' => 'number', 'required' => true, 'default' => 1],
                    ['name' => 'description', 'label' => 'Description', 'type' => 'text'],
                ],
            ],
            'departments' => [
                'title' => 'Departments', 'sub' => 'Manage clinical and non-clinical departments', 'noun' => 'department', 'api' => 'departments',
                'add' => 'Add Department', 'canDelete' => true, 'facilityPicker' => true, 'search' => 'Search departments...',
                'columns' => [['code', 'Dept Code'], ['name', 'Department Name'], ['type', 'Type', 'type'], ['hod', 'HOD'], ['is_active', 'Status']],
                'filters' => [$statusFilter, ['name' => 'type', 'label' => 'All Types', 'options' => ['clinical' => 'Clinical', 'non_clinical' => 'Non-Clinical']]],
                'fields' => [$facilityField,
                    ['name' => 'code', 'label' => 'Department Code', 'type' => 'text', 'required' => true],
                    ['name' => 'name', 'label' => 'Department Name', 'type' => 'text', 'required' => true],
                    ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => ['clinical' => 'Clinical', 'non_clinical' => 'Non-Clinical'], 'required' => true],
                    ['name' => 'hod_user_id', 'label' => 'Head of Department (HOD)', 'type' => 'select', 'options' => 'staff', 'blank' => 'None'],
                ],
            ],
            'locations' => [
                'title' => 'Rooms / Locations', 'sub' => 'Manage wards, rooms, theatres and equipment locations', 'noun' => 'location', 'api' => 'locations',
                'add' => 'Add Location', 'canDelete' => true, 'facilityPicker' => true, 'buildingPicker' => true, 'search' => 'Search locations...',
                'columns' => [['code', 'Location Code'], ['name', 'Location Name'], ['type_label', 'Type'], ['floor', 'Floor'], ['department', 'Department'], ['is_active', 'Status']],
                'filters' => [$statusFilter, ['name' => 'type', 'label' => 'Location Type', 'options' => Location::TYPES]],
                'fields' => [$facilityField,
                    ['name' => 'building_id', 'label' => 'Building', 'type' => 'select', 'options' => 'buildings', 'blank' => 'None'],
                    ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'options' => 'departments', 'blank' => 'None'],
                    ['name' => 'code', 'label' => 'Location Code', 'type' => 'text', 'required' => true],
                    ['name' => 'name', 'label' => 'Location Name', 'type' => 'text', 'required' => true],
                    ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => Location::TYPES, 'required' => true],
                    ['name' => 'floor', 'label' => 'Floor (ground = 0)', 'type' => 'number'],
                ],
            ],
            'manufacturers' => [
                'title' => 'Manufacturers', 'sub' => 'Maintain equipment manufacturer details with country and status', 'noun' => 'manufacturer', 'api' => 'manufacturers',
                'add' => 'Add Manufacturer', 'canDelete' => true, 'facilityPicker' => false, 'search' => 'Search manufacturers...',
                'columns' => [['code', 'Manufacturer Code'], ['name', 'Manufacturer Name'], ['country', 'Country'], ['is_active', 'Status']],
                'filters' => [$statusFilter],
                'fields' => [
                    ['name' => 'code', 'label' => 'Manufacturer Code', 'type' => 'text', 'required' => true],
                    ['name' => 'name', 'label' => 'Manufacturer Name', 'type' => 'text', 'required' => true],
                    ['name' => 'country', 'label' => 'Country', 'type' => 'text'],
                ],
            ],
            'staff' => [
                'title' => 'Staff Directory', 'sub' => 'Staff details, roles and department mapping', 'noun' => 'staff member', 'api' => 'staff',
                'add' => null, 'canDelete' => false, 'canToggle' => false, 'facilityPicker' => true, 'search' => 'Search by name, staff ID or email...',
                'columns' => [['code', 'Staff ID'], ['name', 'Name'], ['role', 'Role'], ['department', 'Department'], ['designation', 'Designation'], ['is_active', 'Status']],
                'filters' => [
                    $statusFilter,
                    ['name' => 'role', 'label' => 'All Roles', 'options' => \App\Models\Role::orderBy('name')->pluck('name', 'slug')->all()],
                ],
                'fields' => [
                    ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'options' => 'departments', 'blank' => 'None'],
                    ['name' => 'designation', 'label' => 'Designation', 'type' => 'text'],
                ],
            ],
            'shifts' => [
                'title' => 'Shift Management', 'sub' => 'Define working shifts and on-call windows', 'noun' => 'shift', 'api' => 'shifts',
                'add' => 'Add Shift', 'canDelete' => true, 'facilityPicker' => true, 'search' => 'Search shifts...',
                'columns' => [['name', 'Shift Name'], ['start_label', 'Start Time'], ['end_label', 'End Time'], ['type_label', 'Type'], ['is_active', 'Status']],
                'filters' => [$statusFilter, ['name' => 'type', 'label' => 'All Types', 'options' => \App\Models\Shift::TYPES]],
                'fields' => [$facilityField,
                    ['name' => 'name', 'label' => 'Shift Name', 'type' => 'text', 'required' => true],
                    ['name' => 'start_time', 'label' => 'Start Time', 'type' => 'time', 'required' => true],
                    ['name' => 'end_time', 'label' => 'End Time', 'type' => 'time', 'required' => true],
                    ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => \App\Models\Shift::TYPES, 'required' => true],
                ],
            ],
            'on-call-roster' => [
                'title' => 'Shift & On-Call Roster', 'sub' => 'Assign technicians and engineers to shifts and on-call duty', 'noun' => 'roster entry', 'api' => 'shift-assignments',
                'add' => 'Add Roster Entry', 'canDelete' => true, 'canToggle' => false, 'facilityPicker' => true, 'search' => null,
                'columns' => [['date_label', 'Date'], ['shift', 'Shift'], ['shift_time', 'Time'], ['shift_type', 'Type'], ['staff', 'Staff'], ['designation', 'Designation']],
                'filters' => [],
                'fields' => [$facilityField,
                    ['name' => 'shift_id', 'label' => 'Shift', 'type' => 'select', 'options' => 'shifts', 'required' => true],
                    ['name' => 'user_id', 'label' => 'Staff Member', 'type' => 'select', 'options' => 'staff', 'required' => true],
                    ['name' => 'shift_date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                ],
            ],
            'priority-levels' => [
                'title' => 'Priority Levels', 'sub' => 'Configure work order priorities with response and resolution targets (SLA)', 'noun' => 'priority level', 'api' => 'priority-levels',
                'add' => 'Add Priority', 'canDelete' => true, 'facilityPicker' => false, 'search' => 'Search priorities...',
                'columns' => [['name', 'Priority', 'priority'], ['color', 'Color', 'color'], ['response_label', 'Response Time'], ['resolution_label', 'Resolution Time'], ['is_active', 'Status']],
                'filters' => [$statusFilter],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code (e.g. urgent)', 'type' => 'text', 'required' => true, 'createOnly' => true],
                    ['name' => 'name', 'label' => 'Priority Name', 'type' => 'text', 'required' => true],
                    ['name' => 'color', 'label' => 'Color', 'type' => 'color', 'required' => true, 'default' => '#2d7be5'],
                    ['name' => 'response_minutes', 'label' => 'Response Time (minutes)', 'type' => 'number', 'required' => true],
                    ['name' => 'resolution_minutes', 'label' => 'Resolution Time (minutes)', 'type' => 'number', 'required' => true],
                ],
            ],
            'equipment-categories' => [
                'title' => 'Equipment Categories', 'sub' => 'Manage biomedical equipment categories', 'noun' => 'category', 'api' => 'equipment-categories',
                'add' => 'Add Category', 'canDelete' => true, 'facilityPicker' => false, 'search' => 'Search categories...',
                'columns' => [['code', 'Category Code'], ['name', 'Category Name'], ['description', 'Description'], ['is_active', 'Status']],
                'filters' => [$statusFilter],
                'fields' => [
                    ['name' => 'code', 'label' => 'Category Code', 'type' => 'text', 'required' => true],
                    ['name' => 'name', 'label' => 'Category Name', 'type' => 'text', 'required' => true],
                    ['name' => 'description', 'label' => 'Description', 'type' => 'text'],
                ],
            ],
        ];
    }
}
