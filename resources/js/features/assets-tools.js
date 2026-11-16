import { api, ApiError } from '../lib/http';
import { esc, setBusy, toast } from '../lib/ui';

const qs = (params) => new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString();

/** QR / barcode libraries are loaded on demand so other pages stay light. */
export async function qrDataUrl(text, width = 256) {
    const QR = (await import('qrcode')).default;
    return QR.toDataURL(text, { margin: 1, width, errorCorrectionLevel: 'M' });
}

export function initAssetTools() {
    const labels = document.querySelector('[data-labels]');
    if (labels) initLabels(labels);
    const scan = document.querySelector('[data-scan]');
    if (scan) initScan(scan);
    const imp = document.querySelector('[data-import]');
    if (imp) initImport(imp);
    const rep = document.querySelector('[data-reports]');
    if (rep) initReports(rep);
}

/* ------------------------------------------------------- labels (5) */
async function labelEl(asset, o) {
    const [w, h] = o.size.split('x').map(Number);
    const el = document.createElement('div');
    el.className = `label label--${o.size}`;
    el.style.width = `${w}mm`; el.style.height = `${h}mm`;
    const info = [];
    if (o.inc.includes('name')) info.push(`<div class="label__name">${esc(asset.name)}</div>`);
    if (o.inc.includes('serial') && asset.serial_number) info.push(`<div>SN: ${esc(asset.serial_number)}</div>`);
    if (o.inc.includes('department') && asset.department) info.push(`<div>Dept: ${esc(asset.department)}</div>`);
    if (o.inc.includes('location') && asset.location) info.push(`<div>Loc: ${esc(asset.location)}</div>`);
    const wantQr = o.kind === 'qr' || o.kind === 'both';
    const wantBar = o.kind === 'barcode' || o.kind === 'both';
    el.innerHTML = `${o.inc.includes('logo') ? '<div class="label__logo">KBT RUVIA <span>BioTech</span></div>' : ''}
        <div class="label__body">${wantQr ? `<img class="label__qr" alt="QR code for ${esc(asset.asset_tag)}" src="${await qrDataUrl(asset.scan_url, 220)}">` : ''}<div class="label__info">${info.join('')}</div></div>
        ${wantBar ? '<svg class="label__bar"></svg>' : ''}
        ${o.inc.includes('tag') ? `<div class="label__tag">${esc(asset.asset_tag)}</div>` : ''}`;
    if (wantBar) {
        const JsBarcode = (await import('jsbarcode')).default;
        JsBarcode(el.querySelector('.label__bar'), asset.asset_tag, { format: 'CODE128', displayValue: false, margin: 0, height: 30, width: 1.4 });
    }
    return el;
}

