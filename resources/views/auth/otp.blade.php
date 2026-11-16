<x-layouts.auth-card title="Verify code">
    <h1>OTP Verification</h1>
    <p class="lead">We have sent a 6-digit code to<br><strong style="color:var(--text)">{{ $destination }}</strong></p>

    @if(session('status'))<x-alert type="success">{{ session('status') }}</x-alert>@endif
    <div id="otp-live" class="sr-only" aria-live="polite"></div>

    <form method="POST" action="{{ route('otp.verify') }}" novalidate data-form data-validate="otp">
        @csrf
        <x-otp-input />

        <div class="otp-meta">
            <span>Code expires in <strong data-otp-expires data-seconds="{{ $expiresIn }}">--:--</strong></span>
            <button type="button" class="link" data-otp-resend data-url="{{ route('otp.send') }}" data-cooldown="{{ $resendIn }}" disabled>Resend OTP</button>
        </div>

        <x-button loading="Verifying…">Verify OTP</x-button>
    </form>

    <div class="auth-card__footer">
        <a class="link link--back" href="{{ route('login') }}"><x-icon name="arrow-left" /> Back</a>
    </div>
</x-layouts.auth-card>
