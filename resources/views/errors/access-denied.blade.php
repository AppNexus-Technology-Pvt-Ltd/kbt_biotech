<x-layouts.auth-card title="Access denied">
    <div class="status-icon status-icon--danger"><x-icon name="lock" /></div>
    <h1 style="color:var(--danger)">Access Denied</h1>
    <p class="lead">{{ $message ?? 'You do not have permission to access this module. Please contact your administrator if you believe this is an error.' }}</p>
    <a class="btn btn--primary" href="{{ \App\Support\Home::urlFor(auth()->user()) }}">Go to Dashboard</a>
</x-layouts.auth-card>
