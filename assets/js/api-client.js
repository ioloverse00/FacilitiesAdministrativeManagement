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
        if (window.FAMNavigation?.appBasePath) return window.FAMNavigation.appBasePath();
        const configuredBase = document.body?.dataset?.appBasePath;
        if (configuredBase) return configuredBase.endsWith('/') ? configuredBase : `${configuredBase}/`;
        return '/';
    }

    function appPath(path = '') {
        const base = appBasePath().replace(/\/+$/, '');
        const normalized = String(path || '').replace(/^\/+/, '');
        return normalized ? `${base}/${normalized}`.replace(/^\/\//, '/') : `${base || '/'}`;
    }

    function loginUrl() {
        return `${window.location.origin}${appPath('pages/login.html')}`;
    }

    function pageLoginUrl() {
        const next = encodeURIComponent(window.location.pathname + window.location.search);
        return `${loginUrl()}?next=${next}`;
    }

    function apiUrl(path) {
        if (window.FAMNavigation?.apiUrl) return window.FAMNavigation.apiUrl(path);
        if (/^https?:\/\//i.test(path)) return path;
        if (path.startsWith('/')) return path;
        const normalized = String(path || '')
            .replace(/^(\.\.\/|\.\/)+/, '')
            .replace(/^api\/?/, '');
        return appPath(`api/${normalized}`);
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
            if (options.body instanceof FormData) {
                init.body = options.body;
            } else {
                headers.set('Content-Type', 'application/json');
                init.body = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);
            }
        }
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && csrfToken) headers.set('X-CSRF-Token', csrfToken);
        let response;
        try { response = await fetch(apiUrl(url), init); }
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
        const payload = await request('auth/me.php', { skipAuthRedirect: true });
        currentUser = payload.data?.user || null;
        updateCsrf(payload.data || payload);
        return { user: currentUser, csrfToken };
    }

    async function logout(options = {}) {
        if (!csrfToken && !options.skipRefresh) await me();
        try {
            const payload = await request('auth/logout.php', { method: 'POST', skipAuthRedirect: true });
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

    function documentRequestFromLink(link) {
        if (!link || link.dataset.documentFileAction) return null;
        const url = new URL(link.href, window.location.href);
        if (!/\/api\/documents\/(view|download)\.php$/i.test(url.pathname)) return null;
        const documentId = Number(url.searchParams.get('id') || 0);
        if (!documentId) return null;
        return {
            documentId,
            versionId: url.searchParams.get('version_id') || '',
            contractId: url.searchParams.get('contract_id') || '',
            legalMatterId: url.searchParams.get('legal_matter_id') || '',
            action: /download\.php$/i.test(url.pathname) ? 'download' : 'view',
            url: link.href,
            target: link.target || '_self',
        };
    }

    function documentStepUpPayload(requestInfo, extra = {}) {
        const url = requestInfo?.url ? new URL(requestInfo.url, window.location.href) : null;
        const value = (name, fallback = '') => requestInfo?.[name] || url?.searchParams.get(name.replace(/[A-Z]/g, char => `_${char.toLowerCase()}`)) || fallback;
        const payload = {
            document_id: requestInfo.documentId,
            version_id: value('versionId'),
            contract_id: value('contractId'),
            legal_matter_id: value('legalMatterId'),
            action: requestInfo.action || (url && /download\.php$/i.test(url.pathname) ? 'download' : 'view'),
            ...extra,
        };
        Object.keys(payload).forEach(key => {
            if (payload[key] === '' || payload[key] === null || payload[key] === undefined) delete payload[key];
        });
        return payload;
    }

    async function openDocumentWithStepUp(requestInfo) {
        await showDocumentPasswordDialog(requestInfo);
    }

    function showDocumentPasswordDialog(requestInfo) {
        return new Promise(resolve => {
            let modal = document.getElementById('fam-document-step-up-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'fam-document-step-up-modal';
                modal.className = 'facility-details-modal';
                modal.hidden = true;
                document.body.appendChild(modal);
            }
            let busy = false;
            const setBusy = nextBusy => {
                busy = nextBusy;
                modal.querySelectorAll('button, input').forEach(control => { control.disabled = nextBusy; });
            };
            const close = () => {
                const passwordInput = modal.querySelector('[data-document-password-input]');
                if (passwordInput) passwordInput.value = '';
                modal.hidden = true;
                modal.innerHTML = '';
                if (!document.querySelector('.facility-details-modal:not([hidden]), .facility-dialog:not([hidden])')) {
                    document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
                }
                resolve();
            };
            const renderShell = body => {
                modal.hidden = false;
                modal.innerHTML = `<form class="facility-dialog-panel document-form document-otp-panel" data-document-password-form>
                    <div class="facility-details-modal-header">
                        <div><p>Confidential Document</p><h2>Confirm document access</h2></div>
                        <button class="facility-details-modal-close" type="button" data-document-password-close aria-label="Close dialog">&times;</button>
                    </div>
                    ${body}
                </form>`;
                window.FAMModal?.bringToFront?.(modal);
                document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
                modal.querySelectorAll('[data-document-password-close]').forEach(button => button.addEventListener('click', close));
            };
            const renderReady = () => {
                renderShell(`<div class="facility-dialog-body document-form-body">
                    <section class="document-form-section document-otp-section">
                        <div class="document-otp-heading">
                            <h3>Enter your current password</h3>
                            <p>This confirms your identity before opening the confidential document.</p>
                        </div>
                        <label class="document-form-field">
                            <span>Password</span>
                            <input type="password" data-document-password-input autocomplete="current-password" required>
                        </label>
                        <div class="document-form-error hidden" data-document-password-error role="alert"></div>
                    </section>
                </div>
                <div class="facility-dialog-actions document-otp-actions">
                    <button class="btn-secondary dashboard-action-button" type="button" data-document-password-close>Cancel</button>
                    <button class="btn-primary dashboard-action-button" type="submit" data-document-password-submit>Continue</button>
                </div>`);
                const passwordInput = modal.querySelector('[data-document-password-input]');
                const errorBox = modal.querySelector('[data-document-password-error]');
                passwordInput?.focus();
                modal.querySelector('[data-document-password-form]')?.addEventListener('submit', async event => {
                    event.preventDefault();
                    if (busy) return;
                    const password = passwordInput?.value || '';
                    if (password === '') {
                        if (errorBox) {
                            errorBox.textContent = 'Password verification failed.';
                            errorBox.classList.remove('hidden');
                        }
                        return;
                    }

                    try {
                        setBusy(true);
                        await request(apiUrl('documents/verify-password.php'), {
                            method: 'POST',
                            body: documentStepUpPayload(requestInfo, { password }),
                        });
                        if (passwordInput) passwordInput.value = '';
                        close();
                        window.open(requestInfo.url, requestInfo.target, 'noopener');
                    } catch (error) {
                        if (passwordInput) {
                            passwordInput.value = '';
                            passwordInput.focus();
                        }
                        if (errorBox) {
                            errorBox.textContent = error.message || 'Password verification failed.';
                            errorBox.classList.remove('hidden');
                        }
                        setBusy(false);
                    }
                });
            };
            renderReady();
        });
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
    }

    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[href]');
        const requestInfo = documentRequestFromLink(link);
        if (!requestInfo) return;
        event.preventDefault();
        openDocumentWithStepUp(requestInfo).catch(error => {
            if (window.FAMModal?.showToast) {
                window.FAMModal.showToast(error.message || 'Document access was denied.', { type: 'error' });
            } else {
                alert(error.message || 'Document access was denied.');
            }
        });
    });

    window.FAMApi = {
        request,
        me,
        logout,
        apiUrl,
        loginUrl,
        pageLoginUrl,
        clearAuthState,
        openDocumentWithStepUp,
        ApiError,
        get csrfToken() { return csrfToken; },
        setCsrfToken: token => { csrfToken = token; },
        get currentUser() { return currentUser; }
    };
})();
