(function () {
    document.addEventListener('fam:layout-ready', () => window.FAMLiveModule.init({
        apiBase: '../api/records', loadingId: 'records-loading-state', tableBodyId: 'records-table', emptyId: 'records-empty-state', countId: 'records-table-count', paginationSelector: '.records-workspace .facility-pagination', pageStatusId: 'records-page-status', prevId: 'records-prev-page', nextId: 'records-next-page', searchId: 'records-search', resetId: 'records-reset-filters', refreshId: 'records-refresh', exportId: 'records-export', updatedId: 'records-updated', recordLabel: 'administrative records', emptyTitle: 'No administrative records are available.', emptyText: 'Records will appear here from the live records workflow.', defaultSort: 'created_at',
        columns: [
            { key: 'documentNo', className: 'facility-request-number', render: (r,h) => `<strong>${h.esc(r.documentNo)}</strong>` },
            { key: 'title', render: (r,h) => `<div class="facility-subject-cell"><strong>${h.esc(r.title)}</strong><span>${h.esc(r.description || '')}</span></div>` },
            { key: 'category' }, { key: 'owner' }, { key: 'department' }, { key: 'reviewDate', className: 'facility-date-cell' }, { key: 'status', render: (r,h) => h.badge(r.status,'status') }
        ],
        titleFor: item => item.title || item.documentNo || 'Administrative Record'
    }));
})();
