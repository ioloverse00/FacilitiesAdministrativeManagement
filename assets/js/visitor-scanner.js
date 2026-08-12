(function () {
  const qs = s => document.querySelector(s);
  const state = {
    user: null,
    options: {},
    item: null,
    stream: null,
    detector: null,
    decoderMode: '',
    devices: [],
    deviceIndex: 0,
    scanning: false,
    resolving: false,
    lastPayload: '',
    lastDecodeAt: 0,
    decodeEveryMs: 220,
    raf: 0,
    lastFocus: null
  };
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  const title = v => String(v || '').toLowerCase().split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  const fmt = v => { if (!v) return ''; const d = new Date(String(v).replace(' ', 'T')); return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleString(undefined, { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' }); };
  const can = action => (state.item?.allowed_actions || []).includes(action);
  const toast = m => window.FAMModal?.showToast?.(m);
  const badge = v => `<span class="facility-badge facility-status-${String(v || 'none').toLowerCase().replace(/[^a-z0-9]+/g, '-')}">${esc(statusLabel(v))}</span>`;

  function statusLabel(v) {
    return ({ PRE_REGISTERED:'Pending Verification', PENDING_REVIEW:'Pending Verification', APPROVED:'Approved', REJECTED:'Rejected', ARRIVED:'Pending Verification', CHECKED_IN:'Checked In', CHECKED_OUT:'Checked Out', CANCELLED:'Cancelled', NO_SHOW:'No Show', EXPIRED:'Expired' }[String(v || '').toUpperCase()] || title(v));
  }

  function cleanText(v) {
    return String(v || '').replace(/\s+/g, ' ').trim();
  }

  function statusTone(text) {
    const value = String(text || '').toLowerCase();
    if (value.includes('denied') || value.includes('not found') || value.includes('unavailable') || value.includes('unsupported') || value.includes('expired') || value.includes('unable')) return ['is-error', 'error'];
    if (value.includes('found') || value.includes('checked') || value.includes('approved')) return ['is-success', 'check_circle'];
    if (value.includes('scanning') || value.includes('verifying')) return ['is-active', 'qr_code_scanner'];
    return ['is-ready', 'radio_button_checked'];
  }

  function message(titleText, helperText) {
    const [tone, icon] = statusTone(titleText);
    const card = qs('#scanner-status-card');
    card.className = `scanner-status-card ${tone}`;
    qs('#scanner-status-icon').textContent = icon;
    qs('#scanner-status').textContent = titleText || 'Ready to scan';
    qs('#camera-message').textContent = helperText || defaultHelper(titleText);
  }

  function defaultHelper(text) {
    const value = String(text || '').toLowerCase();
    if (value.includes('found')) return 'Review the visitor details and complete the next required action.';
    if (value.includes('scanning')) return 'Position the visitor QR code inside the frame.';
    if (value.includes('verifying')) return 'Please wait while the pass is checked.';
    return 'Use the camera or manual lookup to begin.';
  }

  function extractToken(payload) {
    const raw = String(payload || '').trim();
    if (/^[A-Za-z0-9_-]{32,128}$/.test(raw)) return raw;
    try {
      const url = new URL(raw, window.location.origin);
      if (url.origin !== window.location.origin) return '';
      return url.searchParams.get('token') || url.searchParams.get('t') || '';
    } catch {
      return '';
    }
  }

  async function lookupByToken(token) {
    if (!/^[A-Za-z0-9_-]{32,128}$/.test(token)) throw new Error('Unsupported or malformed visitor QR code.');
    return resolve(`../api/visitors/scan-lookup.php?token=${encodeURIComponent(token)}`);
  }

  async function manualLookup(query) {
    return resolve(`../api/visitors/manual-lookup.php?query=${encodeURIComponent(query.trim())}`);
  }

  async function resolve(url) {
    if (state.resolving) return;
    state.resolving = true;
    message('Verifying visitor pass...');
    try {
      const response = await window.FAMApi.request(url);
      state.item = response.data || null;
      renderResult();
      await stopCamera();
      message('Visitor pass found');
      qs('#scanner-result')?.focus();
    } catch (error) {
      message(error.message || 'Visitor pass not recognized', 'Use Manual Lookup or scan a valid Visitor Pass QR.');
      throw error;
    } finally {
      state.resolving = false;
    }
  }

  async function startCamera() {
    if (!window.isSecureContext && location.hostname !== 'localhost') {
      message('Camera QR scanning is unavailable in this browser.', 'Use Manual Lookup.');
      return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      message('Camera QR scanning is unavailable in this browser.', 'Use Manual Lookup.');
      return;
    }
    const supportsNative = 'BarcodeDetector' in window;
    const supportsLocal = typeof window.jsQR === 'function';
    if (!supportsNative && !supportsLocal) {
      message('Camera QR scanning is unavailable in this browser.', 'Use Manual Lookup.');
      return;
    }
    try {
      if (supportsNative) {
        state.detector = state.detector || new BarcodeDetector({ formats: ['qr_code'] });
        state.decoderMode = 'native';
      } else {
        state.decoderMode = 'jsqr';
      }
      state.devices = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput');
      const deviceId = state.devices[state.deviceIndex]?.deviceId;
      const constraints = { video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }, audio: false };
      state.stream = await navigator.mediaDevices.getUserMedia(constraints);
      const video = qs('#scanner-video');
      video.srcObject = state.stream;
      video.hidden = false;
      qs('#scanner-placeholder').hidden = true;
      await video.play();
      state.scanning = true;
      qs('.scanner-camera-frame').classList.add('is-active');
      state.lastPayload = '';
      state.lastDecodeAt = 0;
      updateControls();
      message('Scanning for visitor QR', state.decoderMode === 'native' ? 'Native detection is active.' : 'Local QR detection is active.');
      scanLoop();
    } catch (error) {
      const denied = error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError';
      const notFound = error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError';
      message(denied ? 'Camera access was denied.' : notFound ? 'No usable camera was found.' : 'Camera is unavailable or already in use.', 'You can continue using Manual Lookup.');
      await stopCamera();
    }
  }

  async function stopCamera() {
    cancelAnimationFrame(state.raf);
    state.scanning = false;
    if (state.stream) state.stream.getTracks().forEach(track => track.stop());
    state.stream = null;
    const video = qs('#scanner-video');
    video.pause();
    video.srcObject = null;
    video.hidden = true;
    qs('.scanner-camera-frame').classList.remove('is-active');
    qs('#scanner-placeholder').hidden = false;
    updateControls();
  }

  async function switchCamera() {
    if (state.devices.length < 2) return;
    state.deviceIndex = (state.deviceIndex + 1) % state.devices.length;
    await stopCamera();
    await startCamera();
  }

  async function scanLoop() {
    if (!state.scanning || state.resolving) return;
    try {
      const raw = await decodeFrame();
      if (raw && raw !== state.lastPayload) {
        state.lastPayload = raw;
        const token = extractToken(raw);
        if (!token) {
          message('Visitor pass not recognized', 'Use a Visitor Pass QR or Manual Lookup.');
        } else {
          await lookupByToken(token).catch(() => {});
          qs('#scan-again').hidden = false;
          return;
        }
      }
    } catch {
      message('Unable to read the QR code', 'Keep it steady or use Manual Lookup.');
    }
    state.raf = requestAnimationFrame(scanLoop);
  }

  async function decodeFrame() {
    const now = performance.now();
    if (now - state.lastDecodeAt < state.decodeEveryMs) return '';
    state.lastDecodeAt = now;
    const video = qs('#scanner-video');
    if (!video.videoWidth || !video.videoHeight) return '';
    if (state.decoderMode === 'native') {
      const codes = await state.detector.detect(video);
      return codes[0]?.rawValue || '';
    }
    const canvas = qs('#scanner-canvas');
    const maxWidth = 640;
    const ratio = Math.min(1, maxWidth / video.videoWidth);
    canvas.width = Math.max(1, Math.floor(video.videoWidth * ratio));
    canvas.height = Math.max(1, Math.floor(video.videoHeight * ratio));
    const context = canvas.getContext('2d', { willReadFrequently: true });
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    const image = context.getImageData(0, 0, canvas.width, canvas.height);
    const code = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
    return code?.data || '';
  }

  function updateControls() {
    qs('#start-camera').hidden = state.scanning || Boolean(state.item);
    qs('#stop-camera').hidden = !state.scanning;
    qs('#switch-camera').hidden = state.devices.length < 2 || !state.scanning;
    qs('#scan-again').hidden = state.scanning || !state.item;
  }

  function display(v, fallback = '-') {
    return v ? v : fallback;
  }

  function detail(k, v, fallback = '-') {
    return `<dl class="facility-detail-row"><dt>${esc(k)}</dt><dd>${esc(display(v, fallback))}</dd></dl>`;
  }

  function summaryRow(k, v) {
    return `<div><dt>${esc(k)}</dt><dd>${esc(display(v))}</dd></div>`;
  }

  function compactDateRange(start, end) {
    if (!start && !end) return 'Not scheduled';
    return [fmt(start), end ? `to ${fmt(end)}` : ''].filter(Boolean).join(' ');
  }

  function duration(start, end) {
    if (!start || !end) return '-';
    const a = new Date(String(start).replace(' ', 'T'));
    const b = new Date(String(end).replace(' ', 'T'));
    if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime()) || b < a) return '-';
    const mins = Math.round((b - a) / 60000);
    const hrs = Math.floor(mins / 60);
    const rest = mins % 60;
    return hrs ? `${hrs} hr ${rest} min` : `${rest} min`;
  }

  function renderResult() {
    const target = qs('#scanner-result');
    if (!state.item) return;
    const { visit, visitor, destination, identity_verification: idv, badge: visitBadge, state_message: stateMessage } = state.item;
    const destinationLabel = destination.department_name || destination.facility_space_name || '-';
    const visitorName = cleanText(visitor.full_name);
    target.innerHTML = `<div class="scanner-visitor-hero"><div><p>${esc(stateMessage)}</p><h2 id="resolved-title">${esc(visitorName)}</h2><div class="scanner-visitor-meta"><span>${esc(visit.visitor_reference_number)}</span><span>${esc(title(visitor.visitor_type))}</span><span>${esc(title(visit.registration_source))}</span><span>${esc(destinationLabel)}</span></div></div>${badge(visit.status)}</div>
      <section class="scanner-summary-card" aria-label="Visit summary"><dl>
        ${summaryRow('Purpose', visit.purpose)}
        ${summaryRow('Destination', destinationLabel)}
        ${summaryRow('Host', destination.host_name)}
        ${summaryRow('Scheduled Visit', compactDateRange(visit.scheduled_start_at, visit.scheduled_end_at))}
        ${summaryRow('Current Status', statusLabel(visit.status))}
      </dl></section>
      <div class="scanner-secondary-grid">
        <details open><summary>Identity Verification</summary>${detail('Verified', idv.verified ? 'Yes' : 'Not verified')}${detail('ID Type', title(idv.identification_type))}${detail('ID Last Four', idv.identification_last4)}${detail('Verified At', fmt(idv.verified_at))}</details>
        <details><summary>Badge and Timeline</summary>${detail('Badge', visitBadge ? `${visitBadge.badge_number} (${title(visitBadge.status)})` : 'No badge issued')}${detail('Time In', fmt(visit.actual_check_in_at))}${detail('Time Out', fmt(visit.actual_check_out_at))}${detail('Duration', duration(visit.actual_check_in_at, visit.actual_check_out_at))}</details>
        <details><summary>Registration Details</summary>${detail('Organization', visitor.organization_name)}${detail('Approval Status', title(visit.approval_status))}${detail('Registration Source', title(visit.registration_source))}${detail('Facility Space', destination.facility_space_name)}</details>
      </div>
      <div class="scanner-action-panel">${actionsHtml(visit, visitorName, visitBadge)}</div>`;
  }

  function actionsHtml(visit, visitorName, visitBadge) {
    if (can('APPROVE') || can('REJECT')) {
      return `<span class="scanner-action-eyebrow">Next action</span><h3>Review Required</h3><p>Approve or reject this registration before check-in.</p><div class="scanner-action-row">${can('APPROVE') ? '<button class="btn-primary dashboard-action-button" type="button" data-scanner-action="approve">Approve Visitor</button>' : ''}${can('REJECT') ? '<button class="btn-secondary dashboard-action-button scanner-danger-button" type="button" data-scanner-action="reject">Reject</button>' : ''}</div>`;
    }
    if (can('CHECK_IN')) {
      return `<span class="scanner-action-eyebrow">Next action</span><h3>Identity Verification and Check-In</h3><p>Verify the visitor identity, assign a badge if needed, then confirm check-in.</p><button class="btn-primary dashboard-action-button" type="button" data-scanner-action="checkin">Check In Visitor</button>`;
    }
    if (can('CHECK_OUT')) {
      return `<span class="scanner-action-eyebrow">Next action</span><h3>Visitor Currently Inside</h3><p>${visitBadge ? `Badge ${esc(visitBadge.badge_number)} will be marked returned during checkout.` : 'No badge is currently issued.'}</p><button class="btn-primary dashboard-action-button" type="button" data-scanner-action="checkout">Check Out Visitor</button>`;
    }
    return `<span class="scanner-action-eyebrow">Current state</span><h3>${esc(state.item.state_message || 'No Action Available')}</h3><p>Scan another visitor or use manual lookup to resolve a different record.</p><button class="btn-secondary dashboard-action-button" type="button" data-scan-another>Scan Another Visitor</button>`;
  }

  function optionList(items, placeholder) {
    return `<option value="">${esc(placeholder)}</option>${(items || []).map(x => `<option value="${esc(x.id || x)}">${esc(x.badge_number || x.name || title(x))}</option>`).join('')}`;
  }

  function openDialog(html) {
    const dialog = qs('#scanner-dialog');
    if (dialog.parentElement !== document.body) document.body.appendChild(dialog);
    state.lastFocus = document.activeElement;
    dialog.hidden = false;
    dialog.innerHTML = html;
    dialog.setAttribute('role', 'presentation');
    document.body.style.overflow = 'hidden';
    dialog.querySelector('input,select,textarea,button')?.focus();
  }

  function closeDialog() {
    const dialog = qs('#scanner-dialog');
    window.FAMModal?.closeElement?.(dialog, () => {
      dialog.innerHTML = '';
      document.body.style.overflow = '';
      state.lastFocus?.focus?.();
    });
  }

  function reviewDialog(action) {
    const visit = state.item.visit, visitor = state.item.visitor;
    const visitorName = cleanText(visitor.full_name);
    const approve = action === 'APPROVE';
    const titleText = approve ? 'Approve Visitor' : 'Reject Visitor Registration';
    const desc = approve ? 'You are about to approve this visitor registration.' : 'This visitor will not be allowed to proceed to check-in.';
    const fieldLabel = approve ? 'Remarks' : 'Rejection Reason';
    const buttonText = approve ? 'Approve Visitor' : 'Reject Registration';
    openDialog(`<form class="facility-dialog-panel scanner-modal-panel" role="dialog" aria-modal="true" aria-labelledby="scanner-modal-title" aria-describedby="scanner-modal-desc" data-review-form data-action="${action}"><div class="facility-details-modal-header"><div><p>Visitor Scanner</p><h2 id="scanner-modal-title">${titleText}</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close dialog">&times;</button></div><div class="scanner-modal-body"><div class="scanner-modal-visitor"><strong>${esc(visitorName)}</strong><span>${esc(visit.visitor_reference_number)}</span></div><p id="scanner-modal-desc" class="scanner-dialog-copy">${desc}</p><label class="facility-field"><span>${fieldLabel}${approve ? '' : ' required'}</span><textarea name="remarks" rows="3" ${approve ? '' : 'required'}>${approve ? 'Approved from Visitor Scanner.' : ''}</textarea></label></div><div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-close-dialog>Cancel</button><button class="btn-primary dashboard-action-button ${approve ? '' : 'scanner-danger-primary'}" type="submit">${buttonText}</button></div></form>`);
  }

  function checkinDialog() {
    const visit = state.item.visit, visitor = state.item.visitor;
    const visitorName = cleanText(visitor.full_name);
    const destination = state.item.destination.department_name || state.item.destination.facility_space_name || '-';
    openDialog(`<form class="facility-dialog-panel scanner-modal-panel" role="dialog" aria-modal="true" aria-labelledby="scanner-modal-title" aria-describedby="scanner-modal-desc" data-checkin-form><div class="facility-details-modal-header"><div><p>Visitor Scanner</p><h2 id="scanner-modal-title">Check In Visitor</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close dialog">&times;</button></div><div class="scanner-modal-body"><div class="scanner-modal-visitor"><strong>${esc(visitorName)}</strong><span>${esc(visit.visitor_reference_number)}</span><span>Destination: ${esc(destination)}</span></div><p id="scanner-modal-desc" class="scanner-dialog-copy">Verify the visitor identity before confirming check-in.</p><section class="scanner-modal-section"><h3>Identity Verification</h3><label class="visitor-check-filter"><input name="identity_verified" type="checkbox" value="1" required> Identity verified</label><div class="facility-form-grid"><label class="facility-field"><span>ID Type</span><select name="identification_type">${optionList(state.options.identity_document_types, 'Optional')}</select></label><label class="facility-field"><span>ID Last Four</span><input name="identification_last4" maxlength="16" placeholder="Optional"></label></div><label class="facility-field"><span>Remarks</span><textarea name="remarks" rows="3">Verified at reception.</textarea></label></section><section class="scanner-modal-section"><h3>Badge Assignment</h3><label class="facility-field"><span>Badge / Pass</span><select name="badge_id">${optionList(state.options.available_badges, 'No badge')}</select></label></section></div><div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-close-dialog>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Confirm Check-In</button></div></form>`);
  }

  function checkoutDialog() {
    const visit = state.item.visit, visitor = state.item.visitor, visitBadge = state.item.badge;
    const visitorName = cleanText(visitor.full_name);
    openDialog(`<form class="facility-dialog-panel scanner-modal-panel" role="dialog" aria-modal="true" aria-labelledby="scanner-modal-title" aria-describedby="scanner-modal-desc" data-checkout-form><div class="facility-details-modal-header"><div><p>Visitor Scanner</p><h2 id="scanner-modal-title">Check Out Visitor</h2></div><button class="facility-details-modal-close" type="button" data-close-dialog aria-label="Close dialog">&times;</button></div><div class="scanner-modal-body"><div class="scanner-modal-visitor"><strong>${esc(visitorName)}</strong><span>${esc(visit.visitor_reference_number)}</span></div><dl class="scanner-modal-facts">${summaryRow('Time In', fmt(visit.actual_check_in_at))}${summaryRow('Current Badge', visitBadge ? visitBadge.badge_number : 'No badge issued')}</dl><p id="scanner-modal-desc" class="scanner-dialog-copy">The visitor will be marked as checked out. Any issued badge will be returned to available inventory.</p><label class="facility-field"><span>Remarks</span><textarea name="remarks" rows="3">Checked out from Visitor Scanner.</textarea></label></div><div class="facility-dialog-actions"><button class="btn-secondary dashboard-action-button" type="button" data-close-dialog>Cancel</button><button class="btn-primary dashboard-action-button" type="submit">Confirm Check-Out</button></div></form>`);
  }

  async function refreshResolved() {
    if (!state.item?.visit?.visitor_reference_number) return;
    const query = state.item.visit.visitor_reference_number;
    await manualLookup(query).catch(error => toast(error.message || 'Unable to refresh visitor.'));
  }

  async function submitReview(form) {
    const data = Object.fromEntries(new FormData(form).entries());
    form.querySelector('[type="submit"]').disabled = true;
    await window.FAMApi.request(`../api/visitors/review.php?id=${state.item.visit.id}`, { method:'POST', body:{ action: form.dataset.action, remarks: data.remarks || null } });
    closeDialog();
    toast('Visitor review action completed.');
    await refreshResolved();
  }

  async function submitCheckin(form) {
    if (!form.reportValidity()) return;
    const data = Object.fromEntries(new FormData(form).entries());
    form.querySelector('[type="submit"]').disabled = true;
    data.identity_verified = true;
    await window.FAMApi.request(`../api/visitors/check-in.php?id=${state.item.visit.id}`, { method:'POST', body:data });
    closeDialog();
    toast('Visitor checked in.');
    await refreshResolved();
  }

  async function submitCheckout(form) {
    const data = Object.fromEntries(new FormData(form).entries());
    form.querySelector('[type="submit"]').disabled = true;
    await window.FAMApi.request(`../api/visitors/check-out.php?id=${state.item.visit.id}`, { method:'POST', body:{ remarks: data.remarks || 'Checked out from Visitor Scanner.' } });
    closeDialog();
    toast('Visitor checked out.');
    await refreshResolved();
  }

  function resetResult() {
    state.item = null;
    renderEmptyResult();
    qs('#manual-query').value = '';
    updateControls();
    message('Ready to scan', 'Scan a visitor QR code or enter a visitor reference to begin.');
  }

  function renderEmptyResult() {
    qs('#scanner-result').innerHTML = '<div class="scanner-result-empty"><span class="material-symbols-outlined" aria-hidden="true">badge</span><h2 id="resolved-title">No visitor selected</h2><p>Scan a visitor QR code or enter a visitor reference to begin.</p><ol><li>Scan or enter reference</li><li>Review visitor details</li><li>Complete the required action</li></ol></div>';
  }

  function bind() {
    qs('#start-camera').addEventListener('click', startCamera);
    qs('#stop-camera').addEventListener('click', () => stopCamera().then(() => message('Camera stopped.')));
    qs('#switch-camera').addEventListener('click', switchCamera);
    qs('#scan-again').addEventListener('click', () => { resetResult(); startCamera(); });
    qs('#manual-lookup-form').addEventListener('submit', event => {
      event.preventDefault();
      manualLookup(qs('#manual-query').value).catch(error => toast(error.message || 'Lookup failed.'));
    });
    document.addEventListener('click', event => {
      const action = event.target.closest('[data-scanner-action]')?.dataset.scannerAction;
      if (action === 'approve') return reviewDialog('APPROVE');
      if (action === 'reject') return reviewDialog('REJECT');
      if (action === 'checkin') return checkinDialog();
      if (action === 'checkout') return checkoutDialog();
      if (event.target.closest('[data-scan-another]')) return resetResult();
      if (event.target.closest('[data-close-dialog]') || event.target === qs('#scanner-dialog')) return closeDialog();
    });
    document.addEventListener('submit', event => {
      if (event.target.matches('[data-review-form]')) { event.preventDefault(); submitReview(event.target).catch(error => toast(error.message || 'Review failed.')); }
      if (event.target.matches('[data-checkin-form]')) { event.preventDefault(); submitCheckin(event.target).catch(error => toast(error.message || 'Check-in failed.')); }
      if (event.target.matches('[data-checkout-form]')) { event.preventDefault(); submitCheckout(event.target).catch(error => toast(error.message || 'Check-out failed.')); }
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !qs('#scanner-dialog').hidden) closeDialog(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopCamera(); });
    window.addEventListener('beforeunload', stopCamera);
  }

  document.addEventListener('fam:layout-ready', async () => {
    bind();
    try {
      const me = await window.FAMApi.me();
      const opt = await window.FAMApi.request('../api/visitors/options.php');
      state.user = me.user;
      state.options = opt.data || {};
      renderEmptyResult();
      updateControls();
      message('Ready to scan', 'Manual lookup is ready.');
    } catch (error) {
      if (error.status === 401) window.location.href = window.FAMApi.pageLoginUrl();
      else toast(error.message || 'Unable to initialize Visitor Scanner.');
    }
  });
})();
