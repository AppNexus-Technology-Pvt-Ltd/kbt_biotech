@props(['type' => 'info'])
<div {{ $attributes->class(['alert', 'alert--'.$type]) }} role="{{ $type === 'error' ? 'alert' : 'status' }}">
    <x-icon :name="$type === 'success' ? 'check-circle' : 'alert'" />
    <div>{{ $slot }}</div>
</div>
