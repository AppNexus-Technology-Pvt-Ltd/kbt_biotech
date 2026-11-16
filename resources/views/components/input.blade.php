@props(['label', 'name', 'type' => 'text', 'icon' => null, 'value' => null, 'error' => null, 'hint' => null, 'readonly' => false])
@php
    $id = $attributes->get('id', 'f-'.$name);
    $message = $error ?? $errors->first($name);
@endphp
<div class="field">
    <label class="field__label" for="{{ $id }}">{{ $label }}</label>
    <div class="input-wrap {{ $message ? 'is-invalid' : '' }} {{ $readonly ? 'is-readonly' : '' }}" data-field>
        @if($icon)<x-icon :name="$icon" />@endif
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}"
               @if($readonly) readonly @endif
               aria-invalid="{{ $message ? 'true' : 'false' }}" aria-describedby="{{ $id }}-error"
               {{ $attributes->except('id') }}>
        {{ $slot }}
    </div>
    @if($hint)<div class="field__hint">{{ $hint }}</div>@endif
    <div class="field__error" id="{{ $id }}-error" data-error-for="{{ $name }}" role="alert">{{ $message }}</div>
</div>