async function initLabels(root) {
    const ids = (root.dataset.ids || '').split(',').map((s) => parseInt(s, 10)).filter(Boolean);
    if (!ids.length) { root.querySelector('[data-labels-empty]').hidden = false; return; }
    root.querySelector('[data-labels-main]').hidden = false;
    const form = root.querySelector('[data-labels-form]');
    const preview = root.querySelector('[data-labels-preview]');
    // Lives on <body>, outside the app shell, so printing can hide the shell and show only the labels.
    const sheet = document.createElement('div');
    sheet.className = 'print-sheet'; sheet.setAttribute('aria-hidden', 'true');
    document.body.appendChild(sheet);
    const opts = () => ({
        kind: form.querySelector('[name=kind]:checked').value, size: form.size.value,
        inc: [...form.querySelectorAll('[name=inc]:checked')].map((c) => c.value),
    });
    let assets = [];

    const copies = () => Math.min(50, Math.max(1, parseInt(form.copies.value, 10) || 1));
    const renderCount = () => { root.querySelector('[data-labels-count]').textContent = `${assets.length} asset(s) × ${copies()} = ${assets.length * copies()} label(s)`; };
    const renderPreview = async () => {
        if (!assets.length) return;
        preview.innerHTML = '';
        preview.appendChild(await labelEl(assets[0], opts()));
        renderCount();
    };

    try {
        assets = (await api('POST', root.dataset.api, { ids, preview: true })).data;
        await renderPreview();
    } catch (e) {
        preview.innerHTML = `<div class="empty"><strong>Could not load assets</strong><span>${esc(e.message)}</span></div>`;
        return;
    }
    form.addEventListener('change', renderPreview);
    form.copies.addEventListener('input', renderCount);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type=submit]');
        form.querySelector('[data-error-for=copies]').textContent = '';
        setBusy(btn, true);
        try {
            const n = copies();
            const res = await api('POST', root.dataset.api, { ids, copies: n });   // also writes the audit entry
            sheet.innerHTML = '';
            const o = opts();
            for (const a of res.data) for (let i = 0; i < n; i++) sheet.appendChild(await labelEl(a, o));
            document.body.classList.add('printing-labels');
            const done = () => { document.body.classList.remove('printing-labels'); window.removeEventListener('afterprint', done); };
            window.addEventListener('afterprint', done);
            window.print();
        } catch (err) {
            if (err instanceof ApiError && err.errors?.copies) form.querySelector('[data-error-for=copies]').textContent = err.errors.copies[0];
            else toast(err.message, 'error');
        } finally { setBusy(btn, false); }
    });
}

/* -------------------------------------------------------- scan (6) */
function initScan(root) {
    const $ = (s) => root.querySelector(s);
    const video = $('[data-scan-video]'); const result = $('[data-scan-result]');
    const startBtn = $('[data-scan-start]'); const stopBtn = $('[data-scan-stop]'); const idle = $('[data-scan-idle]');
    let stream = null; let raf = 0; let busy = false; let detector = null; let jsQR = null;

    const card = (inner) => { result.innerHTML = `<div class="card card__body">${inner}</div>`; };

    async function lookup(code) {
        if (busy) return;
        busy = true;
        card('<div class="empty">Looking up…</div>');
        try {
            const { data: a } = await api('GET', `${root.dataset.lookup}?${qs({ code })}`);
            card(`<div class="scan-found"><div class="alert alert--success" role="status" style="margin-bottom:12px">Asset found</div>
                <div class="scan-found__row">${a.image_url ? `<img src="${esc(a.image_url)}" alt="">` : '<div class="scan-found__ph"></div>'}
                <div><strong>${esc(a.asset_tag)}</strong><div>${esc(a.name)}</div><div class="muted">${esc([a.manufacturer, a.model].filter(Boolean).join(' '))}</div></div></div>
                <dl class="kv-list" style="margin-top:12px"><div><dt>Status</dt><dd><span class="pill pill--st-${esc(a.status)}">${esc(a.status_label)}</span></dd></div>
                <div><dt>Location</dt><dd>${esc(a.location || '—')}</dd></div><div><dt>Department</dt><dd>${esc(a.department || '—')}</dd></div></dl>
                <a class="btn btn--primary btn--sm" style="margin-top:14px" href="${esc(a.url)}">View Asset Details</a></div>`);
        } catch (e) {
            card(`<div class="empty"><strong>No match</strong><span>${esc(e.message)}</span></div>`);
        } finally { busy = false; }
    }

    function stop() {
        cancelAnimationFrame(raf);
        stream?.getTracks().forEach((t) => t.stop()); stream = null; video.srcObject = null;
        startBtn.hidden = false; stopBtn.hidden = true; idle.hidden = false;
    }

    async function tick() {
        if (!stream) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA && !busy) {
            let text = null;
            try {
                if (detector) {
                    text = (await detector.detect(video))[0]?.rawValue ?? null;
                } else {
                    const c = document.createElement('canvas'); c.width = video.videoWidth; c.height = video.videoHeight;
                    const ctx = c.getContext('2d', { willReadFrequently: true }); ctx.drawImage(video, 0, 0);
                    text = jsQR(ctx.getImageData(0, 0, c.width, c.height).data, c.width, c.height)?.data ?? null;
                }
            } catch { /* frame not ready */ }
            if (text) { stop(); await lookup(text); return; }
        }
        raf = requestAnimationFrame(tick);
    }

    startBtn.addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia) { toast('Camera access needs a secure (HTTPS) connection. Type the asset ID instead.', 'error'); return; }
        try {
            if ('BarcodeDetector' in window) detector = new window.BarcodeDetector({ formats: ['qr_code', 'code_128'] });
            else jsQR = (await import('jsqr')).default;
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
            video.srcObject = stream; await video.play();
            startBtn.hidden = true; stopBtn.hidden = false; idle.hidden = true;
            $('[data-scan-note]').textContent = detector ? 'Scanning QR codes and barcodes…' : 'Scanning QR codes… (barcodes need a browser with barcode support; type the ID instead)';
            raf = requestAnimationFrame(tick);
        } catch (e) {
            stop(); toast(e.name === 'NotAllowedError' ? 'Camera permission was denied.' : 'Could not start the camera.', 'error');
        }
    });
    stopBtn.addEventListener('click', stop);
    $('[data-scan-form]').addEventListener('submit', (e) => { e.preventDefault(); const v = e.target.code.value.trim(); if (v) lookup(v); });
    window.addEventListener('pagehide', stop);

    if (root.dataset.initial) lookup(root.dataset.initial);   // opened from a scanned QR (phone camera app)
}

