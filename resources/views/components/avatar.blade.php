@props(['user', 'large' => false])
<span {{ $attributes->class(['avatar', 'avatar--lg' => $large]) }} data-avatar>
    @if($user->photoUrl())<img src="{{ $user->photoUrl() }}" alt="">@else{{ $user->initials() }}@endif
</span>
