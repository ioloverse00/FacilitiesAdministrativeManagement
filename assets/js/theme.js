(function () {
    const storageKey = 'fam-theme-preference';
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    const valid = new Set(['light', 'dark', 'system']);

    function storedPreference() {
        try {
            return localStorage.getItem(storageKey);
        } catch (error) {
            return null;
        }
    }

    function preference() {
        const saved = storedPreference();
        return valid.has(saved) ? saved : 'system';
    }

    function resolved(pref = preference()) {
        return pref === 'system' ? (mediaQuery.matches ? 'dark' : 'light') : pref;
    }

    function apply(pref = preference(), emit = true) {
        const nextPreference = valid.has(pref) ? pref : 'system';
        const resolvedTheme = resolved(nextPreference);
        document.documentElement.dataset.themePreference = nextPreference;
        document.documentElement.dataset.theme = resolvedTheme;
        document.documentElement.style.colorScheme = resolvedTheme;
        if (emit) {
            window.dispatchEvent(new CustomEvent('fam:themechange', { detail: { preference: nextPreference, resolvedTheme } }));
        }
        return { preference: nextPreference, resolvedTheme };
    }

    function setPreference(pref) {
        const next = valid.has(pref) ? pref : 'system';
        try {
            localStorage.setItem(storageKey, next);
        } catch (error) {
            // Theme still applies for this page view when storage is unavailable.
        }
        return apply(next);
    }

    function handleSystemThemeChange() {
        if (preference() === 'system') apply('system');
    }

    if (typeof mediaQuery.addEventListener === 'function') {
        mediaQuery.addEventListener('change', handleSystemThemeChange);
    } else if (typeof mediaQuery.addListener === 'function') {
        mediaQuery.addListener(handleSystemThemeChange);
    }

    window.FAMTheme = { preference, resolvedTheme: () => resolved(), apply, setPreference, storageKey };
    apply(preference(), false);
})();
