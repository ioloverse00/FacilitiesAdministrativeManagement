(function () {
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    const text = value => window.FAMEmployeePortal.text(value);
    const initials = name => {
        const parts = text(name, 'Employee').split(/\s+/).filter(Boolean);
        return `${parts[0]?.[0] || 'E'}${parts[1]?.[0] || 'P'}`.toUpperCase();
    };
    const row = (label, value) => `<div class="employee-info-row"><span>${esc(label)}</span><strong>${esc(text(value))}</strong></div>`;
    const section = (title, rows) => `<section class="employee-info-section"><h2>${esc(title)}</h2><div>${rows.join('')}</div></section>`;

    async function init() {
        const context = await window.FAMEmployeePortal.context();
        const target = document.getElementById('employee-profile-fields');
        if (!target) return;
        const status = text(context.employment_status, 'Active');
        target.innerHTML = `
            <section class="employee-profile-summary">
                <div class="employee-profile-avatar" aria-hidden="true">${esc(initials(context.full_name))}</div>
                <div>
                    <h2>${esc(text(context.full_name, 'Employee'))}</h2>
                    <p>${esc(text(context.position, 'Department Requestor'))}</p>
                    <p>${esc(text(context.department?.name || context.department?.code, 'Department not available'))}</p>
                </div>
                <span class="facility-badge facility-status-active">${esc(status)}</span>
            </section>
            <div class="employee-profile-sections">
                ${section('Employment Information', [
                    row('Employee Number', context.employee_number),
                    row('Department', context.department?.name || context.department?.code),
                    row('Position', context.position),
                    row('Employment Status', context.employment_status)
                ])}
                ${section('Contact Information', [
                    row('Email', context.email),
                    row('Contact Number', context.contact_number)
                ])}
            </div>
        `;
    }

    document.addEventListener('fam:employee-layout-ready', () => init().catch(console.error));
})();
