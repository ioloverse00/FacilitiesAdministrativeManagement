(function () {
    class ApiError extends Error {
        constructor(message, details) {
            super(message || 'Request failed.');
            this.name = 'ApiError';
            Object.assign(this, details || {});
        }
    }

    let csrfToken = null;
    let currentUser = null;

    function appBasePath() {
        const marker = '/pages/';
        const path = window.location.pathname;
        const index = path.indexOf(marker);
        if (index >= 0) return path.slice(0, index + 1);
        return path.endsWith('/') ? path : path.replace(/[^/]*$/, '');
    }

    function loginUrl() {
        return `${window.location.origin}${appBasePath()}pages/authentication_card_component_standard.html`;
    }

    function pageLoginUrl() {
        const next = encodeURIComponent(window.location.pathname + window.location.search);
        return `${loginUrl()}?next=${next}`;
    }

    function apiUrl(path) {
        if (/^https?:\/\//i.test(path)) return path;
        if (path.startsWith('../api/')) return path;
        if (path.startsWith('/')) return path;
        return `${appBasePath()}api/${path.replace(/^api\//, '')}`;
    }

    function updateCsrf(data) {
        const token = data?.csrf_token || data?.data?.csrf_token;
        if (token) csrfToken = token;
    }

    function clearAuthState() {
        csrfToken = null;
        currentUser = null;
    }

    async function request(url, options = {}) {
        const method = (options.method || 'GET').toUpperCase();
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        const init = { method, credentials: 'same-origin', headers };
        if (options.body !== undefined && options.body !== null) {
            headers.set('Content-Type', 'application/json');
            init.body = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);
        }
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && csrfToken) headers.set('X-CSRF-Token', csrfToken);
        let response;
        try { response = await fetch(url, init); }
        catch (error) { throw new ApiError('Connection error. Please check the local server and try again.', { status: 0, cause: error }); }
        const text = await response.text();
        let payload = null;
        if (text) { try { payload = JSON.parse(text); } catch { payload = null; } }
        updateCsrf(payload);
        if (response.status === 401 && !options.skipAuthRedirect) {
            window.location.replace(pageLoginUrl());
            throw new ApiError('Authentication required.', { status: 401, payload });
        }
        if (!response.ok || payload?.success === false) {
            throw new ApiError(payload?.message || `Request failed with HTTP ${response.status}.`, {
                status: response.status,
                payload,
                errors: payload?.data?.errors || {}
            });
        }
        return payload || { success: true, data: {} };
    }

    async function me() {
        const payload = await request(apiUrl('auth/me.php'), { skipAuthRedirect: true });
        currentUser = payload.data?.user || null;
        updateCsrf(payload.data || payload);
        return { user: currentUser, csrfToken };
    }

    async function logout(options = {}) {
        if (!csrfToken && !options.skipRefresh) await me();
        try {
            const payload = await request(apiUrl('auth/logout.php'), { method: 'POST', skipAuthRedirect: true });
            clearAuthState();
            return payload;
        } catch (error) {
            if (error.status === 401) {
                clearAuthState();
                return { success: true, alreadyExpired: true };
            }
            if (error.status === 403 && !options.retried) {
                csrfToken = null;
                await me();
                return logout({ retried: true, skipRefresh: true });
            }
            throw error;
        }
    }

    window.FAMApi = {
        request,
        me,
        logout,
        loginUrl,
        pageLoginUrl,
        clearAuthState,
        ApiError,
        get csrfToken() { return csrfToken; },
        setCsrfToken: token => { csrfToken = token; },
        get currentUser() { return currentUser; }
    };
})();