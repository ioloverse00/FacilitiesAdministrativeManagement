(function () {
    document.addEventListener('fam:layout-ready', () => window.FAMLiveModule.init({
        apiBase: '../api/maintenance', loadingId: 'maintenance-loading-state', tableBodyId: 'maintenance-table', emptyId: 'maintenance-empty-state', countId: 'maintenance-table-count', paginationSelector: '.maintenance-workspace .facility-pagination', pageStatusId: 'maintenance-page-status', prevId: 'maintenance-prev-page', nextId: 'maintenance-next-page', searchId: 'maintenance-search', resetId: 'maintenance-reset-filters', refreshId: 'maintenance-refresh', exportId: 'maintenance-export', updatedId: 'maintenance-updated', recordLabel: 'maintenance work orders', emptyTitle: 'No maintenance work orders found.', emptyText: 'Work orders will appear here for assignment, processing, and verification.', defaultSort: 'created_at',
        columns: [
            { key: 'workOrderNo', className: 'facility-request-number', render: (r,h) => `<strong>${h.esc(r.workOrderNo)}</strong>` },
            { key: 'title', render: (r,h) => `<div class="facility-subject-cell"><strong>${h.esc(r.title)}</strong></div>` },
            { key: 'asset' }, { key: 'priority', render: (r,h) => h.badge(r.priority,'priority') }, { key: 'status', render: (r,h) => h.badge(r.status,'status') }, { key: 'assignedTo' }, { key: 'scheduledStart', className: 'facility-date-cell', render: (r,h) => h.esc(h.fmt(r.scheduledStart)) }
        ],
        titleFor: item => item.workOrderNo || 'Maintenance Work Order'
    }));
})();
