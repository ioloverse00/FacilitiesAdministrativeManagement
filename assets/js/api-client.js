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

    function documentRequestFromLink(link) {
        if (!link || link.dataset.documentFileAction) return null;
        const url = new URL(link.href, window.location.href);
        if (!/\/api\/documents\/(view|download)\.php$/i.test(url.pathname)) return null;
        const documentId = Number(url.searchParams.get('id') || 0);
        if (!documentId) return null;
        return {
            documentId,
            versionId: url.searchParams.get('version_id') || '',
            action: /download\.php$/i.test(url.pathname) ? 'download' : 'view',
            url: link.href,
            target: link.target || '_self',
        };
    }

    async function openDocumentWithStepUp(requestInfo) {
        await showDocumentOtpDialog(requestInfo, () => request(apiUrl('documents/request-otp.php'), {
            method: 'POST',
            body: { document_id: requestInfo.documentId },
        }));
    }

    function showDocumentOtpDialog(requestInfo, loadChallenge) {
        return new Promise(resolve => {
            let modal = document.getElementById('fam-document-step-up-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'fam-document-step-up-modal';
                modal.className = 'facility-details-modal';
                modal.hidden = true;
                modal.style.zIndex = '10050';
                document.body.appendChild(modal);
            }
            let remaining = 0;
            let resend = 0;
            let challengeId = '';
            let timer = null;
            let busy = false;
            let closed = false;
            const cells = () => Array.from(modal.querySelectorAll('[data-document-otp-cell]'));
            const otpValue = () => cells().map(input => input.value).join('');
            const setBusy = nextBusy => {
                busy = nextBusy;
                modal.querySelectorAll('button, input').forEach(control => { control.disabled = nextBusy; });
                renderTimer();
            };
            const clearCode = () => {
                cells().forEach(input => { input.value = ''; });
                modal.querySelector('[data-document-otp-cell]')?.focus();
                modal.querySelector('[data-document-otp-submit]')?.setAttribute('disabled', 'disabled');
            };
            const close = () => {
                closed = true;
                if (timer) clearInterval(timer);
                modal.hidden = true;
                modal.innerHTML = '';
                if (!document.querySelector('.facility-details-modal:not([hidden]), .facility-dialog:not([hidden])')) {
                    document.body.classList.remove('fam-modal-open', 'facility-details-modal-open');
                }
                resolve();
            };
            const renderShell = body => {
                modal.hidden = false;
                modal.innerHTML = `<form class="facility-dialog-panel document-form document-otp-panel" data-document-otp-form>
                    <div class="facility-details-modal-header">
                        <div><p>Confidential Document</p><h2>Verify document access</h2></div>
                        <button class="facility-details-modal-close" type="button" data-document-otp-close aria-label="Close dialog">&times;</button>
                    </div>
                    ${body}
                </form>`;
                document.body.classList.add('fam-modal-open', 'facility-details-modal-open');
                modal.querySelectorAll('[data-document-otp-close]').forEach(button => button.addEventListener('click', close));
            };
            const renderLoading = () => {
                renderShell(`<div class="facility-dialog-body document-form-body">
                    <section class="document-form-section document-otp-section">
                        <div class="document-otp-heading">
                            <h3>Sending verification code...</h3>
                            <p>Please wait while we prepare secure access for this confidential document.</p>
                        </div>
                        <div class="document-otp-loading" aria-hidden="true"></div>
                        <div class="document-form-error hidden" data-document-otp-error role="alert"></div>
                    </section>
                </div>
                <div class="facility-dialog-actions document-otp-actions">
                    <button class="btn-secondary dashboard-action-button" type="button" data-document-otp-close>Cancel</button>
                </div>`);
            };
            const renderDeliveryError = error => {
                renderShell(`<div class="facility-dialog-body document-form-body">
                    <section class="document-form-section document-otp-section">
                        <div class="document-otp-heading">
                            <h3>We couldn't send the verification code.</h3>
                            <p>${escapeHtml(error?.message || 'Please try again.')}</p>
                        </div>
                        <div class="document-form-error" data-document-otp-error role="alert">Document access was not granted.</div>
                    </section>
                </div>
                <div class="facility-dialog-actions document-otp-actions">
                    <button class="btn-secondary dashboard-action-button" type="button" data-document-otp-close>Close</button>
                </div>`);
            };
            const renderTimer = () => {
                const countdown = modal.querySelector('[data-document-otp-countdown]');
                if (countdown) countdown.textContent = `${Math.max(0, remaining)}s`;
                const resendButton = modal.querySelector('[data-document-otp-resend]');
                if (resendButton) {
                    resendButton.disabled = busy || resend > 0;
                    resendButton.textContent = resend > 0 ? `Resend code · ${resend}s` : 'Resend code';
                }
                const submit = modal.querySelector('[data-document-otp-submit]');
                if (submit) submit.disabled = busy || otpValue().length !== 6;
                const errorBox = modal.querySelector('[data-document-otp-error]');
                if (remaining <= 0 && errorBox) {
                    errorBox.textContent = 'The code expired. Request a new code.';
                    errorBox.classList.remove('hidden');
                }
            };
            const renderReady = challenge => {
                remaining = Number(challenge.expires_in_seconds || 60);
                resend = Number(challenge.resend_cooldown_seconds || 30);
                challengeId = challenge.challenge_id || '';
                renderShell(`<div class="facility-dialog-body document-form-body">
                    <section class="document-form-section document-otp-section">
                        <div class="document-otp-heading">
                            <h3>Enter verification code</h3>
                            <p>For your security, enter the 6-digit code sent to ${challenge.email_hint ? `<strong>${escapeHtml(challenge.email_hint)}</strong>` : 'your registered email'}.</p>
                            <p>Code expires in <strong data-document-otp-countdown>${remaining}s</strong>.</p>
                        </div>
                        <fieldset class="document-otp-group" aria-label="Document verification code">
                            ${Array.from({ length: 6 }).map((_, index) => `<input class="document-otp-cell" data-document-otp-cell data-index="${index}" inputmode="numeric" autocomplete="${index === 0 ? 'one-time-code' : 'off'}" maxlength="1" pattern="[0-9]" aria-label="Verification code digit ${index + 1} of 6">`).join('')}
                        </fieldset>
                        <div class="document-form-error hidden" data-document-otp-error role="alert"></div>
                    </section>
                </div>
                <div class="facility-dialog-actions document-otp-actions">
                    <button class="btn-secondary dashboard-action-button" type="button" data-document-otp-close>Cancel</button>
                    <button class="btn-secondary dashboard-action-button" type="button" data-document-otp-resend disabled>Resend code · ${resend}s</button>
                    <button class="btn-primary dashboard-action-button" type="submit" data-document-otp-submit disabled>Verify</button>
                </div>`);
                modal.querySelector('[data-document-otp-cell]')?.focus();
                renderTimer();
                timer = setInterval(() => { remaining -= 1; resend -= 1; renderTimer(); }, 1000);
                cells().forEach((input, index) => {
                    input.addEventListener('input', () => {
                        input.value = input.value.replace(/\D/g, '').slice(-1);
                        if (input.value && index < 5) cells()[index + 1]?.focus();
                        renderTimer();
                    });
                    input.addEventListener('keydown', event => {
                        if (event.key === 'Backspace' && !input.value && index > 0) cells()[index - 1]?.focus();
                        if (event.key === 'ArrowLeft' && index > 0) cells()[index - 1]?.focus();
                        if (event.key === 'ArrowRight' && index < 5) cells()[index + 1]?.focus();
                    });
                    input.addEventListener('paste', event => {
                        event.preventDefault();
                        const digits = (event.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 6).split('');
                        cells().forEach((cell, cellIndex) => { cell.value = digits[cellIndex] || ''; });
                        cells()[Math.min(digits.length, 5)]?.focus();
                        renderTimer();
                    });
                });
                modal.querySelector('[data-document-otp-resend]')?.addEventListener('click', async () => {
                    const box = modal.querySelector('[data-document-otp-error]');
                    box?.classList.add('hidden');
                    try {
                        setBusy(true);
                        const fresh = await request(apiUrl('documents/request-otp.php'), { method: 'POST', body: { document_id: requestInfo.documentId } });
                        challengeId = fresh.data.challenge_id || challengeId;
                        remaining = Number(fresh.data.expires_in_seconds || 60);
                        resend = Number(fresh.data.resend_cooldown_seconds || 30);
                        clearCode();
                        renderTimer();
                    } catch (error) {
                        if (box) {
                            box.textContent = error.message || 'Unable to send a new verification code.';
                            box.classList.remove('hidden');
                        }
                    } finally {
                        setBusy(false);
                    }
                });
                modal.querySelector('[data-document-otp-form]')?.addEventListener('submit', async event => {
                    event.preventDefault();
                    const box = modal.querySelector('[data-document-otp-error]');
                    box?.classList.add('hidden');
                    const otp = otpValue();
                    if (otp.length !== 6) return;
                    try {
                        setBusy(true);
                        await request(apiUrl('documents/verify-otp.php'), { method: 'POST', body: { document_id: requestInfo.documentId, challenge_id: challengeId, otp } });
                        close();
                        window.open(requestInfo.url, requestInfo.target, 'noopener');
                    } catch (error) {
                        if (box) {
                            box.textContent = error.message || 'Verification failed. Check the code and try again.';
                            box.classList.remove('hidden');
                        }
                        clearCode();
                        setBusy(false);
                    }
                });
            };
            renderLoading();
            loadChallenge()
                .then(payload => {
                    if (closed) return;
                    if (payload.data?.step_up_required === false) {
                        close();
                        window.open(requestInfo.url, requestInfo.target, 'noopener');
                        return;
                    }
                    renderReady(payload.data || {});
                })
                .catch(error => {
                    if (closed) return;
                    renderDeliveryError(error);
                });
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
