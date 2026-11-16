import { api, ApiError } from '../lib/http';
import { applyServerErrors, clearErrors, confirmDialog, esc, setBusy, toast } from '../lib/ui';

const debounce = (fn, ms = 300) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const qs = (params) => new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString();
const dash = (v) => (v === null || v === undefined || v === '' ? '<span class="muted">—</span>' : esc(v));
const STATUS_LABELS = { operational: 'Operational', under_maintenance: 'Under Maintenance', out_of_service: 'Out of Service', retired: 'Retired', disposed: 'Disposed' };
const stPill = (s, l) => `<span class="pill pill--st-${esc(s)}">${esc(l ?? STATUS_LABELS[s] ?? s)}</span>`;

export function initAssetWorkflow() {
    document.querySelectorAll('[data-wf]').forEach((el) => {
        const kind = el.dataset.wf;
        if (kind === 'transfer') initTransferForm(el);
        else if (kind === 'status') initStatusForm(el);
        else if (kind === 'dispose') initDisposeForm(el);
        else if (kind === 'transfers') initList(el, 7, transferRow, transferActions);
        else if (kind === 'lifecycle') initList(el, 5, lifecycleRow);
    });
}

/* ----------------------------------------------------- generic list page */
function initList(root, colspan, rowHtml, afterRender = () => {}) {
    const rows = root.querySelector('[data-rows]'); const summary = root.querySelector('[data-summary]'); const pager = root.querySelector('[data-pager]');
    const filters = {};
    root.querySelectorAll('[data-f]').forEach((el) => { filters[el.dataset.f] = el; });
    const state = { page: 1 };
    let reqId = 0;

    async function load() {
        const id = ++reqId;
        rows.innerHTML = `<tr><td colspan="${colspan}"><div class="empty">Loading…</div></td></tr>`;
        const params = { page: state.page, ...Object.fromEntries(Object.entries(filters).map(([k, el]) => [k, el.value.trim()])) };
        try {
            const { data, meta } = await api('GET', `${root.dataset.url}?${qs(params)}`);
            if (id !== reqId) return;
            if (!data.length) {
                const filtered = Object.values(params).slice(1).some(Boolean);
                rows.innerHTML = `<tr><td colspan="${colspan}"><div class="empty"><strong>${filtered ? 'No matching records' : 'Nothing here yet'}</strong><span>${filtered ? 'Try changing your search or filters.' : 'Records will appear here as they are created.'}</span></div></td></tr>`;
            } else {
                rows.innerHTML = data.map(rowHtml).join('');
                rows.dataset.rows = JSON.stringify(data);
            }
            summary.textContent = meta.total ? `Showing ${meta.from} to ${meta.to} of ${meta.total} records` : '';
            const btn = (l, p, dis = false, cur = false) => `<button type="button" class="pager__btn ${cur ? 'is-current' : ''}" data-page="${p}" ${dis ? 'disabled' : ''}>${l}</button>`;
            if (meta.last_page <= 1) pager.innerHTML = '';
            else {
                let h = btn('&lsaquo;', meta.page - 1, meta.page === 1);
                for (let p = Math.max(1, meta.page - 2); p <= Math.min(meta.last_page, meta.page + 2); p++) h += btn(p, p, false, p === meta.page);
                pager.innerHTML = h + btn('&rsaquo;', meta.page + 1, meta.page === meta.last_page);
            }
        } catch (e) {
            if (id !== reqId) return;
            rows.innerHTML = `<tr><td colspan="${colspan}"><div class="empty"><strong>Could not load</strong><span>${esc(e.message)}</span><button class="link" data-retry>Try again</button></div></td></tr>`;
            rows.querySelector('[data-retry]').addEventListener('click', load);
        }
    }
    pager.addEventListener('click', (e) => { const b = e.target.closest('[data-page]'); if (!b || b.disabled) return; state.page = Number(b.dataset.page); load(); });
    filters.q?.addEventListener('input', debounce(() => { state.page = 1; load(); }));
    filters.status?.addEventListener('change', () => { state.page = 1; load(); });
    afterRender(root, rows, load);
    load();
}

