(function () {
    document.addEventListener('fam:layout-ready', () => window.FAMLiveModule.init({
        apiBase: '../api/assets', loadingId: 'asset-loading-state', tableBodyId: 'asset-table', emptyId: 'asset-empty-state', countId: 'asset-table-count', paginationSelector: '.asset-management-workspace .facility-pagination', pageStatusId: 'asset-page-status', prevId: 'asset-prev-page', nextId: 'asset-next-page', searchId: 'asset-search', resetId: 'asset-reset-filters', refreshId: 'asset-refresh', exportId: 'asset-export', updatedId: 'asset-updated', recordLabel: 'asset records', emptyTitle: 'No assets have been registered.', emptyText: 'Asset records will appear here from the live registry.', defaultSort: 'created_at',
        exportUrl: query => { const p = new URLSearchParams(query); p.delete('page'); p.delete('per_page'); return `../api/assets/export-csv.php?${p}`; },
        columns: [
            { key: 'assetCode', className: 'facility-request-number', render: (r,h) => h.truncate(r.assetCode, 'table-cell-primary') },
            { key: 'assetName', render: (r,h) => `<div class="facility-subject-cell table-cell-stack">${h.truncate(r.assetName, 'table-cell-primary')}${h.truncate(r.propertyNumber || 'No property number', 'table-cell-secondary')}</div>` },
            { key: 'category' }, { key: 'location' }, { key: 'condition', render: (r,h) => h.badge(r.condition,'status') }, { key: 'lifecycle', render: (r,h) => h.badge(r.lifecycle,'status') }
        ],
        titleFor: item => item.assetName || item.assetCode || 'Asset Details'
    }));
})();
