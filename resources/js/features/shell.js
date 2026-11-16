import { api } from '../lib/http';
import { esc, timeAgo } from '../lib/ui';

const store = {
    get(key) { try { return localStorage.getItem(key); } catch { return null; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch { /* storage unavailable */ } },
};

export function initShell() {
    const shell = document.querySelector('[data-shell]');
    if (!shell) return;

    /* sidebar: collapse (desktop, remembered) + off-canvas (mobile) */
    if (store.get('kbt.sidebar') === 'collapsed') shell.dataset.collapsed = 'true';
    document.querySelector('[data-sidebar-collapse]')?.addEventListener('click', () => {
        const collapsed = shell.dataset.collapsed === 'true';
        shell.dataset.collapsed = String(!collapsed);
        store.set('kbt.sidebar', collapsed ? 'expanded' : 'collapsed');
    });
    document.querySelector('[data-sidebar-open]')?.addEventListener('click', () => { shell.dataset.menuOpen = 'true'; });
    document.querySelector('[data-sidebar-close]')?.addEventListener('click', () => { shell.dataset.menuOpen = 'false'; });

    /* facility selector */
    document.querySelector('[data-facility-select]')?.addEventListener('change', (e) => e.target.form.submit());

    initMenus();
    initNotifications();
    initSearch();
}

function initMenus() {
    const menus = [...document.querySelectorAll('[data-menu]')];
    const closeAll = (except = null) => menus.forEach((m) => {
        if (m === except) return;
        m.querySelector('[data-menu-panel]').hidden = true;
        m.querySelector('[data-menu-trigger]').setAttribute('aria-expanded', 'false');
    });
    menus.forEach((menu) => {
        const trigger = menu.querySelector('[data-menu-trigger]');
        const panel = menu.querySelector('[data-menu-panel]');
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            closeAll(menu);
            panel.hidden = !panel.hidden;
            trigger.setAttribute('aria-expanded', String(!panel.hidden));
            if (!panel.hidden) panel.querySelector('a, button')?.focus?.();
        });
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('[data-menu]')) closeAll(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const open = menus.find((m) => !m.querySelector('[data-menu-panel]').hidden);
            closeAll();
            open?.querySelector('[data-menu-trigger]').focus();
        }
    });
}

function initNotifications() {
    const trigger = document.querySelector('[data-notifications-trigger]');
    if (!trigger) return;
    const count = document.querySelector('[data-notification-count]');
    const list = document.querySelector('[data-notification-list]');

    const load = async () => {
        try {
            const { data } = await api('GET', '/api/notifications');
            count.hidden = data.unread === 0;
            count.textContent = data.unread > 9 ? '9+' : data.unread;
            list.innerHTML = data.items.length
                ? data.items.map((n) => `<div class="notif ${n.read ? '' : 'is-unread'}"><strong>${esc(n.title)}</strong><span>${esc(n.body)}</span><small>${timeAgo(n.created_at)}</small></div>`).join('')
                : '<div class="search-empty">You have no notifications.</div>';
        } catch {
            list.innerHTML = '<div class="search-empty">Could not load notifications.</div>';
        }
    };
    load();
    trigger.addEventListener('click', load);
    document.querySelector('[data-notifications-read]')?.addEventListener('click', async () => {
        await api('POST', '/api/notifications/read').catch(() => {});
        load();
    });
}

function initSearch() {
    const input = document.querySelector('[data-search-input]');
    const box = document.querySelector('[data-search-results]');
    if (!input) return;
    let timer; let controller;

    const hide = () => { box.hidden = true; input.setAttribute('aria-expanded', 'false'); };
    const show = (html) => { box.innerHTML = html; box.hidden = false; input.setAttribute('aria-expanded', 'true'); };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { hide(); return; }
        timer = setTimeout(async () => {
            controller?.abort();
            controller = new AbortController();
            try {
                const res = await fetch(`/api/search?q=${encodeURIComponent(q)}`, {
                    headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal,
                });
                if (res.status === 401 || res.status === 419) { const p = await res.json().catch(() => ({})); window.location.assign(p.redirect ?? '/login'); return; }
                const { data } = await res.json();
                show(data.length
                    ? data.map((r) => `<a href="${esc(r.url)}"><span>${esc(r.title)}</span><small>${esc(r.type)} · ${esc(r.subtitle)}</small></a>`).join('')
                    : `<div class="search-empty">No results for “${esc(q)}”.</div>`);
            } catch (e) {
                if (e.name !== 'AbortError') show('<div class="search-empty">Search is unavailable right now.</div>');
            }
        }, 250);
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { hide(); input.blur(); }
        if (e.key === 'ArrowDown') box.querySelector('a')?.focus();
    });
    box.addEventListener('keydown', (e) => {
        const links = [...box.querySelectorAll('a')];
        const i = links.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); links[i + 1]?.focus(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); (links[i - 1] ?? input).focus(); }
        if (e.key === 'Escape') { hide(); input.focus(); }
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('[data-search]')) hide(); });
}
