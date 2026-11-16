@php($editing = (bool) $facility)
<x-layouts.app :title="$editing ? 'Edit Facility' : 'Add Facility'">
    <nav class="crumbs" aria-label="Breadcrumb">Organization <span>&rsaquo;</span> <a href="{{ route('org.index', 'facilities') }}">Facilities</a> <span>&rsaquo;</span> {{ $editing ? 'Edit Facility' : 'Add Facility' }}</nav>
    <h1 class="page-title">{{ $editing ? 'Edit Facility' : 'Add Facility' }}</h1>
    <p class="page-sub">{{ $editing ? 'Update hospital facility details' : 'Create a new hospital facility' }}</p>

    <form class="card card__body facility-form" data-facility-form-page novalidate enctype="multipart/form-data"
          data-id="{{ $facility?->id }}" data-list-url="{{ route('org.index', 'facilities') }}">
        @csrf
        <div class="facility-form__grid">
            <div class="form-grid">
                <x-input label="Facility Code *" name="code" :value="$facility?->code" placeholder="e.g. H005" maxlength="40" />
                <x-input label="Facility Name *" name="name" :value="$facility?->name" placeholder="Enter facility name" maxlength="255" />

                <div class="field">
                    <label class="field__label" for="f-type">Facility Type *</label>
                    <div data-field>
                        <select class="select" id="f-type" name="type">
                            <option value="">Select type</option>
                            @foreach($types as $value => $label)
                                <option value="{{ $value }}" @selected($facility?->type === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field__error" data-error-for="type" role="alert"></div>
                </div>
                <x-input label="City *" name="city" :value="$facility?->city" placeholder="Enter city" maxlength="120" />

                <div style="grid-column:1/-1">
                    <x-input label="Address *" name="address" :value="$facility?->address" placeholder="Street, area" maxlength="255" />
                </div>
                <x-input label="State *" name="state" :value="$facility?->state" placeholder="e.g. Tamil Nadu" maxlength="120" />
                <x-input label="PIN Code *" name="pin_code" :value="$facility?->pin_code" placeholder="e.g. 600018" maxlength="20" />
                <x-input label="Country *" name="country" :value="$facility?->country ?? 'India'" maxlength="120" />

                <div class="field">
                    <span class="field__label">Status</span>
                    <label class="switch">
                        <input type="checkbox" name="is_active" value="1" @checked($facility ? $facility->is_active : true)>
                        <span class="switch__track" aria-hidden="true"></span>
                        <span data-switch-label>Active</span>
                    </label>
                </div>
            </div>

            <aside class="image-drop">
                <span class="field__label">Facility Image</span>
                <div class="image-drop__preview" data-image-preview>
                    @if($facility?->image_path)
                        <img src="{{ route('org.facilities.image', $facility) }}" alt="Facility image">
                    @else
                        <x-icon name="building" />
                    @endif
                </div>
                <label class="btn btn--outline btn--sm" style="cursor:pointer">
                    Upload Image
                    <input type="file" name="image" accept="image/png,image/jpeg" hidden data-image-input>
                </label>
                <small class="muted">JPG, PNG (max 2 MB)</small>
                @if($facility?->image_path)
                    <label class="check"><input type="checkbox" name="remove_image" value="1" data-remove-image> Remove current image</label>
                @endif
                <div class="field__error" data-error-for="image" role="alert"></div>
            </aside>
        </div>

        <div class="form-actions">
            <a class="btn btn--outline btn--sm btn--auto" href="{{ route('org.index', 'facilities') }}">Cancel</a>
            <x-button class="btn--sm" style="width:auto" loading="Saving…">{{ $editing ? 'Save Changes' : 'Save Facility' }}</x-button>
        </div>
    </form>
</x-layouts.app>
