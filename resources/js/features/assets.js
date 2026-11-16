import { api, ApiError } from '../lib/http';
import { applyServerErrors, clearErrors, confirmDialog, esc, formatDate, setBusy, toast } from '../lib/ui';
import { qrDataUrl } from './assets-tools';

const debounce = (fn, ms = 300) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const qs = (params) => new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString();
const STATUS_COLORS = { operational: '#1f9d55', under_maintenance: '#e5484d', out_of_service: '#f08c1b' };
const STATUS_LABELS = { operational: 'Operational', under_maintenance: 'Under Maintenance', out_of_service: 'Out of Service', retired: 'Retired', disposed: 'Disposed' };
const money = (v) => (v === null || v === undefined || v === '' ? '—' : `₹ ${Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
const day = (v) => (v ? new Date(`${v}T00:00:00`).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const dash = (v) => (v === null || v === undefined || v === '' ? '<span class="muted">—</span>' : esc(v));
const statusPill = (status, label) => `<span class="pill pill--st-${esc(status)}">${esc(label ?? STATUS_LABELS[status] ?? status)}</span>`;

export function initAssets() {
    const dash_ = document.querySelector('[data-asset-dashboard]');
    if (dash_) initDashboard(dash_);
    const reg = document.querySelector('[data-asset-register]');
    if (reg) initRegister(reg);
    const form = document.querySelector('[data-asset-form]');
    if (form) initForm(form);
    const detail = document.querySelector('[data-asset-detail]');
    if (detail) initDetail(detail);
}

/** Accessible tab switcher shared by the form and the detail page. */
function initTabs(root, onShow = () => {}) {
    const tabs = [...root.querySelectorAll('[data-tab]')];
    const show = (key) => {
        tabs.forEach((t) => { const on = t.dataset.tab === key; t.classList.toggle('is-active', on); t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; });
        root.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== key; });
        onShow(key);
    };
    tabs.forEach((t, i) => {
        t.addEventListener('click', () => show(t.dataset.tab));
        t.addEventListener('keydown', (e) => {
            const step = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
            if (!step) return;
            const next = tabs[(i + step + tabs.length) % tabs.length];
            next.focus(); show(next.dataset.tab);
        });
    });
    return show;
}

/* ------------------------------------------------------------ dashboard */
function initDashboard(root) {
    const $ = (s) => root.querySelector(s);
    const show = (state) => {
        $('[data-dash-loading]').hidden = state !== 'loading';
        $('[data-dash-error]').hidden = state !== 'error';
        $('[data-dash-content]').hidden = state !== 'content';
    };
    async function load() {
        show('loading');
        try {
            const { data } = await api('GET', root.dataset.url);
            render(data);
            show('content');
        } catch (e) {
            $('[data-dash-error-msg]').textContent = e.message;
            show('error');
        }
    }
    $('[data-dash-retry]').addEventListener('click', load);

    function render(d) {
        $('[data-stat="total"]').textContent = d.total.value.toLocaleString();
        $('[data-note="total"]').textContent = d.total.new_this_month ? `+${d.total.new_this_month} this month` : '';
        ['operational', 'under_maintenance', 'out_of_service'].forEach((k) => {
            $(`[data-stat="${k}"]`).textContent = d[k].value.toLocaleString();
            $(`[data-note="${k}"]`).textContent = `${d[k].percent}% of total`;
        });

        // categories: bar chart
        const host = $('[data-categories]');
        if (!d.categories.length) {
            host.innerHTML = '<div class="empty"><strong>No assets yet</strong><span>Registered equipment will be charted here.</span></div>';
        } else {
            const max = Math.max(...d.categories.map((c) => c.count));
            const step = Math.max(1, Math.ceil(max / 4 / 10) * 10);
            const top = Math.ceil(max / step) * step;
            const ticks = [top, top * 0.75, top * 0.5, top * 0.25, 0].map((v) => `<span class="bars__axis" style="bottom:${(v / top) * 100}%">${Math.round(v)}</span>`).join('');
            const cols = d.categories.map((c) => `<div class="bars__col" title="${esc(c.label)}: ${c.count}"><span class="bars__val">${c.count}</span><div class="bars__seg" style="height:${(c.count / top) * 100}%;background:#6fb1f5"></div></div>`).join('');
            host.innerHTML = `<div class="bars" role="img" aria-label="Assets by category">${ticks}${cols}</div><div class="bars__labels">${d.categories.map((c) => `<span>${esc(c.label)}</span>`).join('')}</div>`;
        }

        // status: donut
        const chart = $('[data-status-chart]');
        const total = d.total.value;
        if (!total) { chart.innerHTML = '<div class="empty"><strong>No assets yet</strong><span>Status split appears once equipment is registered.</span></div>'; return; }
        const R = 52; const C = 2 * Math.PI * R; let offset = 0;
        const arcs = ['operational', 'under_maintenance', 'out_of_service'].map((k) => {
            const len = (d[k].value / total) * C;
            const el = `<circle r="${R}" cx="70" cy="70" fill="none" stroke="${STATUS_COLORS[k]}" stroke-width="22" stroke-dasharray="${len} ${C - len}" stroke-dashoffset="${-offset}" transform="rotate(-90 70 70)"/>`;
            offset += len;
            return el;
        }).join('');
        const legend = ['operational', 'under_maintenance', 'out_of_service'].map((k) =>
            `<li><i style="background:${STATUS_COLORS[k]}"></i>${STATUS_LABELS[k]}<b>${d[k].percent}%</b></li>`).join('');
        chart.innerHTML = `<div class="donut"><svg viewBox="0 0 140 140" role="img" aria-label="Asset status split">${arcs}<text x="70" y="68" text-anchor="middle" class="donut__n">${total.toLocaleString()}</text><text x="70" y="86" text-anchor="middle" class="donut__t">Total</text></svg><ul class="donut__legend">${legend}</ul></div>`;
    }
    load();
}

/* ------------------------------------------------------------- register */
function initRegister(root) {
    const $ = (s) => root.querySelector(s);
    const rows = $('[data-rows]'); const summary = $('[data-summary]'); const pager = $('[data-pager]');
    const state = { page: 1 };
    const selected = new Set();   // asset ids ticked for label printing (kept across pages)
    const pageBox = root.querySelector('[data-select-page]');
    const syncPageBox = () => {
        const boxes = [...rows.querySelectorAll('[data-pick]')];
        pageBox.checked = boxes.length > 0 && boxes.every((b) => b.checked);
        pageBox.indeterminate = !pageBox.checked && boxes.some((b) => b.checked);
    };
    rows.addEventListener('change', (e) => {
        const b = e.target.closest('[data-pick]'); if (!b) return;
        b.checked ? selected.add(Number(b.dataset.pick)) : selected.delete(Number(b.dataset.pick));
        syncPageBox();
    });
    pageBox.addEventListener('change', () => {
        rows.querySelectorAll('[data-pick]').forEach((b) => { b.checked = pageBox.checked; pageBox.checked ? selected.add(Number(b.dataset.pick)) : selected.delete(Number(b.dataset.pick)); });
    });
    document.querySelector('[data-print-qr]')?.addEventListener('click', (e) => {
        if (!selected.size) { toast('Tick one or more assets first.', 'error'); return; }
        if (selected.size > 200) { toast('You can print up to 200 assets at a time.', 'error'); return; }
        window.location.assign(`${e.currentTarget.dataset.labelsUrl}?ids=${[...selected].join(',')}`);
    });
    const filters = {};
    root.querySelectorAll('[data-f]').forEach((el) => { filters[el.dataset.f] = el; });
    if (root.dataset.initStatus) filters.status.value = root.dataset.initStatus;
    if (root.dataset.initQ) filters.q.value = root.dataset.initQ;
    let reqId = 0;

    const params = () => ({ page: state.page, ...Object.fromEntries(Object.entries(filters).map(([k, el]) => [k, el.value.trim()])) });

    async function load() {
        const id = ++reqId;
        rows.innerHTML = '<tr><td colspan="8"><div class="empty">Loading…</div></td></tr>';
        try {
            const res = await api('GET', `${root.dataset.url}?${qs(params())}`);
            if (id !== reqId) return;
            render(res);
        } catch (e) {
            if (id !== reqId) return;
            rows.innerHTML = `<tr><td colspan="8"><div class="empty"><strong>Could not load assets</strong><span>${esc(e.message)}</span><button class="link" data-retry>Try again</button></div></td></tr>`;
            rows.querySelector('[data-retry]').addEventListener('click', load);
        }
    }

    function render({ data, meta }) {
        if (!data.length) {
            const filtered = Object.values(params()).slice(1).some(Boolean);
            rows.innerHTML = `<tr><td colspan="8"><div class="empty"><strong>${filtered ? 'No matching assets' : 'No assets registered yet'}</strong><span>${filtered ? 'Try changing your search or filters.' : 'Use “Add Asset” to register the first one.'}</span></div></td></tr>`;
        } else {
            rows.innerHTML = data.map((a) => `
                <tr>
                    <td><input type="checkbox" data-pick="${a.id}" ${selected.has(a.id) ? 'checked' : ''} aria-label="Select ${esc(a.asset_tag)}"></td>
                    <td><a class="link" href="${esc(a.url)}">${esc(a.asset_tag)}</a></td>
                    <td>${esc(a.name)}</td><td>${dash(a.category)}</td><td>${dash(a.department)}</td><td>${dash(a.location)}</td>
                    <td>${statusPill(a.status, a.status_label)}</td>
                    <td class="row-actions">
                        <a class="icon-btn" href="${esc(a.url)}" title="View" aria-label="View ${esc(a.name)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></a>
                        ${['operational', 'under_maintenance', 'out_of_service'].includes(a.status) && root.dataset.canEdit === '1' ?`<a class="icon-btn" href="${esc(a.edit_url)}" title="Edit" aria-label="Edit ${esc(a.name)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></a>` : ''}
                    </td>
                </tr>`).join('');
        }
        summary.textContent = meta.total ? `Showing ${meta.from} to ${meta.to} of ${meta.total} records` : '';
        syncPageBox();
        const btn = (label, p, dis = false, cur = false) => `<button type="button" class="pager__btn ${cur ? 'is-current' : ''}" data-page="${p}" ${dis ? 'disabled' : ''} ${cur ? 'aria-current="page"' : ''}>${label}</button>`;
        if (meta.last_page <= 1) { pager.innerHTML = ''; return; }
        let html = btn('&lsaquo;', meta.page - 1, meta.page === 1);
        for (let p = Math.max(1, meta.page - 2); p <= Math.min(meta.last_page, meta.page + 2); p++) html += btn(p, p, false, p === meta.page);
        pager.innerHTML = html + btn('&rsaquo;', meta.page + 1, meta.page === meta.last_page);
    }

    pager.addEventListener('click', (e) => { const b = e.target.closest('[data-page]'); if (!b || b.disabled) return; state.page = Number(b.dataset.page); load(); });
    filters.q.addEventListener('input', debounce(() => { state.page = 1; load(); }));
    ['category_id', 'department_id', 'status'].forEach((k) => filters[k].addEventListener('change', () => { state.page = 1; load(); }));

    document.querySelector('[data-asset-export]')?.addEventListener('click', () => {
        const p = params(); delete p.page;
        window.location.assign(`${root.dataset.export}?${qs(p)}`);
    });

    (async () => {
        try {
            const { data } = await api('GET', root.dataset.options);
            const fill = (el, items) => { items.forEach((i) => el.insertAdjacentHTML('beforeend', `<option value="${i.id}">${esc(i.name)}</option>`)); };
            fill(filters.category_id, data.categories);
            // the same department name can exist in several facilities; show each once
            fill(filters.department_id, [...new Map(data.departments.map((d) => [d.id, d])).values()]);
        } catch { /* filters stay at "All" */ }
        load();
    })();
}

/* ----------------------------------------------------------------- form */
function initForm(form) {
    const id = form.dataset.id;
    const submit = form.querySelector('button[type="submit"]');
    let options = { facilities: [], categories: [], manufacturers: [], departments: [], buildings: [], locations: [] };
    const sel = (name) => form.querySelector(`[name="${name}"]`);
    const facilityEl = form.querySelector('[data-facility]');
    const buildingEl = form.querySelector('[data-building]');
    const readout = form.querySelector('[data-location-readout]');
    const preview = form.querySelector('[data-image-preview]');
    const tabFor = {};
    form.querySelectorAll('[data-panel]').forEach((p) => p.querySelectorAll('[name]').forEach((el) => { tabFor[el.name] = p.dataset.panel; }));

    const showTab = initTabs(form);

    const fillSelect = (el, items, placeholder, keep) => {
        const current = keep ?? el.value;
        el.innerHTML = `<option value="">${esc(placeholder)}</option>` + items.map((i) => `<option value="${i.id}">${esc(i.name)}</option>`).join('');
        if (current) el.value = String(current);
    };

    async function loadOptions(facilityId) {
        const { data } = await api('GET', `${form.dataset.options}?${qs({ facility_id: facilityId })}`);
        options = data;
        fillSelect(sel('category_id'), data.categories, 'Select category');
        fillSelect(sel('manufacturer_id'), data.manufacturers, 'Select manufacturer');
        fillSelect(sel('department_id'), data.departments, facilityId ? 'Select department' : 'Select a facility first');
        fillBuildings();
        fillLocations();
    }
    function fillBuildings() {
        buildingEl.innerHTML = '<option value="">All buildings</option>' + options.buildings.map((b) => `<option value="${b.id}">${esc(b.name)}</option>`).join('');
    }
    function fillLocations() {
        const b = buildingEl.value;
        const list = options.locations.filter((l) => !b || String(l.building_id) === b);
        fillSelect(sel('location_id'), list, facilityEl.value ? 'Select location' : 'Select a facility first');
        updateReadout();
    }
    function updateReadout() {
        const loc = options.locations.find((l) => String(l.id) === sel('location_id').value);
        readout.textContent = loc ? loc.name : 'Not selected';
    }

    buildingEl.addEventListener('change', fillLocations);
    sel('location_id').addEventListener('change', updateReadout);
    facilityEl.addEventListener('change', async () => {
        sel('department_id').value = ''; sel('location_id').value = '';
        try { await loadOptions(facilityEl.value); } catch (e) { toast(e.message, 'error'); }
    });

    sel('image').addEventListener('change', () => {
        const file = sel('image').files[0];
        form.querySelector('[data-error-for="image"]').textContent = '';
        if (!file) return;
        if (!['image/jpeg', 'image/png'].includes(file.type) || file.size > 2 * 1024 * 1024) {
            sel('image').value = '';
            form.querySelector('[data-error-for="image"]').textContent = 'Use a JPG or PNG image up to 2 MB.';
            return;
        }
        preview.innerHTML = `<img src="${URL.createObjectURL(file)}" alt="Selected equipment photo">`;
    });

    function setValue(name, value) {
        const el = form.querySelector(`[name="${name}"]`);
        if (!el || value === null || value === undefined) return;
        if (el.type === 'radio') { form.querySelectorAll(`[name="${name}"]`).forEach((r) => { r.checked = r.value === String(value); }); } else { el.value = value; }
    }

    (async () => {
        try {
            if (id) {
                const { data } = await api('GET', form.dataset.detailUrl);
                await loadOptions(data.facility_id);
                fillSelect(facilityEl, options.facilities, 'Select facility', data.facility_id);
                Object.entries(data).forEach(([k, v]) => { if (!['image_url', 'can'].includes(k)) setValue(k, v); });
                // Status is changed through the lifecycle workflow (with a reason), not here.
                form.querySelectorAll('input[name="status"]').forEach((r) => { r.disabled = true; });
                form.querySelector('[data-error-for="status"]').insertAdjacentHTML('beforebegin', `<p class="field__hint">To change status use <a href="/assets/${id}/status">Change Status</a>.</p>`);
                if (data.image_url) {
                    preview.innerHTML = `<img src="${esc(data.image_url)}" alt="Equipment photo">`;
                    form.querySelector('[data-remove-wrap]').hidden = false;
                }
                updateReadout();
            } else {
                await loadOptions('');
                fillSelect(facilityEl, options.facilities, 'Select facility');
                const pre = form.dataset.selectedFacility || (options.facilities.length === 1 ? options.facilities[0].id : '');
                if (pre) { facilityEl.value = String(pre); await loadOptions(pre); }
                const { data } = await api('GET', form.dataset.nextTag).catch(() => ({ data: null }));
                if (data) sel('asset_tag').value = data.asset_tag;
            }
        } catch (e) {
            toast(e.message, 'error');
        }
    })();

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors(form);
        form.querySelectorAll('.tab__dot').forEach((d) => { d.hidden = true; });
        const data = new FormData(form);
        if (id) { data.set('_method', 'PUT'); data.set('facility_id', facilityEl.value); }
        setBusy(submit, true);
        try {
            const res = await api('POST', id ? `/api/assets/${id}` : '/api/assets', data);
            try { sessionStorage.setItem('kbt.flash', res.message); } catch { /* ignore */ }
            window.location.assign(res.data.url);
        } catch (err) {
            if (err instanceof ApiError && err.status === 422 && Object.keys(err.errors).length) {
                applyServerErrors(form, err.errors);
                const tabs = [...new Set(Object.keys(err.errors).map((k) => tabFor[k]).filter(Boolean))];
                tabs.forEach((t) => { form.querySelector(`[data-tab="${t}"] .tab__dot`).hidden = false; });
                if (tabs.length) showTab(tabs[0]);
                form.querySelector('.is-invalid input, .is-invalid select')?.focus();
                toast('Please correct the highlighted fields.', 'error');
            } else {
                toast(err.message, 'error');
            }
            setBusy(submit, false);
        }
    });
}

/* --------------------------------------------------------------- detail */
function initDetail(root) {
    const $ = (s) => root.querySelector(s);
    const loaded = {};
    let asset = null;

    const kv = (rows) => `<dl class="kv-list">${rows.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${v}</dd></div>`).join('')}</dl>`;
    const empty = (t, s) => `<div class="empty"><strong>${esc(t)}</strong><span>${esc(s)}</span></div>`;

    async function load() {
        $('[data-loading]').hidden = false; $('[data-error]').hidden = true; $('[data-content]').hidden = true;
        try {
            asset = (await api('GET', root.dataset.url)).data;
            render();
            $('[data-loading]').hidden = true; $('[data-content]').hidden = false;
        } catch (e) {
            $('[data-loading]').hidden = true; $('[data-error]').hidden = false; $('[data-error-msg]').textContent = e.message;
        }
    }
    $('[data-retry]').addEventListener('click', load);

    function render() {
        const a = asset;
        $('[data-name]').textContent = a.name;
        $('[data-subtitle]').textContent = [a.manufacturer, a.model].filter(Boolean).join(' ');
        const st = $('[data-status]'); st.className = `pill pill--st-${a.status}`; st.textContent = a.status_label;
        $('[data-photo]').innerHTML = a.image_url ? `<img src="${esc(a.image_url)}" alt="${esc(a.name)}">` : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>';
        $('[data-facts]').innerHTML = [['Asset ID', a.asset_tag], ['Serial Number', a.serial_number], ['Category', a.category], ['Department', a.department], ['Location', a.location_path], ['Facility', a.facility]]
            .map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${dash(v)}</dd></div>`).join('');
        const edit = $('[data-edit-link]'); edit.hidden = !a.can.edit; edit.href = a.edit_url;
        $('[data-delete]').hidden = !a.can.delete;
        $('[data-labels-link]').href = `/assets/labels?ids=${a.id}`;
        $('[data-transfer-link]').hidden = !a.can.transfer;
        $('[data-status-link]').hidden = !a.can.status;
        $('[data-dispose-link]').hidden = !a.can.dispose;
        $('[data-dispose-link]').textContent = a.status === 'retired' ? 'Dispose' : 'Retire / Dispose';
        const pending = $('[data-pending]');
        pending.hidden = !a.pending_transfer;
        if (a.pending_transfer) pending.textContent = `A transfer requested on ${a.pending_transfer.requested_date} is waiting for approval. This asset cannot be transferred, retired or disposed until it is decided.`;
        panels.overview(); panels.specs(); panels.location();
        if (!$('[data-panel="maintenance"]').hidden) showPanel('maintenance');
    }

    const panels = {
        overview: () => {
            const a = asset;
            $('[data-panel="overview"]').innerHTML = `<div class="grid-2e">
                <div><h3 class="sub-h">Key Information</h3>${kv([
        ['Manufacturer', dash(a.manufacturer)], ['Model', dash(a.model)], ['Asset Type', dash(a.asset_type_label)],
        ['Current Status', statusPill(a.status, a.status_label)], ['Current Location', dash(a.location_path)], ['Assigned To', dash(a.department)],
        ['Last PM Date', day(a.last_pm_at)], ['Next PM Due', day(a.next_pm_due_at)], ['Last Calibration', day(a.last_calibration_at)], ['Next Calibration Due', day(a.next_calibration_due_at)],
    ])}</div>
                <div><div class="qr-card"><img data-qr alt="QR code for ${esc(a.asset_tag)}" width="120" height="120"><div><strong>${esc(a.asset_tag)}</strong><p class="muted">Scan to open this asset</p><a class="btn btn--outline btn--sm btn--auto" data-qr-download download="${esc(a.asset_tag)}-qr.png">Download QR</a></div></div>
                <h3 class="sub-h">Purchase &amp; Coverage</h3>${kv([
        ['Purchase Date', day(a.purchase_date)], ['Asset Cost', money(a.purchase_cost)], ['Funding Source', dash(a.funding_source)],
        ['Vendor', dash(a.vendor_name)], ['Invoice No.', dash(a.invoice_number)], ['Installed On', day(a.installed_at)],
        ['Warranty Expiry', day(a.warranty_expiry_at)], ['AMC Expiry', day(a.amc_expiry_at)], ['AMC Vendor', dash(a.amc_vendor)],
    ])}${a.remarks ? `<h3 class="sub-h" style="margin-top:16px">Remarks</h3><p>${esc(a.remarks)}</p>` : ''}</div></div>`;
            qrDataUrl(`${window.location.origin}/assets/scan?code=${encodeURIComponent(a.asset_tag)}`, 512).then((url) => {
                const img = $('[data-panel="overview"] [data-qr]'); const dl = $('[data-panel="overview"] [data-qr-download]');
                if (img) { img.src = url; dl.href = url; }
            }).catch(() => { $('[data-panel="overview"] .qr-card')?.remove(); });
        },
        specs: () => {
            $('[data-panel="specs"]').innerHTML = asset.specifications ? `<pre class="specs">${esc(asset.specifications)}</pre>` : empty('No specifications recorded', 'Edit the asset to add technical specifications.');
        },
        location: () => {
            const a = asset;
            $('[data-panel="location"]').innerHTML = kv([['Facility', dash(a.facility)], ['Department', dash(a.department)], ['Location', dash(a.location_path)]]);
        },
    };

    async function showPanel(key) {
        if (panels[key]) return;
        const host = $(`[data-panel="${key}"]`);
        if (loaded[key] && key !== 'documents') return;
        host.innerHTML = '<div class="empty">Loading…</div>';
        try {
            if (key === 'maintenance' || key === 'calibration') {
                const { data } = await api('GET', `${root.dataset.api}/maintenance`);
                const list = data.filter((w) => (key === 'calibration') === (w.type === 'calibration'));
                const head = key === 'calibration' ? kv([['Last Calibration', day(asset.last_calibration_at)], ['Next Calibration Due', day(asset.next_calibration_due_at)]]) : kv([['Last PM Date', day(asset.last_pm_at)], ['Next PM Due', day(asset.next_pm_due_at)]]);
                host.innerHTML = head + (list.length ? `<div class="table-wrap" style="margin-top:14px"><table class="table"><thead><tr><th>Work Order</th><th>Title</th><th>Type</th><th>Priority</th><th>Status</th><th>Due</th></tr></thead><tbody>${list.map((w) =>
                    `<tr><td>${esc(w.number)}</td><td>${esc(w.title)}</td><td style="text-transform:capitalize">${esc(w.type)}</td><td><span class="pill pill--${esc(w.priority)}">${esc(w.priority)}</span></td><td><span class="pill pill--${esc(w.status)}">${esc(w.status.replace('_', ' '))}</span></td><td>${w.due_at ? formatDate(w.due_at) : '—'}</td></tr>`).join('')}</tbody></table></div>`
                    : empty(`No ${key === 'calibration' ? 'calibration' : 'maintenance'} work orders`, 'Work orders raised for this asset will be listed here.'));
            } else if (key === 'history') {
                const { data } = await api('GET', `${root.dataset.api}/history`);
                host.innerHTML = data.length ? `<ol class="timeline">${data.map((h) => `<li><strong>${esc(h.action.replace('asset.', '').replace(/_/g, ' '))}</strong> <span class="muted">by ${esc(h.user)} · ${formatDate(h.at)}</span>${h.changes.length ? `<ul>${h.changes.map((c) => `<li>${esc(c.field.replace(/_/g, ' '))}: <s>${esc(c.from ?? '—')}</s> → ${esc(c.to ?? '—')}</li>`).join('')}</ul>` : ''}</li>`).join('')}</ol>` : empty('No history yet', 'Changes to this asset will be recorded here.');
            } else if (key === 'documents') {
                await renderDocuments(host);
            }
            loaded[key] = true;
        } catch (e) {
            host.innerHTML = `<div class="empty"><strong>Could not load</strong><span>${esc(e.message)}</span></div>`;
        }
    }

    async function renderDocuments(host) {
        const { data } = await api('GET', `${root.dataset.api}/documents`);
        const canEdit = root.dataset.canEditDocs === '1' && !asset.closed;
        const size = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
        host.innerHTML = `${canEdit ? `<form class="doc-form" data-doc-form novalidate><div class="input-wrap" data-field><input name="title" placeholder="Document title (e.g. Warranty certificate)" maxlength="160"></div><label class="btn btn--outline btn--sm btn--auto" style="cursor:pointer">Choose file<input type="file" name="file" hidden></label><span class="muted" data-file-name>No file chosen</span><button class="btn btn--primary btn--sm btn--auto" type="submit">Upload</button><div class="field__error" data-error-for="title"></div><div class="field__error" data-error-for="file"></div></form>` : ''}
            ${data.length ? `<div class="table-wrap"><table class="table"><thead><tr><th>Title</th><th>File</th><th>Size</th><th>Uploaded</th><th></th></tr></thead><tbody>${data.map((d) => `<tr><td>${esc(d.title)}</td><td><a class="link" href="${esc(d.url)}">${esc(d.name)}</a></td><td>${size(d.size)}</td><td>${formatDate(d.at)}${d.uploaded_by ? ` · ${esc(d.uploaded_by)}` : ''}</td><td class="row-actions">${canEdit ? `<button class="icon-btn icon-btn--danger" data-doc-del="${d.id}" title="Remove" aria-label="Remove ${esc(d.title)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/></svg></button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : empty('No documents', 'Upload warranty certificates, manuals or invoices.')}`;

        const f = host.querySelector('[data-doc-form]');
        f?.querySelector('input[type=file]').addEventListener('change', (e) => { f.querySelector('[data-file-name]').textContent = e.target.files[0]?.name ?? 'No file chosen'; });
        f?.addEventListener('submit', async (e) => {
            e.preventDefault(); clearErrors(f);
            const btn = f.querySelector('button[type=submit]'); btn.disabled = true;
            try {
                const res = await api('POST', `${root.dataset.api}/documents`, new FormData(f));
                toast(res.message); await renderDocuments(host);
            } catch (err) {
                if (err instanceof ApiError && err.status === 422) applyServerErrors(f, err.errors); else toast(err.message, 'error');
                btn.disabled = false;
            }
        });
        host.querySelectorAll('[data-doc-del]').forEach((b) => b.addEventListener('click', async () => {
            if (!await confirmDialog({ title: 'Remove document?', message: 'The file will be permanently deleted.', confirmLabel: 'Remove', danger: true })) return;
            try { toast((await api('DELETE', `${root.dataset.api}/documents/${b.dataset.docDel}`)).message); await renderDocuments(host); } catch (err) { toast(err.message, 'error'); }
        }));
    }

    initTabs(root, (key) => showPanel(key));

    $('[data-delete]').addEventListener('click', async () => {
        const ok = await confirmDialog({ title: 'Delete asset?', message: `${asset.asset_tag} — ${asset.name} will be permanently deleted. Assets with work orders cannot be deleted.`, confirmLabel: 'Delete', danger: true });
        if (!ok) return;
        try {
            const res = await api('DELETE', root.dataset.api);
            try { sessionStorage.setItem('kbt.flash', res.message); } catch { /* ignore */ }
            window.location.assign(root.dataset.listUrl);
        } catch (e) { toast(e.message, 'error'); }
    });

    load();
}
