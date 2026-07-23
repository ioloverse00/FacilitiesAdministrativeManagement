document.addEventListener('fam:layout-ready', () => {
    const newRequestButton = document.getElementById('new-facility-request');

    newRequestButton?.addEventListener('click', () => {
        window.FAMModal?.showToast('Facility request workflow opened.');
    });
});