/* ------------------------------------------------------- import (7) */
function initImport(root) {
    const $ = (s) => root.querySelector(s);
    const input = $('[data-file]'); const drop = $('[data-drop]'); const upload = $('[data-upload]'); let token = null;
    const step = (n) => {
        root.querySelectorAll('[data-step]').forEach((li) => { const s = Number(li.dataset.step); li.classList.toggle('is-current', s === n); li.classList.toggle('is-done', s < n); });
        root.querySelectorAll('[data-pane]').forEach((p) => { p.hidden = Number(p.dataset.pane) !== Math.min(n, 2); });
    };
    const setFile = (f) => { $('[data-file-name]').textContent = f ? `${f.name} (${Math.max(1, Math.round(f.size / 1024))} KB)` : ''; upload.disabled = !f; };

    input.addEventListener('change', () => setFile(input.files[0]));
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', (e) => { const f = e.dataTransfer.files[0]; if (f) { const dt = new DataTransfer(); dt.items.add(f); input.files = dt.files; setFile(f); } });

    upload.addEventListener('click', async () => {
        const fd = new FormData(); fd.append('file', input.files[0]);
        upload.disabled = true; upload.textContent = 'Validating…';
        try {
            const { data } = await api('POST', root.dataset.validate, fd);
            token = data.token;
            step(2);
            $('[data-summary]').innerHTML = `<div class="chips"><span class="chip">Rows: <b>${data.total}</b></span><span class="chip chip--ok">Valid: <b>${data.valid}</b></span><span class="chip ${data.invalid ? 'chip--bad' : ''}">Errors: <b>${data.invalid}</b></span></div>
                ${data.invalid ? '<div class="alert alert--error" role="alert">Fix the problems below in your file and upload it again. Nothing has been imported.</div>' : `<div class="alert alert--success" role="status">All ${data.total} row(s) are valid and ready to import.</div>`}`;
            $('[data-errors-wrap]').hidden = !data.invalid;
            $('[data-errors]').innerHTML = data.errors.map((e) => `<tr><td>${e.row}</td><td>${e.messages.map(esc).join('<br>')}</td></tr>`).join('') + (data.invalid > data.errors.length ? `<tr><td colspan="2" class="muted">…and ${data.invalid - data.errors.length} more row(s) with errors.</td></tr>` : '');
            $('[data-go]').hidden = !token; $('[data-go]').textContent = `Import ${data.valid} asset(s)`;
        } catch (e) { toast(e.message, 'error'); }
        upload.disabled = false; upload.textContent = 'Validate file';
    });

    $('[data-go]').addEventListener('click', async (e) => {
        const btn = e.currentTarget; btn.disabled = true; btn.textContent = 'Importing…';
        try {
            const res = await api('POST', root.dataset.confirm, { token });
            try { sessionStorage.setItem('kbt.flash', res.message); } catch { /* ignore */ }
            step(3);
            window.location.assign(root.dataset.done);
        } catch (err) { toast(err.message, 'error'); btn.disabled = false; btn.textContent = 'Import assets'; }
    });
    $('[data-reset]').addEventListener('click', () => { input.value = ''; setFile(null); token = null; step(1); });
}

