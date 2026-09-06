(function () {
    const categories = [
        ['general', 'General', 'settings'],
        ['organization', 'Organization & Facilities', 'apartment'],
        ['users', 'Users & Roles', 'admin_panel_settings'],
        ['workflow', 'Workflow & Approvals', 'account_tree'],
        ['sla', 'SLA Policies', 'timer'],
        ['reference', 'Reference Data', 'list_alt'],
        ['notifications', 'Notifications', 'notifications'],
        ['ai', 'AI Services', 'psychology'],
        ['integrations', 'Integrations', 'sync_alt'],
        ['audit', 'Audit Logs', 'manage_search'],
        ['system', 'System Information', 'info']
    ];

    const state = { active: 'general', dirty: false, ref: 'Facility Request Categories', userTab: 'users', selectedRole: 'FAM Super Administrator', auditSearch: '', auditResult: 'all', organizationTab: 'Departments' };
    const editableSections = new Set(['general', 'users', 'workflow', 'sla', 'reference', 'notifications', 'ai', 'integrations']);
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

    const sampleRows = {
        Departments: [['Facilities', 'DEP-FAC', 'Active'], ['Administration', 'DEP-ADM', 'Active'], ['Records', 'DEP-REC', 'Active']],
        Buildings: [['Main Building', 'BLD-MAIN', 'Active'], ['North Wing', 'BLD-NW', 'Active'], ['Utility Annex', 'BLD-UA', 'Active']],
        Floors: [['Ground Floor', 'FLR-G', 'Active', 'Main Building'], ['Second Floor', 'FLR-02', 'Active', 'Main Building'], ['Archive Level', 'FLR-AR', 'Inactive', 'Utility Annex']],
        'Facility Spaces': [['Conference Room A', 'RM-401', 'Active', 'Main Building', 'Ground Floor', 'Conference Room'], ['Generator Room', 'UT-GEN', 'Active', 'Utility Annex', 'Ground Floor', 'Technical Room'], ['Archive Storage', 'AR-STO', 'Active', 'North Wing', 'Archive Level', 'Technical Room']],
        'Room Types': [['Conference Room', 'RT-CONF', 'Active'], ['Training Room', 'RT-TRN', 'Active'], ['Technical Room', 'RT-TECH', 'Active']],
        'Facility Amenities': [['Projector', 'AM-PRJ', 'Active'], ['Video Conference', 'AM-VC', 'Active'], ['Whiteboard', 'AM-WB', 'Active']]
    };
    const organizationLabels = { Departments: 'Department', Buildings: 'Building', Floors: 'Floor', 'Facility Spaces': 'Facility Space', 'Room Types': 'Room Type', 'Facility Amenities': 'Amenity' };
    const organizationTabs = ['Departments', 'Buildings', 'Floors', 'Facility Spaces', 'Room Types', 'Facility Amenities'];
    const organizationTabLabels = { 'Facility Amenities': 'Amenities' };
    const organizationDescriptions = {
        Departments: 'Maintain department records used across facility requests and administration.',
        Buildings: 'Maintain facility building records and building codes.',
        Floors: 'Maintain floor records and their assigned buildings.',
        'Facility Spaces': 'Maintain spaces, room locations, and space types.',
        'Room Types': 'Maintain the space type list used for rooms and reservations.',
        'Facility Amenities': 'Maintain amenities that can be assigned to facility spaces.'
    };
    const orgSearches = Object.fromEntries(organizationTabs.map(tab => [tab, '']));
    const orgModal = { open: false, mode: 'add', step: 'form', section: '', search: '', status: 'all', selected: '', dirty: false, values: {}, errors: {} };

    const users = [
        ['Mara Ibarra', 'EMP-2026-0001', 'Information Technology', 'FAM Super Administrator', 'Active', 'Jul 26, 2026 9:12 AM'],
        ['Maria Santos', 'EMP-0142', 'Operations', 'FAM Department Head', 'Active', 'Jul 25, 2026 4:40 PM'],
        ['Ramon Villanueva', 'EMP-0188', 'Facilities', 'FAM Staff', 'Active', 'Jul 24, 2026 3:02 PM'],
        ['Liza Mendoza', 'EMP-0205', 'Training', 'Employee', 'Suspended', 'Jul 19, 2026 1:15 PM']
    ];
    const roles = ['FAM Super Administrator', 'FAM Department Head', 'FAM Staff', 'Department Head', 'Employee'];
    // Centralized Reports & Analytics is temporarily deferred; keep underlying permissions/data intact.
    const modules = ['Dashboard', 'Facilities Reservation', 'Visitor Management', 'Document Management', 'Records Retention & Compliance', 'Administration'];
    const perms = ['View', 'Create', 'Edit', 'Assign', 'Approve', 'Complete', 'Verify', 'Export', 'Manage', 'Delete'];

    function formatUpdatedAt() {
        const now = new Date();
        return `Last updated: ${now.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })}, ${now.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    }

    function badge(value) {
        return `<span class="facility-badge facility-status-${String(value).toLowerCase().replace(/\s+/g, '-')}">${escapeHtml(value)}</span>`;
    }

    function field(label, value, type = 'text') {
        const id = `admin-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
        return `<label class="facility-field"><span>${escapeHtml(label)}</span><input id="${id}" type="${type}" value="${escapeHtml(value)}" data-admin-input></label>`;
    }

    function selectField(label, value, options) {
        const id = `admin-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
        return `<label class="facility-field"><span>${escapeHtml(label)}</span><select id="${id}" data-admin-input>${options.map(option => `<option ${option === value ? 'selected' : ''}>${escapeHtml(option)}</option>`).join('')}</select></label>`;
    }

    function toggle(label, checked = true) {
        return `<label class="admin-toggle"><input type="checkbox" ${checked ? 'checked' : ''} data-admin-input><span>${escapeHtml(label)}</span></label>`;
    }

    function sectionHeader(title, description) {
        return `<div class="admin-panel-heading"><h2>${escapeHtml(title)}</h2><p>${escapeHtml(description)}</p></div>`;
    }

    function sectionGroup(title, content) {
        return `<section class="admin-form-section"><h3>${escapeHtml(title)}</h3>${content}</section>`;
    }

    function compactTable(headers, rows, options = {}) {
        const actions = options.actions !== false;
        return `<div class="facility-table-scroll ${actions ? '' : 'admin-reference-table-scroll'}"><table class="facility-requests-table admin-compact-table ${actions ? '' : 'admin-reference-table'}"><thead><tr>${headers.map(h => `<th>${escapeHtml(h)}</th>`).join('')}${actions ? '<th>Actions</th>' : ''}</tr></thead><tbody>${rows.map(row => `<tr>${row.map(cell => `<td>${cell === 'Active' || cell === 'Inactive' || cell === 'Suspended' ? badge(cell) : escapeHtml(cell)}</td>`).join('')}${actions ? '<td><button class="facility-action-toggle" type="button" data-admin-row-action aria-label="Row actions">⋮</button></td>' : ''}</tr>`).join('')}</tbody></table></div>`;
    }

    function orgRecordCells(section, row) {
        if (section === 'Floors') return [row[0], row[1], row[3] || 'Unassigned', row[2]];
        if (section === 'Facility Spaces') return [row[0], row[1], [row[3], row[4]].filter(Boolean).join(' / ') || 'Unassigned', row[5] || 'Unspecified', row[2]];
        return [row[0], row[1], row[2]];
    }

    function orgColumns(section) {
        if (section === 'Floors') return ['Name', 'Code', 'Building', 'Status'];
        if (section === 'Facility Spaces') return ['Name', 'Code', 'Building / Floor', 'Type', 'Status'];
        return ['Name', 'Code', 'Status'];
    }

    function organizationWorkspace() {
        const section = state.organizationTab;
        const title = organizationTabLabels[section] || section;
        const singular = organizationLabels[section] || 'Record';
        const rows = sampleRows[section] || [];
        const query = (orgSearches[section] || '').trim().toLowerCase();
        const filteredRows = rows.filter(row => !query || orgRecordCells(section, row).some(cell => String(cell).toLowerCase().includes(query)));
        const countLabel = `${filteredRows.length} ${singular.toLowerCase()} record${filteredRows.length === 1 ? '' : 's'}`;
        const emptyTitle = query ? `No matching ${title.toLowerCase()}.` : `No ${title.toLowerCase()} found.`;
        const emptyAction = query ? '<button class="facility-text-button" type="button" data-org-clear-search>Clear Search</button>' : `<button class="btn-primary dashboard-action-button" type="button" data-org-modal="add" data-org-section="${escapeHtml(section)}">Add ${escapeHtml(singular)}</button>`;
        return `<div class="reports-tabs admin-organization-tabs" role="tablist" aria-label="Organization and facilities reference types">
                ${organizationTabs.map(tab => {
                    const active = state.organizationTab === tab;
                    return `<button id="org-tab-${tab.toLowerCase().replace(/[^a-z0-9]+/g, '-')}" class="${active ? 'active' : ''}" type="button" role="tab" aria-selected="${active ? 'true' : 'false'}" aria-controls="org-workspace-panel" tabindex="${active ? '0' : '-1'}" data-org-tab="${escapeHtml(tab)}">${escapeHtml(organizationTabLabels[tab] || tab)}</button>`;
                }).join('')}
            </div>
            <article id="org-workspace-panel" class="fam-card admin-organization-workspace" role="tabpanel" aria-labelledby="org-tab-${section.toLowerCase().replace(/[^a-z0-9]+/g, '-')}">
                <div class="facility-table-header admin-organization-header">
                    <div>
                        <h3>${escapeHtml(title)}</h3>
                        <p>${escapeHtml(organizationDescriptions[section])}</p>
                        <span>${escapeHtml(countLabel)}</span>
                    </div>
                    <div class="admin-organization-controls">
                        <label class="facility-field facility-search-field" for="org-active-search"><span class="sr-only">Search ${escapeHtml(title)}</span><input id="org-active-search" value="${escapeHtml(orgSearches[section] || '')}" placeholder="Search ${escapeHtml(title.toLowerCase())}..." data-org-card-search="${escapeHtml(section)}"></label>
                        <button class="facility-text-button admin-reference-edit" type="button" data-org-modal="edit" data-org-section="${escapeHtml(section)}" aria-label="Edit ${escapeHtml(title)} records">Edit</button>
                        <button class="btn-primary dashboard-action-button admin-reference-add" type="button" data-org-modal="add" data-org-section="${escapeHtml(section)}" aria-label="Add ${escapeHtml(singular)}">Add ${escapeHtml(singular)}</button>
                    </div>
                </div>
                ${filteredRows.length ? compactTable(orgColumns(section), filteredRows.map(row => orgRecordCells(section, row)), { actions: false }) : `<div class="admin-reference-empty admin-organization-empty"><strong>${escapeHtml(emptyTitle)}</strong>${emptyAction}</div>`}
            </article>`;
    }

    function recordObject(row) {
        return { name: row[0], code: row[1], status: row[2], building: row[3] || '', floor: row[4] || '', type: row[5] || '', description: `${row[0]} reference record` };
    }

    function orgFields(section, values = {}) {
        const fieldMap = {
            Departments: [['Department Name', 'name'], ['Department Code', 'code'], ['Status', 'status']],
            Buildings: [['Building Name', 'name'], ['Building Code', 'code'], ['Address', 'address'], ['Description', 'description'], ['Status', 'status']],
            Floors: [['Building', 'building'], ['Floor Name or Number', 'name'], ['Code', 'code'], ['Status', 'status']],
            'Facility Spaces': [['Building', 'building'], ['Floor', 'floor'], ['Space Name', 'name'], ['Space Code', 'code'], ['Space Type', 'type'], ['Capacity', 'capacity'], ['Reservable', 'reservable'], ['Status', 'status']],
            'Room Types': [['Name', 'name'], ['Code', 'code'], ['Description', 'description'], ['Status', 'status']],
            'Facility Amenities': [['Name', 'name'], ['Code', 'code'], ['Description', 'description'], ['Status', 'status']]
        };
        return `<div class="admin-org-form-grid">${(fieldMap[section] || fieldMap.Departments).map(([label, key]) => {
            const id = `org-${key}`;
            const value = values[key] ?? (key === 'status' ? 'Active' : key === 'reservable' ? 'Yes' : '');
            const error = orgModal.errors[key] ? `<small class="admin-org-error" id="${id}-error">${escapeHtml(orgModal.errors[key])}</small>` : '';
            if (key === 'status') return `<label class="facility-field" for="${id}"><span>${escapeHtml(label)}</span><select id="${id}" data-org-field="${key}" aria-invalid="${Boolean(orgModal.errors[key])}" aria-describedby="${id}-error"><option ${value === 'Active' ? 'selected' : ''}>Active</option><option ${value === 'Inactive' ? 'selected' : ''}>Inactive</option><option ${value === 'Archived' ? 'selected' : ''}>Archived</option></select>${error}</label>`;
            if (key === 'reservable') return `<label class="facility-field" for="${id}"><span>${escapeHtml(label)}</span><select id="${id}" data-org-field="${key}" aria-invalid="${Boolean(orgModal.errors[key])}" aria-describedby="${id}-error"><option ${value === 'Yes' ? 'selected' : ''}>Yes</option><option ${value === 'No' ? 'selected' : ''}>No</option></select>${error}</label>`;
            return `<label class="facility-field" for="${id}"><span>${escapeHtml(label)}</span><input id="${id}" value="${escapeHtml(value)}" data-org-field="${key}" aria-invalid="${Boolean(orgModal.errors[key])}" aria-describedby="${id}-error" ${key === 'capacity' ? 'inputmode="numeric"' : ''}>${error}</label>`;
        }).join('')}</div>`;
    }

    function validateOrgValues() {
        const rows = sampleRows[orgModal.section] || [];
        const name = orgModal.values.name?.trim() || '';
        const code = orgModal.values.code?.trim() || '';
        const errors = {};
        if (!name) errors.name = 'Name is required.';
        if (!code) errors.code = 'Code is required.';
        if (name && !/^[a-z0-9 .&'/-]+$/i.test(name)) errors.name = 'Use letters, numbers, spaces, and basic punctuation only.';
        if (code && !/^[a-z0-9-]+$/i.test(code)) errors.code = 'Use letters, numbers, and hyphens only.';
        rows.forEach(row => {
            if (orgModal.mode === 'edit' && row[1] === orgModal.selected) return;
            if (name && row[0].toLowerCase() === name.toLowerCase()) errors.name = 'A record with this name already exists.';
            if (code && row[1].toLowerCase() === code.toLowerCase()) errors.code = 'A record with this code already exists.';
        });
        orgModal.errors = errors;
        return Object.keys(errors).length === 0;
    }

    function renderOrgModal() {
        if (!orgModal.open) return '';
        const section = orgModal.section;
        const singular = organizationLabels[section] || 'Record';
        const rows = sampleRows[section] || [];
        const selectedRow = rows.find(row => row[1] === orgModal.selected);
        const selectedValues = selectedRow ? { ...recordObject(selectedRow), ...orgModal.values } : orgModal.values;
        const query = orgModal.search.toLowerCase();
        const filtered = rows.filter(row => {
            const matchesText = !query || row[0].toLowerCase().includes(query) || row[1].toLowerCase().includes(query);
            const matchesStatus = orgModal.status === 'all' || row[2] === orgModal.status;
            return matchesText && matchesStatus;
        });
        const formVisible = orgModal.mode === 'add' || (selectedRow && orgModal.step === 'form');
        const canSave = canSaveOrgModal(selectedRow);

        return `<div class="admin-org-drawer-backdrop" data-org-modal-backdrop aria-hidden="false">
            <aside class="admin-org-drawer" role="dialog" aria-modal="true" aria-labelledby="admin-org-modal-title" aria-describedby="admin-org-modal-desc">
                <header>
                    <div class="admin-org-drawer-title">
                        ${orgModal.mode === 'edit' && orgModal.step === 'form' ? '<button class="facility-text-button" type="button" data-org-back>Back</button>' : ''}
                        <div><h2 id="admin-org-modal-title">${orgModal.mode === 'add' ? `Add ${singular}` : orgModal.step === 'select' ? `Select ${singular}` : `Edit ${singular}`}</h2><p id="admin-org-modal-desc">${orgModal.mode === 'add' ? `Create a new ${singular.toLowerCase()} reference record.` : orgModal.step === 'select' ? `Search and select one ${singular.toLowerCase()} record to edit.` : `Update this ${singular.toLowerCase()} reference record.`}</p></div>
                    </div>
                    <button class="fam-icon-button" type="button" data-org-modal-close aria-label="Close drawer"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
                </header>
                <div class="admin-org-drawer-body">
                    ${orgModal.mode === 'edit' && orgModal.step === 'select' ? `<section class="admin-org-selector">
                        <label class="facility-field" for="org-record-search"><span>Select ${escapeHtml(singular)}</span><input id="org-record-search" value="${escapeHtml(orgModal.search)}" placeholder="Search ${escapeHtml(section.toLowerCase())}..." data-org-search></label>
                        <label class="facility-field" for="org-status-filter"><span>Status</span><select id="org-status-filter" data-org-status-filter><option value="all" ${orgModal.status === 'all' ? 'selected' : ''}>All statuses</option><option ${orgModal.status === 'Active' ? 'selected' : ''}>Active</option><option ${orgModal.status === 'Inactive' ? 'selected' : ''}>Inactive</option></select></label>
                        <div class="admin-org-record-list" role="listbox" aria-label="${escapeHtml(singular)} records">${filtered.map(row => `<button type="button" class="${orgModal.selected === row[1] ? 'selected' : ''}" data-org-select-record="${escapeHtml(row[1])}" role="option" aria-selected="${orgModal.selected === row[1] ? 'true' : 'false'}"><span>${escapeHtml(row[0])}</span><small>${escapeHtml(row[1])} - ${escapeHtml(row[2])}</small></button>`).join('') || '<p>No matching records.</p>'}</div>
                    </section>` : ''}
                    <form class="admin-org-edit-form" data-org-form>
                        ${formVisible ? `<div id="admin-org-unsaved" class="admin-unsaved ${orgModal.dirty ? '' : 'hidden'}"><span aria-hidden="true"></span>Unsaved changes</div>${orgFields(section, selectedValues)}${orgModal.mode === 'edit' ? '<div class="admin-org-metadata"><h3>Metadata</h3><dl><dt>Created By</dt><dd>Mara Ibarra</dd><dt>Created Date</dt><dd>Jul 24, 2026</dd><dt>Last Updated</dt><dd>Jul 26, 2026</dd></dl></div>' : ''}` : ''}
                        ${orgModal.mode === 'edit' && selectedRow && orgModal.step === 'form' ? '<div class="admin-org-danger"><div><h3>Danger Zone</h3><p>This record cannot be deleted because it is currently referenced by operational data.</p></div><button class="facility-text-button" type="button" data-org-deactivate>Deactivate</button><button class="facility-text-button" type="button" disabled>Delete Permanently</button></div>' : ''}
                    </form>
                </div>
                <footer><button class="facility-text-button" type="button" data-org-modal-close>Cancel</button><button class="btn-primary dashboard-action-button" type="button" data-org-save ${canSave ? '' : 'disabled'}>${orgModal.mode === 'add' ? 'Create' : 'Save Changes'}</button></footer>
            </aside>
        </div>`;
    }

    const panels = {
        general() {
            return `${sectionHeader('General Settings', 'Configure basic operational preferences for the FAM subsystem.')}
                ${sectionGroup('General Information', `<div class="admin-form-grid">${field('System Display Name', 'Facilities & Administrative Management')}${field('Organization Name', 'Great Solomon Manpower Services Inc.')}${field('Organization Logo', 'Logo placeholder')}</div>`)}
                ${sectionGroup('Timezone & Localization', `<div class="admin-form-grid">${selectField('Default Timezone', 'Asia/Manila', ['Asia/Manila', 'UTC', 'Asia/Singapore'])}${selectField('Default Language', 'English', ['English', 'Filipino'])}${selectField('Date Format', 'MMM DD, YYYY', ['MMM DD, YYYY', 'YYYY-MM-DD', 'DD/MM/YYYY'])}${selectField('Time Format', '12-hour', ['12-hour', '24-hour'])}${selectField('Currency', 'PHP', ['PHP', 'USD'])}${selectField('Fiscal Year Start', 'January', ['January', 'April', 'July', 'October'])}</div>`)}
                ${sectionGroup('Business Hours', `<div class="admin-form-grid">${field('Working Days', 'Monday to Friday')}${field('Business Hours Start', '8:00 AM')}${field('Business Hours End', '5:00 PM')}</div>`)}
                ${sectionGroup('System Defaults', `<div class="admin-form-grid">${selectField('Default Landing Page', 'Dashboard', ['Dashboard'])}</div>`)}`;
        },
        organization() {
            return `${sectionHeader('Organization & Facilities', 'Manage operational reference lists for departments, buildings, spaces, room types, and amenities.')}
                ${organizationWorkspace()}`;
        },
        users() {
            return `${sectionHeader('Users & Roles', 'Manage user access and role permissions for FAM operations.')}
                <div class="reports-tabs admin-tabs" role="tablist"><button class="${state.userTab === 'users' ? 'active' : ''}" data-admin-tab="users">Users</button><button class="${state.userTab === 'roles' ? 'active' : ''}" data-admin-tab="roles">Roles & Permissions</button></div>
                ${state.userTab === 'users' ? compactTable(['User', 'Employee Number', 'Department', 'Role', 'Account Status', 'Last Login'], users) : renderRoles()}`;
        },
        workflow() {
            const flows = [['Facility Requests', ['Requester', 'Department Approver', 'Facility Manager', 'Approved']], ['Maintenance Completion Verification', ['Technician', 'Maintenance Supervisor', 'Facility Manager', 'Verified']], ['Room Reservations', ['Requester', 'Reservation Officer', 'Approver', 'Approved']], ['Procurement Requests', ['Requester', 'Department Approver', 'FAM Administrator', 'Supply Chain Forwarding']], ['Records Disposition', ['Records Officer', 'Department Head', 'Auditor', 'Archived']]];
            return `${sectionHeader('Workflow & Approvals', 'Configure approval routing for operational transactions.')}<div class="admin-workflow-grid">${flows.map(flow => `<article class="fam-card admin-workflow-card"><h3>${flow[0]}</h3><div>${flow[1].map(step => `<span>${escapeHtml(step)}</span>`).join('<b>→</b>')}</div><footer><button class="facility-text-button" data-admin-hook>Edit Workflow</button><button class="facility-text-button" data-admin-hook>Preview</button></footer></article>`).join('')}</div>`;
        },
        sla() {
            const rows = [['Critical Electrical Issue', 'Electrical', 'Critical', '15 min', '30 min', '4 hrs', 'Manager alert', 'Active'], ['High Plumbing Issue', 'Plumbing', 'High', '30 min', '1 hr', '8 hrs', 'Supervisor alert', 'Active'], ['Standard Facility Request', 'General', 'Medium', '2 hrs', '4 hrs', '2 days', 'Daily digest', 'Active']];
            return `${sectionHeader('SLA Policies', 'Configure target response and completion times for facility requests.')}<button class="facility-text-button admin-add-button" data-admin-hook>Add Policy</button>${compactTable(['Policy', 'Request Category', 'Priority', 'Acknowledgement Target', 'Assignment Target', 'Resolution Target', 'Escalation', 'Status'], rows)}`;
        },
        reference() {
            const refs = ['Facility Request Categories', 'Asset Categories', 'Maintenance Types', 'Reservation Types', 'Document Categories', 'Contract Types', 'Priority Levels', 'Status Definitions'];
            const rows = [['ELEC', 'Electrical', 'Electrical repairs and inspections', 'Active'], ['HVAC', 'HVAC', 'Air conditioning and ventilation', 'Active'], ['GEN', 'General', 'General administrative option', 'Active']];
            return `${sectionHeader('Reference Data', 'Maintain compact operational lookup values used across FAM modules.')}<div class="reports-tabs">${refs.map(ref => `<button class="${state.ref === ref ? 'active' : ''}" data-ref-tab="${escapeHtml(ref)}">${escapeHtml(ref)}</button>`).join('')}</div>${compactTable(['Code', 'Name', 'Description', 'Status'], rows)}`;
        },
        notifications() {
            const groups = { 'Facility Requests': ['New Request Submitted', 'Request Assigned', 'Approval Required', 'SLA Near Due', 'SLA Breached', 'Request Completed'], Maintenance: ['Work Order Assigned', 'Schedule Changed', 'Work Order Overdue', 'Completion Pending Verification'], Reservations: ['Approval Required', 'Reservation Approved', 'Reservation Reminder', 'Schedule Conflict Detected'], Procurement: ['Approval Required', 'Sent to Supply Chain', 'Integration Failed', 'Request Fulfilled'], Records: ['Review Date Approaching', 'Record Expiring', 'Disposition Pending'] };
            return `${sectionHeader('Notification Rules', 'Configure event notifications and delivery channels.')}<div class="admin-management-grid">${Object.entries(groups).map(([group, events]) => `<article class="fam-card admin-mini-section"><h3>${group}</h3>${events.map(event => `<div class="admin-notification-row"><strong>${event}</strong>${toggle('In-App', true)}${toggle('Email', true)}${toggle('SMS placeholder', false)}<select data-admin-input><option>Immediate</option><option>1 hour before</option><option>Daily digest</option></select></div>`).join('')}</article>`).join('')}</div>`;
        },
        ai() {
            return `${sectionHeader('AI Services', 'Configure the external AI service used for advisory automation and operational insights.')}
                <article class="fam-card admin-ai-summary"><div>${badge('Connected')}<h3>OpenAI-compatible service</h3><p>Model: fam-advisory-model · Last successful request: Jul 26, 2026 8:50 AM</p></div><div><strong>128</strong><span>Requests today</span></div><div><strong>2</strong><span>Failed requests</span></div><div><strong>1.2s</strong><span>Avg response</span></div><button class="facility-text-button" data-admin-hook>Test Connection</button></article>
                <div class="admin-form-grid">${field('AI Provider', 'OpenAI-compatible Provider')}${field('Model Name', 'fam-advisory-model')}${field('API Base URL', 'https://api.example.com/v1')}${field('API Key', '••••••••••••••••')}${field('Connection Timeout', '30 seconds')}${field('Maximum Retry Count', '3')}${field('Daily Request Limit', '500')}${field('Confidence Threshold', '70%')}</div>
                <p class="admin-advisory">Credentials are stored securely on the server. AI suggestions do not automatically approve, assign, close, or modify official records without authorized user review.</p>
                <div class="admin-management-grid">${['Facility Request Classification', 'Maintenance Summaries', 'Document Metadata Extraction', 'Operational Insights', 'Asset Maintenance Recommendations'].map(title => `<article class="fam-card admin-mini-section"><h3>${title}</h3>${toggle('Enabled', true)}${toggle('Require Human Review', true)}${toggle('Store Recommendation History', true)}</article>`).join('')}</div>`;
        },
        integrations() {
            const systems = [['Human Resources Information System', 'Connected', 'Reference sync'], ['Financial Management System', 'Degraded', 'Budget reference sync'], ['Supply Chain and Inventory', 'Synchronizing', 'Request handoff'], ['Fleet and Transportation', 'Pending Setup', 'Vehicle reference sync'], ['Business Intelligence', 'Disabled', 'Report export hook']];
            return `${sectionHeader('Subsystem Integrations', 'Monitor local reference data synchronized from owning subsystems.')}<div class="admin-integration-grid">${systems.map(system => `<article class="fam-card admin-integration-card"><h3>${system[0]}</h3>${badge(system[1])}<p>${system[2]}</p><dl><dt>Last synchronization</dt><dd>Jul 26, 2026 8:30 AM</dd><dt>Records processed</dt><dd>248</dd><dt>Failed records</dt><dd>${system[1] === 'Degraded' ? 3 : 0}</dd></dl><footer><button class="facility-text-button" data-admin-hook>Sync Now</button><button class="facility-text-button" data-admin-hook>View Sync History</button></footer></article>`).join('')}</div>`;
        },
        audit() {
            const rows = [['Mara Ibarra', 'Updated SLA policy', 'SLA Policies', 'SLA-CRIT-ELEC', '2026-07-26 09:20', 'Success'], ['Maria Santos', 'Viewed role matrix', 'Users & Roles', 'Facility Manager', '2026-07-26 08:45', 'Success'], ['Unknown User', 'Attempted admin access', 'Administration', 'Settings', '2026-07-25 18:02', 'Denied'], ['Mara Ibarra', 'Tested AI connection', 'AI Services', 'AI Provider', '2026-07-25 15:12', 'Failed']];
            const filtered = rows.filter(row => (!state.auditSearch || row.join(' ').toLowerCase().includes(state.auditSearch.toLowerCase())) && (state.auditResult === 'all' || row[5] === state.auditResult));
            return `${sectionHeader('Audit Logs', 'Review administrative activity and protected configuration events.')}<div class="facility-table-header admin-inner-header"><div><h3>Audit Logs</h3><p>${filtered.length} audit records</p></div><label class="facility-field facility-search-field"><span class="sr-only">Search audit logs</span><input id="audit-search" value="${escapeHtml(state.auditSearch)}" placeholder="Search audit logs..."></label><select id="audit-result-filter"><option value="all">All Results</option>${['Success', 'Failed', 'Denied'].map(result => `<option ${state.auditResult === result ? 'selected' : ''}>${result}</option>`).join('')}</select></div>${compactTable(['User', 'Action', 'Module', 'Record', 'Timestamp', 'Result'], filtered)}`;
        },
        system() {
            const info = [['Application Version', 'FAM UI v2'], ['Build Version', '2026.07.26'], ['Environment', 'Application workspace'], ['Database Connection', 'Available'], ['AI Service Status', 'Connected'], ['External Integration Health', 'Degraded'], ['Last Data Synchronization', 'Jul 26, 2026 8:30 AM'], ['Storage Usage', 'Within configured limit'], ['Current Server Time', new Date().toLocaleString()], ['Current Timezone', 'Asia/Manila']];
            return `${sectionHeader('System Information', 'Read-only subsystem health and version information.')}<div class="admin-info-grid">${info.map(item => `<article class="fam-card admin-info-card"><span>${escapeHtml(item[0])}</span><strong>${escapeHtml(item[1])}</strong></article>`).join('')}</div>`;
        }
    };

    function renderRoles() {
        return `<div class="admin-role-layout"><div class="admin-role-list">${roles.map(role => `<button class="${state.selectedRole === role ? 'active' : ''}" data-role-select="${escapeHtml(role)}">${escapeHtml(role)}</button>`).join('')}</div><div class="facility-table-scroll"><table class="admin-permission-table"><thead><tr><th>Module</th>${perms.map(permission => `<th>${permission}</th>`).join('')}</tr></thead><tbody>${modules.map((module, index) => `<tr><td>${module}</td>${perms.map((permission, pIndex) => `<td><input type="checkbox" ${state.selectedRole === 'FAM Super Administrator' || (index + pIndex) % 3 === 0 ? 'checked' : ''} data-admin-input aria-label="${permission} ${module}"></td>`).join('')}</tr>`).join('')}</tbody></table></div></div>`;
    }

    function panelActions(label = 'Save Changes') {
        return `<div class="admin-panel-actions">
            <div id="admin-unsaved" class="admin-unsaved ${state.dirty ? '' : 'hidden'}" role="status"><span aria-hidden="true"></span>Unsaved changes</div>
            <div class="admin-panel-action-buttons">
                <button class="facility-text-button admin-reset-button" type="button" data-admin-reset ${state.dirty ? '' : 'disabled'}>Reset Changes</button>
                <button class="btn-primary dashboard-action-button admin-save-button" type="button" data-admin-save ${state.dirty ? '' : 'disabled'}>${label}</button>
            </div>
        </div>`;
    }

    function renderNavigation() {
        document.getElementById('admin-category-nav').innerHTML = categories.map(item => `
            <button class="${state.active === item[0] ? 'active' : ''}" type="button" data-admin-category="${item[0]}" aria-current="${state.active === item[0] ? 'page' : 'false'}">
                <span class="material-symbols-outlined" aria-hidden="true">${item[2]}</span>
                <span>${escapeHtml(item[1])}</span>
            </button>
        `).join('');
        document.getElementById('admin-category-select').innerHTML = categories.map(item => `<option value="${item[0]}" ${state.active === item[0] ? 'selected' : ''}>${escapeHtml(item[1])}</option>`).join('');
    }

    function renderPanel() {
        const card = document.getElementById('admin-panel-card');
        const isEditable = editableSections.has(state.active);
        card.innerHTML = `
            <div id="admin-panel" class="admin-panel-content" aria-live="polite">${panels[state.active]()}</div>
            ${isEditable ? panelActions(state.active === 'ai' ? 'Save AI Settings' : 'Save Changes') : ''}
            ${state.active === 'organization' ? renderOrgModal() : ''}
        `;
        updateActionState();
    }

    function setDirty(value) {
        state.dirty = value;
        updateActionState();
    }

    function updateActionState() {
        document.getElementById('admin-unsaved')?.classList.toggle('hidden', !state.dirty);
        document.querySelectorAll('[data-admin-save], [data-admin-reset]').forEach(button => {
            button.disabled = !state.dirty;
        });
    }

    function saveSettings() {
        if (!state.dirty) return;
        setDirty(false);
        window.FAMModal?.showToast('Settings saved successfully.');
    }

    async function resetSettings() {
        if (!state.dirty) return;
        if (!await window.FAMModal.confirm('Discard unsaved changes?', { title: 'Discard Changes', confirmLabel: 'Discard' })) return;
        setDirty(false);
        renderPanel();
        window.FAMModal?.showToast('Unsaved changes discarded.');
    }

    function openOrgModal(mode, section) {
        orgModal.open = true;
        orgModal.mode = mode;
        orgModal.step = mode === 'edit' ? 'select' : 'form';
        orgModal.section = section;
        orgModal.search = '';
        orgModal.status = 'all';
        orgModal.selected = '';
        orgModal.dirty = false;
        orgModal.values = mode === 'add' ? { status: 'Active', reservable: 'Yes' } : {};
        orgModal.errors = {};
        renderPanel();
        setTimeout(() => document.querySelector('[data-org-modal-close], [data-org-search], [data-org-field]')?.focus(), 0);
    }

    function closeOrgModal() {
        orgModal.open = false;
        orgModal.dirty = false;
        renderPanel();
    }

    function selectOrgRecord(code) {
        const row = sampleRows[orgModal.section]?.find(item => item[1] === code);
        if (!row) return;
        orgModal.selected = code;
        orgModal.step = 'form';
        orgModal.dirty = false;
        orgModal.values = recordObject(row);
        orgModal.errors = {};
        renderPanel();
        setTimeout(() => document.querySelector('[data-org-field]')?.focus(), 0);
    }

    function updateOrgField(fieldName, value) {
        orgModal.values[fieldName] = value;
        orgModal.dirty = true;
        syncOrgModalActions();
    }

    function canSaveOrgModal(selectedRow = null) {
        const hasRequiredFields = Boolean(orgModal.values.name?.trim() && orgModal.values.code?.trim());
        const hasErrors = Object.keys(orgModal.errors).length > 0;
        return orgModal.mode === 'add' ? hasRequiredFields && !hasErrors : Boolean((selectedRow || orgModal.selected) && orgModal.dirty && hasRequiredFields && !hasErrors);
    }

    function syncOrgModalActions() {
        validateOrgValues();
        document.getElementById('admin-org-unsaved')?.classList.toggle('hidden', !orgModal.dirty);
        const saveButton = document.querySelector('[data-org-save]');
        if (saveButton) saveButton.disabled = !canSaveOrgModal();
    }

    function saveOrgModal() {
        validateOrgValues();
        if (!canSaveOrgModal()) {
            renderPanel();
            return;
        }
        const rows = sampleRows[orgModal.section] || [];
        const values = orgModal.values;
        if (orgModal.mode === 'add') {
            rows.push([values.name.trim(), values.code.trim(), values.status || 'Active', values.building?.trim() || '', values.floor?.trim() || '', values.type?.trim() || '']);
        } else {
            const index = rows.findIndex(row => row[1] === orgModal.selected);
            if (index >= 0) rows[index] = [values.name.trim(), values.code.trim(), values.status || 'Active', values.building?.trim() || '', values.floor?.trim() || '', values.type?.trim() || ''];
        }
        const message = orgModal.mode === 'add' ? `${organizationLabels[orgModal.section]} created.` : `${organizationLabels[orgModal.section]} updated.`;
        orgModal.open = false;
        orgModal.dirty = false;
        orgModal.errors = {};
        renderPanel();
        window.FAMModal?.showToast(message);
    }

    function bindEvents() {
        document.getElementById('admin-category-nav')?.addEventListener('click', event => {
            const button = event.target.closest('[data-admin-category]');
            if (!button) return;
            state.active = button.dataset.adminCategory;
            setDirty(false);
            renderNavigation();
            renderPanel();
        });
        document.getElementById('admin-category-select')?.addEventListener('change', event => {
            state.active = event.target.value;
            setDirty(false);
            renderNavigation();
            renderPanel();
        });
        document.getElementById('admin-panel-card')?.addEventListener('input', event => {
            const cardSearch = event.target.closest('[data-org-card-search]');
            if (cardSearch) {
                orgSearches[cardSearch.dataset.orgCardSearch] = cardSearch.value;
                renderPanel();
                setTimeout(() => document.querySelector(`[data-org-card-search="${cardSearch.dataset.orgCardSearch}"]`)?.focus(), 0);
                return;
            }
            if (event.target.matches('[data-org-search]')) {
                orgModal.search = event.target.value;
                renderPanel();
                setTimeout(() => document.getElementById('org-record-search')?.focus(), 0);
                return;
            }
            if (event.target.matches('[data-org-field]')) {
                updateOrgField(event.target.dataset.orgField, event.target.value);
                return;
            }
            if (event.target.matches('[data-admin-input]')) setDirty(true);
            if (event.target.id === 'audit-search') {
                state.auditSearch = event.target.value;
                renderPanel();
            }
        });
        document.getElementById('admin-panel-card')?.addEventListener('change', event => {
            if (event.target.matches('[data-org-status-filter]')) {
                orgModal.status = event.target.value;
                renderPanel();
                return;
            }
            if (event.target.matches('[data-org-field]')) {
                updateOrgField(event.target.dataset.orgField, event.target.value);
                return;
            }
            if (event.target.matches('[data-admin-input]')) setDirty(true);
            if (event.target.id === 'audit-result-filter') {
                state.auditResult = event.target.value;
                renderPanel();
            }
        });
        document.getElementById('admin-panel-card')?.addEventListener('click', async event => {
            const orgTab = event.target.closest('[data-org-tab]');
            if (orgTab) {
                state.organizationTab = orgTab.dataset.orgTab;
                renderPanel();
                return;
            }
            const orgButton = event.target.closest('[data-org-modal]');
            if (orgButton) {
                openOrgModal(orgButton.dataset.orgModal, orgButton.dataset.orgSection);
                return;
            }
            if (event.target.closest('[data-org-clear-search]')) {
                orgSearches[state.organizationTab] = '';
                renderPanel();
                setTimeout(() => document.getElementById('org-active-search')?.focus(), 0);
                return;
            }
            if (event.target.closest('[data-org-modal-close]') || event.target.matches('[data-org-modal-backdrop]')) {
                closeOrgModal();
                return;
            }
            if (event.target.closest('[data-org-back]')) {
                orgModal.step = 'select';
                orgModal.selected = '';
                orgModal.dirty = false;
                orgModal.values = {};
                orgModal.errors = {};
                renderPanel();
                return;
            }
            const orgRecord = event.target.closest('[data-org-select-record]');
            if (orgRecord) {
                selectOrgRecord(orgRecord.dataset.orgSelectRecord);
                return;
            }
            if (event.target.closest('[data-org-save]')) {
                saveOrgModal();
                return;
            }
            if (event.target.closest('[data-org-deactivate]')) {
                if (await window.FAMModal.confirm('Deactivate this record?', { title: 'Deactivate Record', confirmLabel: 'Deactivate' })) {
                    orgModal.values.status = 'Inactive';
                    orgModal.dirty = true;
                    renderPanel();
                }
                return;
            }
            const tab = event.target.closest('[data-admin-tab]');
            if (tab) {
                state.userTab = tab.dataset.adminTab;
                renderPanel();
                return;
            }
            const role = event.target.closest('[data-role-select]');
            if (role) {
                state.selectedRole = role.dataset.roleSelect;
                renderPanel();
                return;
            }
            const ref = event.target.closest('[data-ref-tab]');
            if (ref) {
                state.ref = ref.dataset.refTab;
                renderPanel();
                return;
            }
            if (event.target.closest('[data-admin-save]')) {
                saveSettings();
                return;
            }
            if (event.target.closest('[data-admin-reset]')) {
                resetSettings();
                return;
            }
            if (event.target.closest('[data-admin-hook]') || event.target.closest('[data-admin-row-action]')) {
                window.FAMModal?.showToast('Administration workflow hook opened.');
            }
        });
        document.getElementById('admin-refresh')?.addEventListener('click', () => {
            document.getElementById('admin-updated').textContent = formatUpdatedAt();
            setDirty(false);
            renderPanel();
        });
        document.addEventListener('keydown', event => {
            const activeOrgTab = event.target.closest('[data-org-tab]');
            if (activeOrgTab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();
                const tabs = Array.from(document.querySelectorAll('[data-org-tab]'));
                const index = tabs.indexOf(activeOrgTab);
                const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                state.organizationTab = tabs[nextIndex].dataset.orgTab;
                renderPanel();
                setTimeout(() => document.querySelector(`[data-org-tab="${state.organizationTab}"]`)?.focus(), 0);
                return;
            }
            if (event.key === 'Escape' && orgModal.open) {
                closeOrgModal();
                return;
            }
            if (event.key === 'Escape') setDirty(false);
            if (!orgModal.open) return;
            const modal = document.querySelector('.admin-org-drawer');
            const activeRecord = event.target.closest('[data-org-select-record]');
            if (activeRecord && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
                event.preventDefault();
                const records = Array.from(document.querySelectorAll('[data-org-select-record]'));
                const index = records.indexOf(activeRecord);
                const offset = event.key === 'ArrowDown' ? 1 : -1;
                records[(index + offset + records.length) % records.length]?.focus();
                return;
            }
            if (event.key === 'Tab' && modal) {
                const focusable = Array.from(modal.querySelectorAll('button, input, select, [href], [tabindex]:not([tabindex="-1"])')).filter(element => !element.disabled);
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    }

    function initializeSettings() {
        if (!(window.FAMApi?.currentUser?.permissions || []).includes('administration.view')) {
            window.location.replace('../errors/403.html');
            return;
        }
        document.getElementById('admin-updated').textContent = formatUpdatedAt();
        renderNavigation();
        renderPanel();
        bindEvents();
    }

    document.addEventListener('fam:layout-ready', initializeSettings);
})();
