<?php

namespace App\Services;

use App\Exceptions\WorkflowException;
use App\Models\Asset;
use App\Models\Department;
use App\Models\EquipmentCategory;
use App\Models\Facility;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Bulk import of assets from .xlsx / .csv: parse -> validate every row -> (separately) confirm.
 * Import is all-or-nothing: confirm re-validates the stored rows and refuses if anything is wrong.
 */
class AssetImportService
{
    public const MAX_ROWS = 1000;

    public const TTL_MINUTES = 30;

    /** header => [label, required, example] */
    public const COLUMNS = [
        'asset_id' => ['Asset ID', false, 'BIO-0101 (leave blank to auto-generate)'],
        'equipment_name' => ['Equipment Name', true, 'Patient Monitor'],
        'category_code' => ['Category Code', true, 'CAT001'],
        'manufacturer_code' => ['Manufacturer Code', true, 'M001'],
        'model' => ['Model', true, 'IntelliVue MX450'],
        'serial_number' => ['Serial Number', true, 'SN12345'],
        'asset_type' => ['Asset Type', false, 'movable'],
        'facility_code' => ['Facility Code', true, 'H001'],
        'department_code' => ['Department Code', true, 'D001'],
        'location_code' => ['Location Code', true, 'L001'],
        'status' => ['Status', false, 'operational'],
        'purchase_date' => ['Purchase Date', false, '2024-03-15'],
        'asset_cost' => ['Asset Cost', false, '250000'],
        'funding_source' => ['Funding Source', false, 'Hospital Fund'],
        'warranty_expiry' => ['Warranty Expiry', false, '2027-03-14'],
        'amc_expiry' => ['AMC Expiry', false, '2028-03-14'],
    ];

    /* ------------------------------------------------------------ template */

