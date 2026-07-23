document.addEventListener('DOMContentLoaded', () => {
    const subsystemId = getSubsystemFromUrl();
    const subsystem = subsystemId ? getSubsystemById(subsystemId) : null;

    const dashboardHeading = document.getElementById('dashboard-heading');
    const dashboardCopy = document.getElementById('dashboard-copy');
    const dashboardStatsGrid = document.getElementById('dashboard-stats-grid');
    const dashboardCharts = document.getElementById('dashboard-charts');
    const dashboardChartOverview = document.getElementById('dashboard-chart-overview');
    const dashboardChartBreakdown = document.getElementById('dashboard-chart-breakdown');
    const dashboardQuickActionsList = document.getElementById('dashboard-quick-actions-list');
    const dashboardActivityBody = document.getElementById('dashboard-activity-tbody');
    const breadcrumbCategory = document.getElementById('breadcrumb-category');
    const sidebarBrandTitle = document.getElementById('sidebar-brand-title');
    const sidebarBrandCategory = document.getElementById('sidebar-brand-category');
    const sidebarSubsystemNavPanel = document.getElementById('sidebar-subsystem-nav-panel');
    const sidebarSubsystemModulesNav = document.getElementById('sidebar-subsystem-modules-nav');

    if (!dashboardHeading || !dashboardCopy || !dashboardStatsGrid || !dashboardCharts || !dashboardChartOverview || !dashboardChartBreakdown || !dashboardQuickActionsList || !dashboardActivityBody || !sidebarBrandTitle || !sidebarBrandCategory || !sidebarSubsystemNavPanel || !sidebarSubsystemModulesNav) return;

    const normalizeStatValue = value => {
        if (typeof value === 'number') return Math.min(100, Math.max(5, Math.round(value)));
        if (!value) return 60;
        const parsed = parseFloat(String(value).replace(/[^0-9.-]+/g, ''));
        return Number.isFinite(parsed) ? Math.min(100, Math.max(5, Math.round(parsed))) : 60;
    };

    const moduleIconMap = {
        'Client Management Subsystem': 'groups',
        'Applicant Registration and Profiling System': 'person_add',
        'Recruitment and Selection Subsystem': 'search',
        'Job Order Management Subsystem': 'assignment',
        'Deployment and Assignment Subsystem': 'engineering',
        'Employee Information Management System (HRIS)': 'badge',
        'Timekeeping and Attendance System': 'schedule',
        'Leave and Absence Management System': 'beach_access',
        'Payroll and Compensation System': 'attach_money',
        'Performance Management Subsystem': 'star',
        'Training and Development Subsystem': 'school',
        'Document and Contract Management System': 'description',
        'Government Contribution & Compliance Subsystem': 'gavel',
        'Benefits and Loans Management System': 'health_and_safety',
        'Separation and Exit Clearance Subsystem': 'verified_user',
        'Health, Safety, and Welfare Subsystem': 'safety_check',
        'Legal and Compliance Subsystem': 'gavel',
        'System Administration and Security Subsystem': 'admin_panel_settings',
        'Reports, Analytics, and Dashboards System': 'insights',
        'Asset and Equipment Issuance Tracker': 'inventory',
        'General Ledger': 'account_balance_wallet',
        'Accounts Payable (AP)': 'receipt_long',
        'Accounts Receivable (AR)': 'payments',
        'Disbursement Management': 'account_balance',
        'Collection Management': 'currency_exchange',
        'Budget Management': 'account_balance',
        'Cash Management': 'account_balance_wallet',
        'Financial Reporting & Analytics': 'insights',
        'Tax Management': 'request_quote',
        'Smart Warehousing System (SWS)': 'warehouse',
        'Inventory Management System': 'inventory_2',
        'Procurement & Sourcing Management (PSM)': 'shopping_bag',
        'Supplier / Vendor Management': 'handshake',
        'Purchase Order Management': 'receipt_long',
        'Document Tracking & Logistics Records System (DTRS)': 'local_shipping',
        'Fleet & Vehicle Management (FVM)': 'directions_car',
        'Vehicle Reservation & Dispatch System (VRDS)': 'directions_bus',
        'Driver and Trip Performance Monitoring': 'timeline',
        'Fuel Management System': 'local_gas_station',
        'Transport Cost Analysis & Optimization (TCAO)': 'analytics',
        'Route Planning & Optimization': 'map',
        'Mobile Fleet Command App': 'emoji_transportation',
        'Facilities Reservation System': 'meeting_room',
        'Visitor Management System': 'badge',
        'Document Management (Archiving System)': 'folder',
        'Records Retention & Compliance': 'folder_shared',
        'Legal Management System': 'gavel',
        'Contract Management': 'description',
        'Dashboard & Data Visualization System': 'dashboard',
        'KPI Monitoring & Performance Tracking System': 'trending_up',
        'Predictive Analytics System': 'insights',
        'Custom Report Generation System': 'insert_chart',
        'Data Aggregation & Integration System': 'storage',
        'Exportable Reports & Decision Support System': 'file_download',
        'Lead and Client Tracking System': 'track_changes',
        'Communication History Management': 'forum',
        'Client Satisfaction and Survey System': 'emoji_events',
        'Follow-up Reminder System': 'notifications',
        'Opportunity Pipeline Visualization': 'timeline'
    };

    const getModuleIcon = moduleName => {
        if (!moduleName) return 'apps';
        return moduleIconMap[moduleName] || 'apps';
    };

    const getStatusBadge = status => {
        const normalized = String(status || '').toLowerCase();
        const statusMap = {
            completed: 'status-pill status-pill-success',
            ready: 'status-pill status-pill-success',
            updated: 'status-pill status-pill-info',
            pending: 'status-pill status-pill-warning',
            scheduled: 'status-pill status-pill-info',
            new: 'status-pill status-pill-accent'
        };
        return statusMap[normalized] || 'status-pill status-pill-neutral';
    };

    const actionIconMap = {
        'New Transaction': 'add',
        'Upload Invoice': 'upload_file',
        'Generate Report': 'insert_chart',
        'Budget Planning': 'query_stats',
        'Tax Filing': 'receipt_long',
        'Approve invoices': 'task_alt',
        'Create budget plan': 'account_balance',
        'Review cash forecast': 'analytics',
        'Export financial statements': 'file_download'
    };

    const renderLineChart = dataPoints => {
        const points = dataPoints.map((point, index) => {
            const value = Math.min(100, Math.max(20, Number(point.value) || 20));
            return {
                x: 20 + index * 60,
                y: 150 - value
            };
        });

        const linePath = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ');
        const fillPath = `${linePath} L ${points[points.length - 1].x} 150 L ${points[0].x} 150 Z`;

        return `
            <div class="dashboard-line-chart">
                <svg viewBox="0 0 320 180" aria-hidden="true">
                    <defs>
                        <linearGradient id="lineGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#4f46e5" stop-opacity="0.9" />
                            <stop offset="100%" stop-color="#c7d2fe" stop-opacity="0.08" />
                        </linearGradient>
                    </defs>
                    <path d="${fillPath}" fill="url(#lineGradient)" />
                    <path d="${linePath}" fill="none" stroke="#4338ca" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                    ${points.map(point => `
                        <circle cx="${point.x}" cy="${point.y}" r="4" fill="#4338ca" stroke="#ffffff" stroke-width="2" />
                    `).join('')}
                </svg>
            </div>
        `;
    };

    const renderDonutChart = segments => {
        const gradient = segments.map((segment, index) => `${segment.color} ${index === 0 ? '0%' : ''} ${segments.slice(0, index + 1).reduce((acc, item) => acc + parseInt(item.value, 10), 0)}%`).join(', ');
        return `
            <div class="donut-chart" style="background: conic-gradient(${segments.map(segment => `${segment.color} ${segment.value}`).join(', ')});"></div>
        `;
    };

    if (!subsystem) {
        dashboardHeading.textContent = 'Welcome back, Admin';
        dashboardCopy.textContent = 'Open the module selector and choose a subsystem to view its dedicated dashboard.';
        breadcrumbCategory.textContent = 'No subsystem selected';
        dashboardStatsGrid.innerHTML = '';
        dashboardCharts.innerHTML = '';
        dashboardQuickActionsList.innerHTML = '<p class="text-sm text-on-surface-variant">Select a subsystem from the module selector to display statistics, charts, and activity.</p>';
        dashboardActivityBody.innerHTML = '<tr><td class="px-6 py-4 text-on-surface-variant" colspan="4">No activity available. Select a subsystem to view activity logs.</td></tr>';
        sidebarBrandTitle.textContent = 'No subsystem selected';
        sidebarBrandCategory.textContent = 'Choose a subsystem from the selector.';
        sidebarSubsystemModulesNav.innerHTML = '';
        sidebarSubsystemNavPanel.classList.add('hidden');
        return;
    }

    document.title = `${subsystem.title} — Dashboard`;
    dashboardHeading.textContent = 'Welcome back, Admin';
    dashboardCopy.textContent = `Here's what's happening in ${subsystem.title} today.`;
    if (breadcrumbCategory) breadcrumbCategory.textContent = subsystem.title;
    sidebarBrandTitle.textContent = subsystem.title;
    sidebarBrandCategory.textContent = subsystem.category;
    sidebarSubsystemModulesNav.innerHTML = subsystem.modules.map((module, index) => {
        const moduleName = typeof module === 'string' ? module : module.name;
        const isActive = index === 0;
        return `
            <a href="#" class="sidebar-subsystem-link ${isActive ? 'active' : ''}">
                <span class="material-symbols-outlined sidebar-subsystem-link-icon">${getModuleIcon(moduleName)}</span>
                <span class="truncate">${moduleName}</span>
            </a>
        `;
    }).join('');
    sidebarSubsystemNavPanel.classList.remove('hidden');

    dashboardStatsGrid.innerHTML = subsystem.stats.map(stat => {
        const deltaMap = {
            'Pipeline Value': { text: '+12.4% vs last month', isPositive: true },
            'Open Requests': { text: '+8.6% vs last week', isPositive: true },
            'Active Clients': { text: '+3.2% vs last quarter', isPositive: true },
            'Fill Rate': { text: '+4.5% vs target', isPositive: true }
        };
        const defaultDelta = stat.tone === 'positive'
            ? { text: '+12% vs last month', isPositive: true }
            : stat.tone === 'caution'
            ? { text: '-2.4% vs last month', isPositive: false }
            : { text: '+1.8% vs last month', isPositive: true };
        const delta = stat.delta || deltaMap[stat.label] || defaultDelta;

        return `
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200 flex flex-col justify-between gap-4 overflow-hidden relative">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">${stat.label}</p>
                    <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[20px]">${stat.icon}</span>
                    </div>
                </div>
                <div>
                    <h3 class="text-3xl font-headline font-bold text-slate-900 leading-none">${stat.value}</h3>
                </div>
                <div class="flex items-center gap-2 pt-3 border-t border-slate-100 text-xs">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md font-semibold ${delta.isPositive ? 'text-emerald-700 bg-emerald-50' : 'text-rose-700 bg-rose-50'}">
                        <span class="material-symbols-outlined text-[15px]">${delta.isPositive ? 'trending_up' : 'trending_down'}</span>
                        <span>${delta.text}</span>
                    </span>
                </div>
            </div>
        `;
    }).join('');

    dashboardQuickActionsList.innerHTML = subsystem.quickActions.map(action => `
        <button type="button" class="quick-action-button">
            <span class="flex items-center gap-3">
                <span class="material-symbols-outlined text-lg">${actionIconMap[action] || 'bolt'}</span>
                <span>${action}</span>
            </span>
            <span class="material-symbols-outlined text-sm">arrow_forward</span>
        </button>
    `).join('');

    const renderAnalytics = analytics => {
        const overviewTitle = analytics?.overviewTitle || 'Performance overview';
        const overviewMetric = analytics?.overviewMetric || subsystem.stats[0]?.value || 'Overview';
        const overviewSubtitle = analytics?.overviewSubtitle || subsystem.description || `Track ${subsystem.title} performance.`;
        const overviewTrend = analytics?.overviewTrend || 'Updated now';
        const overviewData = Array.isArray(analytics?.overviewData) && analytics.overviewData.length
            ? analytics.overviewData
            : subsystem.stats.map(stat => ({ label: stat.label, value: normalizeStatValue(stat.value) }));
        const overviewHighlights = Array.isArray(analytics?.overviewHighlights) && analytics.overviewHighlights.length
            ? analytics.overviewHighlights
            : overviewData.slice(0, 2).map(item => ({ label: item.label, value: `${item.value}%` }));
        const breakdownTitle = analytics?.breakdownTitle || 'Detailed breakdown';
        const breakdownTotal = analytics?.breakdownTotal || 'Key metrics';
        const breakdownSegments = Array.isArray(analytics?.breakdownSegments) && analytics.breakdownSegments.length
            ? analytics.breakdownSegments
            : subsystem.stats.map(stat => ({ label: stat.label, value: stat.value, color: '#c7c4d8' }));

        dashboardChartOverview.innerHTML = `
            <div class="dashboard-chart-header">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-on-surface-variant mb-2">${overviewTitle}</p>
                    <h3 class="text-2xl font-headline font-bold text-on-surface">${overviewMetric}</h3>
                    <p class="text-sm text-on-surface-variant mt-2">${overviewSubtitle}</p>
                </div>
                <button class="dashboard-chart-filter-button inline-flex items-center gap-1.5 hover:border-slate-300 transition-colors">
                    <span>${overviewTrend}</span>
                    <span class="material-symbols-outlined text-[18px]">keyboard_arrow_down</span>
                </button>
            </div>
            ${renderLineChart(overviewData)}
            <div class="grid gap-3 sm:grid-cols-2 mt-5">
                ${overviewHighlights.map(item => `
                    <div class="rounded-2xl bg-surface-container-high p-4 border border-outline-variant/20">
                        <p class="text-xs text-on-surface-variant">${item.label}</p>
                        <p class="mt-2 text-sm font-semibold text-on-surface">${item.value}</p>
                    </div>
                `).join('')}
            </div>
        `;

        dashboardChartBreakdown.innerHTML = `
            <div class="dashboard-chart-header">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-on-surface-variant mb-2">${breakdownTitle}</p>
                    <h3 class="text-2xl font-headline font-bold text-on-surface">${breakdownTotal}</h3>
                </div>
            </div>
            <div class="grid gap-5 lg:grid-cols-[1fr_0.95fr] items-center">
                <div class="donut-chart-wrapper">
                    ${renderDonutChart(breakdownSegments)}
                    <div class="donut-center-text">
                        <p>Total</p>
                        <p>${breakdownTotal}</p>
                    </div>
                </div>
                <div class="space-y-3">
                    ${breakdownSegments.map(segment => `
                        <div class="donut-list-item">
                            <span class="flex items-center gap-3">
                                <span class="donut-list-color" style="background: ${segment.color};"></span>
                                <span>${segment.label}</span>
                            </span>
                            <span class="text-sm font-semibold text-on-surface">${segment.value}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    };

    renderAnalytics(subsystem.analytics);

    dashboardActivityBody.innerHTML = subsystem.activity.map(item => `
        <tr class="hover:bg-surface-container-lowest transition-colors">
            <td class="px-6 py-4 text-sm text-on-surface">${item.label}</td>
            <td class="px-6 py-4 text-sm text-on-surface-variant">${item.status}</td>
            <td class="px-6 py-4 text-sm text-on-surface-variant">${item.time}</td>
            <td class="px-6 py-4">
                <button class="text-on-surface-variant hover:text-primary transition-colors p-1.5 rounded-md hover:bg-surface-container-low cursor-pointer" title="View Details">
                    <span class="material-symbols-outlined text-sm">visibility</span>
                </button>
            </td>
        </tr>
    `).join('');
});