const lifecycleRow = (c) => `<tr><td>${esc(c.effective_date)}</td><td><a class="link" href="${esc(c.asset_url)}">${esc(c.asset_tag)}</a> <span class="muted">${esc(c.asset_name)}</span></td>
    <td>${stPill(c.from, c.from_label)} → ${stPill(c.to, c.to_label)}</td><td>${esc(c.reason)}${c.remarks ? `<div class="muted">${esc(c.remarks)}</div>` : ''}</td><td>${dash(c.by)}</td></tr>`;

/* ----------------------------------------------------------- transfers */
const trPill = (t) => `<span class="pill pill--${esc(t.status)}">${esc(t.status_label)}</span>`;
const transferRow = (t) => `<tr data-id="${t.id}">
    <td><a class="link" href="${esc(t.asset_url)}">${esc(t.asset_tag)}</a> <span class="muted">${esc(t.asset_name)}</span></td>
    <td>${dash(t.from)}</td><td>${dash(t.to)}</td>
    <td>${esc(t.requested_date)}<div class="muted">${dash(t.requested_by)}</div></td>
    <td>${esc(t.reason)}${t.decision_note ? `<div class="muted">${esc(t.decided_by ?? '')}: ${esc(t.decision_note)}</div>` : ''}</td>
    <td>${trPill(t)}</td>
    <td class="row-actions">${t.can.approve ? '<button class="btn btn--primary btn--sm btn--auto" data-act="approve">Approve</button> <button class="btn btn--outline btn--sm btn--auto" data-act="reject">Reject</button>' : ''}${t.can.cancel ? ' <button class="btn btn--outline btn--sm btn--auto" data-act="cancel">Cancel request</button>' : ''}</td></tr>`;

function transferActions(root, rows, reload) {
    const dialog = document.querySelector('[data-reject-dialog]');
    const form = dialog.querySelector('[data-reject-form]');
    let rejecting = null;
    dialog.querySelector('[data-reject-cancel]').addEventListener('click', () => dialog.close());

    const run = async (call) => { try { toast((await call()).message); reload(); } catch (e) { toast(e.message, 'error'); reload(); } };

    rows.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const t = JSON.parse(rows.dataset.rows).find((r) => String(r.id) === btn.closest('tr').dataset.id);
        const url = `${root.dataset.base}/${t.id}`;
        if (btn.dataset.act === 'approve') {
            if (!await confirmDialog({ title: 'Approve transfer?', message: `${t.asset_tag} will move to ${t.to} immediately.`, confirmLabel: 'Approve' })) return;
            run(() => api('POST', `${url}/approve`, {}));
        } else if (btn.dataset.act === 'cancel') {
            if (!await confirmDialog({ title: 'Cancel transfer request?', message: `The request for ${t.asset_tag} will be withdrawn.`, confirmLabel: 'Cancel request', danger: true })) return;
            run(() => api('POST', `${url}/cancel`, {}));
        } else {
            rejecting = t; clearErrors(form); form.reset(); dialog.showModal(); form.querySelector('input').focus();
        }
    });
    form.addEventListener('submit', async (e) => {
        e.preventDefault(); clearErrors(form);
        try {
            const res = await api('POST', `${root.dataset.base}/${rejecting.id}/reject`, { note: form.note.value });
            dialog.close(); toast(res.message); reload();
        } catch (err) {
            if (err instanceof ApiError && err.status === 422 && err.errors.note) applyServerErrors(form, err.errors); else { dialog.close(); toast(err.message, 'error'); reload(); }
        }
    });
}

/* ----------------------------------------------------- shared form bits */
async function loadAsset(form) {
    const { data } = await api('GET', form.dataset.assetUrl);
    const line = form.querySelector('[data-asset-line]');
    if (line) line.textContent = `${data.name} · ${[data.manufacturer, data.model].filter(Boolean).join(' ')}`;
    return data;
}

