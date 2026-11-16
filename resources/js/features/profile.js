import { api, ApiError } from '../lib/http';
import { applyServerErrors, clearErrors, confirmDialog, esc, formatDate, setBusy, setFieldError, timeAgo, toast } from '../lib/ui';
import { passwordIsStrong } from './auth';

const PHONE_RE = /^\+?[0-9][0-9 ()\-]{6,19}$/;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const ACTIVITY = {
    'profile.updated': 'Profile details updated',
    'profile.photo_updated': 'Profile photo changed',
    'auth.password_changed': 'Password changed',
    'auth.password_reset': 'Password reset via email link',
    'auth.two_factor_changed': 'Two-factor setting changed',
    'auth.sessions_revoked': 'Signed out other devices',
};

export function initProfile() {
    const root = document.querySelector('[data-profile]');
    if (!root) return;
    const $ = (s) => root.querySelector(s);
    const $$ = (s) => [...root.querySelectorAll(s)];
    const d = root.dataset;

    initTabs();
    initPersonalForm();
    initPhoto();
    initPrefs();
    initPassword();
    initTwoFactor();
    initSessions();
    refresh();

    /* ------------------------------------------------------------------ tabs */
    function initTabs() {
        const tabs = $$('[data-tab]');
        const select = (name, focus = false) => {
            tabs.forEach((t) => {
                const on = t.dataset.tab === name;
                t.setAttribute('aria-selected', String(on));
                t.tabIndex = on ? 0 : -1;
                if (on && focus) t.focus();
            });
            $$('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== name; });
            history.replaceState(null, '', `#${name}`);
        };
        tabs.forEach((t, i) => {
            t.addEventListener('click', () => select(t.dataset.tab));
            t.addEventListener('keydown', (e) => {
                const dir = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
                if (dir) { e.preventDefault(); select(tabs[(i + dir + tabs.length) % tabs.length].dataset.tab, true); }
            });
        });
        const initial = location.hash.slice(1);
        if (tabs.some((t) => t.dataset.tab === initial)) select(initial);
    }

    /* ------------------------------------------------------- personal details */
    function initPersonalForm() {
        const form = $('[data-profile-form]');
        const actions = $('[data-edit-actions]');
        const toggle = $('[data-edit-toggle]');
        const fields = [...form.querySelectorAll('[data-editable]')];
        const original = () => Object.fromEntries(fields.map((f) => [f.name, f.defaultValue]));

        const setEditing = (on) => {
            fields.forEach((f) => { f.readOnly = !on; f.closest('[data-field]').classList.toggle('is-readonly', !on); });
            actions.hidden = !on;
            actions.style.display = on ? 'flex' : 'none';
            toggle.hidden = on;
            if (on) fields[0].focus();
        };
        toggle.addEventListener('click', () => setEditing(true));
        $('[data-edit-cancel]').addEventListener('click', () => {
            const o = original();
            fields.forEach((f) => { f.value = o[f.name]; });
            clearErrors(form);
            setEditing(false);
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearErrors(form);
            const errors = {};
            if (form.elements.name.value.trim().length < 2) errors.name = 'Enter your full name.';
            if (!EMAIL_RE.test(form.email.value.trim())) errors.email = 'Enter a valid email address.';
            if (form.phone.value.trim() && !PHONE_RE.test(form.phone.value.trim())) errors.phone = 'Enter a valid phone number, e.g. +91 98765 43210.';
            if (Object.keys(errors).length) { applyServerErrors(form, errors); return; }

            const btn = form.querySelector('button[type="submit"]');
            setBusy(btn, true);
            try {
                const res = await api('PUT', d.update, {
                    name: form.elements.name.value.trim(), email: form.email.value.trim(), phone: form.phone.value.trim() || null,
                });
                fields.forEach((f) => { f.defaultValue = f.value = res.data[f.name] ?? ''; });
                root.querySelector('[data-view="name"]').textContent = res.data.name;
                root.querySelector('[data-view="email"]').textContent = res.data.email;
                document.querySelectorAll('.profile-trigger__text strong').forEach((el) => { el.textContent = res.data.name; });
                setEditing(false);
                renderActivity(res.data.activity);
                toast(res.message);
            } catch (err) {
                if (err instanceof ApiError && err.status === 422) applyServerErrors(form, err.errors);
                else toast(err.message, 'error');
            } finally { setBusy(btn, false); }
        });
    }

    /* ---------------------------------------------------------------- photo */
    function initPhoto() {
        const input = $('[data-photo-input]');
        $('[data-photo-trigger]').addEventListener('click', () => input.click());
        input.addEventListener('change', async () => {
            const file = input.files[0];
            if (!file) return;
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { toast('Use a JPG, PNG or WebP image.', 'error'); input.value = ''; return; }
            if (file.size > 2 * 1024 * 1024) { toast('The photo must be 2 MB or smaller.', 'error'); input.value = ''; return; }
            const body = new FormData();
            body.append('photo', file);
            try {
                const res = await api('POST', d.photo, body);
                document.querySelectorAll('[data-avatar]').forEach((el) => { el.innerHTML = `<img src="${esc(res.data.photo_url)}" alt="">`; });
                toast(res.message);
            } catch (err) {
                toast(err.errors?.photo?.[0] ?? err.message, 'error');
            } finally { input.value = ''; }
        });
    }

    /* ---------------------------------------------------------- preferences */
    function initPrefs() {
        const form = $('[data-prefs-form]');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            setBusy(btn, true);
            try {
                const res = await api('PUT', d.update, {
                    name: $('[data-profile-form]').elements.name.defaultValue, email: $('[data-profile-form]').email.defaultValue,
                    phone: $('[data-profile-form]').phone.defaultValue || null,
                    preferences: {
                        notify_email_security: form.notify_email_security.checked,
                        notify_email_work_orders: form.notify_email_work_orders.checked,
                        language: form.language.value,
                        density: form.density.value,
                    },
                });
                document.documentElement.dataset.density = res.data.preferences.density;
                toast('Preferences saved.');
            } catch (err) { toast(err.message, 'error'); } finally { setBusy(btn, false); }
        });
    }

    /* ------------------------------------------------------------- password */
    function initPassword() {
        const form = $('[data-password-form]');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearErrors(form);
            const errors = {};
            if (!form.current_password.value) errors.current_password = 'Enter your current password.';
            if (!passwordIsStrong(form.password.value)) errors.password = 'Password does not meet all requirements.';
            if (form.password_confirmation.value !== form.password.value) errors.password_confirmation = 'The two passwords do not match.';
            if (Object.keys(errors).length) { applyServerErrors(form, errors); return; }

            const btn = form.querySelector('button[type="submit"]');
            setBusy(btn, true);
            try {
                const res = await api('PUT', d.password, Object.fromEntries(new FormData(form)));
                form.reset();
                form.querySelector('input[data-password-rules]').dispatchEvent(new Event('input'));
                toast(res.message);
                loadSessions();
            } catch (err) {
                if (err instanceof ApiError && err.status === 422) applyServerErrors(form, err.errors);
                else toast(err.message, 'error');
            } finally { setBusy(btn, false); }
        });
    }

    /* ----------------------------------------------------------- two-factor */
    function initTwoFactor() {
        const btn = $('[data-2fa-toggle]');
        if (!btn) return;
        btn.addEventListener('click', async () => {
            const enable = btn.dataset.enabled !== '1';
            const result = await confirmDialog({
                title: enable ? 'Enable two-factor verification?' : 'Disable two-factor verification?',
                message: enable ? 'A 6-digit code will be emailed to you every time you sign in.' : 'You will no longer be asked for an emailed code when signing in.',
                confirmLabel: enable ? 'Enable' : 'Disable', danger: !enable,
                extra: '<label class="field__label" for="dlg-pw" style="margin-top:8px">Confirm your password</label><div class="input-wrap"><input id="dlg-pw" name="password" type="password" autocomplete="current-password" required></div>',
            });
            if (!result) return;
            try {
                const res = await api('PUT', d.twoFactor, { enabled: enable, password: result.password });
                btn.dataset.enabled = res.data.two_factor_enabled ? '1' : '0';
                btn.textContent = res.data.two_factor_enabled ? 'Disable' : 'Enable';
                const tag = $('[data-2fa-tag]');
                tag.textContent = res.data.two_factor_enabled ? 'Enabled' : 'Disabled';
                tag.className = `tag ${res.data.two_factor_enabled ? 'tag--ok' : 'tag--off'}`;
                toast(res.message);
            } catch (err) { toast(err.errors?.password?.[0] ?? err.message, 'error'); }
        });
    }

    /* -------------------------------------------------------------- sessions */
    function initSessions() {
        loadSessions();
        $('[data-logout-others]').addEventListener('click', async () => {
            const result = await confirmDialog({
                title: 'Log out from other devices?', message: 'All other browsers and devices will be signed out. This device stays signed in.',
                confirmLabel: 'Log out others', danger: true,
                extra: '<label class="field__label" for="dlg-pw" style="margin-top:8px">Confirm your password</label><div class="input-wrap"><input id="dlg-pw" name="password" type="password" autocomplete="current-password" required></div>',
            });
            if (!result) return;
            try {
                const res = await api('DELETE', d.sessionsOthers, { password: result.password });
                toast(res.message);
                loadSessions();
            } catch (err) { toast(err.errors?.password?.[0] ?? err.message, 'error'); }
        });
    }

    async function loadSessions() {
        const host = $('[data-sessions-list]');
        try {
            const { data } = await api('GET', d.sessions);
            host.innerHTML = data.length ? data.map((s) => `
                <div class="session"><div><strong>${esc(s.device)}</strong>${s.current ? ' <span class="tag tag--ok">This device</span>' : ''}
                <small>${esc(s.ip ?? 'Unknown IP')} · active ${esc(timeAgo(s.last_active))}</small></div>
                ${s.current ? '' : `<button type="button" class="btn btn--outline btn--sm btn--auto" data-revoke="${esc(s.id)}">Sign out</button>`}</div>`).join('')
                : '<div class="search-empty">No active sessions.</div>';
            host.querySelectorAll('[data-revoke]').forEach((b) => b.addEventListener('click', async () => {
                if (!await confirmDialog({ title: 'Sign out this device?', message: 'That session will end immediately.', confirmLabel: 'Sign out', danger: true })) return;
                try { toast((await api('DELETE', `${d.sessions}/${b.dataset.revoke}`)).message); loadSessions(); }
                catch (err) { toast(err.message, 'error'); }
            }));
        } catch { host.innerHTML = '<div class="search-empty">Could not load sessions.</div>'; }
    }

    /* --------------------------------------------------- last login / activity */
    async function refresh() {
        try {
            const { data } = await api('GET', d.api);
            const last = $('[data-last-login]');
            if (data.last_login_at) last.textContent = `${formatDate(data.last_login_at)}${data.last_login_ip ? ` from ${data.last_login_ip}` : ''}`;
            renderActivity(data.activity);
        } catch { /* secondary info; page remains usable */ }
    }

    function renderActivity(items) {
        $('[data-activity-list]').innerHTML = items.length
            ? items.map((a) => `<div class="session"><div><strong>${esc(ACTIVITY[a.action] ?? a.action)}</strong><small>${esc(formatDate(a.at))}${a.ip ? ` · ${esc(a.ip)}` : ''}</small></div></div>`).join('')
            : '<div class="search-empty">No recent changes.</div>';
    }
}