    public function templatePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_map(fn ($c) => $c[0].($c[1] ? ' *' : ''), self::COLUMNS)));
        $writer->addRow(Row::fromValues(array_map(fn ($c) => $c[2], self::COLUMNS)));
        $writer->close();

        return $path;
    }

    /* --------------------------------------------------------------- parse */

    /** @return array<int,array<string,mixed>> rows keyed by their spreadsheet row number */
    public function parse(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $reader = $ext === 'csv' ? new CsvReader() : new XlsxReader();
        $rows = [];
        $map = null;

        try {
            $reader->open($file->getRealPath());
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $i => $row) {
                    $cells = array_map(fn ($c) => $c instanceof DateTimeInterface ? $c->format('Y-m-d') : (is_string($c) ? trim($c) : $c), $row->toArray());
                    if ($map === null) {
                        $map = $this->mapHeaders($cells);

                        continue;
                    }
                    if (count(array_filter($cells, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue; // blank line
                    }
                    if (count($rows) >= self::MAX_ROWS) {
                        throw new WorkflowException('The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
                    }
                    $rows[$i] = collect($map)->map(fn ($idx) => $idx === null ? null : ($cells[$idx] ?? null))->all();
                }
                break; // first sheet only
            }
        } catch (WorkflowException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new WorkflowException('The file could not be read. Use the Excel template (.xlsx) or a .csv file.');
        } finally {
            try {
                $reader->close();
            } catch (\Throwable) {
            }
        }

        if (! $rows) {
            throw new WorkflowException('The file has no data rows.');
        }

        return $rows;
    }

    /** @return array<string,?int> field => column index */
    private function mapHeaders(array $cells): array
    {
        $norm = fn ($s) => Str::of((string) $s)->replace('*', '')->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
        $found = [];
        foreach ($cells as $idx => $cell) {
            $found[$norm($cell)] = $idx;
        }
        $aliases = ['equipment_name' => ['equipment_name', 'name'], 'asset_id' => ['asset_id', 'asset_tag'], 'asset_cost' => ['asset_cost', 'asset_cost_inr', 'cost']];
        $map = [];
        $missing = [];
        foreach (self::COLUMNS as $field => [$label, $required]) {
            $idx = null;
            foreach ($aliases[$field] ?? [$field] as $alias) {
                if (isset($found[$alias])) {
                    $idx = $found[$alias];
                    break;
                }
            }
            $map[$field] = $idx;
            if ($required && $idx === null) {
                $missing[] = $label;
            }
        }
        if ($missing) {
            throw new WorkflowException('Missing required column(s): '.implode(', ', $missing).'. Download the template for the expected layout.');
        }

        return $map;
    }

    /* ------------------------------------------------------------ validate */

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{valid:array<int,array<string,mixed>>,errors:array<int,array<int,string>>}
     */
    public function validate(User $user, array $rows): array
    {
        $categories = EquipmentCategory::where('is_active', true)->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [strtolower($c) => $id]);
        $manufacturers = Manufacturer::where('is_active', true)->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [strtolower($c) => $id]);
        $facilities = Facility::where('is_active', true)->get(['id', 'code'])->keyBy(fn ($f) => strtolower((string) $f->code));
        $departments = Department::where('is_active', true)->get(['id', 'facility_id', 'code'])->keyBy(fn ($d) => $d->facility_id.'|'.strtolower($d->code));
        $locations = Location::where('is_active', true)->get(['id', 'facility_id', 'code'])->keyBy(fn ($l) => $l->facility_id.'|'.strtolower($l->code));
        $existingTags = Asset::pluck('asset_tag')->map('strtolower')->flip();
        $existingSerials = Asset::whereNotNull('manufacturer_id')->get(['manufacturer_id', 'serial_number'])->mapWithKeys(fn ($a) => [$a->manufacturer_id.'|'.strtolower((string) $a->serial_number) => true]);

        $valid = [];
        $errors = [];
        $seenTags = [];
        $seenSerials = [];

        foreach ($rows as $line => $r) {
            $msgs = [];
            // normalise tolerant inputs before validating (e.g. "Out of Service", "Movable")
            foreach (['asset_type', 'status'] as $f) {
                if (is_string($r[$f] ?? null)) {
                    $r[$f] = Str::of($r[$f])->lower()->replaceMatches('/[^a-z]+/', '_')->trim('_')->toString() ?: null;
                }
            }
            $v = Validator::make($r, [
                'asset_id' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
                'equipment_name' => ['required', 'string', 'max:255'],
                'category_code' => ['required'], 'manufacturer_code' => ['required'],
                'model' => ['required', 'string', 'max:120'], 'serial_number' => ['required', 'max:120'],
                'asset_type' => ['nullable', 'in:movable,immovable'],
                'facility_code' => ['required'], 'department_code' => ['required'], 'location_code' => ['required'],
                'status' => ['nullable', 'in:'.implode(',', Asset::EDITABLE_STATUSES)],
                'purchase_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'asset_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
                'funding_source' => ['nullable', 'string', 'max:120'],
                'warranty_expiry' => ['nullable', 'date_format:Y-m-d'], 'amc_expiry' => ['nullable', 'date_format:Y-m-d'],
            ], [], collect(self::COLUMNS)->map(fn ($c) => $c[0])->all());

            foreach ($v->errors()->all() as $m) {
                $msgs[] = $m;
            }
            $r = array_map(fn ($x) => $x === '' ? null : $x, $r);

            $category = $categories[strtolower((string) $r['category_code'])] ?? null;
            $manufacturer = $manufacturers[strtolower((string) $r['manufacturer_code'])] ?? null;
            $facility = $facilities[strtolower((string) $r['facility_code'])] ?? null;
            $department = $facility ? ($departments[$facility->id.'|'.strtolower((string) $r['department_code'])] ?? null) : null;
            $location = $facility ? ($locations[$facility->id.'|'.strtolower((string) $r['location_code'])] ?? null) : null;

            if ($r['category_code'] && ! $category) {
                $msgs[] = "Category code '{$r['category_code']}' was not found or is inactive.";
            }
            if ($r['manufacturer_code'] && ! $manufacturer) {
                $msgs[] = "Manufacturer code '{$r['manufacturer_code']}' was not found or is inactive.";
            }
            if ($r['facility_code'] && ! $facility) {
                $msgs[] = "Facility code '{$r['facility_code']}' was not found or is inactive.";
            } elseif ($facility && ! $user->canAccessFacility($facility->id)) {
                $msgs[] = "You do not have access to facility '{$r['facility_code']}'.";
            } else {
                if ($r['department_code'] && ! $department) {
                    $msgs[] = "Department code '{$r['department_code']}' does not exist in that facility.";
                }
                if ($r['location_code'] && ! $location) {
                    $msgs[] = "Location code '{$r['location_code']}' does not exist in that facility.";
                }
            }

            $tag = $r['asset_id'] ? strtolower((string) $r['asset_id']) : null;
            if ($tag) {
                if (isset($existingTags[$tag])) {
                    $msgs[] = "Asset ID '{$r['asset_id']}' already exists.";
                } elseif (isset($seenTags[$tag])) {
                    $msgs[] = "Asset ID '{$r['asset_id']}' is repeated (also on row {$seenTags[$tag]}).";
                }
                $seenTags[$tag] ??= $line;
            }
            if ($manufacturer && $r['serial_number']) {
                $key = $manufacturer.'|'.strtolower((string) $r['serial_number']);
                if (isset($existingSerials[$key])) {
                    $msgs[] = "Serial number '{$r['serial_number']}' is already registered for this manufacturer.";
                } elseif (isset($seenSerials[$key])) {
                    $msgs[] = "Serial number '{$r['serial_number']}' is repeated (also on row {$seenSerials[$key]}).";
                }
                $seenSerials[$key] ??= $line;
            }
            foreach (['warranty_expiry', 'amc_expiry'] as $f) {
                if ($r['purchase_date'] && $r[$f] && $r[$f] < $r['purchase_date']) {
                    $msgs[] = self::COLUMNS[$f][0].' cannot be before the purchase date.';
                }
            }

            if ($msgs) {
                $errors[$line] = array_values(array_unique($msgs));

                continue;
            }
            $valid[$line] = [
                'facility_id' => $facility->id, 'asset_tag' => $r['asset_id'], 'name' => $r['equipment_name'], 'category_id' => $category,
                'manufacturer_id' => $manufacturer, 'model' => $r['model'], 'serial_number' => (string) $r['serial_number'],
                'asset_type' => $r['asset_type'] ?: 'movable', 'department_id' => $department->id, 'location_id' => $location->id,
                'status' => $r['status'] ?: 'operational', 'purchase_date' => $r['purchase_date'], 'purchase_cost' => $r['asset_cost'],
                'funding_source' => $r['funding_source'], 'warranty_expiry_at' => $r['warranty_expiry'], 'amc_expiry_at' => $r['amc_expiry'],
            ];
        }

        return ['valid' => $valid, 'errors' => $errors];
    }

    /* ------------------------------------------------------- stash + commit */

    public function stash(User $user, array $rows): string
    {
        $token = (string) Str::uuid();
        Storage::disk('local')->put("asset-imports/$token.json", json_encode(['user' => $user->id, 'at' => now()->timestamp, 'rows' => $rows]));

        return $token;
    }

    /** @return array<int,array<string,mixed>> */
    private function unstash(User $user, string $token): array
    {
        $path = "asset-imports/$token.json";
        if (! Str::isUuid($token) || ! Storage::disk('local')->exists($path)) {
            throw new WorkflowException('This import has expired or was already completed. Upload the file again.', 410);
        }
        $data = json_decode(Storage::disk('local')->get($path), true);
        if ($data['user'] !== $user->id) {
            throw new WorkflowException('This import belongs to another user.', 403);
        }
        if (now()->timestamp - $data['at'] > self::TTL_MINUTES * 60) {
            Storage::disk('local')->delete($path);
            throw new WorkflowException('This import has expired. Upload the file again.', 410);
        }

        return $data['rows'];
    }

    /** @return int number of assets created */
    public function commit(User $user, string $token): int
    {
        $rows = $this->unstash($user, $token);
        $result = $this->validate($user, $rows); // data may have changed since the preview
        if ($result['errors']) {
            throw new WorkflowException('The data changed since validation and '.count($result['errors']).' row(s) now have errors. Upload the file again.', 409);
        }

        $tags = DB::transaction(function () use ($result, $user) {
            $next = $this->nextNumber();
            $created = [];
            foreach ($result['valid'] as $payload) {
                $payload['asset_tag'] ??= sprintf('BIO-%04d', $next++);
                $payload['category'] = EquipmentCategory::find($payload['category_id'])->name;
                $payload['manufacturer'] = Manufacturer::find($payload['manufacturer_id'])->name;
                $created[] = Asset::create($payload)->asset_tag;
            }
            AuditLogger::log('asset.imported', $user, null, null, ['count' => count($created), 'first' => array_slice($created, 0, 20)]);

            return $created;
        });
        Storage::disk('local')->delete("asset-imports/$token.json");

        return count($tags);
    }

    private function nextNumber(): int
    {
        $max = Asset::where('asset_tag', 'like', 'BIO-%')->lockForUpdate()->pluck('asset_tag')
            ->map(fn ($t) => preg_match('/^BIO-(\d+)$/', $t, $m) ? (int) $m[1] : 0)->max() ?? 0;

        return $max + 1;
    }
}
