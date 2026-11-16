import { api, ApiError } from '../lib/http';
import { applyServerErrors, clearErrors, confirmDialog, esc, formatDate, setBusy, toast } from '../lib/ui';

const debounce = (fn, ms = 300) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const qs = (params) => new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString();
const dash = (v) => (v === null || v === undefined || v === '' ? '<span class="muted">—</span>' : esc(v));
const LABELS = { draft: 'Draft', in_progress: 'In Progress', pending_approval: 'Pending Approval', completed: 'Completed', cancelled: 'Cancelled' };
const pill = (s, l) => `<span class="pill pill--ist-${esc(s)}">${esc(l ?? LABELS[s] ?? s)}</span>`;
const ICON_VIEW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
const ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/></svg>';

export function initInstallations() {
    const d = document.querySelector('[data-inst-dashboard]'); if (d) initDashboard(d);
    const l = document.querySelector('[data-inst-jobs]'); if (l) initJobs(l);
    const j = document.querySelector('[data-inst-job]'); if (j) initJob(j);
    const r = document.querySelector('[data-inst-reports]'); if (r) initReports(r);
}

function tabs(root, onShow = () => {}) {
    const list = [...root.querySelectorAll('[data-tab]')];
    const show = (key) => {
        list.forEach((t) => { const on = t.dataset.tab === key; t.classList.toggle('is-active', on); t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; });
        root.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== key; });
        onShow(key);
    };
    list.forEach((t, i) => {
        t.addEventListener('click', () => show(t.dataset.tab));
        t.addEventListener('keydown', (e) => { const s = { ArrowRight: 1, ArrowLeft: -1 }[e.key]; if (!s) return; const n = list[(i + s + list.length) % list.length]; n.focus(); show(n.dataset.tab); });
    });
    return show;
}

/* ------------------------------------------------------------- dashboard (1) */
function initDashboard(root) {
    const $ = (s) => root.querySelector(s);
    async function load() {
        $('[data-loading]').hidden = false; $('[data-error]').hidden = true; $('[data-content]').hidden = true;
        try {
            const { data } = await api('GET', root.dataset.url);
            ['total', 'in_progress', 'pending_approval', 'completed'].forEach((k) => { $(`[data-stat="${k}"]`).textContent = data[k].toLocaleString(); });

            const max = Math.max(1, ...data.trend.flatMap((t) => [t.planned, t.completed]));
            const top = Math.max(4, Math.ceil(max / 4) * 4);
            $('[data-trend]').innerHTML = `<div class="legend"><span><i style="background:#9cc8f7"></i>Planned</span><span><i style="background:#0d3b73"></i>Completed</span></div>
                <div class="bars" role="img" aria-label="Installations planned and completed per month">${[top, top * .75, top * .5, top * .25, 0].map((v) => `<span class="bars__axis" style="bottom:${(v / top) * 100}%">${Math.round(v)}</span>`).join('')}
                ${data.trend.map((t) => `<div class="bars__col bars__col--pair" title="${esc(t.label)}: planned ${t.planned}, completed ${t.completed}"><div class="pairbar" style="height:${(t.planned / top) * 100}%;background:#9cc8f7"></div><div class="pairbar" style="height:${(t.completed / top) * 100}%;background:#0d3b73"></div></div>`).join('')}</div>
                <div class="bars__labels">${data.trend.map((t) => `<span>${esc(t.label)}</span>`).join('')}</div>`;

            const colors = ['#2d7be5', '#e5484d', '#11b5ab', '#f08c1b', '#8a97a8'];
            const total = data.departments.reduce((a, d) => a + d.count, 0);
            if (!total) $('[data-departments]').innerHTML = '<div class="empty"><strong>No installations yet</strong><span>Department split appears once jobs are submitted.</span></div>';
            else {
                const R = 52; const C = 2 * Math.PI * R; let off = 0;
                const arcs = data.departments.map((d, i) => { const len = (d.count / total) * C; const el = `<circle r="${R}" cx="70" cy="70" fill="none" stroke="${colors[i % 5]}" stroke-width="22" stroke-dasharray="${len} ${C - len}" stroke-dashoffset="${-off}" transform="rotate(-90 70 70)"/>`; off += len; return el; }).join('');
                $('[data-departments]').innerHTML = `<div class="donut"><svg viewBox="0 0 140 140" role="img" aria-label="Installations by department">${arcs}<text x="70" y="68" text-anchor="middle" class="donut__n">${total}</text><text x="70" y="86" text-anchor="middle" class="donut__t">Total</text></svg>
                    <ul class="donut__legend">${data.departments.map((d, i) => `<li><i style="background:${colors[i % 5]}"></i>${esc(d.label)}<b>${d.count}</b></li>`).join('')}</ul></div>`;
            }
            $('[data-recent]').innerHTML = data.recent.length ? data.recent.map((j) => `<tr><td><a class="link" href="${esc(j.url)}">${esc(j.job_number)}</a></td><td>${esc(j.asset_tag)}</td><td>${esc(j.equipment)}</td><td>${dash(j.department)}</td><td>${esc(j.planned_date)}</td><td>${pill(j.status, j.status_label)}</td></tr>`).join('')
                : '<tr><td colspan="6"><div class="empty"><strong>No installation jobs yet</strong><span>Create the first one from “New Installation”.</span></div></td></tr>';
            $('[data-loading]').hidden = true; $('[data-content]').hidden = false;
        } catch (e) { $('[data-loading]').hidden = true; $('[data-error]').hidden = false; $('[data-error-msg]').textContent = e.message; }
    }
    $('[data-retry]').addEventListener('click', load);
    load();
}

