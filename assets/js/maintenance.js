(function () {
    const pageSize = 6;
    const priorityRank = { Critical: 4, High: 3, Medium: 2, Low: 1 };
    const statusRank = { Open: 1, Assigned: 2, Scheduled: 3, 'In Progress': 4, 'On Hold': 5, Completed: 6, Verified: 7, Cancelled: 8 };
    const slaRank = { overdue: 3, 'due-soon': 2, 'on-track': 1 };

    const workOrders = [
        { workOrderNo: 'WO-2026-0214', title: 'North wing air conditioning inspection', asset: 'AHU-03 Air Handling Unit', priority: 'High', status: 'Scheduled', technician: 'Maintenance Team', scheduledDate: '2026-07-24', sla: 'Due Soon', slaState: 'due-soon', location: 'North Wing / Floor 3', requester: 'Maria Santos', relatedFacilityRequest: 'FR-2026-0148' },
        { workOrderNo: 'WO-2026-0213', title: 'Electrical panel inspection', asset: 'Panel EP-2B', priority: 'Critical', status: 'Assigned', technician: 'Electrical Team', scheduledDate: '2026-07-24', sla: 'Overdue', slaState: 'overdue', location: 'Service Core B', requester: 'Admin User', relatedFacilityRequest: 'FR-2026-0147' },
        { workOrderNo: 'WO-2026-0212', title: 'Visitor lobby lighting repair', asset: 'Lobby Lighting Circuit', priority: 'Medium', status: 'In Progress', technician: 'Facilities Desk', scheduledDate: '2026-07-24', sla: 'On Track', slaState: 'on-track', location: 'Main Lobby', requester: 'Anne Lim', relatedFacilityRequest: 'FR-2026-0146' },
        { workOrderNo: 'WO-2026-0211', title: 'Water leak repair near pantry sink', asset: 'Pantry Plumbing Line', priority: 'High', status: 'Open', technician: 'Unassigned', scheduledDate: '2026-07-25', sla: 'Due Soon', slaState: 'due-soon', location: 'Finance Pantry', requester: 'Leah Garcia', relatedFacilityRequest: 'FR-2026-0144' },
        { workOrderNo: 'WO-2026-0210', title: 'Archive shelving safety reinforcement', asset: 'Archive Shelf Bay 4', priority: 'Medium', status: 'Completed', technician: 'Safety Officer', scheduledDate: '2026-07-23', sla: 'On Track', slaState: 'on-track', location: 'Archive Storage', requester: 'Noel Reyes', relatedFacilityRequest: 'FR-2026-0143' },
        { workOrderNo: 'WO-2026-0209', title: 'Parking barrier sensor calibration', asset: 'Parking Barrier Gate 1', priority: 'Medium', status: 'Verified', technician: 'Security Facilities', scheduledDate: '2026-07-22', sla: 'On Track', slaState: 'on-track', location: 'Basement Parking', requester: 'Camille Tan', relatedFacilityRequest: 'FR-2026-0142' },
        { workOrderNo: 'WO-2026-0208', title: 'Executive office carpet cleaning', asset: 'Executive Office Carpet Zone', priority: 'Low', status: 'Cancelled', technician: 'Housekeeping', scheduledDate: '2026-07-21', sla: 'On Track', slaState: 'on-track', location: 'Executive Office', requester: 'Admin User', relatedFacilityRequest: 'FR-2026-0141' },
        { workOrderNo: 'WO-2026-0207', title: 'Records room access door adjustment', asset: 'Records Door Assembly', priority: 'Low', status: 'On Hold', technician: 'Carpentry Team', scheduledDate: '2026-07-26', sla: 'On Track', slaState: 'on-track', location: 'Records Center', requester: 'Rafael Moreno', relatedFacilityRequest: 'FR-2026-0145' }
    ];

    const state = {
        search: '',
        priority: 'all',
        status: 'all',
        technician: 'all',
        scheduledDate: 'all',
        slaState: 'all',
        sortKey: 'scheduledDate',
        sortDirection: 'desc',
        page: 1,
        loading: true,
        openActionMenu: null,
        openColumnMenu: null
    };

    const columnMenuConfig = {
        priority: { label: 'Priority', filterKey: 'priority', options: () => ['Critical', 'High', 'Medium', 'Low'] },
        status: { label: 'Status', filterKey: 'status', options: () => ['Open', 'Assigned', 'Scheduled', 'In Progress', 'On Hold', 'Completed', 'Verified', 'Cancelled'] },
        technician: { label: 'Technician', filterKey: 'technician', options: () => uniqueOptions('technician') },
        scheduledDate: { label: 'Scheduled Date', filterKey: 'scheduledDate', sortKey: 'scheduledDate', options: () => [{ value: 'today', label: 'Today' }, { value: '7', label: 'Last 7 Days' }, { value: '30', label: 'Last 30 Days' }, { value: 'custom', label: 'Custom Date Range' }] },
        slaState: { label: 'SLA', filterKey: 'slaState', sortKey: 'sla', options: () => [{ value: 'on-track', label: 'On Track' }, { value: 'due-soon', label: 'Due Soon' }, { value: 'overdue', label: 'Overdue' }] }
    };

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    const uniqueOptions = key => [...new Set(workOrders.map(item => item[key]))].sort();

    function formatUpdatedAt() {
        const now = new Date();
        return `Last updated: ${now.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })}, ${now.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    }

    function renderSummary() {
        const openStatuses = new Set(['Open', 'Assigned', 'Scheduled', 'In Progress', 'On Hold']);
        const cards = [
            { label: 'Open Work Orders', value: workOrders.filter(item => openStatuses.has(item.status)).length, supporting: 'Currently active maintenance jobs', status: 'On Track', icon: 'build' },
            { label: 'Assigned Today', value: workOrders.filter(item => item.scheduledDate === '2026-07-24' && item.technician !== 'Unassigned').length, supporting: 'Technicians assigned today', status: 'Scheduled', icon: 'engineering' },
            { label: 'Overdue Work Orders', value: workOrders.filter(item => item.slaState === 'overdue').length, supporting: 'Past scheduled completion', status: 'Overdue', icon: 'timer_off' },
            { label: 'Awaiting Verification', value: workOrders.filter(item => item.status === 'Completed').length, supporting: 'Completed work pending inspection', status: 'Needs Attention', icon: 'fact_check' }
        ];
        document.getElementById('maintenance-summary').innerHTML = cards.map(card => `
            <article class="fam-card facility-summary-card">
                <div class="fam-kpi-top">
                    <div>
                        <p>${escapeHtml(card.label)}</p>
                        <h3>${escapeHtml(card.value)}</h3>
                    </div>
                    <span class="fam-kpi-icon material-symbols-outlined" aria-hidden="true">${card.icon}</span>
                </div>
                <p class="fam-kpi-main-label">${escapeHtml(card.supporting)}</p>
                <div class="fam-kpi-footer">
                    ${badge(card.status, 'status')}
                    <span>${escapeHtml(card.status)}</span>
                </div>
            </article>
        `).join('');
    }

    function badge(value, type) {
        const normalized = String(value).toLowerCase().replace(/\s+/g, '-').replace(/[()]/g, '');
        return `<span class="facility-badge facility-${type}-${normalized}">${escapeHtml(value)}</span>`;
    }

    function slaBadge(item) {
        const icon = item.slaState === 'overdue' ? 'error' : item.slaState === 'due-soon' ? 'schedule' : 'check_circle';
        return `<span class="facility-sla facility-sla-${item.slaState}"><span class="material-symbols-outlined" aria-hidden="true">${icon}</span>${escapeHtml(item.sla)}</span>`;
    }

    function getActions(item) {
        const actionsByStatus = {
            Open: ['View Details', 'Assign Technician', 'Schedule Visit', 'Cancel Work Order'],
            Assigned: ['View Details', 'Update Progress', 'Add Work Log', 'Upload Photos', 'Schedule Visit', 'Cancel Work Order'],
            Scheduled: ['View Details', 'Update Progress', 'Add Work Log', 'Upload Photos', 'Complete Work'],
            'In Progress': ['View Details', 'Update Progress', 'Add Work Log', 'Upload Photos', 'Complete Work'],
            'On Hold': ['View Details', 'Update Progress', 'Add Work Log', 'Cancel Work Order'],
            Completed: ['View Details', 'Verify Completion', 'Add Work Log'],
            Verified: ['View Details'],
            Cancelled: ['View Details']
        };
        return actionsByStatus[item.status] || ['View Details'];
    }

    function matchesDate(item) {
        if (state.scheduledDate === 'all') return true;
        const scheduled = new Date(`${item.scheduledDate}T00:00:00`);
        const now = new Date('2026-07-24T00:00:00');
        if (state.scheduledDate === 'today') return scheduled.toDateString() === now.toDateString();
        if (state.scheduledDate === 'custom') return true;
        const days = Number(state.scheduledDate);
        const cutoff = new Date(now);
        cutoff.setDate(now.getDate() - days);
        return scheduled >= cutoff;
    }

    function filteredOrders() {
        const search = state.search.trim().toLowerCase();
        return workOrders.filter(item => {
            const searchMatch = !search || item.workOrderNo.toLowerCase().includes(search) || item.title.toLowerCase().includes(search) || item.asset.toLowerCase().includes(search);
            return searchMatch
                && (state.priority === 'all' || item.priority === state.priority)
                && (state.status === 'all' || item.status === state.status)
                && (state.technician === 'all' || item.technician === state.technician)
                && (state.slaState === 'all' || item.slaState === state.slaState)
                && matchesDate(item);
        }).sort((a, b) => {
            let first = a[state.sortKey];
            let second = b[state.sortKey];
            if (state.sortKey === 'priority') {
                first = priorityRank[a.priority];
                second = priorityRank[b.priority];
            }
            if (state.sortKey === 'status') {
                first = statusRank[a.status];
                second = statusRank[b.status];
            }
            if (state.sortKey === 'sla') {
                first = slaRank[a.slaState];
                second = slaRank[b.slaState];
            }
            if (state.sortKey === 'scheduledDate') {
                first = new Date(a.scheduledDate).getTime();
                second = new Date(b.scheduledDate).getTime();
            }
            const result = first > second ? 1 : first < second ? -1 : 0;
            return state.sortDirection === 'asc' ? result : -result;
        });
    }

    function renderTable() {
        const loading = document.getElementById('maintenance-loading-state');
        const tbody = document.getElementById('maintenance-table');
        const empty = document.getElementById('maintenance-empty-state');
        const count = document.getElementById('maintenance-table-count');
        const pagination = document.querySelector('.maintenance-workspace .facility-pagination');

        if (state.loading) {
            loading.classList.remove('hidden');
            tbody.innerHTML = '';
            empty.classList.add('hidden');
            pagination.classList.add('hidden');
            return;
        }

        loading.classList.add('hidden');
        const filtered = filteredOrders();
        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        state.page = Math.min(state.page, totalPages);
        const pageItems = filtered.slice((state.page - 1) * pageSize, state.page * pageSize);
        count.textContent = filtered.length ? `${filtered.length} active work orders` : 'No matching work orders';

        if (!workOrders.length || !filtered.length) {
            tbody.innerHTML = '';
            empty.classList.remove('hidden');
            empty.innerHTML = `
                <span class="material-symbols-outlined" aria-hidden="true">${workOrders.length ? 'search_off' : 'inbox'}</span>
                <strong>${workOrders.length ? 'No work orders match the current filters.' : 'No maintenance request data available.'}</strong>
                <span>${workOrders.length ? 'Reset filters or adjust your search criteria.' : 'New work orders will appear here.'}</span>
            `;
            pagination.classList.add('hidden');
            return;
        }

        empty.classList.add('hidden');
        pagination.classList.remove('hidden');
        document.getElementById('maintenance-page-status').textContent = `Page ${state.page} of ${totalPages}`;
        document.getElementById('maintenance-prev-page').disabled = state.page <= 1;
        document.getElementById('maintenance-next-page').disabled = state.page >= totalPages;
        renderColumnHeaders();

        tbody.innerHTML = pageItems.map(item => `
            <tr data-work-order-no="${escapeHtml(item.workOrderNo)}">
                <td class="facility-request-number"><strong>${escapeHtml(item.workOrderNo)}</strong></td>
                <td><div class="facility-subject-cell"><strong>${escapeHtml(item.title)}</strong></div></td>
                <td>${escapeHtml(item.asset)}</td>
                <td>${badge(item.priority, 'priority')}</td>
                <td>${badge(item.status, 'status')}</td>
                <td>${escapeHtml(item.technician)}</td>
                <td class="facility-date-cell">${escapeHtml(item.scheduledDate)}</td>
                <td>${slaBadge(item)}</td>
                <td class="facility-actions-cell">
                    <div class="facility-action-menu">
                        <button class="facility-action-toggle" type="button" aria-haspopup="menu" aria-expanded="${state.openActionMenu === item.workOrderNo}" aria-label="Actions for ${escapeHtml(item.workOrderNo)}" data-action-menu-toggle="${escapeHtml(item.workOrderNo)}">&#8942;</button>
                        <div class="facility-action-dropdown ${state.openActionMenu === item.workOrderNo ? '' : 'hidden'}" role="menu">
                            ${getActions(item).map(action => `<button type="button" role="menuitem" data-request-action="${escapeHtml(action)}" data-request-no="${escapeHtml(item.workOrderNo)}">${escapeHtml(action)}</button>`).join('')}
                        </div>
                    </div>
                </td>
            </tr>
        `).join('');
        positionOpenActionMenu();
    }

    function renderColumnHeaders() {
        Object.entries(columnMenuConfig).forEach(([columnKey, config]) => {
            const header = document.querySelector(`.maintenance-requests-table [data-column="${columnKey}"]`);
            if (!header) return;
            const filterValue = state[config.filterKey];
            const sortKey = config.sortKey || columnKey;
            const isSorted = state.sortKey === sortKey;
            const isFiltered = filterValue !== 'all';
            const menuId = `maintenance-column-menu-${columnKey}`;
            header.innerHTML = `
                <div class="facility-column-menu">
                    <button class="facility-column-trigger ${isSorted || isFiltered ? 'active' : ''}" type="button" aria-haspopup="menu" aria-expanded="${state.openColumnMenu === columnKey}" aria-controls="${menuId}" data-column-menu-toggle="${columnKey}">
                        <span>${escapeHtml(config.label)}</span>
                        ${isFiltered ? '<span class="facility-filter-dot" aria-label="Filtered"></span>' : ''}
                        <span class="facility-sort-indicator ${isSorted ? `facility-sort-${state.sortDirection}` : ''}" aria-hidden="true"></span>
                    </button>
                    <div id="${menuId}" class="facility-column-dropdown ${state.openColumnMenu === columnKey ? '' : 'hidden'}" role="menu">
                        ${renderColumnMenu(columnKey, config)}
                    </div>
                </div>
            `;
        });
        positionOpenColumnMenu();
    }

    function renderColumnMenu(columnKey, config) {
        const filterValue = state[config.filterKey];
        const sortKey = config.sortKey || columnKey;
        const options = config.options().map(option => typeof option === 'string' ? { value: option, label: option } : option);
        const customNote = columnKey === 'scheduledDate' ? '<span class="facility-column-note">Custom range is reserved for the date picker workflow.</span>' : '';
        return `
            <div class="facility-column-menu-group">
                <span>Sort</span>
                <button type="button" role="menuitem" data-column-sort="${columnKey}" data-sort-direction="asc">${columnKey === 'scheduledDate' ? 'Sort oldest first' : 'Sort Ascending'}</button>
                <button type="button" role="menuitem" data-column-sort="${columnKey}" data-sort-direction="desc">${columnKey === 'scheduledDate' ? 'Sort newest first' : 'Sort Descending'}</button>
                ${state.sortKey === sortKey ? `<button type="button" role="menuitem" data-column-clear-sort="${columnKey}">Clear Sort</button>` : ''}
            </div>
            <div class="facility-column-menu-group">
                <span>Filter</span>
                <button type="button" role="menuitem" data-column-filter="${columnKey}" data-filter-value="all">All</button>
                ${options.map(option => `<button class="${filterValue === option.value ? 'selected' : ''}" type="button" role="menuitem" data-column-filter="${columnKey}" data-filter-value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</button>`).join('')}
                ${customNote}
                ${filterValue !== 'all' ? `<button type="button" role="menuitem" data-column-clear-filter="${columnKey}">Clear Filter</button>` : ''}
            </div>
        `;
    }

    function positionOpenActionMenu() {
        const dropdown = document.querySelector('.maintenance-workspace .facility-action-dropdown:not(.hidden)');
        const toggle = document.querySelector('.maintenance-workspace [data-action-menu-toggle][aria-expanded="true"]');
        if (!dropdown || !toggle) return;
        dropdown.classList.remove('facility-action-dropdown-up', 'facility-action-dropdown-down');
        dropdown.classList.add('facility-action-dropdown-down');
        const toggleRect = toggle.getBoundingClientRect();
        const dropdownHeight = dropdown.getBoundingClientRect().height;
        const spaceBelow = window.innerHeight - toggleRect.bottom;
        const spaceAbove = toggleRect.top;
        const needsUpward = spaceBelow < dropdownHeight + 16 && spaceAbove > spaceBelow;
        dropdown.classList.toggle('facility-action-dropdown-down', !needsUpward);
        dropdown.classList.toggle('facility-action-dropdown-up', needsUpward);
    }

    function positionOpenColumnMenu() {
        const dropdown = document.querySelector('.maintenance-workspace .facility-column-dropdown:not(.hidden)');
        const trigger = document.querySelector('.maintenance-workspace [data-column-menu-toggle][aria-expanded="true"]');
        if (!dropdown || !trigger) return;
        dropdown.style.top = '';
        dropdown.style.left = '';
        const triggerRect = trigger.getBoundingClientRect();
        const dropdownRect = dropdown.getBoundingClientRect();
        const spaceBelow = window.innerHeight - triggerRect.bottom;
        const spaceAbove = triggerRect.top;
        const needsUpward = spaceBelow < dropdownRect.height + 16 && spaceAbove > spaceBelow;
        const top = needsUpward ? Math.max(16, triggerRect.top - dropdownRect.height - 8) : Math.min(window.innerHeight - dropdownRect.height - 16, triggerRect.bottom + 8);
        const left = Math.min(Math.max(16, triggerRect.left), window.innerWidth - dropdownRect.width - 16);
        dropdown.classList.toggle('facility-column-dropdown-down', !needsUpward);
        dropdown.classList.toggle('facility-column-dropdown-up', needsUpward);
        dropdown.style.top = `${top}px`;
        dropdown.style.left = `${left}px`;
    }

    function bindEvents() {
        document.getElementById('maintenance-search')?.addEventListener('input', event => {
            state.search = event.target.value;
            state.page = 1;
            state.openActionMenu = null;
            state.openColumnMenu = null;
            updateResetVisibility();
            renderTable();
        });

        document.querySelectorAll('.maintenance-workspace [data-sort]').forEach(button => {
            button.addEventListener('click', () => {
                const key = button.dataset.sort;
                state.sortDirection = state.sortKey === key && state.sortDirection === 'asc' ? 'desc' : 'asc';
                state.sortKey = key;
                state.openActionMenu = null;
                state.openColumnMenu = null;
                renderTable();
            });
        });

        document.getElementById('maintenance-prev-page')?.addEventListener('click', () => {
            state.page = Math.max(1, state.page - 1);
            state.openActionMenu = null;
            state.openColumnMenu = null;
            renderTable();
        });

        document.getElementById('maintenance-next-page')?.addEventListener('click', () => {
            state.page += 1;
            state.openActionMenu = null;
            state.openColumnMenu = null;
            renderTable();
        });

        document.getElementById('maintenance-reset-filters')?.addEventListener('click', () => {
            Object.assign(state, { search: '', priority: 'all', status: 'all', technician: 'all', scheduledDate: 'all', slaState: 'all', page: 1, openActionMenu: null, openColumnMenu: null });
            const search = document.getElementById('maintenance-search');
            if (search) search.value = '';
            updateResetVisibility();
            renderTable();
        });

        document.getElementById('new-work-order')?.addEventListener('click', () => window.FAMModal?.showToast('Work order workflow opened.'));
        document.getElementById('maintenance-export')?.addEventListener('click', () => window.FAMModal?.showToast('Maintenance export prepared.'));
        document.getElementById('maintenance-refresh')?.addEventListener('click', () => {
            state.loading = true;
            renderTable();
            window.setTimeout(() => {
                document.getElementById('maintenance-updated').textContent = formatUpdatedAt();
                state.loading = false;
                renderTable();
            }, 350);
        });

        document.querySelector('.maintenance-requests-table')?.addEventListener('click', event => {
            const columnToggle = event.target.closest('[data-column-menu-toggle]');
            if (columnToggle) {
                const willOpen = state.openColumnMenu !== columnToggle.dataset.columnMenuToggle;
                state.openColumnMenu = willOpen ? columnToggle.dataset.columnMenuToggle : null;
                state.openActionMenu = null;
                renderTable();
                if (willOpen) window.setTimeout(() => {
                    positionOpenColumnMenu();
                    document.querySelector('.maintenance-workspace .facility-column-dropdown:not(.hidden) [role="menuitem"]')?.focus();
                }, 0);
                return;
            }
            const columnSort = event.target.closest('[data-column-sort]');
            if (columnSort) {
                const columnKey = columnSort.dataset.columnSort;
                const config = columnMenuConfig[columnKey];
                state.sortKey = config.sortKey || columnKey;
                state.sortDirection = columnSort.dataset.sortDirection;
                state.openColumnMenu = null;
                renderTable();
                return;
            }
            const clearSort = event.target.closest('[data-column-clear-sort]');
            if (clearSort) {
                state.sortKey = 'scheduledDate';
                state.sortDirection = 'desc';
                state.openColumnMenu = null;
                renderTable();
                return;
            }
            const columnFilter = event.target.closest('[data-column-filter]');
            if (columnFilter) {
                const config = columnMenuConfig[columnFilter.dataset.columnFilter];
                state[config.filterKey] = columnFilter.dataset.filterValue;
                state.page = 1;
                state.openColumnMenu = null;
                updateResetVisibility();
                renderTable();
                return;
            }
            const clearFilter = event.target.closest('[data-column-clear-filter]');
            if (clearFilter) {
                const config = columnMenuConfig[clearFilter.dataset.columnClearFilter];
                state[config.filterKey] = 'all';
                state.page = 1;
                state.openColumnMenu = null;
                updateResetVisibility();
                renderTable();
                return;
            }
            const toggle = event.target.closest('[data-action-menu-toggle]');
            if (toggle) {
                const willOpen = state.openActionMenu !== toggle.dataset.actionMenuToggle;
                state.openActionMenu = willOpen ? toggle.dataset.actionMenuToggle : null;
                state.openColumnMenu = null;
                renderTable();
                if (willOpen) window.setTimeout(() => {
                    positionOpenActionMenu();
                    document.querySelector('.maintenance-workspace .facility-action-dropdown:not(.hidden) [role="menuitem"]')?.focus();
                }, 0);
                return;
            }
            const action = event.target.closest('[data-request-action]');
            if (!action) return;
            state.openActionMenu = null;
            renderTable();
            window.FAMModal?.showToast(`${action.dataset.requestAction} selected for ${action.dataset.requestNo}.`);
        });

        document.addEventListener('click', event => {
            if (!state.openActionMenu && !state.openColumnMenu) return;
            if (event.target.closest('.facility-action-menu') || event.target.closest('.facility-column-menu')) return;
            state.openActionMenu = null;
            state.openColumnMenu = null;
            renderTable();
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && (state.openActionMenu || state.openColumnMenu)) {
                state.openActionMenu = null;
                state.openColumnMenu = null;
                renderTable();
                return;
            }
            if ((!state.openActionMenu && !state.openColumnMenu) || !['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            const items = Array.from(document.querySelectorAll('.maintenance-workspace .facility-action-dropdown:not(.hidden) [role="menuitem"], .maintenance-workspace .facility-column-dropdown:not(.hidden) [role="menuitem"]'));
            if (!items.length) return;
            event.preventDefault();
            const index = items.indexOf(document.activeElement);
            const nextIndex = event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
            items[nextIndex].focus();
        });
    }

    function hasActiveFilters() {
        return Boolean(state.search.trim()) || state.priority !== 'all' || state.status !== 'all' || state.technician !== 'all' || state.scheduledDate !== 'all' || state.slaState !== 'all';
    }

    function updateResetVisibility() {
        document.getElementById('maintenance-reset-filters')?.classList.toggle('hidden', !hasActiveFilters());
    }

    function initializeMaintenance() {
        document.getElementById('maintenance-updated').textContent = formatUpdatedAt();
        renderSummary();
        bindEvents();
        renderTable();
        window.setTimeout(() => {
            state.loading = false;
            renderTable();
        }, 350);
    }

    document.addEventListener('fam:layout-ready', initializeMaintenance);
})();
