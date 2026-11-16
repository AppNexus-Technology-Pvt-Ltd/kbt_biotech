@php($editing = (bool) $asset)
<x-layouts.app :title="$editing ? 'Edit Asset' : 'Add Asset'">
    <nav class="crumbs" aria-label="Breadcrumb">Assets <span>&rsaquo;</span> <a href="{{ route('assets.register') }}">Asset Register</a> <span>&rsaquo;</span> {{ $editing ? 'Edit Asset' : 'Add Asset' }}</nav>

    <form data-asset-form novalidate enctype="multipart/form-data"
          data-id="{{ $asset?->id }}" data-selected-facility="{{ $selectedFacility }}"
          data-options="{{ route('api.assets.options') }}" data-next-tag="{{ route('api.assets.next-tag') }}"
          data-detail-url="{{ $editing ? route('api.assets.show', $asset->id) : '' }}" data-list-url="{{ route('assets.register') }}">
        @csrf
        <div class="page-head">
            <div>
                <h1 class="page-title">{{ $editing ? 'Edit Asset' : 'Add Asset' }}</h1>
                <p class="page-sub">{{ $editing ? 'Update equipment details.' : 'Register new biomedical equipment.' }}</p>
            </div>
            <div class="page-head__actions">
                <a class="btn btn--outline btn--sm btn--auto" href="{{ $editing ? route('assets.show', $asset) : route('assets.register') }}">Cancel</a>
                <x-button class="btn--sm" style="width:auto" loading="Saving…">Save</x-button>
            </div>
        </div>

        <div class="card">
            <div class="tabs" role="tablist" aria-label="Asset sections">
                @foreach(['basic' => 'Basic Information', 'specs' => 'Specifications', 'location' => 'Location', 'purchase' => 'Purchase & Cost', 'warranty' => 'Warranty & AMC', 'other' => 'Other Details'] as $key => $label)
                    <button type="button" class="tab {{ $loop->first ? 'is-active' : '' }}" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-tab="{{ $key }}">{{ $label }}<i class="tab__dot" hidden></i></button>
                @endforeach
            </div>

            <div class="card__body">
                {{-- Basic --}}
                <section class="tabpanel" id="panel-basic" role="tabpanel" aria-labelledby="tab-basic" data-panel="basic">
                    <div class="form-grid">
                        <div class="field">
                            <label class="field__label" for="f-facility_id">Facility *</label>
                            <div data-field><select class="select" id="f-facility_id" name="facility_id" data-facility @disabled($editing)></select></div>
                            <div class="field__error" data-error-for="facility_id" role="alert"></div>
                        </div>
                        <x-input label="Asset ID *" name="asset_tag" placeholder="BIO-0001" maxlength="60" />

                        <x-input label="Equipment Name *" name="name" placeholder="e.g. Patient Monitor" maxlength="255" />
                        <div class="field">
                            <label class="field__label" for="f-department_id">Department *</label>
                            <div data-field><select class="select" id="f-department_id" name="department_id" data-select="departments"></select></div>
                            <div class="field__error" data-error-for="department_id" role="alert"></div>
                        </div>

                        <div class="field">
                            <label class="field__label" for="f-category_id">Category *</label>
                            <div data-field><select class="select" id="f-category_id" name="category_id" data-select="categories"></select></div>
                            <div class="field__error" data-error-for="category_id" role="alert"></div>
                        </div>
                        <div class="field">
                            <label class="field__label" for="f-location_id">Location *</label>
                            <div data-field><select class="select" id="f-location_id" name="location_id" data-select="locations"></select></div>
                            <div class="field__error" data-error-for="location_id" role="alert"></div>
                        </div>

                        <div class="field">
                            <label class="field__label" for="f-manufacturer_id">Manufacturer *</label>
                            <div data-field><select class="select" id="f-manufacturer_id" name="manufacturer_id" data-select="manufacturers"></select></div>
                            <div class="field__error" data-error-for="manufacturer_id" role="alert"></div>
                        </div>
                        <x-input label="Purchase Date" name="purchase_date" type="date" />

                        <x-input label="Model *" name="model" placeholder="e.g. IntelliVue MX450" maxlength="120" />
                        <x-input label="Asset Cost (INR)" name="purchase_cost" type="number" step="0.01" min="0" placeholder="0.00" />

                        <x-input label="Serial Number *" name="serial_number" maxlength="120" />
                        <x-input label="Funding Source" name="funding_source" list="funding-sources" maxlength="120" placeholder="e.g. Hospital Fund" />
                        <datalist id="funding-sources"><option value="Hospital Fund"><option value="Grant"><option value="Donation"><option value="Lease"></datalist>

                        <div class="field">
                            <span class="field__label">Asset Type *</span>
                            <div class="radios" data-field>
                                @foreach(\App\Models\Asset::TYPES as $v => $t)
                                    <label class="check"><input type="radio" name="asset_type" value="{{ $v }}" @checked($v === 'movable')> {{ $t }}</label>
                                @endforeach
                            </div>
                            <div class="field__error" data-error-for="asset_type" role="alert"></div>
                        </div>
                        <div class="field">
                            <span class="field__label">Status *</span>
                            <div class="radios" data-field>
                                @foreach(array_intersect_key(\App\Models\Asset::STATUSES, array_flip(\App\Models\Asset::EDITABLE_STATUSES)) as $v => $t)
                                    <label class="check"><input type="radio" name="status" value="{{ $v }}" @checked($v === 'operational')> {{ $t }}</label>
                                @endforeach
                            </div>
                            <div class="field__error" data-error-for="status" role="alert"></div>
                        </div>
                    </div>
                </section>

                {{-- Specifications --}}
                <section class="tabpanel" id="panel-specs" role="tabpanel" aria-labelledby="tab-specs" data-panel="specs" hidden>
                    <div class="field">
                        <label class="field__label" for="f-specifications">Technical specifications</label>
                        <textarea class="textarea" id="f-specifications" name="specifications" rows="9" maxlength="5000" placeholder="Power rating, dimensions, accessories, software version…"></textarea>
                        <div class="field__error" data-error-for="specifications" role="alert"></div>
                    </div>
                </section>

                {{-- Location --}}
                <section class="tabpanel" id="panel-location" role="tabpanel" aria-labelledby="tab-location" data-panel="location" hidden>
                    <p class="muted" style="margin-bottom:14px">Narrow the location list by building. The location itself is chosen on the Basic Information tab.</p>
                    <div class="form-grid">
                        <div class="field">
                            <label class="field__label" for="f-building">Building (filter)</label>
                            <div data-field><select class="select" id="f-building" data-building></select></div>
                        </div>
                        <div class="field">
                            <span class="field__label">Selected location</span>
                            <div class="readout" data-location-readout>Not selected</div>
                        </div>
                    </div>
                </section>

                {{-- Purchase --}}
                <section class="tabpanel" id="panel-purchase" role="tabpanel" aria-labelledby="tab-purchase" data-panel="purchase" hidden>
                    <div class="form-grid">
                        <x-input label="Vendor / Supplier" name="vendor_name" maxlength="160" />
                        <x-input label="Invoice Number" name="invoice_number" maxlength="80" />
                        <x-input label="Installation / Commissioning Date" name="installed_at" type="date" />
                    </div>
                </section>

                {{-- Warranty --}}
                <section class="tabpanel" id="panel-warranty" role="tabpanel" aria-labelledby="tab-warranty" data-panel="warranty" hidden>
                    <div class="form-grid">
                        <x-input label="Warranty Expiry" name="warranty_expiry_at" type="date" />
                        <x-input label="AMC Expiry" name="amc_expiry_at" type="date" />
                        <x-input label="AMC Vendor" name="amc_vendor" maxlength="160" />
                    </div>
                </section>

                {{-- Other --}}
                <section class="tabpanel" id="panel-other" role="tabpanel" aria-labelledby="tab-other" data-panel="other" hidden>
                    <div class="form-grid">
                        <x-input label="Last PM Date" name="last_pm_at" type="date" />
                        <x-input label="Next PM Due" name="next_pm_due_at" type="date" />
                        <x-input label="Last Calibration" name="last_calibration_at" type="date" />
                        <x-input label="Next Calibration Due" name="next_calibration_due_at" type="date" />
                    </div>
                    <div class="field">
                        <label class="field__label" for="f-remarks">Remarks</label>
                        <textarea class="textarea" id="f-remarks" name="remarks" rows="4" maxlength="2000"></textarea>
                        <div class="field__error" data-error-for="remarks" role="alert"></div>
                    </div>
                    <div class="field">
                        <span class="field__label">Equipment photo</span>
                        <div class="photo-pick">
                            <div class="image-drop__preview" data-image-preview style="width:160px"><x-icon name="box" /></div>
                            <div style="display:grid;gap:8px;justify-items:start">
                                <label class="btn btn--outline btn--sm btn--auto" style="cursor:pointer">Choose image<input type="file" name="image" accept="image/png,image/jpeg" hidden data-image-input></label>
                                <small class="muted">JPG or PNG, max 2 MB</small>
                                <label class="check" data-remove-wrap hidden><input type="checkbox" name="remove_image" value="1"> Remove current photo</label>
                            </div>
                        </div>
                        <div class="field__error" data-error-for="image" role="alert"></div>
                    </div>
                </section>
            </div>
        </div>
    </form>
</x-layouts.app>
