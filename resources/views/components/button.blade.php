@props(['variant' => 'primary', 'type' => 'submit', 'loading' => 'Please wait…'])
<button type="{{ $type }}" {{ $attributes->class(['btn', 'btn--'.$variant]) }} data-loading-text="{{ $loading }}">
    <span class="spinner" hidden aria-hidden="true"></span>
    <span class="btn__label">{{ $slot }}</span>
</button>
