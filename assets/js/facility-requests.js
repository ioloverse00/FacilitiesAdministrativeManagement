(function () {
    const pageSize = 6;
    const priorityRank = { Critical: 4, High: 3, Medium: 2, Low: 1 };
    const statusRank = {
        Draft: 1,
        Submitted: 2,
        'Pending Approval': 3,
        Approved: 4,
        Assigned: 5,
        'In Progress': 6,
        Completed: 7,
        Verified: 8,
        Closed: 9,
        Cancelled: 10
    };

    const requests = [
        {
            requestNo: 'FR-2026-0148',
            subject: 'North wing air conditioning inspection',
            category: 'HVAC',
            location: 'North Wing / Floor 3',
            priority: 'High',
            status: 'Assigned',
            sla: 'Due in 2 hrs',
            slaState: 'due-soon',
            assignedTo: 'Maintenance Team',
            requestedBy: 'Maria Santos',
            department: 'Operations',
            submitted: '2026-07-24',
            ai: 'Suggested Team'
        },
        {
            requestNo: 'FR-2026-0147',
            subject: 'Conference room projector replacement',
            category: 'Equipment',
            location: 'Admin Building / Room 402',
            priority: 'Critical',
            status: 'Pending Approval',
            sla: 'Overdue',
            slaState: 'overdue',
            assignedTo: 'Unassigned',
            requestedBy: 'Daniel Cruz',
            department: 'Administration',
            submitted: '2026-07-24',
            ai: 'Suggested Priority'
        },
        {
            requestNo: 'FR-2026-0146',
            subject: 'Visitor lobby lighting repair',
            category: 'Electrical',
            location: 'Main Lobby',
            priority: 'Medium',
            status: 'In Progress',
            sla: 'On Track',
            slaState: 'on-track',
            assignedTo: 'Facilities Desk',
            requestedBy: 'Anne Lim',
            department: 'Public Assistance',
            submitted: '2026-07-23',
            ai: 'Suggested Category'
        },
        {
            requestNo: 'FR-2026-0145',
            subject: 'Records room access door adjustment',
            category: 'Carpentry',
            location: 'Records Center',
            priority: 'Low',
            status: 'Submitted',
            sla: 'On Track',
            slaState: 'on-track',
            assignedTo: 'Unassigned',
            requestedBy: 'Rafael Moreno',
            department: 'Records',
            submitted: '2026-07-22',
            ai: 'Suggested Team'
        },
        {
            requestNo: 'FR-2026-0144',
            subject: 'Water leak near pantry sink',
            category: 'Plumbing',
            location: 'Finance Pantry',
            priority: 'High',
            status: 'Approved',
            sla: 'Due in 6 hrs',
            slaState: 'due-soon',
            assignedTo: 'Facilities Desk',
            requestedBy: 'Leah Garcia',
            department: 'Finance',
            submitted: '2026-07-21',
            ai: 'Suggested Priority'
        },
        {
            requestNo: 'FR-2026-0143',
            subject: 'Archive shelving safety inspection',
            category: 'Safety',
            location: 'Archive Storage',
            priority: 'Medium',
            status: 'Verified',
            sla: 'On Track',
            slaState: 'on-track',
            assignedTo: 'Safety Officer',
            requestedBy: 'Noel Reyes',
            department: 'Records',
            submitted: '2026-07-19',
            ai: 'Suggested Category'
        },
        {
            requestNo: 'FR-2026-0142',
            subject: 'Parking barrier sensor calibration',
            category: 'Security',
            location: 'Basement Parking',
            priority: 'Medium',
            status: 'Assigned',
            sla: 'On Track',
            slaState: 'on-track',
            assignedTo: 'Security Facilities',
            requestedBy: 'Camille Tan',
            department: 'Security',
            submitted: '2026-07-18',
            ai: 'Suggested Team'
        },
        {
            requestNo: 'FR-2026-0141',
            subject: 'Executive office carpet cleaning',
            category: 'Housekeeping',
            location: 'Executive Office',
            priority: 'Low',
            status: 'Cancelled',
            sla: 'On Track',
            slaState: 'on-track',
            assignedTo: 'Housekeeping',
            requestedBy: 'Admin User',
            department: 'Administration',
            submitted: '2026-07-15',
            ai: ''
        }
    ];

    const state = {
        search: '',
        status: 'all',
        priority: 'all',
        category: 'all',
        department: 'all',
        assignedTo: 'all',
        dateRange: 'all',
        slaState: 'all',
        sortKey: 'submitted',
        sortDirection: 'desc',
        page: 1,
        loading: true,
        openActionMenu: null,
        openColumnMenu: null
    };

    const columnMenuConfig = {
        category: { label: 'Category', filterKey: 'category', options: () => uniqueOptions('category') },
        priority: { label: 'Priority', filterKey: 'priority', options: () => ['Critical', 'High', 'Medium', 'Low'] },
        status: { label: 'Status', filterKey: 'status', options: () => ['Draft', 'Submitted', 'Pending Approval', 'Approved', 'Assigned', 'In Progress', 'Completed', 'Verified', 'Closed', 'Cancelled'] },
        slaState: { label: 'SLA', filterKey: 'slaState', sortKey: 'sla', options: () => [{ value: 'on-track', label: 'On Track' }, { value: 'due-soon', label: 'Due Soon' }, { value: 'overdue', label: 'Overdue' }] },
        assignedTo: { label: 'Assigned To', filterKey: 'assignedTo', options: () => uniqueOptions('assignedTo') },
        submitted: { label: 'Submitted', filterKey: 'dateRange', sortKey: 'submitted', options: () => [{ value: 'today', label: 'Today' }, { value: '7', label: 'Last 7 Days' }, { value: '30', label: 'Last 30 Days' }, { value: 'custom', label: 'Custom Date Range' }] }
    };

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

    function formatUpdatedAt() {
        const now = new Date();
        return `Last updated: ${now.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })}, ${now.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    }

    function uniqueOptions(key) {
        return [...new Set(requests.map(item => item[key]))].sort();
    }

    function renderSummary() {
        const openStatuses = new Set(['Draft', 'Submitted', 'Pending Approval', 'Approved', 'Assigned', 'In Progress']);
        const openRequests = requests.filter(item => openStatuses.has(item.status)).length;
        const pendingAssignment = requests.filter(item => item.assignedTo === 'Unassigned' || item.status === 'Submitted').length;
        const highPriority = requests.filter(item => ['Critical', 'High'].includes(item.priority)).length;
        const overdue = requests.filter(item => item.slaState === 'overdue').length;
        const cards = [
            { label: 'Open Requests', value: openRequests, supporting: 'Active facility requests', status: 'On Track', icon: 'domain' },
            { label: 'Pending Assignment', value: pendingAssignment, supporting: 'Requests awaiting assignment', status: 'Needs Attention', icon: 'assignment_ind' },
            { label: 'High Priority', value: highPriority, supporting: 'Critical and high-priority requests', status: 'Priority Review', icon: 'priority_high' },
            { label: 'Overdue (SLA)', value: overdue, supporting: 'Requests past their target time', status: overdue ? 'Overdue' : 'On Track', icon: 'timer_off' }
        ];
        document.getElementById('facility-request-summary').innerHTML = cards.map(card => `
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
            Draft: ['View', 'Edit', 'Submit', 'Delete'],
            Submitted: ['View Details', 'Assign', 'Approve', 'Reject', 'Attach Document', 'View Timeline'],
            'Pending Approval': ['View Details', 'Assign', 'Approve', 'Reject', 'Attach Document', 'View Timeline'],
            Approved: ['View Details', 'Convert to Work Order', 'Assign Technician', 'Attach Document', 'View Timeline'],
            Assigned: ['View Details', 'Convert to Work Order', 'Update Assignment', 'Attach Document', 'View Timeline', 'Cancel Request'],
            'In Progress': ['View Details', 'Update Progress', 'Complete', 'Attach Document', 'View Timeline'],
            Completed: ['View Details', 'Verify', 'Close', 'View Timeline'],
            Verified: ['View Details', 'Close', 'View Timeline'],
            Closed: ['View Details'],
            Cancelled: ['View Details', 'View Timeline']
        };
        const actions = actionsByStatus[item.status] || ['View Details'];
        return actions;
    }

    function matchesDate(item) {
        if (state.dateRange === 'all') return true;
        const submitted = new Date(`${item.submitted}T00:00:00`);
        const now = new Date('2026-07-24T00:00:00');
        if (state.dateRange === 'today') return submitted.toDateString() === now.toDateString();
        if (state.dateRange === 'custom') return true;
        const days = Number(state.dateRange);
        const cutoff = new Date(now);
        cutoff.setDate(now.getDate() - days);
        return submitted >= cutoff;
    }

    function getFilteredRequests() {
        const search = state.search.trim().toLowerCase();
        return requests.filter(item => {
            const searchMatch = !search || item.requestNo.toLowerCase().includes(search) || item.subject.toLowerCase().includes(search);
            return searchMatch
                && (state.status === 'all' || item.status === state.status)
                && (state.priority === 'all' || item.priority === state.priority)
                && (state.category === 'all' || item.category === state.category)
                && (state.department === 'all' || item.department === state.department)
                && (state.assignedTo === 'all' || item.assignedTo === state.assignedTo)
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
                const slaRank = { overdue: 3, 'due-soon': 2, 'on-track': 1 };
                first = slaRank[a.slaState];
                second = slaRank[b.slaState];
            }
            if (state.sortKey === 'submitted') {
                first = new Date(a.submitted).getTime();
                second = new Date(b.submitted).getTime();
            }
            const result = first > second ? 1 : first < second ? -1 : 0;
            return state.sortDirection === 'asc' ? result : -result;
        });
    }

    function renderTable() {
        const loadingState = document.getElementById('facility-loading-state');
        const tableBody = document.getElementById('facility-requests-table');
        const emptyState = document.getElementById('facility-empty-state');
        const tableCount = document.getElementById('facility-table-count');
        const pagination = document.querySelector('.facility-pagination');

        if (state.loading) {
            loadingState.classList.remove('hidden');
            tableBody.innerHTML = '';
            emptyState.classList.add('hidden');
            pagination.classList.add('hidden');
            return;
        }

        loadingState.classList.add('hidden');
        const filtered = getFilteredRequests();
        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        state.page = Math.min(state.page, totalPages);
        const pageItems = filtered.slice((state.page - 1) * pageSize, state.page * pageSize);
        tableCount.textContent = filtered.length ? `${filtered.length} open request records` : 'No matching request records';

        if (!requests.length || !filtered.length) {
            tableBody.innerHTML = '';
            emptyState.classList.remove('hidden');
            emptyState.innerHTML = `
                <span class="material-symbols-outlined" aria-hidden="true">${requests.length ? 'search_off' : 'inbox'}</span>
                <strong>${requests.length ? 'No requests match the current filters.' : 'No facility request data available.'}</strong>
                <span>${requests.length ? 'Reset filters or adjust your search criteria.' : 'New facility requests will appear here.'}</span>
            `;
            pagination.classList.add('hidden');
            return;
        }

        emptyState.classList.add('hidden');
        pagination.classList.remove('hidden');
        document.getElementById('facility-page-status').textContent = `Page ${state.page} of ${totalPages}`;
        document.getElementById('facility-prev-page').disabled = state.page <= 1;
        document.getElementById('facility-next-page').disabled = state.page >= totalPages;
        renderColumnHeaders();

        tableBody.innerHTML = pageItems.map(item => `
            <tr data-request-no="${escapeHtml(item.requestNo)}">
                <td class="facility-request-number"><strong>${escapeHtml(item.requestNo)}</strong></td>
                <td>
                    <div class="facility-subject-cell">
                        <strong>${escapeHtml(item.subject)}</strong>
                        ${item.ai ? `<button class="facility-ai-indicator" type="button" title="AI recommendation available" aria-label="AI recommendation available for ${escapeHtml(item.requestNo)}">AI</button>` : ''}
                    </div>
                </td>
                <td>${escapeHtml(item.category)}</td>
                <td>${badge(item.priority, 'priority')}</td>
                <td>${badge(item.status, 'status')}</td>
                <td>${slaBadge(item)}</td>
                <td>${escapeHtml(item.assignedTo)}</td>
                <td class="facility-date-cell">${escapeHtml(item.submitted)}</td>
                <td class="facility-actions-cell">
                    <div class="facility-action-menu">
                        <button class="facility-action-toggle" type="button" aria-haspopup="menu" aria-expanded="${state.openActionMenu === item.requestNo}" aria-label="Actions for ${escapeHtml(item.requestNo)}" data-action-menu-toggle="${escapeHtml(item.requestNo)}">&#8942;</button>
                        <div class="facility-action-dropdown ${state.openActionMenu === item.requestNo ? '' : 'hidden'}" role="menu">
                            ${getActions(item).map(action => `<button type="button" role="menuitem" data-request-action="${escapeHtml(action)}" data-request-no="${escapeHtml(item.requestNo)}">${escapeHtml(action)}</button>`).join('')}
                        </div>
                    </div>
                </td>
            </tr>
        `).join('');
        positionOpenActionMenu();
    }

    function renderColumnHeaders() {
        Object.entries(columnMenuConfig).forEach(([columnKey, config]) => {
            const header = document.querySelector(`[data-column="${columnKey}"]`);
            if (!header) return;
            const filterValue = state[config.filterKey];
            const sortKey = config.sortKey || columnKey;
            const isSorted = state.sortKey === sortKey;
            const isFiltered = filterValue !== 'all';
            const menuId = `facility-column-menu-${columnKey}`;
            header.innerHTML = `
                <div class="facility-column-menu">
                    <button class="facility-column-trigger ${isSorted || isFiltered ? 'active' : ''}" type="button" aria-haspopup="menu" aria-expanded="${state.openColumnMenu === columnKey}" aria-controls="${menuId}" data-column-menu-toggle="${columnKey}">
                        <span>${escapeHtml(config.label)}</span>
                        ${isFiltered ? '<span class="facility-filter-dot" aria-label="Filtered"></span>' : ''}
                        <span class="facility-sort-indicator ${isSorted ? `facility-sort-${state.sortDirection}` : ''}" aria-hidden="true"></span>
                    </button>
                    <div id="${menuId}" class="facility-column-dropdown ${state.openColumnMenu === columnKey ? '' : 'hidden'}" role="menu" data-column-menu="${columnKey}">
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
        const customNote = columnKey === 'submitted' ? '<span class="facility-column-note">Custom range is reserved for the date picker workflow.</span>' : '';
        return `
            <div class="facility-column-menu-group">
                <span>Sort</span>
                <button type="button" role="menuitem" data-column-sort="${columnKey}" data-sort-direction="asc">${columnKey === 'submitted' ? 'Sort oldest first' : 'Sort Ascending'}</button>
                <button type="button" role="menuitem" data-column-sort="${columnKey}" data-sort-direction="desc">${columnKey === 'submitted' ? 'Sort newest first' : 'Sort Descending'}</button>
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
        const dropdown = document.querySelector('.facility-action-dropdown:not(.hidden)');
        const toggle = document.querySelector('[data-action-menu-toggle][aria-expanded="true"]');
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
        const dropdown = document.querySelector('.facility-column-dropdown:not(.hidden)');
        const trigger = document.querySelector('[data-column-menu-toggle][aria-expanded="true"]');
        if (!dropdown || !trigger) return;

        dropdown.classList.remove('facility-column-dropdown-left', 'facility-column-dropdown-up', 'facility-column-dropdown-down');
        dropdown.style.top = '';
        dropdown.style.left = '';

        const triggerRect = trigger.getBoundingClientRect();
        const dropdownRect = dropdown.getBoundingClientRect();
        const spaceBelow = window.innerHeight - triggerRect.bottom;
        const spaceAbove = triggerRect.top;
        const needsUpward = spaceBelow < dropdownRect.height + 16 && spaceAbove > spaceBelow;
        const top = needsUpward
            ? Math.max(16, triggerRect.top - dropdownRect.height - 8)
            : Math.min(window.innerHeight - dropdownRect.height - 16, triggerRect.bottom + 8);
        const left = Math.min(
            Math.max(16, triggerRect.left),
            window.innerWidth - dropdownRect.width - 16
        );

        dropdown.classList.toggle('facility-column-dropdown-down', !needsUpward);
        dropdown.classList.toggle('facility-column-dropdown-up', needsUpward);
        dropdown.style.top = `${top}px`;
        dropdown.style.left = `${left}px`;
    }

    function bindEvents() {
        document.getElementById('facility-search')?.addEventListener('input', event => {
            state.search = event.target.value;
            state.page = 1;
            state.openActionMenu = null;
            state.openColumnMenu = null;
            updateResetVisibility();
            renderTable();
        });

        document.querySelectorAll('[data-sort]').forEach(button => {
            button.addEventListener('click', () => {
                const key = button.dataset.sort;
                state.sortDirection = state.sortKey === key && state.sortDirection === 'asc' ? 'desc' : 'asc';
                state.sortKey = key;
                state.openActionMenu = null;
                state.openColumnMenu = null;
                renderTable();
            });
        });

        document.getElementById('facility-prev-page')?.addEventListener('click', () => {
            state.page = Math.max(1, state.page - 1);
            state.openActionMenu = null;
            state.openColumnMenu = null;
            renderTable();
        });

        document.getElementById('facility-next-page')?.addEventListener('click', () => {
            state.page += 1;
            state.openActionMenu = null;
            state.openColumnMenu = null;
            renderTable();
        });

        document.getElementById('facility-reset-filters')?.addEventListener('click', () => {
            Object.assign(state, { search: '', status: 'all', priority: 'all', category: 'all', department: 'all', assignedTo: 'all', dateRange: 'all', slaState: 'all', page: 1, openActionMenu: null, openColumnMenu: null });
            syncSearchField();
            updateResetVisibility();
            renderTable();
        });

        document.getElementById('new-facility-request')?.addEventListener('click', () => {
            window.FAMModal?.showToast('Facility request workflow opened.');
        });

        document.getElementById('facility-export')?.addEventListener('click', () => {
            window.FAMModal?.showToast('Facility request export prepared.');
        });

        document.getElementById('facility-refresh')?.addEventListener('click', () => {
            state.loading = true;
            renderTable();
            window.setTimeout(() => {
                document.getElementById('facility-requests-updated').textContent = formatUpdatedAt();
                state.loading = false;
                renderTable();
            }, 350);
        });

        document.querySelector('.facility-requests-table')?.addEventListener('click', event => {
            const columnToggle = event.target.closest('[data-column-menu-toggle]');
            if (columnToggle) {
                const willOpen = state.openColumnMenu !== columnToggle.dataset.columnMenuToggle;
                state.openColumnMenu = willOpen ? columnToggle.dataset.columnMenuToggle : null;
                state.openActionMenu = null;
                renderTable();
                if (willOpen) {
                    window.setTimeout(() => {
                        positionOpenColumnMenu();
                        document.querySelector('.facility-column-dropdown:not(.hidden) [role="menuitem"]')?.focus();
                    }, 0);
                }
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
                state.sortKey = 'submitted';
                state.sortDirection = 'desc';
                state.openColumnMenu = null;
                renderTable();
                return;
            }

            const columnFilter = event.target.closest('[data-column-filter]');
            if (columnFilter) {
                const columnKey = columnFilter.dataset.columnFilter;
                const config = columnMenuConfig[columnKey];
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
                if (willOpen) {
                    window.setTimeout(() => {
                        positionOpenActionMenu();
                        document.querySelector('.facility-action-dropdown:not(.hidden) [role="menuitem"]')?.focus();
                    }, 0);
                }
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
            const items = Array.from(document.querySelectorAll('.facility-action-dropdown:not(.hidden) [role="menuitem"], .facility-column-dropdown:not(.hidden) [role="menuitem"]'));
            if (!items.length) return;
            event.preventDefault();
            const index = items.indexOf(document.activeElement);
            const nextIndex = event.key === 'ArrowDown'
                ? (index + 1) % items.length
                : (index - 1 + items.length) % items.length;
            items[nextIndex].focus();
        });
    }

    function hasActiveFilters() {
        return Boolean(state.search.trim())
            || state.status !== 'all'
            || state.priority !== 'all'
            || state.category !== 'all'
            || state.department !== 'all'
            || state.assignedTo !== 'all'
            || state.dateRange !== 'all'
            || state.slaState !== 'all';
    }

    function updateResetVisibility() {
        document.getElementById('facility-reset-filters')?.classList.toggle('hidden', !hasActiveFilters());
    }

    function syncSearchField() {
        const search = document.getElementById('facility-search');
        if (search) search.value = state.search;
    }

    function initializeFacilityRequests() {
        document.getElementById('facility-requests-updated').textContent = formatUpdatedAt();
        renderSummary();
        bindEvents();
        renderTable();
        window.setTimeout(() => {
            state.loading = false;
            renderTable();
        }, 350);
    }

    document.addEventListener('fam:layout-ready', initializeFacilityRequests);
})();
