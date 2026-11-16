@props(['name' => 'code', 'length' => 6])
<div class="otp {{ $errors->has($name) ? 'is-invalid' : '' }}" data-otp role="group" aria-label="{{ $length }}-digit verification code">
    @for($i = 0; $i < $length; $i++)
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
               aria-label="Digit {{ $i + 1 }}" data-otp-input>
    @endfor
    <input type="hidden" name="{{ $name }}" data-otp-value>
</div>
<div class="field__error" data-error-for="{{ $name }}" role="alert" style="margin:-4px 0 12px">{{ $errors->first($name) }}</div>
