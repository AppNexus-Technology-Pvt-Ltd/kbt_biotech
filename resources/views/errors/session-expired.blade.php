<x-layouts.auth-card title="Session expired">
    <div class="status-icon status-icon--danger"><x-icon name="clock" /></div>
    <h1 style="color:var(--danger)">Session Expired</h1>
    <p class="lead">Your session has expired due to inactivity.<br>Please login again to continue.</p>
    <a class="btn btn--primary" href="{{ route('login') }}">Back to Login</a>
</x-layouts.auth-card>