async function submitForm(form, buildBody, onDone, confirmCfg = null) {
    clearErrors(form);
    const btn = form.querySelector('button[type="submit"]');
    if (confirmCfg && !await confirmDialog(confirmCfg)) return;
    setBusy(btn, true);
    try {
        const res = await api('POST', form.dataset.submit, buildBody());
        try { sessionStorage.setItem('kbt.flash', res.message); } catch { /* ignore */ }
        onDone(res);
    } catch (err) {
        if (err instanceof ApiError && err.status === 422 && Object.keys(err.errors).length) {
            applyServerErrors(form, err.errors);
            form.querySelector('.is-invalid input, .is-invalid select')?.focus();
            toast('Please correct the highlighted fields.', 'error');
        } else toast(err.message, 'error');
        setBusy(btn, false);
    }
}

const values = (form) => Object.fromEntries([...new FormData(form)].map(([k, v]) => [k, v === '' ? null : v]));

/* ------------------------------------------------------ transfer (8) */
async function initTransferForm(form) {
    const el = (n) => form.querySelector(`[name="${n}"]`);
    let options = { facilities: [], departments: [], locations: [] };
    const fill = (sel, items, ph) => { sel.innerHTML = `<option value="">${esc(ph)}</option>` + items.map((i) => `<option value="${i.id}">${esc(i.name)}</option>`).join(''); };
    const refill = () => {
        const f = el('to_facility_id').value;
        fill(el('to_department_id'), options.departments, f ? 'Select department' : 'Select a facility first');
        fill(el('to_location_id'), options.locations, f ? 'Select location' : 'Select a facility first');
    };
    try {
        const asset = await loadAsset(form);
        form.querySelector('[data-current]').textContent = [asset.facility, asset.location_path].filter(Boolean).join(' › ') || '—';
        options = (await api('GET', form.dataset.options)).data;
        fill(el('to_facility_id'), options.facilities, 'Select facility');
        options = { ...options, departments: [], locations: [] };
        refill();
    } catch (e) { toast(e.message, 'error'); }

    el('to_facility_id').addEventListener('change', async () => {
        const f = el('to_facility_id').value;
        options.departments = []; options.locations = [];
        if (f) {
            try { const { data } = await api('GET', `${form.dataset.options}?facility_id=${f}`); options.departments = data.departments; options.locations = data.locations; } catch (e) { toast(e.message, 'error'); }
        }
        refill();
    });
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        submitForm(form, () => values(form), (res) => window.location.assign(res.data.url ?? form.dataset.done));
    });
}

/* ----------------------------------------------------- status (9) */
async function initStatusForm(form) {
    const submitLabel = form.querySelector('button[type="submit"] .btn__label');
    let current = null;
    try {
        const asset = await loadAsset(form);
        current = asset.status;
        form.querySelector('[data-current-line]').textContent = `Current status: ${asset.status_label}`;
        const same = form.querySelector(`input[name="status"][value="${current}"]`);
        if (same) { same.disabled = true; same.closest('label').classList.add('is-disabled'); }
    } catch (e) { toast(e.message, 'error'); }

    const retiring = () => form.querySelector('input[name="status"]:checked')?.dataset.retire !== undefined;
    form.addEventListener('change', () => {
        const label = retiring() ? 'Continue to Retirement' : 'Update Status';
        submitLabel.textContent = label;
        form.querySelector('button[type="submit"]').dataset.originalLabel = label;
    });
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (retiring()) { window.location.assign(form.dataset.retireUrl); return; }
        submitForm(form, () => values(form), (res) => window.location.assign(res.data.url));
    });
}

/* -------------------------------------------------- disposal (10) */
async function initDisposeForm(form) {
    let asset = null;
    try { asset = await loadAsset(form); } catch (e) { toast(e.message, 'error'); }
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const type = form.querySelector('input[name="type"]:checked')?.value;
        const verb = type === 'disposal' ? 'dispose of' : 'retire';
        submitForm(form, () => values(form), () => window.location.assign(form.dataset.done), {
            title: `Confirm ${type === 'disposal' ? 'disposal' : 'retirement'}`,
            message: `You are about to ${verb} ${asset?.asset_tag ?? 'this asset'}. This cannot be undone.`,
            confirmLabel: type === 'disposal' ? 'Dispose' : 'Retire', danger: true,
        });
    });
}
