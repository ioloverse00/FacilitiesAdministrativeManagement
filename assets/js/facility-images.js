(function () {
    const apiUrl = path => window.FAMApi?.apiUrl?.(path)
        || window.FAMNavigation?.apiUrl?.(path)
        || (/^\.\.\//.test(path) || /^\//.test(path) ? path : `/api/${path}`);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const iconFor = value => String(value || '').toUpperCase().includes('PARKING') ? 'local_parking' : 'meeting_room';

    function imageUrl(image) {
        return image?.has_image && image.url ? apiUrl(image.url) : '';
    }

    function placeholderHtml(item = {}, label = 'No image yet') {
        const fallback = item.image?.fallback || item.roomImage?.fallback || {};
        const icon = fallback.icon || iconFor(item.type || item.roomType || item.name || item.room);
        const text = fallback.label || label;
        return `<div class="facility-image-placeholder" data-facility-image-placeholder><span class="material-symbols-outlined" aria-hidden="true">${esc(icon)}</span><span>${esc(text)}</span></div>`;
    }

    function mediaHtml(item = {}, options = {}) {
        const image = item.image || item.roomImage || {};
        const url = imageUrl(image);
        const alt = options.alt || item.name || item.room || item.code || 'Facility image';
        if (url) return `<img src="${esc(url)}" alt="${esc(alt)}" loading="lazy">`;
        return placeholderHtml(item, options.fallbackLabel || 'No image yet');
    }

    window.FAMFacilityImages = {
        imageUrl,
        mediaHtml,
        placeholderHtml,
    };
})();
