import { api, ApiError } from '../lib/http';
import { applyServerErrors, clearErrors, setBusy, setFieldError, toast } from '../lib/ui';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

export const PASSWORD_RULES = {
    length: (v) => v.length >= 8,
    upper: (v) => /[A-Z]/.test(v),
    lower: (v) => /[a-z]/.test(v),
    number: (v) => /[0-9]/.test(v),
    special: (v) => /[^A-Za-z0-9]/.test(v),
};

/* ------------------------------------------------------------ show / hide password */
export function initPasswordToggles(root = document) {
    root.querySelectorAll('[data-toggle-password]').forEach((btn) => {
        if (btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => {
            const input = btn.closest('[data-field]').querySelector('input');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', String(show));
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.querySelector('[data-icon-show]').style.display = show ? 'none' : '';
            btn.querySelector('[data-icon-hide]').style.display = show ? '' : 'none';
            input.focus();
        });
    });
}

/* --------------------------------------------------------------- password checklist */
export function initChecklists(root = document) {
    root.querySelectorAll('input[data-password-rules]').forEach((input) => {
        const list = input.closest('form')?.querySelector('[data-checklist]');
        if (!list || input.dataset.bound) return;
        input.dataset.bound = '1';
        const update = () => list.querySelectorAll('[data-rule]').forEach((li) => {
            li.classList.toggle('is-ok', PASSWORD_RULES[li.dataset.rule](input.value));
        });
        input.addEventListener('input', update);
        update();
    });
}

export const passwordIsStrong = (value) => Object.values(PASSWORD_RULES).every((rule) => rule(value));

/* ------------------------------------------------------------- form validation */
const validators = {
    login(form) {
        const errors = {};
        if (!form.login.value.trim()) errors.login = 'Enter your username or email.';
        if (!form.password.value) errors.password = 'Enter your password.';
        return errors;
    },
    forgot(form) {
        const v = form.email.value.trim();
        if (!v) return { email: 'Enter your registered email.' };
        return EMAIL_RE.test(v) ? {} : { email: 'Enter a valid email address.' };
    },
    otp(form) {
        return /^\d{6}$/.test(form.code.value) ? {} : { code: 'Enter all 6 digits of the code.' };
    },
    reset(form) {
        const errors = {};
        if (!passwordIsStrong(form.password.value)) errors.password = 'Password does not meet all requirements.';
        if (form.password_confirmation.value !== form.password.value) errors.password_confirmation = 'The two passwords do not match.';
        else if (!form.password_confirmation.value) errors.password_confirmation = 'Confirm your new password.';
        return errors;
    },
};

export function initAuthForms() {
    document.querySelectorAll('form[data-form]').forEach((form) => {
        const button = form.querySelector('button[type="submit"]');
        form.addEventListener('submit', (event) => {
            if (form.dataset.submitting) { event.preventDefault(); return; }
            clearErrors(form);
            const errors = validators[form.dataset.validate]?.(form) ?? {};
            if (Object.keys(errors).length) {
                event.preventDefault();
                applyServerErrors(form, errors);
                const first = Object.keys(errors)[0];
                (form.querySelector(`[name="${first}"]`) ?? form.querySelector('[data-otp-input]'))?.focus();
                return;
            }
            form.dataset.submitting = '1';
            setBusy(button, true);
        });
        // Clear a field's message as soon as the user edits it.
        form.addEventListener('input', (e) => e.target.name && setFieldError(form, e.target.name, ''));
    });
    // Back/forward cache can restore a page with a stuck spinner.
    window.addEventListener('pageshow', () => document.querySelectorAll('form[data-form]').forEach((f) => {
        delete f.dataset.submitting;
        setBusy(f.querySelector('button[type="submit"]'), false);
    }));
}

/* ------------------------------------------------------------------------- OTP */
export function initOtp() {
    const group = document.querySelector('[data-otp]');
    if (!group) return;
    const inputs = [...group.querySelectorAll('[data-otp-input]')];
    const hidden = group.querySelector('[data-otp-value]');
    const sync = () => { hidden.value = inputs.map((i) => i.value).join(''); };
    const fill = (digits) => {
        inputs.forEach((input, i) => { input.value = digits[i] ?? ''; });
        sync();
        inputs[Math.min(digits.length, inputs.length - 1)].focus();
    };

    inputs.forEach((input, i) => {
        input.addEventListener('input', () => {
            const digits = input.value.replace(/\D/g, '');
            if (digits.length > 1) return fill(digits.slice(0, inputs.length)); // autofill / IME delivering several digits
            input.value = digits;
            sync();
            if (digits && inputs[i + 1]) inputs[i + 1].focus();
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !input.value && inputs[i - 1]) {
                e.preventDefault();
                inputs[i - 1].value = '';
                inputs[i - 1].focus();
                sync();
            } else if (e.key === 'ArrowLeft' && inputs[i - 1]) { inputs[i - 1].focus(); }
            else if (e.key === 'ArrowRight' && inputs[i + 1]) { inputs[i + 1].focus(); }
        });
        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const digits = (e.clipboardData?.getData('text') ?? '').replace(/\D/g, '').slice(0, inputs.length);
            if (digits) fill(digits);
        });
        input.addEventListener('focus', () => input.select());
    });
    inputs[0].focus();

    /* countdown + resend */
    const expiresEl = document.querySelector('[data-otp-expires]');
    const resend = document.querySelector('[data-otp-resend]');
    let expires = Number(expiresEl?.dataset.seconds ?? 0);
    let cooldown = Number(resend?.dataset.cooldown ?? 0);
    const fmt = (s) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;

    const paint = () => {
        if (expiresEl) {
            expiresEl.textContent = expires > 0 ? fmt(expires) : 'expired';
            expiresEl.style.color = expires > 0 ? '' : 'var(--danger)';
        }
        if (resend) {
            resend.disabled = cooldown > 0 || resend.dataset.busy === '1';
            resend.textContent = cooldown > 0 ? `Resend OTP (${cooldown}s)` : 'Resend OTP';
        }
    };
    paint();
    setInterval(() => { if (expires > 0) expires--; if (cooldown > 0) cooldown--; paint(); }, 1000);

    resend?.addEventListener('click', async () => {
        resend.dataset.busy = '1';
        paint();
        try {
            const res = await api('POST', resend.dataset.url);
            expires = res.data.expires_in;
            cooldown = res.data.resend_in;
            fill([]); inputs[0].focus();
            clearErrors(group.closest('form'));
            toast(res.message);
        } catch (e) {
            if (e instanceof ApiError) {
                if (e.status === 429 && e.data?.retry_after) cooldown = e.data.retry_after;
                toast(e.message, 'error');
            }
        } finally {
            delete resend.dataset.busy;
            paint();
        }
    });
}
