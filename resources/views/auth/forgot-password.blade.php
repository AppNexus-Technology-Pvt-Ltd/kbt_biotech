<x-layouts.auth-card title="Forgot password">
    <h1>Forgot Password?</h1>
    <p class="lead">Enter your registered email to receive a reset link.</p>

    @if(session('status'))<x-alert type="success">{{ session('status') }}</x-alert>@endif

    <form method="POST" action="{{ route('password.email') }}" novalidate data-form data-validate="forgot">
        @csrf
        <x-input label="Email Address" name="email" type="email" icon="mail" placeholder="Enter your registered email"
                 autocomplete="email" autofocus required />
        <x-button loading="Sending…">Send Reset Link</x-button>
    </form>

    <div class="auth-card__footer">
        <a class="link link--back" href="{{ route('login') }}"><x-icon name="arrow-left" /> Back to Login</a>
    </div>
</x-layouts.auth-card>
