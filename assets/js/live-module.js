(function () {
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const title = value => String(value || '').replace(/([a-z0-9])([A-Z])/g, '$1 $2').replace(/[_-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    const fmt = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'Not applicable';
    const currency = (value, code = 'PHP') => new Intl.NumberFormat(undefined, { style: 'currency', currency: code || 'PHP' }).format(Number(value || 0));
    const badge = (value, type = 'status') => `<span class="facility-badge facility-${type}-${String(value || 'none').toLowerCase().replace(/[^a-z0-9]+/g, '-')}">${esc(title(value || 'Not applicable'))}</span>`;
    const truncate = (value, className = 'table-cell-truncate') => `<span class="${className}" title="${esc(value || 'Not applicable')}">${esc(value || 'Not applicable')}</span>`;

    function qs(id) { return document.getElementById(id); }
    function params(state) {
        const p = new URLSearchParams();
        Object.entries(state.filters).forEach(([k, v]) => { if (v && v !== 'all') p.set(k, v); });
        p.set('page', state.page); p.set('per_page', state.perPage); p.set('sort', state.sort); p.set('direction', state.direction);
        return p.toString();
    }
    function renderHeaders(config) {
        const columns = Array.isArray(config.columns) ? config.columns : [];
        const table = qs(config.tableBodyId)?.closest('table');
        if (!table) return;
        table.querySelectorAll('th[data-column]').forEach(th => {
            const key = th.dataset.column;
            const column = columns.find(col => col.columnKey === key || col.key === key);
            th.innerHTML = `<span class="facility-column-label">${esc(column?.label || title(key))}</span>`;
        });
    }
    function auditTable(config) {
        const table = qs(config.tableBodyId)?.closest('table');
        if (!table || !window.FAMTableAudit) return;
        window.FAMTableAudit.check(table, config.tableBodyId);
    }
    function renderRows(config, rows, state) {
        const loading = qs(config.loadingId), body = qs(config.tableBodyId), empty = qs(config.emptyId), count = qs(config.countId), pager = document.querySelector(config.paginationSelector);
        renderHeaders(config);
        loading?.classList.add('hidden');
        if (count) count.textContent = rows.length ? `${state.pagination.total} ${config.recordLabel}` : `No matching ${config.recordLabel}`;
        if (!body) return;
        auditTable(config);
        if (!rows.length) {
            body.innerHTML = ''; empty?.classList.remove('hidden'); pager?.classList.add('hidden');
            if (empty) empty.innerHTML = `<span class="material-symbols-outlined" aria-hidden="true">inbox</span><strong>${esc(config.emptyTitle)}</strong><span>${esc(config.emptyText || 'Records will appear here after they are created in the database.')}</span>`;
            return;
        }
        empty?.classList.add('hidden'); pager?.classList.remove('hidden');
        body.innerHTML = rows.map(row => `<tr data-live-id="${row.id}">${config.columns.map(col => `<td class="${esc(col.className || '')}">${col.render ? col.render(row, { esc, title, fmt, currency, badge, truncate }) : truncate(row[col.key])}</td>`).join('')}<td class="facility-actions-cell"><div class="facility-action-menu"><button class="facility-action-toggle" type="button" data-live-view="${row.id}" aria-label="View details">&#8942;</button></div></td></tr>`).join('');
        auditTable(config);
    }
    function renderPagination(config, state) {
        const pages = Math.max(1, Number(state.pagination.total_pages || 1));
        const status = qs(config.pageStatusId); if (status) status.textContent = `Page ${state.page} of ${pages}`;
        const prev = qs(config.prevId), next = qs(config.nextId); if (prev) prev.disabled = state.page <= 1; if (next) next.disabled = state.page >= pages;
    }
    function detailPairs(item) {
        const skip = new Set(['id', 'history', 'materials', 'items', 'participants', 'documents', 'purchase_orders', 'work_orders']);
        const pairs = [];
        Object.entries(item || {}).forEach(([key, value]) => { if (!skip.has(key) && value !== null && typeof value !== 'object') pairs.push({ label: title(key), value: value === '' ? 'Not applicable' : String(value) }); });
        ['history','materials','items','participants','documents','purchase_orders','work_orders'].forEach(key => {
            if (Array.isArray(item?.[key]) && item[key].length) pairs.push({ label: title(key), value: item[key].map(x => Object.values(x).filter(v => v !== null && v !== '').join(' - ')).join('\n') });
        });
        return pairs;
    }
    function init(config) {
        const state = { page: 1, perPage: config.perPage || 10, sort: config.defaultSort || 'created_at', direction: config.defaultDirection || 'desc', filters: {}, pagination: { total: 0, total_pages: 1 }, rows: [] };
        async function load() {
            qs(config.loadingId)?.classList.remove('hidden');
            try {
                const payload = await window.FAMApi.request(`${config.apiBase}/index.php?${params(state)}`);
                state.rows = payload.data?.items || []; state.pagination = payload.data?.pagination || state.pagination;
                renderRows(config, state.rows, state); renderPagination(config, state);
                const updated = qs(config.updatedId); if (updated) updated.textContent = `Last updated: ${new Date().toLocaleString()}`;
            } catch (error) {
                state.rows = []; renderRows(config, [], state); window.FAMModal?.showToast(error.message || 'Unable to load live data.');
            }
        }
        async function show(id) {
            try {
                const payload = await window.FAMApi.request(`${config.apiBase}/show.php?id=${encodeURIComponent(id)}`);
                const item = payload.data?.item || {};
                window.FAMDetailsModal?.show('View Details', config.titleFor(item), detailPairs(item));
            } catch (error) { window.FAMModal?.showToast(error.message || 'Unable to load details.'); }
        }
        qs(config.searchId)?.addEventListener('input', e => { state.filters.search = e.target.value.trim(); state.page = 1; clearTimeout(state.timer); state.timer = setTimeout(load, 300); });
        qs(config.prevId)?.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); load(); });
        qs(config.nextId)?.addEventListener('click', () => { state.page += 1; load(); });
        qs(config.refreshId)?.addEventListener('click', load);
        qs(config.resetId)?.addEventListener('click', () => { state.filters = {}; state.page = 1; const search = qs(config.searchId); if (search) search.value = ''; load(); });
        if (config.createId) qs(config.createId)?.addEventListener('click', () => window.FAMModal?.showToast('This workflow will be enabled in a later implementation step.'));
        qs(config.exportId)?.addEventListener('click', () => {
            if (typeof config.exportUrl === 'function') {
                window.location.href = config.exportUrl(params(state), state);
                return;
            }
            window.FAMModal?.showToast('Live export will be enabled in a later implementation step.');
        });
        qs(config.tableBodyId)?.addEventListener('click', e => { const button = e.target.closest('[data-live-view]'); if (button) show(button.dataset.liveView); });
        load();
    }
    window.FAMLiveModule = { init, badge, fmt, currency, esc, title };
})();
