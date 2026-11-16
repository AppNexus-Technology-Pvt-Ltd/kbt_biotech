import { api, ApiError } from '../lib/http';
import { applyServerErrors, clearErrors, confirmDialog, esc, setBusy, toast } from '../lib/ui';

const debounce = (fn, ms = 300) => {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
};

const qs = (params) => new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString();

export function initOrganization() {
    const root = document.querySelector('[data-org-page]');
    if (root) initList(root);
    const form = document.querySelector('[data-facility-form-page]');
    if (form) initFacilityForm(form);
}

/* ------------------------------------------------------------ list pages */
function initList(root) {
    const cfg = JSON.parse(root.dataset.config);
    const canManage = root.dataset.canManage === '1';
    const rows = root.querySelector('[data-org-rows]');
    const summary = root.querySelector('[data-org-summary]');
    const pager = root.querySelector('[data-org-pager]');
    const facilitySel = document.querySelector('[data-org-facility]');
    const buildingSel = document.querySelector('[data-org-building]');
    const dialog = document.querySelector('[data-org-dialog]');
    const colspan = cfg.columns.length + 2;

    const state = { page: 1, q: '', filters: {}, options: { facilities: [], buildings: [], departments: [], staff: [] } };
    let reqId = 0;

    const loadOptions = async (facilityId = '') => {
        const res = await api('GET', `/api/org/options?${qs({ facility_id: facilityId })}`);
        return res.data;
    };

    const fillPicker = (sel, items, allLabel, selected) => {
        if (!sel) return;
        sel.innerHTML = `<option value="">${esc(allLabel)}</option>` + items.map((i) => `<option value="${i.id}">${esc(i.name)}</option>`).join('');
        sel.value = selected ? String(selected) : '';
    };

    /* ---- listing */
    const statusPill = (active) => `<span class="pill pill--${active ? 'active' : 'inactive'}">${active ? 'Active' : 'Inactive'}</span>`;
    const cell = (row, [key, , kind]) => {
        if (key === 'is_active') return statusPill(row.is_active);
        if (kind === 'color') return `<span class="swatch" style="background:${esc(row[key])}" aria-hidden="true"></span> <span class="muted">${esc(row[key])}</span>`;
        if (kind === 'priority') return `<span class="pill" style="background:${esc(row.color)}22;color:${esc(row.color)}">${esc(row[key])}</span>`;
        if (kind === 'type') return `<span class="muted-pill">${esc(row[key] === 'clinical' ? 'Clinical' : 'Non-Clinical')}</span>`;
        const v = row[key];
        return v === null || v === undefined || v === '' ? '<span class="muted">—</span>' : esc(v);
    };

    async function load() {
        const id = ++reqId;
        rows.innerHTML = `<tr><td colspan="${colspan}"><div class="empty">Loading…</div></td></tr>`;
        const params = { page: state.page, q: state.q, ...state.filters, facility_id: facilitySel?.value, building_id: buildingSel?.value };
        try {
            const res = await api('GET', `/api/org/${cfg.api}?${qs(params)}`);
            if (id !== reqId) return;
            render(res);
        } catch (e) {
            if (id !== reqId) return;
            rows.innerHTML = `<tr><td colspan="${colspan}"><div class="empty"><strong>Could not load ${esc(cfg.title.toLowerCase())}</strong><span>${esc(e.message)}</span><button class="link" data-retry>Try again</button></div></td></tr>`;
            rows.querySelector('[data-retry]')?.addEventListener('click', load);
        }
    }

    function render({ data, meta }) {
        if (!data.length) {
            const filtered = state.q || Object.values(state.filters).some(Boolean);
            rows.innerHTML = `<tr><td colspan="${colspan}"><div class="empty"><strong>${filtered ? 'No matching records' : `No ${esc(cfg.title.toLowerCase())} yet`}</strong><span>${filtered ? 'Try changing your search or filters.' : (canManage ? `Use “${esc(cfg.add)}” to create the first one.` : 'Nothing has been set up yet.')}</span></div></td></tr>`;
        } else {
            rows.innerHTML = data.map((row, i) => `
                <tr data-id="${row.id}">
                    <td>${meta.from + i}</td>
                    ${cfg.columns.map((c) => `<td>${cell(row, c)}</td>`).join('')}
                    <td class="row-actions">${actions(row)}</td>
                </tr>`).join('');
        }
        summary.textContent = meta.total ? `Showing ${meta.from} to ${meta.to} of ${meta.total} records` : '';
        renderPager(meta);
        rows.dataset.rows = JSON.stringify(data);
    }

    function actions(row) {
        if (!canManage) return '<span class="muted">—</span>';
        const edit = `<button class="icon-btn" data-act="edit" title="Edit" aria-label="Edit ${esc(row.name)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>`;
        const toggle = cfg.canToggle === false ? '' : `<button class="icon-btn" data-act="toggle" title="${row.is_active ? 'Deactivate' : 'Activate'}" aria-label="${row.is_active ? 'Deactivate' : 'Activate'} ${esc(row.name)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/><path d="M12 2v10"/></svg></button>`;
        const del = cfg.canDelete ? `<button class="icon-btn icon-btn--danger" data-act="delete" title="Delete" aria-label="Delete ${esc(row.name)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/></svg></button>` : '';
        return edit + toggle + del;
    }

    function renderPager({ page, last_page }) {
        if (last_page <= 1) { pager.innerHTML = ''; return; }
        const btn = (label, p, { disabled = false, current = false } = {}) =>
            `<button type="button" class="pager__btn ${current ? 'is-current' : ''}" data-page="${p}" ${disabled ? 'disabled' : ''} ${current ? 'aria-current="page"' : ''}>${label}</button>`;
        const pages = [];
        for (let p = Math.max(1, page - 2); p <= Math.min(last_page, page + 2); p++) pages.push(btn(p, p, { current: p === page }));
        pager.innerHTML = btn('&lsaquo;', page - 1, { disabled: page === 1 }) + pages.join('') + btn('&rsaquo;', page + 1, { disabled: page === last_page });
    }

    pager.addEventListener('click', (e) => {
        const b = e.target.closest('[data-page]');
        if (!b || b.disabled) return;
        state.page = Number(b.dataset.page);
        load();
    });

    root.querySelector('[data-org-search]')?.addEventListener('input', debounce((e) => { state.q = e.target.value.trim(); state.page = 1; load(); }));
    root.querySelectorAll('[data-org-filter]').forEach((sel) => sel.addEventListener('change', () => {
        state.filters[sel.dataset.orgFilter] = sel.value; state.page = 1; load();
    }));

    /* ---- facility / building pickers */
    facilitySel?.addEventListener('change', async () => {
        state.page = 1;
        if (buildingSel) {
            state.options = await loadOptions(facilitySel.value);
            fillPicker(buildingSel, state.options.buildings, 'All Buildings');
        }
        load();
    });
    buildingSel?.addEventListener('change', () => { state.page = 1; load(); });

    /* ---- row actions */
    rows.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const row = JSON.parse(rows.dataset.rows).find((r) => String(r.id) === btn.closest('tr').dataset.id);
        if (!row) return;

        if (btn.dataset.act === 'edit') {
            if (cfg.editUrl) { window.location.assign(cfg.editUrl.replace('__ID__', row.id)); return; }
            openForm(row);
        } else if (btn.dataset.act === 'toggle') {
            const next = !row.is_active;
            const ok = await confirmDialog({
                title: `${next ? 'Activate' : 'Deactivate'} ${cfg.noun}?`,
                message: next ? `${row.name} will be available again.` : `${row.name} will be hidden from new selections. Existing records keep their link to it.`,
                confirmLabel: next ? 'Activate' : 'Deactivate', danger: !next,
            });
            if (!ok) return;
            await run(() => api('PATCH', `/api/org/${cfg.api}/${row.id}/status`, { is_active: next }));
        } else if (btn.dataset.act === 'delete') {
            const ok = await confirmDialog({ title: `Delete ${cfg.noun}?`, message: `“${row.name}” will be permanently deleted. This cannot be undone.`, confirmLabel: 'Delete', danger: true });
            if (!ok) return;
            await run(() => api('DELETE', `/api/org/${cfg.api}/${row.id}`));
        }
    });

    async function run(call) {
        try {
            const res = await call();
            toast(res.message);
            load();
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    /* ---- add / edit dialog */
    const fieldsHost = dialog?.querySelector('[data-org-fields]');
    const form = dialog?.querySelector('[data-org-form]');
    const saveBtn = dialog?.querySelector('[data-org-save]');
    let editing = null;

    const optionList = (field) => {
        if (typeof field.options === 'object') return Object.entries(field.options).map(([v, t]) => [v, t]);
        const src = state.options[field.options] ?? [];
        return src.map((o) => [o.id, o.name + (o.designation ? ` (${o.designation})` : '')]);
    };

    function buildFields(row) {
        fieldsHost.innerHTML = cfg.fields.filter((f) => !(row && f.createOnly)).map((f) => {
            const value = row ? (row[f.name] ?? '') : (f.default ?? '');
            const id = `of-${f.name}`;
            const wide = f.name === 'description' || f.name === 'name' ? '' : '';
            let control;
            if (f.type === 'select') {
                const blank = f.blank !== undefined ? `<option value="">${esc(f.blank)}</option>` : `<option value="">Select…</option>`;
                control = `<div data-field><select class="select" id="${id}" name="${f.name}" data-opts="${esc(f.options && typeof f.options === 'string' ? f.options : '')}">${blank}${optionList(f).map(([v, t]) => `<option value="${esc(v)}" ${String(v) === String(value) ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></div>`;
            } else {
                control = `<div class="input-wrap" data-field><input id="${id}" name="${f.name}" type="${f.type}" value="${esc(value)}" ${f.type === 'number' ? 'step="1" min="0"' : (f.type === 'text' ? 'maxlength="255"' : '')} autocomplete="off"></div>`;
            }
            return `<div class="field ${wide}"><label class="field__label" for="${id}">${esc(f.label)}${f.required ? ' *' : ''}</label>${control}<div class="field__error" data-error-for="${f.name}" role="alert"></div></div>`;
        }).join('');
    }

    async function openForm(row = null) {
        editing = row;
        dialog.querySelector('[data-org-dialog-title]').textContent = row ? `Edit ${cfg.noun}` : cfg.add;
        // Dropdowns depend on the facility: use the record's own, or the one currently picked.
        const facilityId = row ? row.facility_id : (facilitySel?.value || '');
        try {
            state.options = await loadOptions(facilityId);
        } catch (e) { toast(e.message, 'error'); return; }
        buildFields(row);
        const facField = fieldsHost.querySelector('[name="facility_id"]');
        if (facField && facilityId) facField.value = String(facilityId);
        if (facField) {
            facField.addEventListener('change', async () => {
                state.options = await loadOptions(facField.value);
                fieldsHost.querySelectorAll('select[data-opts]').forEach((s) => {
                    const key = s.dataset.opts;
                    if (!['buildings', 'departments', 'staff', 'shifts'].includes(key)) return;
                    const first = s.options[0].outerHTML;
                    s.innerHTML = first + (state.options[key] ?? []).map((o) => `<option value="${o.id}">${esc(o.name)}</option>`).join('');
                });
            });
        }
        clearErrors(form);
        dialog.showModal();
        fieldsHost.querySelector('input, select')?.focus();
    }

    root.closest('main')?.querySelector('[data-org-add]')?.addEventListener('click', () => openForm());
    dialog?.querySelector('[data-org-cancel]')?.addEventListener('click', () => dialog.close());

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors(form);
        const body = Object.fromEntries(new FormData(form));
        Object.keys(body).forEach((k) => { if (body[k] === '') body[k] = null; });
        setBusy(saveBtn, true);
        try {
            const res = editing
                ? await api('PUT', `/api/org/${cfg.api}/${editing.id}`, body)
                : await api('POST', `/api/org/${cfg.api}`, body);
            dialog.close();
            toast(res.message);
            load();
        } catch (err) {
            if (err instanceof ApiError && err.status === 422 && Object.keys(err.errors).length) applyServerErrors(form, err.errors);
            else toast(err.message, 'error');
        } finally {
            setBusy(saveBtn, false);
        }
    });

    /* ---- boot */
    (async () => {
        try {
            state.options = await loadOptions('');
            const preselected = root.dataset.selectedFacility || '';
            fillPicker(facilitySel, state.options.facilities, 'All Facilities', preselected);
            if (buildingSel) {
                if (preselected) state.options = await loadOptions(preselected);
                fillPicker(buildingSel, state.options.buildings, 'All Buildings');
            }
        } catch (e) { toast(e.message, 'error'); }
        load();
    })();
}

/* ----------------------------------------------------- facility add / edit */
function initFacilityForm(form) {
    const id = form.dataset.id;
    const submit = form.querySelector('button[type="submit"]');
    const preview = form.querySelector('[data-image-preview]');
    const fileInput = form.querySelector('[data-image-input]');
    const switchInput = form.querySelector('input[name="is_active"]');
    const switchLabel = form.querySelector('[data-switch-label]');
    const syncSwitch = () => { switchLabel.textContent = switchInput.checked ? 'Active' : 'Inactive'; };
    switchInput.addEventListener('change', syncSwitch);
    syncSwitch();

    fileInput.addEventListener('change', () => {
        const file = fileInput.files[0];
        setFieldErrorSlot('image', '');
        if (!file) return;
        if (!['image/jpeg', 'image/png'].includes(file.type)) { fileInput.value = ''; setFieldErrorSlot('image', 'Use a JPG or PNG image.'); return; }
        if (file.size > 2 * 1024 * 1024) { fileInput.value = ''; setFieldErrorSlot('image', 'The image must be 2 MB or smaller.'); return; }
        const url = URL.createObjectURL(file);
        preview.innerHTML = `<img src="${url}" alt="Selected facility image">`;
    });
    const setFieldErrorSlot = (name, msg) => { const s = form.querySelector(`[data-error-for="${name}"]`); if (s) s.textContent = msg; };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors(form);
        const data = new FormData(form);
        data.set('is_active', switchInput.checked ? '1' : '0');
        if (id) data.set('_method', 'PUT');
        setBusy(submit, true);
        try {
            const res = await api('POST', id ? `/api/org/facilities/${id}` : '/api/org/facilities', data);
            sessionStorage.setItem('kbt.flash', res.message);
            window.location.assign(form.dataset.listUrl);
        } catch (err) {
            if (err instanceof ApiError && err.status === 422 && Object.keys(err.errors).length) {
                applyServerErrors(form, err.errors);
                form.querySelector('.is-invalid input, .is-invalid select')?.focus();
            } else {
                toast(err.message, 'error');
            }
            setBusy(submit, false);
        }
    });
}

/** Show a toast queued by the previous page (e.g. after saving a facility). */
export function showFlash() {
    try {
        const msg = sessionStorage.getItem('kbt.flash');
        if (msg) { sessionStorage.removeItem('kbt.flash'); toast(msg); }
    } catch { /* storage unavailable */ }
}
