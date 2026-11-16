/** Small fetch wrapper: JSON in/out, CSRF header, and uniform handling of expired sessions. */
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export class ApiError extends Error {
    constructor(message, status, errors = {}, data = null) {
        super(message);
        this.status = status;
        this.errors = errors;
        this.data = data;
    }
}

export async function api(method, url, body = null) {
    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' };
    const options = { method, headers, credentials: 'same-origin' };

    if (body instanceof FormData) {
        options.body = body;
    } else if (body !== null) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(url, options);
    } catch {
        throw new ApiError('Network error. Check your connection and try again.', 0);
    }

    let payload = null;
    try { payload = await response.json(); } catch { /* non-JSON body */ }

    // Session ended (idle timeout, logged out elsewhere, CSRF token stale): go to the right screen.
    if (response.status === 401 || response.status === 419) {
        window.location.assign(payload?.redirect ?? '/login');
        throw new ApiError(payload?.message ?? 'Session expired.', response.status);
    }
    if (!response.ok) {
        throw new ApiError(payload?.message ?? 'Something went wrong. Please try again.', response.status, payload?.errors ?? {}, payload?.data ?? null);
    }
    return payload;
}
