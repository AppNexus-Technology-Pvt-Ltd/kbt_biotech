<x-layouts.auth-split title="Sign in">
    <h1>Welcome Back</h1>
    <p class="lead">Sign in to your KBT RUVIA BioTech account</p>

    @if(session('status'))<x-alert type="success">{{ session('status') }}</x-alert>@endif
    @if($errors->has('credentials'))<x-alert type="error">{{ $errors->first('credentials') }}</x-alert>@endif

    <form method="POST" action="{{ route('login.store') }}" novalidate data-form data-validate="login">
        @csrf
        <x-input label="Username / Email" name="login" icon="user" placeholder="Enter your username or email"
                 autocomplete="username" autofocus required />
        <x-password-input label="Password" name="password" placeholder="Enter your password" autocomplete="current-password" required />

        <div class="row-between">
            <label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me</label>
            <a class="link" href="{{ route('password.request') }}">Forgot Password?</a>
        </div>

        <x-button loading="Signing in…">Sign In</x-button>
    </form>
</x-layouts.auth-split>