/* ------------------------------------------------------- reports (11) */
function initReports(root) {
    const $ = (s) => root.querySelector(s);
    const result = $('[data-result]'); const form = $('[data-filters]');
    let type = null;

    (async () => {
        try {
            const { data } = await api('GET', root.dataset.options);
            data.facilities.forEach((f) => form.facility_id.insertAdjacentHTML('beforeend', `<option value="${f.id}">${esc(f.name)}</option>`));
            data.categories.forEach((c) => form.category_id.insertAdjacentHTML('beforeend', `<option value="${c.id}">${esc(c.name)}</option>`));
        } catch { /* filters keep defaults */ }
    })();

    const params = () => Object.fromEntries([...new FormData(form)].filter(([k]) => {
        const el = form.querySelector(`[name="${k}"]`)?.closest('[data-only]') ?? form.querySelector(`[name="${k}"]`);
        return !el.hidden;
    }));

    async function run() {
        const table = $('[data-table]');
        table.innerHTML = '<div class="empty">Generating…</div>'; $('[data-summary]').innerHTML = ''; $('[data-note]').textContent = '';
        try {
            const { data } = await api('GET', `${root.dataset.base}/${type}?${qs(params())}`);
            $('[data-title]').textContent = data.title;
            if (type === 'lifecycle') $('[data-summary]').innerHTML = data.summary.map((s) => `<span class="chip">${esc(s.label)}: <b>${s.count}</b></span>`).join('');
            if (type === 'warranty' && data.summary.period) $('[data-summary]').innerHTML = `<span class="chip">Period: <b>${esc(data.summary.period)}</b></span>`;
            table.innerHTML = data.rows.length
                ? `<table class="table"><thead><tr>${data.columns.map((c) => `<th>${esc(c.label)}</th>`).join('')}</tr></thead><tbody>${data.rows.map((r) => `<tr>${data.columns.map((c) => `<td>${r[c.key] === null || r[c.key] === undefined || r[c.key] === '' ? '<span class="muted">—</span>' : esc(r[c.key])}</td>`).join('')}</tr>`).join('')}</tbody></table>`
                : '<div class="empty"><strong>No records</strong><span>Nothing matches these filters.</span></div>';
            $('[data-note]').textContent = data.total > data.shown ? `Showing the first ${data.shown} of ${data.total} rows. Export CSV for everything.` : `${data.total} row(s)`;
        } catch (e) { table.innerHTML = `<div class="empty"><strong>Could not generate</strong><span>${esc(e.message)}</span></div>`; }
    }

    root.querySelectorAll('[data-report]').forEach((b) => b.addEventListener('click', () => {
        type = b.dataset.report;
        form.querySelectorAll('[data-only]').forEach((el) => { el.hidden = !el.dataset.only.split(',').includes(type); });
        result.hidden = false; result.scrollIntoView({ behavior: 'smooth', block: 'start' });
        run();
    }));
    form.addEventListener('submit', (e) => { e.preventDefault(); run(); });
    $('[data-export]').addEventListener('click', () => window.location.assign(`${root.dataset.base}/${type}?${qs({ ...params(), format: 'csv' })}`));
    $('[data-print]').addEventListener('click', () => window.print());
}