/* ------------------------------------------------------------------ jobs (2) */
function initJobs(root) {
    const $ = (s) => root.querySelector(s);
    const rows = $('[data-rows]'); const summary = $('[data-summary]'); const pager = $('[data-pager]');
    const f = {}; root.querySelectorAll('[data-f]').forEach((el) => { f[el.dataset.f] = el; });
    if (root.dataset.initStatus) f.status.value = root.dataset.initStatus;
    const state = { page: 1 }; let req = 0;
    const params = () => ({ page: state.page, ...Object.fromEntries(Object.entries(f).map(([k, el]) => [k, el.value.trim()])) });

    $('[data-more]').addEventListener('click', (e) => { const p = $('[data-more-panel]'); p.hidden = !p.hidden; e.currentTarget.setAttribute('aria-expanded', String(!p.hidden)); });

    async function load() {
        const id = ++req;
        rows.innerHTML = '<tr><td colspan="7"><div class="empty">Loading…</div></td></tr>';
        try {
            const { data, meta } = await api('GET', `${root.dataset.url}?${qs(params())}`);
            if (id !== req) return;
            rows.innerHTML = data.length ? data.map((j) => `<tr><td><a class="link" href="${esc(j.url)}">${esc(j.job_number)}</a></td><td>${esc(j.asset_tag)}</td><td>${esc(j.equipment)}</td><td>${dash(j.department)}</td><td>${esc(j.planned_date)}</td><td>${pill(j.status, j.status_label)}</td>
                <td class="row-actions"><a class="icon-btn" href="${esc(j.url)}" title="View" aria-label="View ${esc(j.job_number)}">${ICON_VIEW}</a></td></tr>`).join('')
                : `<tr><td colspan="7"><div class="empty"><strong>${Object.values(params()).slice(1).some(Boolean) ? 'No matching jobs' : 'No installation jobs yet'}</strong><span>Try changing your filters, or create a new installation.</span></div></td></tr>`;
            summary.textContent = meta.total ? `Showing ${meta.from} to ${meta.to} of ${meta.total} records` : '';
            const b = (l, p, dis = false, cur = false) => `<button type="button" class="pager__btn ${cur ? 'is-current' : ''}" data-page="${p}" ${dis ? 'disabled' : ''}>${l}</button>`;
            if (meta.last_page <= 1) pager.innerHTML = ''; else { let h = b('&lsaquo;', meta.page - 1, meta.page === 1); for (let p = Math.max(1, meta.page - 2); p <= Math.min(meta.last_page, meta.page + 2); p++) h += b(p, p, false, p === meta.page); pager.innerHTML = h + b('&rsaquo;', meta.page + 1, meta.page === meta.last_page); }
        } catch (e) { if (id === req) rows.innerHTML = `<tr><td colspan="7"><div class="empty"><strong>Could not load</strong><span>${esc(e.message)}</span><button class="link" data-retry>Try again</button></div></td></tr>`; rows.querySelector('[data-retry]')?.addEventListener('click', load); }
    }
    pager.addEventListener('click', (e) => { const b = e.target.closest('[data-page]'); if (!b || b.disabled) return; state.page = Number(b.dataset.page); load(); });
    f.q.addEventListener('input', debounce(() => { state.page = 1; load(); }));
    Object.entries(f).filter(([k]) => k !== 'q').forEach(([, el]) => el.addEventListener('change', () => { state.page = 1; load(); }));

    (async () => {
        try {
            const { data } = await api('GET', root.dataset.options);
            const uniq = (a) => [...new Map(a.map((x) => [x.id, x])).values()];
            uniq(data.departments).forEach((d) => f.department_id.insertAdjacentHTML('beforeend', `<option value="${d.id}">${esc(d.name)}</option>`));
        } catch { /* filters keep defaults */ }
        load();
    })();
    // Engineers are listed per facility: collect them from every facility the user can see.
    (async () => {
        try {
            const { data } = await api('GET', root.dataset.options);
            const all = await Promise.all(data.facilities.map((x) => api('GET', `${root.dataset.options}?facility_id=${x.id}`).then((r) => r.data.engineers).catch(() => [])));
            [...new Map(all.flat().map((u) => [u.id, u])).values()].forEach((u) => f.engineer_id.insertAdjacentHTML('beforeend', `<option value="${u.id}">${esc(u.name)}</option>`));
        } catch { /* filter keeps "Any engineer" */ }
    })();
}

