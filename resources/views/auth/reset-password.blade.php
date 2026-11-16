<x-layouts.auth-card title="Reset password">
    @if(! $valid)
        <div class="status-icon status-icon--warn"><x-icon name="clock" /></div>
        <h1>Link expired</h1>
        <p class="lead">This password reset link is invalid, has expired, or was already used.</p>
        <a class="btn btn--primary" href="{{ route('password.request') }}">Request a new link</a>
        <div class="auth-card__footer"><a class="link link--back" href="{{ route('login') }}"><x-icon name="arrow-left" /> Back to Login</a></div>
    @else
        <h1>Reset Password</h1>
        <p class="lead">Create a new password for your account.</p>

        <form method="POST" action="{{ route('password.update') }}" novalidate data-form data-validate="reset">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <x-password-input label="New Password" name="password" placeholder="Enter new password" autocomplete="new-password" required data-password-rules />

            <ul class="checklist" data-checklist aria-label="Password requirements">
                <li data-rule="length"><span class="dot"><x-icon name="check" /></span> At least 8 characters</li>
                <li data-rule="upper"><span class="dot"><x-icon name="check" /></span> One uppercase letter</li>
                <li data-rule="lower"><span class="dot"><x-icon name="check" /></span> One lowercase letter</li>
                <li data-rule="number"><span class="dot"><x-icon name="check" /></span> One number</li>
                <li data-rule="special"><span class="dot"><x-icon name="check" /></span> One special character</li>
            </ul>

            <x-password-input label="Confirm Password" name="password_confirmation" placeholder="Confirm new password" autocomplete="new-password" required />
            <x-button loading="Resetting…">Reset Password</x-button>
        </form>
    @endif
</x-layouts.auth-card>
