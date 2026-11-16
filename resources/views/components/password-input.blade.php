@props(['label', 'name', 'error' => null, 'hint' => null])
@php
    $id = $attributes->get('id', 'f-'.$name);
    $message = $error ?? $errors->first($name);
@endphp
<div class="field">
    <label class="field__label" for="{{ $id }}">{{ $label }}</label>
    <div class="input-wrap {{ $message ? 'is-invalid' : '' }}" data-field>
        <x-icon name="lock" />
        <input id="{{ $id }}" name="{{ $name }}" type="password"
               aria-invalid="{{ $message ? 'true' : 'false' }}" aria-describedby="{{ $id }}-error"
               {{ $attributes->except('id') }}>
        <button type="button" class="input-wrap__btn" data-toggle-password aria-label="Show password" aria-pressed="false">
            <x-icon name="eye" data-icon-show />
            <x-icon name="eye-off" data-icon-hide style="display:none" />
        </button>
    </div>
    @if($hint)<div class="field__hint">{{ $hint }}</div>@endif
    <div class="field__error" id="{{ $id }}-error" data-error-for="{{ $name }}" role="alert">{{ $message }}</div>
</div>