/* ------------------------------------------------------- job workspace (3-9) */
function initJob(root) {
    const $ = (s) => root.querySelector(s);
    const id = root.dataset.id || null;
    const form = $('[data-basic]');
    let job = null; let opts = { assets: [], departments: [], engineers: [], staff: [], vendors: [], facilities: [] }; let facilityId = '';
    const loaded = {};

    const showTab = tabs(root, (key) => render(key));
    const setBanner = (text, kind = 'info') => { const b = $('[data-banner]'); b.hidden = !text; b.className = `alert alert--${kind}`; b.textContent = text || ''; };
    const q = (p) => `${root.dataset.api}${p}`;

    /* ---- options + basic form */
    let readonly = false;
    const fill = (sel, items, ph, label = (i) => i.name) => { const cur = sel.value; sel.innerHTML = `<option value="">${esc(ph)}</option>` + items.map((i) => `<option value="${i.id}">${esc(label(i))}</option>`).join(''); if (cur) sel.value = cur; };
    // Department and engineer lists depend on the asset's facility, so they stay locked until an asset is chosen.
    const applyLocks = () => { const lock = !facilityId; form.department_id.disabled = readonly || lock; form.engineer_id.disabled = readonly || lock; };

    async function loadOptions(fid) {
        const { data } = await api('GET', `${root.dataset.options}?${qs({ facility_id: fid })}`);
        opts = { ...data, assets: opts.assets };
        fill(form.department_id, opts.departments, fid ? 'Select department' : 'Select an asset first');
        fill(form.engineer_id, opts.engineers, fid ? 'Select engineer' : 'Select an asset first', (u) => `${u.name}${u.designation ? ` (${u.designation})` : ''}`);
        $('#vendor-list').innerHTML = opts.vendors.map((v) => `<option value="${esc(v)}">`).join('');
        applyLocks();
    }

    /* asset autocomplete: type to search, pick a result, the rest of the form follows */
    const combo = $('[data-combo]'); const assetInput = $('[data-asset-search]'); const assetId = form.asset_id; const list = $('[data-asset-list]');
    let results = []; let active = -1; let seq = 0; let chosenText = '';
    const setActive = (i) => {
        active = i; list.querySelectorAll('[data-i]').forEach((li) => li.classList.toggle('is-active', Number(li.dataset.i) === i));
        assetInput.setAttribute('aria-activedescendant', results[i] ? `asset-opt-${results[i].id}` : '');
        list.querySelector(`[data-i="${i}"]`)?.scrollIntoView({ block: 'nearest' });
    };
    const closeList = () => { list.hidden = true; assetInput.setAttribute('aria-expanded', 'false'); active = -1; };

    async function searchAssets(term) {
        const my = ++seq;
        list.hidden = false; assetInput.setAttribute('aria-expanded', 'true');
        list.innerHTML = '<li class="combo__msg">Searching…</li>';
        try {
            const { data } = await api('GET', `${root.dataset.options}?${qs({ q: term })}`);
            if (my !== seq) return;
            const facilities = Object.fromEntries(data.facilities.map((f) => [f.id, f.name]));
            results = data.assets; active = -1;
            list.innerHTML = results.length
                ? results.map((a, i) => `<li role="option" id="asset-opt-${a.id}" data-i="${i}"><strong>${esc(a.asset_tag)}</strong> — ${esc(a.name)}<small>${esc(a.department ?? 'No department')} · ${esc(facilities[a.facility_id] ?? '')}</small></li>`).join('')
                : `<li class="combo__msg">${term ? 'No available asset matches that.' : 'No available assets.'} Register the asset first (<a href="/assets/create">New Asset</a>); assets that already have an open installation job are not listed.</li>`;
        } catch (e) { if (my === seq) list.innerHTML = `<li class="combo__msg">${esc(e.message)}</li>`; }
    }

    async function chooseAsset(a) {
        assetId.value = String(a.id); chosenText = `${a.asset_tag} — ${a.name}`; assetInput.value = chosenText;
        $('[data-equipment]').textContent = a.name; $('[data-error-for="asset_id"]').textContent = ''; assetInput.closest('[data-field]').classList.remove('is-invalid');
        closeList();
        if (String(a.facility_id) !== String(facilityId)) {
            facilityId = a.facility_id; form.engineer_id.value = ''; form.department_id.value = '';
            try { await loadOptions(facilityId); } catch (e) { toast(e.message, 'error'); }
        }
        if (a.department_id) form.department_id.value = String(a.department_id);
    }

    assetInput.addEventListener('input', () => {
        if (assetId.value && assetInput.value !== chosenText) { assetId.value = ''; $('[data-equipment]').textContent = '—'; }   // text edited: selection no longer valid
        debouncedSearch(assetInput.value.trim());
    });
    const debouncedSearch = debounce((t) => searchAssets(t), 250);
    assetInput.addEventListener('focus', () => { if (!assetInput.disabled && !assetId.value) searchAssets(assetInput.value.trim()); });
    assetInput.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault(); if (list.hidden) { searchAssets(assetInput.value.trim()); return; }
            if (results.length) setActive((active + (e.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length);
        } else if (e.key === 'Enter' && !list.hidden && results[active]) { e.preventDefault(); chooseAsset(results[active]); }
        else if (e.key === 'Escape') closeList();
    });
    list.addEventListener('mousedown', (e) => { const li = e.target.closest('[data-i]'); if (li) { e.preventDefault(); chooseAsset(results[Number(li.dataset.i)]); } });
    document.addEventListener('click', (e) => { if (!combo.contains(e.target)) closeList(); });

    function setReadonly(ro) {
        readonly = ro;
        form.querySelectorAll('input, select, textarea').forEach((el) => { el.disabled = ro; });
        if (!ro && job && job.status !== 'draft') assetInput.disabled = true;   // asset is locked once submitted
        applyLocks();
    }

    function fillForm(j) {
        ['type'].forEach(() => form.querySelectorAll('[name="type"]').forEach((r) => { r.checked = r.value === j.type; }));
        form.priority.value = j.priority; form.vendor_name.value = j.vendor_name ?? ''; form.estimated_hours.value = j.estimated_hours ?? '';
        form.planned_date.value = j.planned_date_iso; form.remarks.value = j.remarks ?? '';
        chosenText = `${j.asset_tag} — ${j.asset_name}`; assetInput.value = chosenText; form.asset_id.value = String(j.asset_id); form.department_id.value = j.department_id ? String(j.department_id) : ''; form.engineer_id.value = j.engineer_id ? String(j.engineer_id) : '';
        $('[data-job-number]').textContent = j.job_number; $('[data-equipment]').textContent = j.asset_name;
    }

    const body = () => Object.fromEntries([...new FormData(form)].map(([k, v]) => [k, v === '' ? null : v]));

    /* ---- load */
    async function load(keepTab = true) {
        if (!id) { $('[data-content]').hidden = false; renderActions(); await loadOptions(''); setReadonly(false); renderTabsState(); return; }
        $('[data-loading]').hidden = false; $('[data-error]').hidden = true;
        try {
            job = (await api('GET', q(''))).data;
            facilityId = job.facility_id;
            await loadOptions(facilityId);
            fillForm(job);
            Object.keys(loaded).forEach((k) => delete loaded[k]);
            $('[data-title]').textContent = job.job_number;
            $('[data-subtitle]').innerHTML = `${pill(job.status, job.status_label)} <span class="muted">${esc(job.asset_tag)} · ${esc(job.asset_name)} · ${esc(job.facility ?? '')}</span>`;
            setReadonly(!job.can.edit);
            banner(); renderActions(); renderTabsState();
            $('[data-loading]').hidden = true; $('[data-content]').hidden = false;
            const active = root.querySelector('[data-tab].is-active')?.dataset.tab ?? 'basic';
            render(keepTab ? active : 'basic');
        } catch (e) { $('[data-loading]').hidden = true; $('[data-error]').hidden = false; $('[data-error-msg]').textContent = e.message; }
    }
    $('[data-retry]').addEventListener('click', () => load());

    function banner() {
        if (job.status === 'in_progress' && job.decision_note) setBanner(`Returned for changes${job.decided_by ? ` by ${job.decided_by}` : ''}: ${job.decision_note}`, 'error');
        else if (job.status === 'pending_approval') setBanner('This job is waiting for approval. Checklists are locked.');
        else if (job.status === 'cancelled') setBanner(`Cancelled: ${job.decision_note ?? ''}`, 'error');
        else if (job.status === 'completed') setBanner(`Completed on ${job.completed_at}. Certificate ${job.certificate_number}.`, 'success');
        else setBanner('');
    }

    function renderTabsState() {
        root.querySelectorAll('[data-tab]').forEach((t) => {
            const needsJob = !['basic'].includes(t.dataset.tab);
            const hide = (!job && needsJob) || (job && job.status === 'draft' && ['site_readiness', 'installation', 'acceptance', 'certificate', 'handover'].includes(t.dataset.tab) && false);
            t.disabled = hide; t.title = hide ? 'Save the job first' : '';
        });
    }

    /* ---- actions */
    function renderActions() {
        const host = $('[data-actions]'); host.innerHTML = '';
        const add = (label, cls, fn) => { const b = document.createElement('button'); b.type = 'button'; b.className = `btn btn--sm btn--auto ${cls}`; b.textContent = label; b.addEventListener('click', () => fn(b)); host.appendChild(b); };
        if (!job) { add('Save as Draft', 'btn--outline', (b) => save(b, false)); add('Submit', 'btn--primary', (b) => save(b, true)); return; }
        const c = job.can;
        if (c.edit) add(job.status === 'draft' ? 'Save Draft' : 'Save Changes', 'btn--outline', (b) => saveEdit(b));
        if (c.submit) add('Submit', 'btn--primary', (b) => act(b, 'submit', null, { title: 'Submit installation job?', message: 'The standard checklists will be created and the asset cannot be changed afterwards.', confirmLabel: 'Submit' }));
        if (c.request_approval) add('Send for Approval', 'btn--primary', (b) => act(b, 'request-approval', null, { title: 'Send for approval?', message: 'All checklists must be complete. They will be locked until the approver decides.', confirmLabel: 'Send' }));
        if (c.decide) { add('Approve', 'btn--primary', (b) => act(b, 'approve', null, { title: 'Approve and commission?', message: `${job.asset_tag} will be marked commissioned and the certificate issued.`, confirmLabel: 'Approve' })); add('Reject', 'btn--outline', () => askReason('Reject installation', 'The engineer will see this reason and can correct the checklists.', 'reject', 'note', 'Reject')); }
        if (c.cancel) add('Cancel Job', 'btn--outline', () => askReason('Cancel installation job', 'The job will be closed. This cannot be undone.', 'cancel', 'reason', 'Cancel job'));
        if (c.delete) add('Delete Draft', 'btn--outline', () => del());
    }

    async function save(btn, submit) {
        clearErrors(form); setBusy(btn, true);
        try {
            const res = await api('POST', root.dataset.createUrl, { ...body(), submit });
            try { sessionStorage.setItem('kbt.flash', res.message); } catch { /* ignore */ }
            window.location.assign(res.data.url);
        } catch (e) { fail(e); setBusy(btn, false); }
    }
    async function saveEdit(btn) {
        clearErrors(form); btn.disabled = true;
        try { const b = body(); if (job.status !== 'draft') delete b.asset_id; const res = await api('PUT', q(''), b); toast(res.message); await load(); } catch (e) { fail(e); }
        btn.disabled = false;
    }
    function fail(e) {
        if (e instanceof ApiError && e.status === 422 && Object.keys(e.errors).length) { applyServerErrors(form, e.errors); showTab('basic'); form.querySelector('.is-invalid input:not([type=hidden]), .is-invalid select')?.focus(); toast('Please correct the highlighted fields.', 'error'); } else toast(e.message, 'error');
    }
    async function act(btn, path, payload, confirmCfg) {
        if (confirmCfg && !await confirmDialog(confirmCfg)) return;
        btn.disabled = true;
        try { const res = await api('POST', q(`/${path}`), payload ?? {}); toast(res.message); await load(); } catch (e) { toast(e.message, 'error'); btn.disabled = false; await load().catch(() => {}); }
    }
    async function del() {
        if (!await confirmDialog({ title: 'Delete draft?', message: 'This draft will be permanently deleted.', confirmLabel: 'Delete', danger: true })) return;
        try { const res = await api('DELETE', q('')); try { sessionStorage.setItem('kbt.flash', res.message); } catch { /* ignore */ } window.location.assign(root.dataset.listUrl); } catch (e) { toast(e.message, 'error'); }
    }

    const dlg = document.querySelector('[data-reason-dialog]'); const dform = dlg.querySelector('[data-reason-form]'); let pending = null;
    dlg.querySelector('[data-reason-cancel]').addEventListener('click', () => dlg.close());
    function askReason(title, text, path, field, ok) { pending = { path, field }; dlg.querySelector('[data-reason-title]').textContent = title; dlg.querySelector('[data-reason-text]').textContent = text; dlg.querySelector('[data-reason-ok]').textContent = ok; clearErrors(dform); dform.reset(); dlg.showModal(); dform.reason.focus(); }
    dform.addEventListener('submit', async (e) => {
        e.preventDefault(); clearErrors(dform);
        try { const res = await api('POST', q(`/${pending.path}`), { [pending.field]: dform.reason.value }); dlg.close(); toast(res.message); await load(); }
        catch (err) { if (err instanceof ApiError && err.status === 422 && (err.errors.note || err.errors.reason)) { const m = (err.errors.note ?? err.errors.reason)[0]; dform.querySelector('[data-error-for="reason"]').textContent = m; } else { dlg.close(); toast(err.message, 'error'); await load(); } }
    });

    /* ---- panels */
    function render(key) {
        if (!job || key === 'basic') return;
        if (['site_readiness', 'installation', 'acceptance'].includes(key)) return renderChecklist(key);
        if (key === 'documents') return renderDocuments();
        if (key === 'certificate') return renderCertificate();
        if (key === 'handover') return renderHandover();
    }
    const panel = (k) => $(`[data-panel="${k}"]`);
    const stateChip = (s) => ({ completed: '<span class="pill pill--ist-completed">Completed</span>', in_progress: '<span class="pill pill--ist-in_progress">In Progress</span>', failed: '<span class="pill pill--ist-cancelled">Failed items</span>', pending: '<span class="pill pill--ist-draft">Not started</span>' }[s]);

    const SECTION = {
        site_readiness: { title: 'Site Readiness Checklist', sub: 'Verify infrastructure and environment readiness before installation', cols: ['Checklist Item', 'Requirement'], opts: [['pending', 'Pending'], ['pass', 'Pass'], ['fail', 'Fail'], ['na', 'N/A']] },
        installation: { title: 'Installation Execution Checklist', sub: 'Standardised checklist to ensure correct installation process', cols: ['Task', 'Details / Measurement'], opts: [['pending', 'Pending'], ['in_progress', 'In Progress'], ['completed', 'Completed']] },
        acceptance: { title: 'Acceptance Testing', sub: 'Enter test results as per manufacturer specification', cols: ['Test Parameter', 'Specification / Expected'], opts: [['pending', 'Pending'], ['pass', 'Pass'], ['fail', 'Fail']] },
    };

    async function renderChecklist(key) {
        const host = panel(key); const cfg = SECTION[key];
        if (job.status === 'draft') { host.innerHTML = '<div class="empty"><strong>Not available yet</strong><span>Submit the job to create its checklists.</span></div>'; return; }
        host.innerHTML = '<div class="empty">Loading…</div>';
        try {
            const { data } = await api('GET', q('/checklist'));
            const edit = job.can.checklist; const items = data.items[key] ?? []; const sum = data.sections[key];
            const head = `<div class="sec-head"><div><h3 class="sub-h">${esc(cfg.title)}</h3><p class="muted">${esc(cfg.sub)}</p></div><div data-sum>${sumHtml(key, sum)}</div></div>`;
            const rowsHtml = items.map((i, n) => `<tr data-item="${i.id}"><td>${n + 1}</td><td>${esc(i.title)}${i.is_custom ? ' <span class="muted">(added)</span>' : ''}</td><td class="muted">${esc(i.detail ?? '')}</td>
                ${key === 'acceptance' ? `<td><input class="mini" data-f="measured_value" value="${esc(i.measured_value ?? '')}" maxlength="120" ${edit ? '' : 'disabled'} aria-label="Measured value"></td>` : ''}
                <td><select class="mini mini--st st-${esc(i.status)}" data-f="status" ${edit ? '' : 'disabled'} aria-label="Status">${cfg.opts.map(([v, t]) => `<option value="${v}" ${i.status === v ? 'selected' : ''}>${t}</option>`).join('')}</select></td>
                <td><input class="mini" data-f="remarks" value="${esc(i.remarks ?? '')}" maxlength="255" placeholder="${edit ? 'Remarks' : ''}" ${edit ? '' : 'disabled'} aria-label="Remarks"></td>
                <td class="row-actions">${edit && i.is_custom ? `<button class="icon-btn icon-btn--danger" data-del="${i.id}" title="Remove" aria-label="Remove ${esc(i.title)}">${ICON_TRASH}</button>` : ''}</td></tr>`).join('');
            host.innerHTML = `${head}<div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>${cfg.cols[0]}</th><th>${cfg.cols[1]}</th>${key === 'acceptance' ? '<th>Measured Value</th>' : ''}<th>${key === 'acceptance' ? 'Result' : 'Status'}</th><th>Remarks</th><th></th></tr></thead><tbody>${rowsHtml}</tbody></table></div>
                ${edit ? `<form class="add-item" data-add novalidate><div class="input-wrap" data-field><input name="title" maxlength="255" placeholder="Add an item…" aria-label="New item"></div><div class="input-wrap" data-field><input name="detail" maxlength="255" placeholder="Requirement / details (optional)" aria-label="Details"></div><button type="submit" class="btn btn--outline btn--sm btn--auto">+ Add</button><div class="field__error" data-error-for="title"></div></form>` : ''}`;

            host.querySelectorAll('[data-item]').forEach((tr) => {
                const iid = tr.dataset.item;
                const save = async (field, el) => {
                    try { const res = await api('PUT', q(`/checklist/${iid}`), { [field]: el.value === '' ? null : el.value }); host.querySelector('[data-sum]').innerHTML = sumHtml(key, res.data.sections[key]); if (field === 'status') el.className = `mini mini--st st-${res.data.item.status}`; }
                    catch (e) { toast(e.message, 'error'); renderChecklist(key); }
                };
                tr.querySelector('[data-f="status"]').addEventListener('change', (e) => save('status', e.target));
                tr.querySelector('[data-f="remarks"]').addEventListener('change', (e) => save('remarks', e.target));
                tr.querySelector('[data-f="measured_value"]')?.addEventListener('change', (e) => save('measured_value', e.target));
            });
            host.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => { try { await api('DELETE', q(`/checklist/${b.dataset.del}`)); renderChecklist(key); } catch (e) { toast(e.message, 'error'); } }));
            host.querySelector('[data-add]')?.addEventListener('submit', async (e) => {
                e.preventDefault(); const f = e.target; clearErrors(f);
                try { await api('POST', q('/checklist'), { section: key, title: f.title.value, detail: f.detail.value || null }); renderChecklist(key); }
                catch (err) { if (err instanceof ApiError && err.errors.title) applyServerErrors(f, err.errors); else toast(err.message, 'error'); }
            });
        } catch (e) { host.innerHTML = `<div class="empty"><strong>Could not load</strong><span>${esc(e.message)}</span></div>`; }
    }
    function sumHtml(key, s) {
        const done = s.completed_on && s.state === 'completed' ? `<span class="muted"> · Completed on ${esc(s.completed_on)}</span>` : '';
        const bar = key === 'installation' ? `<div class="progress" role="progressbar" aria-valuenow="${s.percent}" aria-valuemin="0" aria-valuemax="100"><span style="width:${s.percent}%"></span></div> <b>${s.percent}%</b> ` : '';
        return `${bar}${stateChip(s.state)}${done} <span class="muted">${s.done}/${s.total}</span>`;
    }

    /* documents */
    async function renderDocuments() {
        const host = panel('documents'); host.innerHTML = '<div class="empty">Loading…</div>';
        try {
            const { data } = await api('GET', q('/documents')); const can = job.can.documents; const rm = job.can.remove_documents;
            const size = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
            host.innerHTML = `<h3 class="sub-h">Documents &amp; Attachments</h3><p class="muted">Store manuals, certificates, photos and related documents.</p>
                ${can ? `<form class="doc-form" data-doc novalidate><div class="input-wrap" data-field><input name="title" maxlength="160" placeholder="Document name (e.g. Installation report)"></div><label class="btn btn--outline btn--sm btn--auto" style="cursor:pointer">Choose file<input type="file" name="file" hidden></label><span class="muted" data-fname>No file chosen</span><button class="btn btn--primary btn--sm btn--auto" type="submit">Upload</button><small class="muted" style="flex-basis:100%">PDF, DOC, XLS, JPG, PNG · max 10 MB</small><div class="field__error" data-error-for="title"></div><div class="field__error" data-error-for="file"></div></form>` : ''}
                ${data.length ? `<div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Document Name</th><th>Type</th><th>Upload Date</th><th>Size</th><th style="text-align:right">Actions</th></tr></thead><tbody>${data.map((d, n) => `<tr><td>${n + 1}</td><td>${esc(d.title)}${d.category === 'signed_certificate' ? ' <span class="pill pill--ist-completed">Signed certificate</span>' : ''}</td><td>${esc(d.type)}</td><td>${esc(d.date)}</td><td>${size(d.size)}</td>
                    <td class="row-actions"><a class="icon-btn" href="${esc(d.url)}" title="Download" aria-label="Download ${esc(d.title)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg></a>${rm ? `<button class="icon-btn icon-btn--danger" data-rm="${d.id}" title="Remove" aria-label="Remove ${esc(d.title)}">${ICON_TRASH}</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : '<div class="empty"><strong>No documents</strong><span>Upload manuals, certificates or photos.</span></div>'}`;
            const f = host.querySelector('[data-doc]');
            f?.querySelector('input[type=file]').addEventListener('change', (e) => { f.querySelector('[data-fname]').textContent = e.target.files[0]?.name ?? 'No file chosen'; });
            f?.addEventListener('submit', async (e) => { e.preventDefault(); clearErrors(f); const btn = f.querySelector('button[type=submit]'); btn.disabled = true; try { const res = await api('POST', q('/documents'), new FormData(f)); toast(res.message); renderDocuments(); } catch (err) { if (err instanceof ApiError && err.status === 422) applyServerErrors(f, err.errors); else toast(err.message, 'error'); btn.disabled = false; } });
            host.querySelectorAll('[data-rm]').forEach((b) => b.addEventListener('click', async () => { if (!await confirmDialog({ title: 'Remove document?', message: 'The file will be permanently deleted.', confirmLabel: 'Remove', danger: true })) return; try { toast((await api('DELETE', q(`/documents/${b.dataset.rm}`))).message); renderDocuments(); } catch (e) { toast(e.message, 'error'); } }));
        } catch (e) { host.innerHTML = `<div class="empty"><strong>Could not load</strong><span>${esc(e.message)}</span></div>`; }
    }

    /* certificate */
    async function renderCertificate() {
        const host = panel('certificate');
        if (job.status !== 'completed') { host.innerHTML = '<div class="empty"><strong>No certificate yet</strong><span>The certificate is issued when the job is approved and completed.</span></div>'; return; }
        let signed = [];
        try { signed = (await api('GET', q('/documents'))).data.filter((d) => d.category === 'signed_certificate'); } catch { /* shown empty */ }
        host.innerHTML = `<h3 class="sub-h">Commissioning Certificate</h3><p class="muted">Certificate ${esc(job.certificate_number)} issued on ${esc(job.completed_at)}.</p>
            <div class="cert-actions"><a class="btn btn--primary btn--sm btn--auto" href="${esc(job.certificate.pdf)}">Download PDF</a><a class="btn btn--outline btn--sm btn--auto" href="${esc(job.certificate.view)}">View / Print Certificate</a></div>
            <h3 class="sub-h" style="margin-top:20px">Signed copy</h3>
            ${signed.length ? `<ul class="plain">${signed.map((d) => `<li><a class="link" href="${esc(d.url)}">${esc(d.title)} (${esc(d.name)})</a> <span class="muted">${esc(d.date)}</span></li>`).join('')}</ul>` : '<p class="muted">No signed copy uploaded yet.</p>'}
            ${job.can.documents ? `<form class="doc-form" data-signed novalidate><label class="btn btn--outline btn--sm btn--auto" style="cursor:pointer">Upload Signed Copy<input type="file" name="file" hidden></label><span class="muted" data-fname></span><div class="field__error" data-error-for="file"></div></form>` : ''}`;
        const f = host.querySelector('[data-signed]');
        f?.querySelector('input').addEventListener('change', async (e) => {
            const file = e.target.files[0]; if (!file) return; clearErrors(f);
            const fd = new FormData(); fd.append('file', file); fd.append('title', 'Signed commissioning certificate'); fd.append('category', 'signed_certificate');
            try { toast((await api('POST', q('/documents'), fd)).message); renderCertificate(); } catch (err) { if (err instanceof ApiError && err.errors.file) applyServerErrors(f, err.errors); else toast(err.message, 'error'); }
        });
    }

    /* handover + signature pad */
    function renderHandover() {
        const host = panel('handover');
        if (job.handover) {
            const h = job.handover;
            host.innerHTML = `<h3 class="sub-h">Department Handover</h3><p class="muted">Recorded handover to the using department.</p>
                <dl class="kv-list"><div><dt>Handover Date</dt><dd>${esc(h.date)}</dd></div><div><dt>Handed over to</dt><dd>${esc(h.to)}${h.to_designation ? ` (${esc(h.to_designation)})` : ''}</dd></div>
                <div><dt>Staff trained</dt><dd>${h.staff_trained.length ? h.staff_trained.map((s) => `<span class="chip">${esc(s.group)} (${s.count})</span>`).join(' ') : '—'}</dd></div><div><dt>Notes</dt><dd>${dash(h.notes)}</dd></div>
                <div><dt>Department sign-off</dt><dd><img class="sig-img" src="${esc(h.signature_url)}" alt="Department sign-off"></dd></div></dl>`;
            return;
        }
        if (job.status !== 'completed') { host.innerHTML = '<div class="empty"><strong>Handover not available yet</strong><span>Handover is recorded after the installation is approved and completed.</span></div>'; return; }
        if (!job.can.handover) { host.innerHTML = '<div class="empty"><strong>No handover recorded</strong><span>Someone who manages installations can record the department handover.</span></div>'; return; }

        const trained = [];
        host.innerHTML = `<h3 class="sub-h">Department Handover &amp; Sign-off</h3><p class="muted">Formal handover to the using department after successful installation.</p>
            <form data-handover novalidate><div class="form-grid">
                <div class="field"><label class="field__label" for="h-date">Handover Date *</label><div class="input-wrap" data-field><input id="h-date" type="date" name="handover_date" value="${new Date().toISOString().slice(0, 10)}"></div><div class="field__error" data-error-for="handover_date"></div></div>
                <div class="field"><label class="field__label" for="h-to">Handover To (Department Head) *</label><div data-field><select class="select" id="h-to" name="handover_to"><option value="">Select person</option>${opts.staff.map((u) => `<option value="${u.id}">${esc(u.name)}${u.designation ? ` – ${esc(u.designation)}` : ''}</option>`).join('')}</select></div><div class="field__error" data-error-for="handover_to"></div></div>
            </div>
            <div class="field"><span class="field__label">Staff Trained</span><div class="trained" data-chips></div>
                <div class="add-item"><div class="input-wrap" data-field><input data-group maxlength="60" placeholder="Group (e.g. Nurse Team)"></div><div class="input-wrap" data-field style="max-width:110px"><input data-count type="number" min="1" max="500" placeholder="Count"></div><button type="button" class="btn btn--outline btn--sm btn--auto" data-add-group>+ Add</button></div><div class="field__error" data-error-for="staff_trained"></div></div>
            <div class="field"><label class="field__label" for="h-notes">Handover Notes</label><textarea class="textarea" id="h-notes" name="notes" rows="3" maxlength="2000" placeholder="Training completed and equipment handed over for clinical use."></textarea></div>
            <div class="field"><span class="field__label">Department Sign-off *</span><canvas class="sig-pad" data-sig width="520" height="140" aria-label="Signature pad: draw your signature"></canvas>
                <div class="scan-actions"><button type="button" class="btn btn--outline btn--sm btn--auto" data-sig-clear>Clear</button></div><div class="field__error" data-error-for="signature"></div></div>
            <div class="form-actions"><button type="submit" class="btn btn--primary btn--sm btn--auto">Save Handover</button></div></form>`;

        const chips = host.querySelector('[data-chips]');
        const paintChips = () => { chips.innerHTML = trained.map((t, i) => `<span class="chip">${esc(t.group)} (${t.count}) <button type="button" class="chip__x" data-x="${i}" aria-label="Remove ${esc(t.group)}">×</button></span>`).join(''); };
        host.querySelector('[data-add-group]').addEventListener('click', () => { const g = host.querySelector('[data-group]'); const c = host.querySelector('[data-count]'); const n = parseInt(c.value, 10); if (!g.value.trim() || !n) { toast('Enter a group and a count.', 'error'); return; } trained.push({ group: g.value.trim(), count: n }); g.value = ''; c.value = ''; paintChips(); });
        chips.addEventListener('click', (e) => { const x = e.target.closest('[data-x]'); if (x) { trained.splice(Number(x.dataset.x), 1); paintChips(); } });

        const cv = host.querySelector('[data-sig]'); const ctx = cv.getContext('2d'); let drawing = false; let ink = 0;
        ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0f2742';
        const pos = (e) => { const r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * (cv.width / r.width), (e.clientY - r.top) * (cv.height / r.height)]; };
        cv.addEventListener('pointerdown', (e) => { drawing = true; cv.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.moveTo(...pos(e)); e.preventDefault(); });
        cv.addEventListener('pointermove', (e) => { if (!drawing) return; ctx.lineTo(...pos(e)); ctx.stroke(); ink += 1; });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach((ev) => cv.addEventListener(ev, () => { drawing = false; }));
        host.querySelector('[data-sig-clear]').addEventListener('click', () => { ctx.clearRect(0, 0, cv.width, cv.height); ink = 0; });

        const f = host.querySelector('[data-handover]');
        f.addEventListener('submit', async (e) => {
            e.preventDefault(); clearErrors(f);
            if (ink < 8) { f.querySelector('[data-error-for="signature"]').textContent = 'Please sign in the box.'; return; }
            const btn = f.querySelector('button[type=submit]'); btn.disabled = true;
            try {
                const res = await api('POST', q('/handover'), { handover_date: f.handover_date.value, handover_to: f.handover_to.value || null, staff_trained: trained, notes: f.notes.value || null, signature: cv.toDataURL('image/png') });
                toast(res.message); await load();
            } catch (err) {
                if (err instanceof ApiError && err.status === 422 && Object.keys(err.errors).length) applyServerErrors(f, err.errors); else toast(err.message, 'error');
                btn.disabled = false;
            }
        });
    }

    load();
}

/* ---------------------------------------------------------------- reports (10) */
function initReports(root) {
    const $ = (s) => root.querySelector(s); const form = $('[data-filters]'); const result = $('[data-result]');
    (async () => {
        try {
            const { data } = await api('GET', root.dataset.options);
            data.facilities.forEach((x) => form.facility_id.insertAdjacentHTML('beforeend', `<option value="${x.id}">${esc(x.name)}</option>`));
            [...new Map(data.departments.map((d) => [d.id, d])).values()].forEach((d) => form.department_id.insertAdjacentHTML('beforeend', `<option value="${d.id}">${esc(d.name)}</option>`));
        } catch { /* defaults */ }
    })();
    const filters = () => Object.fromEntries([...new FormData(form)].filter(([k, v]) => k !== 'type' && v));
    async function run(type) {
        result.hidden = false; const table = $('[data-table]'); table.innerHTML = '<div class="empty">Generating…</div>'; $('[data-summary]').innerHTML = ''; $('[data-note]').textContent = '';
        try {
            const { data } = await api('GET', `${root.dataset.base}/${type}?${qs(filters())}`);
            $('[data-title]').textContent = data.title;
            if (data.summary?.total !== undefined) $('[data-summary]').innerHTML = `<span class="chip">Total cost: <b>₹ ${Number(data.summary.total).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</b></span>`;
            table.innerHTML = data.rows.length ? `<table class="table"><thead><tr>${data.columns.map((c) => `<th>${esc(c.label)}</th>`).join('')}</tr></thead><tbody>${data.rows.map((r) => `<tr>${data.columns.map((c) => `<td>${dash(r[c.key])}</td>`).join('')}</tr>`).join('')}</tbody></table>` : '<div class="empty"><strong>No records</strong><span>Nothing matches these filters.</span></div>';
            $('[data-note]').textContent = data.total > data.shown ? `Showing the first ${data.shown} of ${data.total} rows. Download for everything.` : `${data.total} row(s)`;
            result.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) { table.innerHTML = `<div class="empty"><strong>Could not generate</strong><span>${esc(e.message)}</span></div>`; }
    }
    form.addEventListener('submit', (e) => { e.preventDefault(); run(form.type.value); });
    root.querySelectorAll('[data-download]').forEach((b) => b.addEventListener('click', () => window.location.assign(`${root.dataset.base}/${b.dataset.download}?${qs({ ...filters(), format: 'csv' })}`)));
    $('[data-print]').addEventListener('click', () => window.print());
}
