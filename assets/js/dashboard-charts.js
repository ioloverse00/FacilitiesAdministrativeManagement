(function () {
    const chartInstances = {};

    function cssVar(name, fallback) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    }

    function palette() {
        return {
            primary: cssVar('--color-primary', '#4f46e5'),
            teal: cssVar('--color-info', '#0f766e'),
            amber: cssVar('--color-warning', '#d97706'),
            rose: cssVar('--color-danger', '#dc2626'),
            slate: cssVar('--color-text-subtle', '#64748b'),
            green: cssVar('--color-success', '#16a34a'),
            primaryFill: cssVar('--color-primary-soft', 'rgba(79, 70, 229, 0.10)'),
            surface: cssVar('--color-surface-elevated', '#ffffff'),
            text: cssVar('--color-text-muted', '#464555'),
            border: cssVar('--color-border', '#c7c4d8')
        };
    }

    function baseOptions() {
        const colors = palette();
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: { labels: { color: colors.text, boxWidth: 12, boxHeight: 12 } },
                tooltip: {
                    backgroundColor: colors.surface,
                    titleColor: cssVar('--color-text', '#111827'),
                    bodyColor: colors.text,
                    borderColor: colors.border,
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 8
                }
            },
            scales: {
                x: { ticks: { color: colors.text }, grid: { color: colors.border } },
                y: { beginAtZero: true, ticks: { color: colors.text, precision: 0 }, grid: { color: colors.border } }
            }
        };
    }

    function destroyChart(key) {
        if (chartInstances[key]) {
            chartInstances[key].destroy();
            chartInstances[key] = null;
        }
    }

    function hasValues(values) {
        return Array.isArray(values) && values.some(value => Number(value) > 0);
    }

    function createRequestsTrendChart(canvas, data) {
        if (!canvas || !window.Chart || !data) return null;
        destroyChart('requestsTrend');
        const colors = palette();
        const options = baseOptions();
        options.scales.y.title = { display: true, text: 'Requests', color: colors.text };
        chartInstances.requestsTrend = new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: [
                    { label: 'Facility requests received', data: data.facilityRequests || [], borderColor: colors.primary, backgroundColor: colors.primaryFill, tension: 0.3, fill: true, pointRadius: 3 },
                    { label: 'Maintenance requests received', data: data.maintenanceRequests || [], borderColor: colors.amber, backgroundColor: cssVar('--color-warning-bg', 'rgba(217, 119, 6, 0.08)'), tension: 0.3, pointRadius: 3 },
                    { label: 'Requests completed', data: data.completedRequests || [], borderColor: colors.green, backgroundColor: cssVar('--color-success-bg', 'rgba(22, 163, 74, 0.08)'), tension: 0.3, pointRadius: 3 }
                ]
            },
            options
        });
        return chartInstances.requestsTrend;
    }

    function createRequestStatusChart(canvas, data) {
        if (!canvas || !window.Chart || !Array.isArray(data)) return null;
        destroyChart('requestStatus');
        const colors = palette();
        chartInstances.requestStatus = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.map(item => item.label),
                datasets: [{
                    data: data.map(item => item.value),
                    backgroundColor: [colors.slate, colors.primary, colors.teal, colors.amber, colors.rose],
                    borderWidth: 2,
                    borderColor: colors.surface
                }]
            },
            options: { ...baseOptions(), cutout: '66%', scales: undefined }
        });
        return chartInstances.requestStatus;
    }

    function destroyAllCharts() {
        Object.keys(chartInstances).forEach(destroyChart);
    }

    window.FAMDashboardCharts = {
        chartInstances,
        hasValues,
        createRequestsTrendChart,
        createRequestStatusChart,
        destroyAllCharts
    };
})();

