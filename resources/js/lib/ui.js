const ICON_OK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>';
const ICON_ERR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>';

export const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export function toast(message, type = 'success') {
    const host = document.getElementById('toasts');
    if (!host) return;
    const el = document.createElement('div');
    el.className = `toast toast--${type}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML = (type === 'error' ? ICON_ERR : ICON_OK) + `<span>${esc(message)}</span>`;
    host.appendChild(el);
    setTimeout(() => el.remove(), 5000);
}

/**
 * Promise-based confirmation dialog. `extra` is optional HTML injected above the buttons (e.g. a password field);
 * resolve value is `false` on cancel, otherwise the dialog element's form data as an object (or true).
 */
export function confirmDialog({ title, message, confirmLabel = 'Confirm', danger = false, extra = '' }) {
    const dialog = document.getElementById('confirm-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return Promise.resolve(window.confirm(message));
    }
    dialog.querySelector('#confirm-title').textContent = title;
    dialog.querySelector('#confirm-message').textContent = message;
    dialog.querySelector('[data-confirm-extra]').innerHTML = extra;
    const ok = dialog.querySelector('[data-confirm-ok]');
    ok.textContent = confirmLabel;
    ok.className = `btn btn--sm ${danger ? 'btn--danger' : 'btn--primary'}`;

    return new Promise((resolve) => {
        dialog.addEventListener('close', () => {
            if (dialog.returnValue !== 'ok') return resolve(false);
            const data = Object.fromEntries(new FormData(dialog.querySelector('form')));
            resolve(Object.keys(data).length ? data : true);
        }, { once: true });
        dialog.returnValue = 'cancel';
        dialog.showModal();
        dialog.querySelector('[data-confirm-extra] input')?.focus();
    });
}

export function timeAgo(iso) {
    if (!iso) return '';
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    const units = [[86400, 'day'], [3600, 'hour'], [60, 'minute']];
    for (const [size, name] of units) {
        if (seconds >= size) {
            const n = Math.floor(seconds / size);
            return `${n} ${name}${n > 1 ? 's' : ''} ago`;
        }
    }
    return '';
}

export const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

export function setBusy(button, busy) {
    if (!button) return;
    const label = button.querySelector('.btn__label');
    const spinner = button.querySelector('.spinner');
    if (busy) {
        button.dataset.originalLabel = label?.textContent ?? '';
        if (label && button.dataset.loadingText) label.textContent = button.dataset.loadingText;
        if (spinner) spinner.hidden = false;
        button.disabled = true;
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
    } else {
        if (label && button.dataset.originalLabel) label.textContent = button.dataset.originalLabel;
        if (spinner) spinner.hidden = true;
        button.disabled = false;
        button.classList.remove('is-loading');
        button.removeAttribute('aria-busy');
    }
}

/** Show a validation message under a named field (matches the [data-error-for] slot rendered by <x-input>). */
export function setFieldError(form, name, message) {
    const slot = form.querySelector(`[data-error-for="${name}"]`);
    const input = form.querySelector(`[name="${name}"]`);
    if (slot) slot.textContent = message ?? '';
    input?.closest('[data-field]')?.classList.toggle('is-invalid', Boolean(message));
    input?.setAttribute('aria-invalid', message ? 'true' : 'false');
    if (name === 'code') form.querySelector('[data-otp]')?.classList.toggle('is-invalid', Boolean(message));
}

export function clearErrors(form) {
    form.querySelectorAll('[data-error-for]').forEach((el) => setFieldError(form, el.dataset.errorFor, ''));
}

export function applyServerErrors(form, errors) {
    Object.entries(errors).forEach(([name, messages]) => setFieldError(form, name, Array.isArray(messages) ? messages[0] : messages));
}
