import { api, ApiError } from '../lib/http';
import { esc, formatDate } from '../lib/ui';

const TYPE_COLORS = { preventive: '#2d7be5', breakdown: '#e5484d', calibration: '#f08c1b', installation: '#11b5ab' };
const STATUS_COLORS = { open: '#2d7be5', in_progress: '#11b5ab', on_hold: '#7b61c9', completed: '#1f9d55', cancelled: '#8a97a8' };
const label = (s) => s.replace(/_/g, ' ');

const ICONS = {
    box: '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    clipboard: '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/>',
    calendar: '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/>',
    gauge: '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
    inbox: '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
};
const svg = (name) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name]}</svg>`;
const empty = (title, text) => `<div class="empty">${svg('inbox')}<strong>${esc(title)}</strong><span>${esc(text)}</span></div>`;

export function initDashboard() {
    const root = document.querySelector('[data-dashboard]');
    if (!root) return;
    const $ = (sel) => root.querySelector(sel);

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
            if (e instanceof ApiError && e.status === 403) {
                $('[data-dash-error-msg]').textContent = 'Your role does not include the dashboard.';
            } else {
                $('[data-dash-error-msg]').textContent = e.message;
            }
            show('error');
        }
    }
    $('[data-dash-retry]').addEventListener('click', load);

    function render(d) {
        renderStats(d);
        const perms = d.permissions;
        const visible = {
            trend: perms.work_orders, recent: perms.work_orders, status: perms.work_orders,
            priority: perms.work_orders, pm: d.pm_overview !== null,
        };
        Object.entries(visible).forEach(([key, on]) => { $(`[data-card="${key}"]`).hidden = !on; });
        // keep rows tidy when a role sees fewer widgets
        [['[data-row-1]', ['trend', 'recent']], ['[data-row-2]', ['status', 'priority', 'pm']]].forEach(([sel, keys]) => {
            const n = keys.filter((k) => visible[k]).length;
            const row = $(sel);
            row.hidden = n === 0;
            if (n) row.style.gridTemplateColumns = window.matchMedia('(max-width: 1100px)').matches ? '' : `repeat(${n}, minmax(0, 1fr))`;
        });

        if (perms.work_orders) {
            renderTrend(d.trend);
            renderRecent(d.recent_work_orders);
            renderStatus(d.status_breakdown);
            renderPriority(d.priority_breakdown);
        }
        if (d.pm_overview) renderPm(d.pm_overview);
    }

    function renderStats(d) {
        const s = d.stats;
        const cards = [];
        if (s.total_equipment) cards.push(['green', 'box', 'Total Equipment', s.total_equipment.value, `${s.total_equipment.note} added this month`, '']);
        if (s.open_work_orders) cards.push(['red', 'clipboard', 'Open Work Orders', s.open_work_orders.value, s.open_work_orders.critical ? `${s.open_work_orders.critical} critical` : 'No critical orders', s.open_work_orders.critical ? 'is-bad' : 'is-good']);
        if (s.pm_due) cards.push(['blue', 'calendar', 'PM Due This Month', s.pm_due.value, s.pm_due.overdue ? `${s.pm_due.overdue} overdue` : 'None overdue', s.pm_due.overdue ? 'is-bad' : 'is-good']);
        if (s.calibration_due) cards.push(['orange', 'gauge', 'Calibration Due', s.calibration_due.value, s.calibration_due.overdue ? `${s.calibration_due.overdue} overdue` : 'None overdue', s.calibration_due.overdue ? 'is-bad' : 'is-good']);

        $('[data-stats]').innerHTML = cards.length
            ? cards.map(([color, icon, name, value, note, cls]) => `
                <div class="card stat"><div class="stat__icon stat__icon--${color}">${svg(icon)}</div>
                <div><div class="stat__label">${esc(name)}</div><div class="stat__value">${Number(value).toLocaleString()}</div>
                <div class="stat__note ${cls}">${esc(note)}</div></div></div>`).join('')
            : `<div class="card" style="grid-column:1/-1">${empty('No dashboard widgets for your role', 'Ask an administrator if you need access to asset or work order data.')}</div>`;
    }

    function renderTrend(t) {
        const keys = Object.keys(t.series);
        const totals = t.labels.map((_, i) => keys.reduce((sum, k) => sum + t.series[k][i], 0));
        const max = Math.max(...totals);
        const host = $('[data-trend]');
        if (max === 0) { host.innerHTML = empty('No work orders yet', 'The trend appears once work orders are created.'); return; }

        const top = Math.ceil(max / 5) * 5 || 5;
        const legend = keys.map((k) => `<span><i style="background:${TYPE_COLORS[k]}"></i>${esc(label(k))}</span>`).join('');
        const cols = t.labels.map((_, i) => `<div class="bars__col">${keys.map((k) => {
            const v = t.series[k][i];
            return v ? `<div class="bars__seg" style="height:${(v / top) * 100}%;background:${TYPE_COLORS[k]}" title="${esc(label(k))}: ${v}"></div>` : '';
        }).join('')}</div>`).join('');
        const ticks = [0, 0.5, 1].map((f) => `<span class="bars__axis" style="bottom:${f * 100}%">${Math.round(top * f)}</span>`).join('');
        const table = `<table class="sr-only"><caption>Work orders created per month by type</caption><thead><tr><th>Month</th>${keys.map((k) => `<th>${esc(label(k))}</th>`).join('')}</tr></thead><tbody>${t.labels.map((m, i) => `<tr><td>${esc(m)}</td>${keys.map((k) => `<td>${t.series[k][i]}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
        host.innerHTML = `<div class="legend">${legend}</div><div class="bars" aria-hidden="true">${ticks}${cols}</div><div class="bars__labels" aria-hidden="true">${t.labels.map((l) => `<span>${esc(l)}</span>`).join('')}</div>${table}`;
    }

    function renderRecent(rows) {
        $('[data-recent]').innerHTML = rows.length
            ? `<table class="table"><thead><tr><th>#</th><th>Asset</th><th>Type</th><th>Priority</th><th>Status</th></tr></thead><tbody>${rows.map((r) => `
                <tr><td><a class="link" href="/work-orders?q=${encodeURIComponent(r.number)}">${esc(r.number)}</a></td><td>${esc(r.asset)}</td><td style="text-transform:capitalize">${esc(r.type)}</td>
                <td><span class="pill pill--${esc(r.priority)}">${esc(r.priority)}</span></td>
                <td><span class="pill pill--${esc(r.status)}" title="${esc(formatDate(r.created_at))}">${esc(label(r.status))}</span></td></tr>`).join('')}</tbody></table>`
            : empty('No work orders yet', 'Recently created work orders will be listed here.');
    }

    function renderStatus(counts) {
        const total = Object.values(counts).reduce((a, b) => a + b, 0);
        $('[data-status]').innerHTML = total
            ? Object.entries(counts).map(([k, v]) => `<div class="meter-row"><span style="min-width:90px;text-transform:capitalize">${esc(label(k))}</span><div class="meter"><span style="width:${(v / total) * 100}%;background:${STATUS_COLORS[k]}"></span></div><strong>${v}</strong></div>`).join('')
            : empty('No work orders yet', 'Status distribution appears once work orders exist.');
    }

    function renderPriority(counts) {
        const total = Object.values(counts).reduce((a, b) => a + b, 0);
        $('[data-priority]').innerHTML = total
            ? Object.entries(counts).map(([k, v]) => `<div class="meter-row"><span class="pill pill--${esc(k)}">${esc(k)}</span><div class="meter"><span style="width:${(v / total) * 100}%;background:currentColor" class="pill--${esc(k)}"></span></div><strong>${v}</strong></div>`).join('')
            : empty('No open work orders', 'Nothing is waiting on you.');
    }

    function renderPm(pm) {
        const rows = [['Due this month', pm.due_this_month, ''], ['Overdue', pm.overdue, pm.overdue ? 'color:var(--danger)' : ''], ['Completed this month', pm.completed_this_month, 'color:var(--success)']]
            .filter((r) => r[1] !== null);
        $('[data-pm]').innerHTML = pm.due_this_month + pm.overdue + (pm.completed_this_month ?? 0) === 0
            ? empty('No preventive maintenance scheduled', 'Set next PM dates on assets to see the schedule here.')
            : rows.map(([name, v, style]) => `<div class="meter-row"><span>${esc(name)}</span><strong style="${style}">${v}</strong></div>`).join('');
    }

    load();
}
