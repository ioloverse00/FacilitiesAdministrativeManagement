(function () {
    const chartInstances = {};

    const palette = {
        primary: '#4f46e5',
        teal: '#0f766e',
        amber: '#d97706',
        rose: '#dc2626',
        slate: '#64748b',
        green: '#16a34a'
    };

    function cssVar(name, fallback) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    }

    function baseOptions() {
        const text = cssVar('--text-muted', '#464555');
        const grid = cssVar('--outline-variant', '#c7c4d8');
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: { labels: { color: text, boxWidth: 12, boxHeight: 12 } },
                tooltip: {
                    backgroundColor: '#111827',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    padding: 12,
                    cornerRadius: 8
                }
            },
            scales: {
                x: { ticks: { color: text }, grid: { color: 'rgba(199, 196, 216, 0.35)' } },
                y: { beginAtZero: true, ticks: { color: text, precision: 0 }, grid: { color: grid } }
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
        const options = baseOptions();
        options.scales.y.title = { display: true, text: 'Requests', color: cssVar('--text-muted', '#464555') };
        chartInstances.requestsTrend = new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: [
                    { label: 'Facility requests received', data: data.facilityRequests || [], borderColor: palette.primary, backgroundColor: 'rgba(79, 70, 229, 0.10)', tension: 0.3, fill: true, pointRadius: 3 },
                    { label: 'Maintenance requests received', data: data.maintenanceRequests || [], borderColor: palette.amber, backgroundColor: 'rgba(217, 119, 6, 0.08)', tension: 0.3, pointRadius: 3 },
                    { label: 'Requests completed', data: data.completedRequests || [], borderColor: palette.green, backgroundColor: 'rgba(22, 163, 74, 0.08)', tension: 0.3, pointRadius: 3 }
                ]
            },
            options
        });
        return chartInstances.requestsTrend;
    }

    function createRequestStatusChart(canvas, data) {
        if (!canvas || !window.Chart || !Array.isArray(data)) return null;
        destroyChart('requestStatus');
        chartInstances.requestStatus = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.map(item => item.label),
                datasets: [{
                    data: data.map(item => item.value),
                    backgroundColor: [palette.slate, palette.primary, palette.teal, palette.amber, palette.rose],
                    borderWidth: 2,
                    borderColor: '#ffffff'
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
