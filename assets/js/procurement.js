(function () {
    document.addEventListener('fam:layout-ready', () => window.FAMLiveModule.init({
        apiBase: '../api/procurement', loadingId: 'procurement-loading-state', tableBodyId: 'procurement-table', emptyId: 'procurement-empty-state', countId: 'procurement-table-count', paginationSelector: '.procurement-workspace .facility-pagination', pageStatusId: 'procurement-page-status', prevId: 'procurement-prev-page', nextId: 'procurement-next-page', searchId: 'procurement-search', resetId: 'procurement-reset-filters', refreshId: 'procurement-refresh', exportId: 'procurement-export', updatedId: 'procurement-updated', recordLabel: 'procurement request records', emptyTitle: 'No procurement requests have been submitted.', emptyText: 'Submitted procurement requests will appear here for review.', defaultSort: 'created_at',
        exportUrl: query => { const p = new URLSearchParams(query); p.delete('page'); p.delete('per_page'); return `../api/procurement/export-csv.php?${p}`; },
        columns: [
            { key: 'requestNo', className: 'facility-request-number', render: (r,h) => h.truncate(r.requestNo, 'table-cell-primary') },
            { key: 'justification', columnKey: 'purpose', render: (r,h) => `<div class="facility-subject-cell table-cell-stack">${h.truncate(r.justification, 'table-cell-primary')}</div>` },
            { key: 'priority', render: (r,h) => h.badge(r.priority,'priority') },
            { key: 'status', render: (r,h) => h.badge(r.status,'status') },
            { key: 'estimatedCost', render: (r,h) => h.truncate(h.currency(r.estimatedCost, r.currency)) },
            { key: 'createdAt', columnKey: 'submitted', label: 'Submitted', className: 'facility-date-cell', render: (r,h) => h.truncate(h.fmt(r.createdAt)) }
        ],
        titleFor: item => item.requestNo || 'Procurement Request'
    }));
})();
