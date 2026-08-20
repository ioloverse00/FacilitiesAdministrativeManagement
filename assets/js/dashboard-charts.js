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

    function createReservationActivityChart(canvas, data) {
        if (!canvas || !window.Chart || !data) return null;
        destroyChart('reservationActivity');
        const colors = palette();
        const options = baseOptions();
        options.plugins.legend.display = false;
        options.scales.y.title = { display: true, text: 'Reservations', color: colors.text };
        options.plugins.tooltip.callbacks = {
            label: context => `${context.parsed.y} ${Number(context.parsed.y) === 1 ? 'reservation' : 'reservations'}`
        };
        chartInstances.reservationActivity = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [
                    { label: 'Reservations', data: data.values || [], borderColor: colors.primary, backgroundColor: colors.primaryFill, borderRadius: 8, maxBarThickness: 42 }
                ]
            },
            options
        });
        return chartInstances.reservationActivity;
    }

    function createOperationalOverviewChart(canvas, data) {
        if (!canvas || !window.Chart || !data) return null;
        destroyChart('operationalOverview');
        const colors = palette();
        const options = baseOptions();
        options.indexAxis = 'y';
        options.plugins.legend.display = false;
        options.scales.x.title = { display: true, text: 'Items', color: colors.text };
        options.plugins.tooltip.callbacks = {
            label: context => `${context.label} - ${context.parsed.x}`
        };
        chartInstances.operationalOverview = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: 'Current workload',
                    data: data.values || [],
                    backgroundColor: [
                        cssVar('--color-warning-bg', 'rgba(217, 119, 6, 0.10)'),
                        colors.primaryFill,
                        cssVar('--color-danger-bg', 'rgba(220, 38, 38, 0.08)')
                    ],
                    borderColor: [colors.amber, colors.primary, colors.rose],
                    borderWidth: 1,
                    borderRadius: 8,
                    maxBarThickness: 34
                }]
            },
            options
        });
        return chartInstances.operationalOverview;
    }

    function destroyAllCharts() {
        Object.keys(chartInstances).forEach(destroyChart);
    }

    window.FAMDashboardCharts = {
        chartInstances,
        hasValues,
        createReservationActivityChart,
        createOperationalOverviewChart,
        destroyAllCharts
    };
})();

